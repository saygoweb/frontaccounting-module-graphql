<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Contact\ContactCreateInput;
use FA\GraphQL\Type\Contact\ContactType;
use FA\GraphQL\Type\Contact\ContactUpdateInput;
use GraphQL\GraphQL;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The lifecycle is this file's own: a contact is written through FrontAccounting and
 * is created with at least one link, which is an object field (`links { … }`).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ContactTypeTest extends TestCase
{
    private ?string $prefix = null;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Contact Type Customer', 'ref' => $this->testRowPrefix() . 'c',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
            ]);
        });
        $GLOBALS['SysPrefs']->auto_create_branch = 1;
    }

    protected function typeClass(): string
    {
        return ContactType::class;
    }

    protected function inputClass(): ?string
    {
        return ContactCreateInput::class;
    }

    // updateInputClass() is non-null — without it, it wrongly expects 'id' on
    // ContactCreateInput. requiredFields() must then list what ContactCreateInputBase
    // actually marks @required (Task 5 report, deviation 3).
    protected function updateInputClass(): ?string
    {
        return ContactUpdateInput::class;
    }

    protected function requiredFields(): array
    {
        return ['ref', 'name'];
    }

    protected function entityName(): string
    {
        return 'contact';
    }

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'ref' => 'String',
            'name' => 'String',
            'name2' => 'String',
            'address' => 'String',
            'phone' => 'String',
            'phone2' => 'String',
            'fax' => 'String',
            'email' => 'String',
            'lang' => 'String',
            'notes' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'ref' => $this->testRowPrefix() . 'p',
            'name' => 'Pat Accounts',
            'email' => 'pat@example.com',
            'links' => [['entity' => 'CUSTOMER', 'id' => (string) $this->customerId, 'category' => 'INVOICE']],
        ];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'Pat Receivables'];
    }

    private const FIELDS = 'id ref name email links { entity id category }';

    public function testLifecycle(): void
    {
        $before = count($this->listAll());

        $created = $this->execute(
            'mutation ($input: [ContactCreateInput!]!) { contactCreate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [$this->sampleInput(), $this->sampleInput()]]
        )['contactCreate'];
        $this->assertCount(2, $created);
        $this->assertSame('Pat Accounts', $created[0]['name']);
        $this->assertSame($this->sampleInput()['links'], $created[0]['links']);
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $updated = $this->execute(
            'mutation ($input: [ContactUpdateInput!]!) { contactUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id] + $this->sampleUpdate()]]
        )['contactUpdate'];
        $this->assertSame('Pat Receivables', $updated[0]['name']);
        $this->assertSame($this->sampleInput()['links'], $updated[0]['links'], 'an update without links keeps them');

        $relinked = $this->execute(
            'mutation ($input: [ContactUpdateInput!]!) { contactUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id, 'links' => [
                ['entity' => 'CUSTOMER', 'id' => (string) $this->customerId, 'category' => 'ORDER'],
            ]]]]
        )['contactUpdate'];
        $this->assertSame('ORDER', $relinked[0]['links'][0]['category']);

        $deleted = $this->execute(
            'mutation ($id: [ID!]!) { contactDelete(id: $id) { id name } }',
            ['id' => [$id]]
        )['contactDelete'];
        $this->assertSame($id, $deleted[0]['id']);
        $this->assertCount(0, $this->listWhere(['id' => (int) $id]));
        $this->assertCount($before + 1, $this->listAll());
    }

    public function testACustomerListsItsContacts(): void
    {
        $this->execute(
            'mutation ($input: [ContactCreateInput!]!) { contactCreate(input: $input) { id } }',
            ['input' => [$this->sampleInput()]]
        );

        $customers = $this->execute(
            'query ($q: MangoInput) { customerList(query: $q) { id contacts { name links { category } } } }',
            ['q' => ['selector' => json_encode(['id' => $this->customerId])]]
        )['customerList'];

        $this->assertSame(
            [['name' => 'Pat Accounts', 'links' => [['category' => 'INVOICE']]]],
            $customers[0]['contacts']
        );
    }

    /** A person who is only a supplier's contact, linked as FrontAccounting's supplier pages link one. */
    private function supplierContact(): int
    {
        $pdo = FaTestRows::connect();
        $pdo->prepare('INSERT INTO 0_crm_persons (ref, name, notes) VALUES (?, ?, ?)')
            ->execute([$this->testRowPrefix() . 's', 'Sam Supplier', '']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO 0_crm_contacts (person_id, type, action, entity_id) VALUES (?, 'supplier', 'general', '1')"
        )->execute([$id]);

        return $id;
    }

    /**
     * The errors of a request that is expected to fail (execute() asserts there are none).
     *
     * @return array<int, array<string, mixed>>
     */
    private function errorsOf(string $query, array $variables): array
    {
        $schema = $this->createSchema($this->container);
        $result = GraphQL::executeQuery($schema, $query, null, $this->container, $variables)->toArray();
        $this->assertArrayHasKey('errors', $result, json_encode($result));

        return $result['errors'];
    }

    /**
     * Checkpoint B review C-1: Contact is a customer's or branch's contact. A person
     * FrontAccounting knows only as a supplier's contact (demo persons 1 and 2, say)
     * is not listed, and SA_CUSTOMER cannot reach it.
     */
    public function testOnlyCustomerAndBranchContactsAreListed(): void
    {
        $supplierOnly = $this->supplierContact();
        $demoSupplierOnly = array_map('intval', FaTestRows::connect()->query(
            'SELECT id FROM 0_crm_persons p WHERE NOT EXISTS (SELECT 1 FROM 0_crm_contacts c'
            . " WHERE c.person_id = p.id AND c.type IN ('customer', 'cust_branch'))"
        )->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertContains($supplierOnly, $demoSupplierOnly);

        $listed = $this->execute('{ contactList { id links { entity } } }')['contactList'];

        $ids = array_map('intval', array_column($listed, 'id'));
        $this->assertSame([], array_values(array_intersect($ids, $demoSupplierOnly)));
        $this->assertCount(0, $this->listWhere(['id' => $supplierOnly]));
        foreach ($listed as $contact) {
            $this->assertNotSame([], $contact['links'], "contact {$contact['id']} is listed with no link");
        }
    }

    public function testASupplierContactCannotBeUpdatedOrDeleted(): void
    {
        $id = (string) $this->supplierContact();

        $update = $this->errorsOf(
            'mutation ($input: [ContactUpdateInput!]!) { contactUpdate(input: $input) { id } }',
            ['input' => [['id' => $id, 'name' => 'Taken Over', 'links' => [
                ['entity' => 'CUSTOMER', 'id' => (string) $this->customerId, 'category' => 'GENERAL'],
            ]]]]
        );
        $this->assertSame('NOT_FOUND', $update[0]['extensions']['code']);
        $delete = $this->errorsOf('mutation ($id: [ID!]!) { contactDelete(id: $id) { id } }', ['id' => [$id]]);
        $this->assertSame('NOT_FOUND', $delete[0]['extensions']['code']);

        $pdo = FaTestRows::connect();
        $name = $pdo->prepare('SELECT name FROM 0_crm_persons WHERE id = ?');
        $name->execute([$id]);
        $this->assertSame('Sam Supplier', $name->fetchColumn());
        $links = $pdo->prepare('SELECT type FROM 0_crm_contacts WHERE person_id = ?');
        $links->execute([$id]);
        $this->assertSame(['supplier'], $links->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Spec section 3.1: the error names the index of the item refused. */
    public function testAMissingContactInADeleteNamesItsIndex(): void
    {
        $created = $this->execute(
            'mutation ($input: [ContactCreateInput!]!) { contactCreate(input: $input) { id } }',
            ['input' => [$this->sampleInput()]]
        )['contactCreate'];

        $errors = $this->errorsOf(
            'mutation ($id: [ID!]!) { contactDelete(id: $id) { id } }',
            ['id' => [$created[0]['id'], '999999']]
        );

        $this->assertSame('NOT_FOUND', $errors[0]['extensions']['code']);
        $this->assertSame(1, $errors[0]['extensions']['index'] ?? null, json_encode($errors[0]));
        $this->assertSame("Contact id '999999' not found", $errors[0]['message']);
        $this->assertCount(1, $this->listWhere(['id' => (int) $created[0]['id']]));
    }
}
