<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Customer\CustomerCreateInput;
use FA\GraphQL\Type\Customer\CustomerType;
use FA\GraphQL\Type\Customer\CustomerUpdateInput;
use FA\GraphQL\Tests\Support\FaTestRows;
use GraphQL\GraphQL;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The structural tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase;
 * the lifecycle is this file's own, because a customer is written through
 * FrontAccounting: debtor_ref is unique (two identical creates are refused), and a
 * customer with its default branch cannot be deleted (customers.php:168-173).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerTypeTest extends TestCase
{
    private ?string $prefix = null;

    protected function typeClass(): string
    {
        return CustomerType::class;
    }

    protected function inputClass(): ?string
    {
        return CustomerCreateInput::class;
    }

    // Missing from the brief's original test: ModelTypeTestCase::testInputMirrorsTheType
    // treats a Type with separate create/update mutations (bin/generate's
    // --mutations create-update) differently from a single upsert Input only when
    // updateInputClass() is non-null — without it, it wrongly expects 'id' on
    // CustomerCreateInput. requiredFields() must then list what CustomerCreateInputBase
    // actually marks @required, matching what the generator's own first draft of this
    // file (before being replaced) already declared.
    protected function updateInputClass(): ?string
    {
        return CustomerUpdateInput::class;
    }

    protected function requiredFields(): array
    {
        return ['name', 'ref', 'salesTypeId', 'creditStatusId', 'paymentTermsId'];
    }

    protected function entityName(): string
    {
        return 'customer';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'name' => 'String',
            'ref' => 'String',
            'address' => 'String',
            // taxId ends in "Id": anorm-graphql's naming convention (Foundation spec
            // §4.4, "a foreign key ends in Id") types it ID regardless of its @var
            // string, same as bin/generate actually wrote here — confirmed against
            // the generated CustomerTypeBase::fields() and the generator's own first
            // draft of this file before it was replaced.
            'taxId' => 'ID',
            'currencyId' => 'ID',
            'salesTypeId' => 'ID',
            'creditStatusId' => 'ID',
            'paymentTermsId' => 'ID',
            'discountPercent' => 'Float',
            'paymentDiscountPercent' => 'Float',
            'creditLimit' => 'Float',
            'notes' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'GraphQL Test Customer',
            'ref' => $this->testRowPrefix() . 'a',
            'salesTypeId' => '1',
            'creditStatusId' => '1',
            'paymentTermsId' => '3',
            'discountPercent' => 10.0,
        ];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'Renamed Customer'];
    }

    private const FIELDS = 'id name ref salesTypeId creditStatusId paymentTermsId discountPercent '
        . 'currencyId creditLimit';

    private const BRANCH = [
        'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1',
    ];

    /** @return array<string, mixed> the first error */
    private function errorOf(string $query, array $variables): array
    {
        $result = GraphQL::executeQuery(
            $this->createSchema($this->container),
            $query,
            null,
            $this->container,
            $variables
        )->toArray();
        $this->assertArrayHasKey('errors', $result, json_encode($result));

        return $result['errors'][0];
    }

    // Named createCustomers, not create(): ModelTypeTestCase already declares a
    // protected create(array $inputs): array (its own customerCreate helper, with a
    // full-field selection() rather than self::FIELDS) — a same-named private method
    // here is a fatal "access level must be protected or weaker" error.
    private function createCustomers(array $inputs): array
    {
        return $this->execute(
            'mutation ($input: [CustomerCreateInput!]!) { customerCreate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => $inputs]
        )['customerCreate'];
    }

    public function testLifecycle(): void
    {
        $prefix = $this->testRowPrefix();
        $before = count($this->listAll());

        $created = $this->createCustomers([
            $this->sampleInput() + ['branch' => self::BRANCH],
            array_merge($this->sampleInput(), ['ref' => $prefix . 'b', 'branch' => self::BRANCH]),
        ]);
        $this->assertCount(2, $created);
        foreach ($this->sampleInput() as $name => $value) {
            $this->assertEquals($value, $created[0][$name], "created $name");
        }
        $this->assertSame('USD', $created[0]['currencyId'], 'the company currency by default');
        $this->assertEquals(1000, $created[0]['creditLimit'], 'the company default credit limit');
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $this->assertCount(1, $this->listWhere(['id' => (int) $id]));

        $updated = $this->execute(
            'mutation ($input: [CustomerUpdateInput!]!) { customerUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id] + $this->sampleUpdate()]]
        )['customerUpdate'];
        $this->assertSame('Renamed Customer', $updated[0]['name']);
        $this->assertSame($prefix . 'a', $updated[0]['ref'], 'an update keeps what it does not name');

        $error = $this->errorOf(
            'mutation ($id: [ID!]!) { customerDelete(id: $id) { id } }',
            ['id' => [$id]]
        );
        $this->assertSame('FA_REJECTED', $error['extensions']['code']);
        $this->assertSame(
            'Cannot delete this customer because there are branch records set up against it.',
            $error['message']
        );

        // A customer created while auto_create_branch is off has no branch and can go.
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $lone = $this->createCustomers([array_merge($this->sampleInput(), ['ref' => $prefix . 'c'])])[0];
        $deleted = $this->execute(
            'mutation ($id: [ID!]!) { customerDelete(id: $id) { ' . self::FIELDS . ' } }',
            ['id' => [$lone['id']]]
        )['customerDelete'];
        $this->assertSame($lone['id'], $deleted[0]['id'], 'delete returns the row as it was');
        $this->assertCount(0, $this->listWhere(['id' => (int) $lone['id']]));
    }

    public function testABatchRefusalNamesTheItemAndWritesNothing(): void
    {
        $error = $this->errorOf(
            'mutation ($input: [CustomerCreateInput!]!) { customerCreate(input: $input) { id } }',
            ['input' => [
                $this->sampleInput() + ['branch' => self::BRANCH],
                array_merge(
                    $this->sampleInput(),
                    ['ref' => $this->testRowPrefix() . 'b', 'name' => '', 'branch' => self::BRANCH]
                ),
            ]]
        );

        $this->assertSame('BAD_INPUT', $error['extensions']['code']);
        $this->assertSame('name', $error['extensions']['field']);
        $this->assertSame(1, $error['extensions']['index']);
        $this->assertCount(0, $this->listWhere(['ref' => $this->testRowPrefix() . 'a']), 'item 0 was rolled back');
    }

    public function testAnIdThatIsNotANumberIsBadInput(): void
    {
        $error = $this->errorOf('mutation { customerDelete(id: ["1 OR 1=1"]) { id } }', []);

        $this->assertSame('BAD_INPUT', $error['extensions']['code']);
    }

    public function testAMissingCustomerIsNotFound(): void
    {
        $error = $this->errorOf('mutation { customerDelete(id: ["999999"]) { id } }', []);

        $this->assertSame('NOT_FOUND', $error['extensions']['code']);
    }
}
