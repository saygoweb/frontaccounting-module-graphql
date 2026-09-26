<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Contact\ContactCreateInput;
use FA\GraphQL\Type\Contact\ContactType;
use FA\GraphQL\Type\Contact\ContactUpdateInput;

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
}
