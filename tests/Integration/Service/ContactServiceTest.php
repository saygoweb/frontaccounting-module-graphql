<?php

namespace FA\GraphQL\Tests\Integration\Service;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\ContactService;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ContactServiceTest extends FaTestCase
{
    private string $prefix;

    private int $customerId;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        (new FaSession(Config::fromArray(['secret' => str_repeat('k', 32)])))
            ->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Contact Test Customer', 'ref' => $this->prefix . 'c',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
                'branch' => [
                    'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                    'locationId' => 'DEF', 'shipperId' => '1',
                ],
            ]);
        });
        $this->branchId = (int) $this->one(
            'SELECT branch_code FROM 0_cust_branch WHERE debtor_no = ?',
            [$this->customerId]
        )['branch_code'];
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'ref' => $this->prefix . 'p',
            'name' => 'Pat Accounts',
            'email' => 'pat@example.com',
            'links' => [
                ['entity' => 'customer', 'id' => (string) $this->customerId, 'category' => 'invoice'],
                ['entity' => 'cust_branch', 'id' => (string) $this->branchId, 'category' => 'delivery'],
            ],
        ], $overrides);
    }

    private function create(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return (new ContactService())->create($input);
        });
    }

    private function update(array $input): void
    {
        ServiceCall::run(function () use ($input): void {
            (new ContactService())->update($input);
        });
    }

    private function delete(int $id): void
    {
        ServiceCall::run(function () use ($id): void {
            (new ContactService())->delete($id);
        });
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, string>> */
    private function links(int $personId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT type, action, entity_id FROM 0_crm_contacts WHERE person_id = ? ORDER BY type, action'
        );
        $statement->execute([$personId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** A link this API does not model: to a supplier, as FrontAccounting's supplier pages make. */
    private function linkToASupplier(int $personId): void
    {
        $this->pdo()->prepare(
            "INSERT INTO 0_crm_contacts (person_id, type, action, entity_id) VALUES (?, 'supplier', 'general', '1')"
        )->execute([$personId]);
    }

    /** A person who is only a supplier's contact. */
    private function supplierContact(): int
    {
        $this->pdo()->prepare('INSERT INTO 0_crm_persons (ref, name, notes) VALUES (?, ?, ?)')
            ->execute([$this->prefix . 's', 'Sam Supplier', '']);
        $id = (int) $this->pdo()->lastInsertId();
        $this->linkToASupplier($id);

        return $id;
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

    public function testAContactIsAPersonWithItsLinks(): void
    {
        $id = $this->create($this->input());

        $person = $this->one('SELECT * FROM 0_crm_persons WHERE id = ?', [$id]);
        $this->assertSame('Pat Accounts', $person['name']);
        $this->assertSame($this->prefix . 'p', $person['ref']);
        $this->assertSame('pat@example.com', $person['email']);
        // ORDER BY type sorts under this database's collation (utf8mb4_general_ci),
        // where 'customer' sorts before 'cust_branch' (Task 5 report, deviation 5).
        $this->assertSame([
            ['type' => 'customer', 'action' => 'invoice', 'entity_id' => (string) $this->customerId],
            ['type' => 'cust_branch', 'action' => 'delivery', 'entity_id' => (string) $this->branchId],
        ], $this->links($id));
    }

    /**
     * contacts_view.inc:126-142, then what the editor offers from lists.
     *
     * @dataProvider refusals
     */
    public function testRefused(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create($this->input($overrides));
        });
        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE ref = ?', [$this->prefix . 'p']));
    }

    public function refusals(): array
    {
        return [
            'empty name' => [['name' => ''], 'name', 'The contact name cannot be empty.'],
            'empty reference' => [['ref' => ''], 'ref', 'Contact reference cannot be empty.'],
            'no links' => [['links' => []], 'links', 'You have to select at least one category.'],
            'unknown customer' => [
                ['links' => [['entity' => 'customer', 'id' => '999999', 'category' => 'general']]],
                'links.0.id', "There is no customer '999999'.",
            ],
            'unknown category' => [
                ['links' => [['entity' => 'customer', 'id' => '1', 'category' => 'department']]],
                'links.0.category', 'There is no contact category customer/department in use.',
            ],
        ];
    }

    public function testAnUpdateWithoutLinksKeepsThem(): void
    {
        $id = $this->create($this->input());
        $before = $this->links($id);

        $this->update(['id' => $id, 'name' => 'Pat Receivables', 'phone' => '555-0142', 'inactive' => true]);

        $person = $this->one('SELECT * FROM 0_crm_persons WHERE id = ?', [$id]);
        $this->assertSame('Pat Receivables', $person['name']);
        $this->assertSame('555-0142', $person['phone']);
        $this->assertSame('pat@example.com', $person['email']);
        $this->assertSame('1', (string) $person['inactive']);
        $this->assertSame($before, $this->links($id), 'update_crm_person must not have touched the links');
    }

    public function testAnUpdateWithLinksReplacesThem(): void
    {
        $id = $this->create($this->input());

        $this->update(['id' => $id, 'links' => [
            ['entity' => 'customer', 'id' => (string) $this->customerId, 'category' => 'order'],
        ]]);

        $this->assertSame(
            [['type' => 'customer', 'action' => 'order', 'entity_id' => (string) $this->customerId]],
            $this->links($id)
        );
    }

    public function testAnUpdateCannotLeaveAContactWithNoLinks(): void
    {
        $id = $this->create($this->input());

        $this->assertBadInput('links', 'You have to select at least one category.', function () use ($id): void {
            $this->update(['id' => $id, 'links' => []]);
        });
        $this->assertCount(2, $this->links($id));
    }

    public function testAnUpdateOfAMissingContactIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->update(['id' => 999999, 'name' => 'Nobody']);
    }

    public function testDeleteRemovesThePersonAndItsLinks(): void
    {
        $id = $this->create($this->input());

        $this->delete($id);

        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE id = ?', [$id]));
        $this->assertSame([], $this->links($id));
    }

    /**
     * contacts_view.inc db_update(): the editor replaces only the links of its own
     * class. A contact who is also a supplier's keeps that link.
     */
    public function testAnUpdateWithLinksReplacesOnlyCustomerAndBranchLinks(): void
    {
        $id = $this->create($this->input());
        $this->linkToASupplier($id);

        $this->update(['id' => $id, 'links' => [
            ['entity' => 'customer', 'id' => (string) $this->customerId, 'category' => 'order'],
        ]]);

        $this->assertSame([
            ['type' => 'customer', 'action' => 'order', 'entity_id' => (string) $this->customerId],
            ['type' => 'supplier', 'action' => 'general', 'entity_id' => '1'],
        ], $this->links($id));
    }

    /**
     * contacts_view.inc db_delete(): the editor removes its own links, and the person
     * only when no link is left.
     */
    public function testDeleteKeepsAPersonWhoIsStillASuppliersContact(): void
    {
        $id = $this->create($this->input());
        $this->linkToASupplier($id);

        $this->delete($id);

        $this->assertNotNull($this->one('SELECT id FROM 0_crm_persons WHERE id = ?', [$id]));
        $this->assertSame([['type' => 'supplier', 'action' => 'general', 'entity_id' => '1']], $this->links($id));
    }

    public function testAPersonWithNoCustomerOrBranchLinkIsNotFound(): void
    {
        $id = $this->supplierContact();

        $calls = [
            function () use ($id): void {
                $this->update(['id' => $id, 'name' => 'Taken Over', 'links' => [
                    ['entity' => 'customer', 'id' => (string) $this->customerId, 'category' => 'general'],
                ]]);
            },
            function () use ($id): void {
                $this->delete($id);
            },
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('a supplier contact was written');
            } catch (NotFound $e) {
                $this->assertSame("Contact id '$id' not found", $e->getMessage());
            }
        }
        $this->assertSame('Sam Supplier', $this->one('SELECT name FROM 0_crm_persons WHERE id = ?', [$id])['name']);
        $this->assertSame([['type' => 'supplier', 'action' => 'general', 'entity_id' => '1']], $this->links($id));
    }

    public function testDeleteOfAMissingContactIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }
}
