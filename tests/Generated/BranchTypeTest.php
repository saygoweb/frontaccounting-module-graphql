<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Branch\BranchCreateInput;
use FA\GraphQL\Type\Branch\BranchType;
use FA\GraphQL\Type\Branch\BranchUpdateInput;
use GraphQL\GraphQL;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The lifecycle is this file's own: a branch is written through FrontAccounting and
 * needs a customer to belong to.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BranchTypeTest extends TestCase
{
    private ?string $prefix = null;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Branch Type Customer', 'ref' => $this->testRowPrefix() . 'c',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
            ]);
        });
        $GLOBALS['SysPrefs']->auto_create_branch = 1;
    }

    protected function typeClass(): string
    {
        return BranchType::class;
    }

    protected function inputClass(): ?string
    {
        return BranchCreateInput::class;
    }

    // updateInputClass() is non-null — without it, it wrongly expects 'id' on
    // BranchCreateInput. requiredFields() must then list what BranchCreateInputBase
    // actually marks @required (Task 5 report, deviation 3).
    protected function updateInputClass(): ?string
    {
        return BranchUpdateInput::class;
    }

    protected function requiredFields(): array
    {
        return ['customerId', 'name', 'ref', 'salesmanId', 'salesAreaId', 'taxGroupId', 'locationId', 'shipperId'];
    }

    protected function entityName(): string
    {
        return 'branch';
    }

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'customerId' => 'ID',
            'name' => 'String',
            'ref' => 'String',
            'address' => 'String',
            'postAddress' => 'String',
            'salesmanId' => 'ID',
            'salesAreaId' => 'ID',
            'taxGroupId' => 'ID',
            'locationId' => 'ID',
            'shipperId' => 'ID',
            'notes' => 'String',
            'bankAccount' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'customerId' => (string) $this->customerId,
            'name' => 'Head Office',
            'ref' => $this->testRowPrefix() . 'b',
            'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1',
        ];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'Renamed Office'];
    }

    private const FIELDS = 'id customerId name ref salesmanId salesAreaId taxGroupId locationId shipperId';

    public function testLifecycle(): void
    {
        $before = count($this->listAll());

        $created = $this->execute(
            'mutation ($input: [BranchCreateInput!]!) { branchCreate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [$this->sampleInput(), $this->sampleInput()]]
        )['branchCreate'];
        $this->assertCount(2, $created);
        foreach ($this->sampleInput() as $name => $value) {
            $this->assertEquals($value, $created[0][$name], "created $name");
        }
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $updated = $this->execute(
            'mutation ($input: [BranchUpdateInput!]!) { branchUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id] + $this->sampleUpdate()]]
        )['branchUpdate'];
        $this->assertSame('Renamed Office', $updated[0]['name']);
        $this->assertSame('DEF', $updated[0]['locationId']);

        $deleted = $this->execute(
            'mutation ($id: [ID!]!) { branchDelete(id: $id) { ' . self::FIELDS . ' } }',
            ['id' => [$id]]
        )['branchDelete'];
        $this->assertSame($id, $deleted[0]['id']);
        $this->assertCount(0, $this->listWhere(['id' => (int) $id]));
        $this->assertCount($before + 1, $this->listAll());
    }

    public function testACustomerListsItsBranches(): void
    {
        $this->execute(
            'mutation ($input: [BranchCreateInput!]!) { branchCreate(input: $input) { id } }',
            ['input' => [$this->sampleInput()]]
        );

        $customers = $this->execute(
            'query ($q: MangoInput) { customerList(query: $q) { id branches { name ref } } }',
            ['q' => ['selector' => json_encode(['id' => $this->customerId])]]
        )['customerList'];

        $this->assertSame(
            [['name' => 'Head Office', 'ref' => $this->testRowPrefix() . 'b']],
            $customers[0]['branches']
        );
    }

    public function testAnUnknownReferenceIsBadInputNamingTheField(): void
    {
        $result = GraphQL::executeQuery(
            $this->createSchema($this->container),
            'mutation ($input: [BranchCreateInput!]!) { branchCreate(input: $input) { id } }',
            null,
            $this->container,
            ['input' => [array_merge($this->sampleInput(), ['shipperId' => '999'])]]
        )->toArray();

        $this->assertSame('BAD_INPUT', $result['errors'][0]['extensions']['code']);
        $this->assertSame('shipperId', $result['errors'][0]['extensions']['field']);
        $this->assertSame(0, $result['errors'][0]['extensions']['index']);
    }
}
