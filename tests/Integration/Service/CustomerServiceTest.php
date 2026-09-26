<?php

namespace FA\GraphQL\Tests\Integration\Service;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * CustomerService against FrontAccounting in-process, as apitest. Every row written
 * carries this test's reference prefix and is swept in tearDown.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerServiceTest extends FaTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        (new FaSession(Config::fromArray(['secret' => str_repeat('k', 32)])))
            ->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function service(): CustomerService
    {
        return new CustomerService();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'GraphQL Test Customer',
            'ref' => $this->prefix . 'c',
            'salesTypeId' => '1',
            'paymentTermsId' => '3',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => ['phone' => '555-0100', 'email' => 'gqlt@example.com'],
        ], $overrides);
    }

    private function create(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return $this->service()->create($input);
        });
    }

    private function update(array $input): void
    {
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    private function delete(int $id): void
    {
        ServiceCall::run(function () use ($id): void {
            $this->service()->delete($id);
        });
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function customerByRef(string $ref): ?array
    {
        return $this->one('SELECT * FROM 0_debtors_master WHERE debtor_ref = ?', [$ref]);
    }

    /** A demo customer with transactions, or the test is skipped. */
    private function customerWithTransactions(): array
    {
        $row = $this->one(
            'SELECT d.* FROM 0_debtors_master d WHERE EXISTS '
            . '(SELECT 1 FROM 0_debtor_trans t WHERE t.debtor_no = d.debtor_no) ORDER BY d.debtor_no LIMIT 1',
            []
        );
        if ($row === null) {
            $this->markTestSkipped('The dataset has no customer with transactions.');
        }

        return $row;
    }

    private function assertBadInput(string $field, string $message, callable $call): void
    {
        try {
            $call();
            $this->fail("expected BadInput on $field");
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field(), $e->getMessage());
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testApitestMayWriteCustomers(): void
    {
        $this->assertTrue($_SESSION['wa_current_user']->can_access('SA_CUSTOMER'));
    }

    public function testANewCustomerGetsItsDefaultBranchAndContactAsThePageMakesThem(): void
    {
        $id = $this->create($this->input(['address' => "1 Test Street\nTestville"]));

        $customer = $this->customerByRef($this->prefix . 'c');
        $this->assertSame((string) $id, (string) $customer['debtor_no']);
        $this->assertSame('GraphQL Test Customer', $customer['name']);
        $this->assertSame('USD', $customer['curr_code']);
        $this->assertEquals(1000, $customer['credit_limit']);
        $this->assertEquals(0, $customer['discount']);
        $this->assertEquals(0, $customer['pymt_discount']);
        $this->assertSame('1', (string) $customer['sales_type']);
        $this->assertSame('3', (string) $customer['payment_terms']);
        $this->assertSame('1', (string) $customer['credit_status']);
        $this->assertSame('0', (string) $customer['dimension_id']);

        $branches = $this->all('SELECT * FROM 0_cust_branch WHERE debtor_no = ?', [$id]);
        $this->assertCount(1, $branches);
        $branch = $branches[0];
        $this->assertSame('GraphQL Test Customer', $branch['br_name']);
        $this->assertSame($this->prefix . 'c', $branch['branch_ref']);
        $this->assertSame("1 Test Street\nTestville", $branch['br_address']);
        $this->assertSame("1 Test Street\nTestville", $branch['br_post_address']);
        $this->assertSame('1', (string) $branch['salesman']);
        $this->assertSame('1', (string) $branch['area']);
        $this->assertSame('1', (string) $branch['tax_group_id']);
        $this->assertSame('DEF', $branch['default_location']);
        $this->assertSame('1', (string) $branch['default_ship_via']);
        $this->assertSame('', $branch['sales_account']);
        $this->assertSame((string) get_company_pref('default_sales_discount_act'), $branch['sales_discount_account']);
        $this->assertSame((string) get_company_pref('debtors_act'), $branch['receivables_account']);
        $this->assertSame((string) get_company_pref('default_prompt_payment_act'), $branch['payment_discount_account']);

        $person = $this->one('SELECT * FROM 0_crm_persons WHERE ref = ?', [$this->prefix . 'c']);
        $this->assertSame('GraphQL Test Customer', $person['name']);
        $this->assertSame('555-0100', $person['phone']);
        $this->assertSame('gqlt@example.com', $person['email']);
        $links = $this->all(
            'SELECT type, action, entity_id FROM 0_crm_contacts WHERE person_id = ? ORDER BY type',
            [$person['id']]
        );
        // utf8mb4_general_ci orders 'customer' before 'cust_branch' (confirmed
        // directly: `SELECT 'cust_branch' < 'customer'` is 0 under this collation),
        // not the byte-wise ASCII order ('_' < 'o') the brief assumed.
        $this->assertSame([
            ['type' => 'customer', 'action' => 'general', 'entity_id' => (string) $id],
            ['type' => 'cust_branch', 'action' => 'general', 'entity_id' => (string) $branch['branch_code']],
        ], $links);
    }

    public function testDiscountsArePercentInTheApiAndFractionsInTheTable(): void
    {
        $this->create($this->input(['discountPercent' => 12.5, 'paymentDiscountPercent' => 2]));

        $customer = $this->customerByRef($this->prefix . 'c');
        $this->assertEqualsWithDelta(0.125, (float) $customer['discount'], 1e-9);
        $this->assertEqualsWithDelta(0.02, (float) $customer['pymt_discount'], 1e-9);
    }

    /**
     * customers.php:39-77, in its order and with its messages.
     *
     * @dataProvider pageChecks
     */
    public function testThePagesChecksRefuse(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create($this->input($overrides));
        });
        $this->assertNull($this->customerByRef($this->prefix . 'c'));
    }

    public function pageChecks(): array
    {
        return [
            'empty name' => [['name' => ''], 'name', 'The customer name cannot be empty.'],
            'empty short name' => [['ref' => ''], 'ref', 'The customer short name cannot be empty.'],
            'negative credit limit' => [
                ['creditLimit' => -1], 'creditLimit', 'The credit limit must be numeric and not less than zero.',
            ],
            'payment discount over 100' => [
                ['paymentDiscountPercent' => 100.5], 'paymentDiscountPercent',
                'The payment discount must be numeric and is expected to be less than 100% '
                . 'and greater than or equal to 0.',
            ],
            'negative discount' => [
                ['discountPercent' => -0.5], 'discountPercent',
                'The discount percentage must be numeric and is expected to be less than 100% '
                . 'and greater than or equal to 0.',
            ],
        ];
    }

    public function testAShortNameInUseIsRefusedByName(): void
    {
        $this->create($this->input());

        $this->assertBadInput(
            'ref',
            "A customer with the short name '{$this->prefix}c' already exists.",
            function (): void {
                $this->create($this->input(['name' => 'Another']));
            }
        );
    }

    /**
     * @dataProvider unknownReferences
     */
    public function testUnknownReferencesAreRefused(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create(array_replace_recursive($this->input(), $overrides));
        });
        $this->assertNull($this->customerByRef($this->prefix . 'c'));
    }

    public function unknownReferences(): array
    {
        return [
            'sales type' => [['salesTypeId' => '999'], 'salesTypeId', "There is no sales type '999'."],
            'payment terms' => [['paymentTermsId' => '999'], 'paymentTermsId', "There is no payment terms '999'."],
            'credit status' => [['creditStatusId' => '999'], 'creditStatusId', "There is no credit status '999'."],
            'currency' => [['currencyId' => 'XXX'], 'currencyId', "There is no currency 'XXX'."],
            'salesperson' => [
                ['branch' => ['salesmanId' => '999']], 'branch.salesmanId', "There is no salesperson '999'.",
            ],
            'location' => [['branch' => ['locationId' => 'NOPE']], 'branch.locationId', "There is no location 'NOPE'."],
            // MySQL would cast '1 x' to 1 for the check, then strict mode refuse the
            // insert as INTERNAL: an integer reference is a whole number or BAD_INPUT.
            'sales type not a number' => [
                ['salesTypeId' => '1 x'], 'salesTypeId', 'salesTypeId must be a positive whole number.',
            ],
            'payment terms not a number' => [
                ['paymentTermsId' => '3.0'], 'paymentTermsId', 'paymentTermsId must be a positive whole number.',
            ],
            'credit status not a number' => [
                ['creditStatusId' => ' 1'], 'creditStatusId', 'creditStatusId must be a positive whole number.',
            ],
            'salesperson not a number' => [
                ['branch' => ['salesmanId' => '1 x']], 'branch.salesmanId',
                'branch.salesmanId must be a positive whole number.',
            ],
        ];
    }

    /**
     * Spec section 3.1: a batch is one transaction; a guard's refusal of item 1 rolls
     * back item 0 and names index 1, with its message in messages (spec section 5).
     */
    public function testAGuardRefusingOneItemOfABatchNamesItsIndexAndRollsBackTheBatch(): void
    {
        $id = $this->create($this->input());
        $booked = $this->customerWithTransactions();
        $other = $booked['curr_code'] === 'EUR' ? 'GBP' : 'EUR';

        try {
            ServiceCall::each(
                [
                    ['id' => $id, 'name' => 'Changed In A Batch'],
                    ['id' => (int) $booked['debtor_no'], 'currencyId' => $other],
                ],
                function (array $input): void {
                    $this->service()->update($input);
                }
            );
            $this->fail('the batch was accepted');
        } catch (FaRejected $e) {
            $message = 'The currency of a customer with transactions or sales orders cannot be changed.';
            $this->assertSame(
                ['code' => 'FA_REJECTED', 'messages' => [$message], 'index' => 1],
                $e->getExtensions()
            );
        }
        $this->assertSame('GraphQL Test Customer', $this->customerByRef($this->prefix . 'c')['name']);
    }

    public function testAMissingRowInABatchNamesItsIndexAndRollsBackTheBatch(): void
    {
        $id = $this->create($this->input());

        try {
            ServiceCall::each(
                [['id' => $id, 'name' => 'Changed In A Batch'], ['id' => 999999, 'name' => 'Nobody']],
                function (array $input): void {
                    $this->service()->update($input);
                }
            );
            $this->fail('the batch was accepted');
        } catch (NotFound $e) {
            $this->assertSame(['code' => 'NOT_FOUND', 'index' => 1], $e->getExtensions());
        }
        $this->assertSame('GraphQL Test Customer', $this->customerByRef($this->prefix . 'c')['name']);
    }

    public function testANewCustomerNeedsBranchDefaultsWhileAutoCreateBranchIsOn(): void
    {
        $input = $this->input();
        unset($input['branch']);

        $this->assertBadInput(
            'branch',
            'A new customer needs its default branch (auto_create_branch is on): give branch.',
            function () use ($input): void {
                $this->create($input);
            }
        );
    }

    public function testWithAutoCreateBranchOffOnlyTheCustomerIsWritten(): void
    {
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $input = $this->input();
        unset($input['branch'], $input['contact']);

        $id = $this->create($input);

        $this->assertNotNull($this->customerByRef($this->prefix . 'c'));
        $this->assertSame([], $this->all('SELECT * FROM 0_cust_branch WHERE debtor_no = ?', [$id]));
        $this->assertSame(
            [],
            $this->all("SELECT * FROM 0_crm_contacts WHERE type = 'customer' AND entity_id = ?", [$id])
        );
    }

    public function testWithAutoCreateBranchOffBranchDefaultsAreRefused(): void
    {
        $GLOBALS['SysPrefs']->auto_create_branch = 0;

        $this->assertBadInput(
            'branch',
            'auto_create_branch is off, so no branch or contact is created with the customer: '
            . 'leave out branch and use branchCreate or contactCreate.',
            function (): void {
                $this->create($this->input());
            }
        );
    }

    public function testAnUpdateChangesWhatItNamesAndKeepsTheRest(): void
    {
        $id = $this->create($this->input(['discountPercent' => 10, 'taxNumber' => 'GB 123 4567 89']));

        $this->update(['id' => $id, 'name' => 'Renamed', 'paymentDiscountPercent' => 5, 'inactive' => true]);

        $customer = $this->customerByRef($this->prefix . 'c');
        $this->assertSame('Renamed', $customer['name']);
        $this->assertSame('GB 123 4567 89', $customer['tax_id'], 'taxNumber is the tax_id column');
        $this->assertEqualsWithDelta(0.05, (float) $customer['pymt_discount'], 1e-9);
        $this->assertEqualsWithDelta(0.1, (float) $customer['discount'], 1e-9);
        $this->assertSame('1', (string) $customer['inactive']);
        $this->assertSame('1', (string) $customer['sales_type']);
        $this->assertSame('USD', $customer['curr_code']);
    }

    public function testNullForARequiredFieldIsBadInput(): void
    {
        $id = $this->create($this->input());

        $this->assertBadInput('name', 'name cannot be null.', function () use ($id): void {
            $this->update(['id' => $id, 'name' => null]);
        });
    }

    public function testAnUpdateOfAMissingCustomerIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->update(['id' => 999999, 'name' => 'Nobody']);
    }

    public function testANewCustomersCurrencyCanChange(): void
    {
        $id = $this->create($this->input());

        $this->update(['id' => $id, 'currencyId' => 'EUR']);

        $this->assertSame('EUR', $this->customerByRef($this->prefix . 'c')['curr_code']);
    }

    public function testTheCurrencyOfACustomerWithTransactionsCannotChange(): void
    {
        $customer = $this->customerWithTransactions();
        $other = $customer['curr_code'] === 'EUR' ? 'GBP' : 'EUR';

        try {
            $this->update(['id' => (int) $customer['debtor_no'], 'currencyId' => $other]);
            $this->fail('a booked customer changed currency');
        } catch (FaRejected $e) {
            $this->assertSame(
                'The currency of a customer with transactions or sales orders cannot be changed.',
                $e->getMessage()
            );
        }
        $after = $this->one('SELECT curr_code FROM 0_debtors_master WHERE debtor_no = ?', [$customer['debtor_no']]);
        $this->assertSame($customer['curr_code'], $after['curr_code']);
    }

    public function testDeleteFollowsThePagesGuards(): void
    {
        $id = $this->create($this->input());

        try {
            $this->delete($id);
            $this->fail('a customer with a branch was deleted');
        } catch (FaRejected $e) {
            $this->assertSame(
                'Cannot delete this customer because there are branch records set up against it.',
                $e->getMessage()
            );
        }

        $booked = $this->customerWithTransactions();
        try {
            $this->delete((int) $booked['debtor_no']);
            $this->fail('a customer with transactions was deleted');
        } catch (FaRejected $e) {
            $this->assertSame(
                'This customer cannot be deleted because there are transactions that refer to it.',
                $e->getMessage()
            );
        }

        $branch = $this->one('SELECT branch_code FROM 0_cust_branch WHERE debtor_no = ?', [$id]);
        ServiceCall::run(function () use ($id, $branch): void {
            delete_branch($id, $branch['branch_code']);
        });
        $this->delete($id);

        $this->assertNull($this->customerByRef($this->prefix . 'c'));
        // delete_branch then delete_customer each drop their links; the person, left
        // with none, goes with the customer (delete_entity_contacts).
        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE ref = ?', [$this->prefix . 'c']));
    }

    public function testDeleteOfAMissingCustomerIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }

    public function testARefusalRollsBackTheWholeCallAndTheNextCallStillCommits(): void
    {
        try {
            ServiceCall::run(function (): void {
                $this->service()->create($this->input());
                $this->service()->create($this->input(['ref' => $this->prefix . 'd', 'name' => '']));
            });
            $this->fail('the second create was accepted');
        } catch (BadInput $e) {
            $this->assertSame('name', $e->field());
        }
        $this->assertNull($this->customerByRef($this->prefix . 'c'), 'the first create was not rolled back');

        // FaTransaction's cancel_transaction() reset $transaction_level: this commits.
        $this->create($this->input(['ref' => $this->prefix . 'e']));
        $this->assertNotNull($this->customerByRef($this->prefix . 'e'));
    }
}
