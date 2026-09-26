<?php

namespace FA\GraphQL\Tests\Integration\Service;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\BranchService;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BranchServiceTest extends FaTestCase
{
    private string $prefix;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        (new FaSession(Config::fromArray(['secret' => str_repeat('k', 32)])))
            ->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
        // A customer with no branch yet: auto_create_branch off for its creation.
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Branch Test Customer', 'ref' => $this->prefix . 'c', 'address' => '9 Customer Road',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
            ]);
        });
        $GLOBALS['SysPrefs']->auto_create_branch = 1;
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'customerId' => (string) $this->customerId,
            'name' => 'Head Office',
            'ref' => $this->prefix . 'b',
            'address' => '1 Branch Street',
            'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1',
        ], $overrides);
    }

    private function create(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return (new BranchService())->create($input);
        });
    }

    private function update(array $input): void
    {
        ServiceCall::run(function () use ($input): void {
            (new BranchService())->update($input);
        });
    }

    private function delete(int $id): void
    {
        ServiceCall::run(function () use ($id): void {
            (new BranchService())->delete($id);
        });
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function branch(int $id): ?array
    {
        return $this->one('SELECT * FROM 0_cust_branch WHERE branch_code = ?', [$id]);
    }

    /** The CRM person linked to a branch as its general contact. */
    private function branchPerson(int $id): ?array
    {
        return $this->one(
            "SELECT p.* FROM 0_crm_persons p JOIN 0_crm_contacts c ON c.person_id = p.id "
            . "WHERE c.type = 'cust_branch' AND c.action = 'general' AND c.entity_id = ?",
            [$id]
        );
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

    public function testABranchIsWrittenAsThePageWritesIt(): void
    {
        $id = $this->create($this->input(['notes' => 'deliver to the back door']));

        $branch = $this->branch($id);
        $this->assertSame((string) $this->customerId, (string) $branch['debtor_no']);
        $this->assertSame('Head Office', $branch['br_name']);
        $this->assertSame($this->prefix . 'b', $branch['branch_ref']);
        $this->assertSame('1 Branch Street', $branch['br_address']);
        $this->assertSame('1 Branch Street', $branch['br_post_address'], 'the postal address defaults to the address');
        $this->assertSame('DEF', $branch['default_location']);
        $this->assertSame('deliver to the back door', $branch['notes']);
        $this->assertSame('', $branch['sales_account']);
        $this->assertSame((string) get_company_pref('default_sales_discount_act'), $branch['sales_discount_account']);
        $this->assertSame((string) get_company_pref('debtors_act'), $branch['receivables_account']);
        $this->assertSame((string) get_company_pref('default_prompt_payment_act'), $branch['payment_discount_account']);
        $this->assertSame('0', (string) $branch['group_no']);
    }

    public function testACustomersFirstBranchGetsAMainBranchContactAndLaterOnesTheBranchName(): void
    {
        $first = $this->create($this->input());
        $second = $this->create($this->input(['name' => 'Warehouse', 'ref' => $this->prefix . 'w']));

        $this->assertSame('Main Branch', $this->branchPerson($first)['name']);
        $this->assertSame('Warehouse', $this->branchPerson($second)['name']);
        $this->assertSame('1 Branch Street', $this->branchPerson($first)['address']);
    }

    public function testAGivenContactIsTheBranchesPerson(): void
    {
        $id = $this->create($this->input([
            'contact' => ['name' => $this->prefix . 'p', 'phone' => '555-0199', 'email' => 'branch@example.com'],
        ]));

        $person = $this->branchPerson($id);
        $this->assertSame($this->prefix . 'p', $person['name']);
        $this->assertSame($this->prefix . 'p', $person['ref'], 'the page uses the contact name as its reference');
        $this->assertSame('555-0199', $person['phone']);
        $this->assertSame('branch@example.com', $person['email']);
    }

    /**
     * @dataProvider refusals
     */
    public function testRefused(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create($this->input($overrides));
        });
        $this->assertNull($this->one('SELECT * FROM 0_cust_branch WHERE debtor_no = ?', [$this->customerId]));
    }

    public function refusals(): array
    {
        return [
            // customer_branches.php:64-76
            'empty name' => [['name' => ''], 'name', 'The Branch name cannot be empty.'],
            'empty short name' => [['ref' => ''], 'ref', 'The Branch short name cannot be empty.'],
            'unknown customer' => [['customerId' => '999999'], 'customerId', "There is no customer '999999'."],
            'unknown salesperson' => [['salesmanId' => '999'], 'salesmanId', "There is no salesperson '999'."],
            'unknown area' => [['salesAreaId' => '999'], 'salesAreaId', "There is no sales area '999'."],
            'unknown tax group' => [['taxGroupId' => '999'], 'taxGroupId', "There is no tax group '999'."],
            'unknown location' => [['locationId' => 'NOPE'], 'locationId', "There is no location 'NOPE'."],
            'unknown shipper' => [['shipperId' => '999'], 'shipperId', "There is no shipper '999'."],
            'null address' => [['address' => null], 'address', 'address cannot be null.'],
            // Integer references are whole numbers: MySQL would cast '1 x' to 1.
            'customer not a number' => [
                ['customerId' => '1 x'], 'customerId', 'customerId must be a positive whole number.',
            ],
            'salesperson not a number' => [
                ['salesmanId' => '1 x'], 'salesmanId', 'salesmanId must be a positive whole number.',
            ],
            'area not a number' => [
                ['salesAreaId' => '01'], 'salesAreaId', 'salesAreaId must be a positive whole number.',
            ],
            'tax group not a number' => [
                ['taxGroupId' => '1e0'], 'taxGroupId', 'taxGroupId must be a positive whole number.',
            ],
            'shipper not a number' => [
                ['shipperId' => '1 '], 'shipperId', 'shipperId must be a positive whole number.',
            ],
        ];
    }

    public function testAnUpdateKeepsWhatItDoesNotNameAndTheGlAccounts(): void
    {
        $id = $this->create($this->input());
        $before = $this->branch($id);

        $this->update(['id' => $id, 'name' => 'Renamed Office', 'taxGroupId' => '2', 'inactive' => true]);

        $after = $this->branch($id);
        $this->assertSame('Renamed Office', $after['br_name']);
        $this->assertSame('2', (string) $after['tax_group_id']);
        $this->assertSame('1', (string) $after['inactive']);
        $this->assertSame($before['br_address'], $after['br_address']);
        $this->assertSame($before['receivables_account'], $after['receivables_account']);
        $this->assertSame($before['group_no'], $after['group_no']);
    }

    public function testABranchCannotMoveToAnotherCustomer(): void
    {
        $id = $this->create($this->input());

        $this->assertBadInput('customerId', 'A branch cannot move to another customer.', function () use ($id): void {
            $this->update(['id' => $id, 'customerId' => '1']);
        });
    }

    public function testAnUpdateOfAMissingBranchIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->update(['id' => 999999, 'name' => 'Nowhere']);
    }

    public function testDeleteRemovesTheBranchAndItsContact(): void
    {
        $id = $this->create($this->input());
        $person = $this->branchPerson($id);

        $this->delete($id);

        $this->assertNull($this->branch($id));
        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE id = ?', [$person['id']]));
    }

    public function testABranchWithTransactionsCannotBeDeleted(): void
    {
        $booked = $this->one(
            'SELECT b.branch_code FROM 0_cust_branch b WHERE EXISTS (SELECT 1 FROM 0_debtor_trans t '
            . 'WHERE t.branch_code = b.branch_code AND t.debtor_no = b.debtor_no) ORDER BY b.branch_code LIMIT 1',
            []
        );
        if ($booked === null) {
            $this->markTestSkipped('The dataset has no branch with transactions.');
        }

        try {
            $this->delete((int) $booked['branch_code']);
            $this->fail('a branch with transactions was deleted');
        } catch (FaRejected $e) {
            $this->assertSame(
                'Cannot delete this branch because customer transactions have been created to this branch.',
                $e->getMessage()
            );
        }
        $this->assertNotNull($this->branch((int) $booked['branch_code']));
    }

    public function testDeleteOfAMissingBranchIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }
}
