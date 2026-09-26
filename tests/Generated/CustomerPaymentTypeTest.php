<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentCreateInput;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentType;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentUpdateInput;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The structural tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 * The lifecycle is this file's own: a payment is written through FrontAccounting on
 * its own mysqli connection, which the inherited PDO rollback cannot reach, so what
 * it creates is purged in tearDown — only what it created (FaBillingRows). The create
 * Input differs from the Type by what FrontAccounting sets (SERVER_SET) and by the
 * bank side and the allocations; the update Input keeps its generated fields (the
 * service refuses them) and adds the allocations.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerPaymentTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return CustomerPaymentType::class;
    }

    protected function inputClass(): ?string
    {
        return CustomerPaymentCreateInput::class;
    }

    protected function updateInputClass(): ?string
    {
        return CustomerPaymentUpdateInput::class;
    }

    protected function usesCreateMutation(): bool
    {
        return true;
    }

    protected function requiredFields(): array
    {
        return [
            'customerId',
            'date',
            'amount',
        ];
    }

    protected function entityName(): string
    {
        return 'customerPayment';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'transType' => 'Int',
            'version' => 'Int',
            'customerId' => 'ID',
            'branchId' => 'ID',
            'date' => 'Date',
            'reference' => 'String',
            'amount' => 'Float',
            'discount' => 'Float',
            'allocated' => 'Float',
            'rate' => 'Float',
            // Computed. Scalars only: the inherited list test selects these. The
            // computed allocations are read in the lifecycle.
            'unallocated' => 'Float!',
            'bankAccountId' => 'ID',
            'bankAmount' => 'Float',
            'charge' => 'Float!',
            'memo' => 'String',
            'voided' => 'Boolean!',
        ];
    }

    /** Computed fields: on the Type, never on an Input. */
    private const COMPUTED = ['unallocated', 'voided'];

    /** Computed on the Type, and taken by the create Input (the bank side). */
    private const BANK_SIDE = ['bankAccountId', 'bankAmount', 'charge', 'memo'];

    private ?FaBillingRows $billingRows = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billingRows = FaBillingRows::mark(FaTestRows::connect());
    }

    protected function tearDown(): void
    {
        // First: it rolls back the PDO transaction a read-only inherited test opened.
        parent::tearDown();
        if ($this->billingRows !== null) {
            $this->billingRows->purge();
        }
    }

    /**
     * The create Input: the Type's fields less the key, the computed fields and
     * CustomerPaymentCreateInput::SERVER_SET, plus the bank side (bankAccountId
     * required) and the allocations. The update Input: the generated fields, the key
     * required, plus the allocations.
     */
    public function testInputMirrorsTheType(): void
    {
        $create = $this->inputFields(CustomerPaymentCreateInput::class);
        $update = $this->inputFields(CustomerPaymentUpdateInput::class);
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            $bare = rtrim($expected, '!');
            if ($name === 'id') {
                $this->assertArrayNotHasKey('id', $create);
                $this->assertSame('ID!', $update['id']);
                continue;
            }
            if (in_array($name, self::COMPUTED, true)) {
                $this->assertArrayNotHasKey($name, $create);
                $this->assertArrayNotHasKey($name, $update);
                continue;
            }
            if (in_array($name, self::BANK_SIDE, true)) {
                $this->assertArrayNotHasKey($name, $update, 'a posted payment\'s bank side is not edited');
                continue;
            }
            $this->assertSame($bare, $update[$name] ?? null, "CustomerPaymentUpdateInput.$name");
            if (in_array($name, CustomerPaymentCreateInput::SERVER_SET, true)) {
                $this->assertArrayNotHasKey($name, $create, "FrontAccounting sets $name");
                continue;
            }
            $required = in_array($name, $this->requiredFields(), true);
            $this->assertSame(
                $required ? $bare . '!' : $bare,
                $create[$name] ?? null,
                "CustomerPaymentCreateInput.$name"
            );
        }
        $this->assertSame('ID!', $create['bankAccountId']);
        $this->assertSame('Float', $create['bankAmount']);
        $this->assertSame('Float', $create['charge']);
        $this->assertSame('String', $create['memo']);
        $this->assertSame('[AllocationInput!]', $create['allocations']);
        $this->assertSame('[AllocationInput!]', $update['allocations']);
        $this->assertSame(
            [],
            array_diff(array_keys($create), array_keys($this->expectedFieldTypes()), ['allocations']),
            'nothing else on the create Input'
        );
    }

    /**
     * en_US demo: payment 1 paid invoice 1 in full.
     */
    public function testTheDemoPayment(): void
    {
        $this->useDatabase();
        $rows = $this->execute(
            'query ($q: MangoInput) {
                customerPaymentList(query: $q) {
                    id transType customerId amount unallocated voided allocations { toType toId amount }
                }
            }',
            ['q' => ['selector' => json_encode(['id' => 1])]]
        )['customerPaymentList'];
        $this->assertSame([[
            'id' => '1', 'transType' => 12, 'customerId' => '1', 'amount' => 6240.0, 'unallocated' => 0.0,
            'voided' => false, 'allocations' => [['toType' => 10, 'toId' => '1', 'amount' => 6240.0]],
        ]], $rows);
        foreach ($this->execute('{ customerPaymentList { transType } }')['customerPaymentList'] as $row) {
            $this->assertSame(12, $row['transType']);
        }
    }

    /**
     * Through the schema, with no PDO transaction open (see runGraphQL()): created,
     * listed, reallocated (to nothing), voided — returned as it was — then read voided.
     */
    public function testLifecycle(): void
    {
        $created = $this->runGraphQL(
            'mutation ($i: [CustomerPaymentCreateInput!]!) {
                customerPaymentCreate(input: $i) {
                    id amount unallocated voided bankAccountId bankAmount charge memo allocations { id }
                }
            }',
            ['i' => [[
                'customerId' => '1', 'branchId' => '1', 'bankAccountId' => '1', 'date' => date('Y-m-d'),
                'amount' => 7.5, 'memo' => 'lifecycle',
            ]]]
        )['customerPaymentCreate'][0];
        $this->assertSame(7.5, $created['amount']);
        $this->assertSame(7.5, $created['unallocated']);
        $this->assertFalse($created['voided']);
        $this->assertSame('1', $created['bankAccountId']);
        $this->assertSame(7.5, $created['bankAmount']);
        $this->assertSame(0.0, $created['charge']);
        $this->assertSame('lifecycle', $created['memo']);
        $this->assertSame([], $created['allocations']);

        $listed = $this->runGraphQL(
            'query ($q: MangoInput) { customerPaymentList(query: $q) { id } }',
            ['q' => ['selector' => json_encode(['id' => (int) $created['id']])]]
        )['customerPaymentList'];
        $this->assertSame([$created['id']], array_column($listed, 'id'));

        $updated = $this->runGraphQL(
            'mutation ($i: [CustomerPaymentUpdateInput!]!) {
                customerPaymentUpdate(input: $i) { id allocations { id } }
            }',
            ['i' => [['id' => $created['id'], 'allocations' => []]]]
        )['customerPaymentUpdate'][0];
        $this->assertSame([], $updated['allocations']);

        $deleted = $this->runGraphQL(
            'mutation ($id: [ID!]!) { customerPaymentDelete(id: $id) { id amount voided } }',
            ['id' => [$created['id']]]
        )['customerPaymentDelete'];
        $this->assertSame([['id' => $created['id'], 'amount' => 7.5, 'voided' => false]], $deleted, 'as it was');

        $after = $this->runGraphQL(
            'query ($q: MangoInput) { customerPaymentList(query: $q) { voided amount } }',
            ['q' => ['selector' => json_encode(['id' => (int) $created['id']])]]
        )['customerPaymentList'];
        $this->assertSame([['voided' => true, 'amount' => 0.0]], $after);
    }

    /**
     * @return array<string, string>
     */
    private function inputFields(string $class): array
    {
        $fields = [];
        foreach ($this->container->get($class)->getFields() as $name => $field) {
            $fields[$name] = (string) $field->getType();
        }

        return $fields;
    }

    /**
     * ModelTypeTestCase::execute() would open the PDO transaction first; this does not.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function runGraphQL(string $query, array $variables): array
    {
        $result = \GraphQL\GraphQL::executeQuery(
            $this->createSchema($this->container),
            $query,
            null,
            $this->container,
            $variables
        )->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));

        return $result['data'];
    }
}
