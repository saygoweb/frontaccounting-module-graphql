<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineCreateInput;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The structural tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 * The lifecycle is this file's own: an order is written through FrontAccounting's
 * Cart on its own mysqli connection, which the inherited PDO rollback cannot reach,
 * so what it creates is purged in tearDown. The Inputs differ from the Type by the
 * fields FrontAccounting sets itself (SERVER_SET) and the nested lines.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return SalesOrderType::class;
    }

    protected function inputClass(): ?string
    {
        return SalesOrderCreateInput::class;
    }

    protected function updateInputClass(): ?string
    {
        return SalesOrderUpdateInput::class;
    }

    protected function requiredFields(): array
    {
        return [
            'customerId',
            'branchId',
            'orderDate',
        ];
    }

    protected function entityName(): string
    {
        return 'salesOrder';
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
            'template' => 'Boolean',
            'customerId' => 'ID',
            'branchId' => 'ID',
            'reference' => 'String',
            'customerRef' => 'String',
            'comments' => 'String',
            'orderDate' => 'Date',
            'salesTypeId' => 'ID',
            'shipperId' => 'ID',
            'deliveryAddress' => 'String',
            'phone' => 'String',
            'email' => 'String',
            'deliverTo' => 'String',
            'freight' => 'Float',
            'locationId' => 'ID',
            'deliveryDate' => 'Date',
            'paymentTermsId' => 'ID',
            'total' => 'Float',
            'prepaymentAmount' => 'Float',
            'allocated' => 'Float',
        ];
    }

    /** @var int[] */
    private array $made = [];

    protected function tearDown(): void
    {
        // First: it rolls back the PDO transaction a read-only inherited test opened.
        parent::tearDown();
        $pdo = $this->container->get(\PDO::class);
        foreach ($this->made as $orderNo) {
            self::purgeSchedule($this->container, $orderNo);
            foreach (
                [
                'DELETE FROM 0_sales_order_details WHERE trans_type = 30 AND order_no = ?',
                'DELETE FROM 0_sales_orders WHERE trans_type = 30 AND order_no = ?',
                'DELETE FROM 0_audit_trail WHERE type = 30 AND trans_no = ?',
                'DELETE FROM 0_refs WHERE type = 30 AND id = ?',
                ] as $sql
            ) {
                $pdo->prepare($sql)->execute([$orderNo]);
            }
        }
        $this->made = [];
    }

    protected function sampleInput(): array
    {
        // Demo customer 1 / branch 1 on credit terms (its own are cash-only).
        return [
            'customerId' => 1,
            'branchId' => 1,
            'orderDate' => date('Y-m-d'),
            'paymentTermsId' => 3,
            'deliverTo' => 'Donald Easter',
            'deliveryAddress' => '1 Test Street',
            'lines' => [['stockId' => '101', 'quantity' => 1.0]],
        ];
    }

    public function testLifecycle(): void
    {
        $this->lifecycleThroughFrontAccounting();
    }

    /**
     * The create Input: the Type's fields less the key and SalesOrderCreateInput::SERVER_SET,
     * the required ones non-null, plus the lines. The update Input: a patch — the key
     * and the version read non-null, the rest optional, less SalesOrderUpdateInput::SERVER_SET,
     * plus the optional lines. Both take the optional recurring schedule (Task 9).
     */
    public function testInputMirrorsTheType(): void
    {
        $create = $this->inputFields(SalesOrderCreateInput::class);
        $update = $this->inputFields(SalesOrderUpdateInput::class);
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            $bare = rtrim($expected, '!');
            if ($name === 'id') {
                $this->assertArrayNotHasKey('id', $create, 'a create makes the key');
                $this->assertSame('ID!', $update['id'] ?? null);
                continue;
            }
            if (in_array($name, SalesOrderUpdateInput::SERVER_SET, true)) {
                $this->assertArrayNotHasKey($name, $update, "FrontAccounting sets $name");
            } else {
                $expectedUpdate = $name === 'version' ? 'Int!' : $bare;
                $this->assertSame($expectedUpdate, $update[$name] ?? null, "SalesOrderUpdateInput.$name");
            }
            if (in_array($name, SalesOrderCreateInput::SERVER_SET, true)) {
                $this->assertArrayNotHasKey($name, $create, "FrontAccounting sets $name");
                continue;
            }
            $required = in_array($name, $this->requiredFields(), true);
            $this->assertSame($required ? $bare . '!' : $bare, $create[$name] ?? null, "SalesOrderCreateInput.$name");
        }
        $this->assertSame('[SalesOrderLineCreateInput!]!', $create['lines'] ?? null);
        $this->assertSame('RecurrenceInput', $create['recurring'] ?? null);
        $this->assertSame(
            [],
            array_diff(array_keys($create), array_keys($this->expectedFieldTypes()), ['lines', 'recurring']),
            'nothing else on the create Input'
        );
        $this->assertSame('[SalesOrderLineUpdateInput!]', $update['lines'] ?? null);
        $this->assertSame('RecurrenceInput', $update['recurring'] ?? null);
        $this->assertSame(
            [],
            array_diff(array_keys($update), array_keys($this->expectedFieldTypes()), ['lines', 'recurring']),
            'nothing else on the update Input'
        );
    }

    public function testARecurringOrderThroughTheSchema(): void
    {
        if (!$this->container->get(\FA\GraphQL\Fa\Service\RecurringSchedule::class)->isAvailable()) {
            $this->markTestSkipped('sgw_sales is not active in this stack.');
        }
        $input = array_merge($this->sampleInput(), [
            'recurring' => ['start' => date('Y-m-d'), 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
        ]);
        $created = $this->runGraphQL(
            'mutation ($input: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $input) {'
            . ' id recurring { start repeats every day monthDay next auto } } }',
            ['input' => [$input]]
        )['salesOrderCreate'];
        $this->made[] = (int) $created[0]['id'];

        $this->assertSame(
            [
                'start' => date('Y-m-d'),
                'repeats' => 'MONTH',
                'every' => 1,
                'day' => 1,
                'monthDay' => null,
                'next' => null,
                'auto' => true,
            ],
            $created[0]['recurring']
        );
        self::purgeSchedule($this->container, (int) $created[0]['id']);
    }

    /**
     * @param mixed $container
     */
    public static function purgeSchedule($container, int $orderNo): void
    {
        $pdo = $container->get(\PDO::class);
        // As SalesOrderTestCase::purgeOrder(): a stack without sgw_sales has no table.
        if ($pdo->query("SHOW TABLES LIKE '0_sales_recurring'")->fetch() !== false) {
            $pdo->prepare('DELETE FROM 0_sales_recurring WHERE trans_no = ?')->execute([$orderNo]);
        }
    }

    /**
     * @return array<string, string> field name => GraphQL type as printed
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
     * Through the schema, with no PDO transaction open (see above): create, then read
     * back by id with the computed lines; update with the version read, refuse the
     * same version again as stale; delete, which returns the order as it was.
     */
    private function lifecycleThroughFrontAccounting(): void
    {
        $created = $this->runGraphQL(
            'mutation ($input: [SalesOrderCreateInput!]!) {
                salesOrderCreate(input: $input) { id version customerId orderDate lines { stockId } }
            }',
            ['input' => [$this->sampleInput(), $this->sampleInput()]]
        )['salesOrderCreate'];
        foreach ($created as $row) {
            $this->made[] = (int) $row['id'];
        }
        $this->assertCount(2, $created);
        $this->assertNotSame($created[0]['id'], $created[1]['id']);
        $this->assertSame('1', $created[0]['customerId']);
        $this->assertSame(date('Y-m-d'), $created[0]['orderDate']);
        $this->assertSame([['stockId' => '101']], $created[0]['lines']);
        $id = (int) $created[0]['id'];

        $read = $this->runGraphQL(
            'query ($q: MangoInput) {
                salesOrderList(query: $q) {
                    id lines { stockId quantity qtyDelivered discountPercent } recurring { repeats }
                }
            }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['salesOrderList'];
        $this->assertCount(1, $read);
        $this->assertSame(
            [['stockId' => '101', 'quantity' => 1.0, 'qtyDelivered' => 0.0, 'discountPercent' => 0.0]],
            $read[0]['lines']
        );
        $this->assertNull($read[0]['recurring'], 'a plain order has no schedule');

        $version = (int) $created[0]['version'];
        $updated = $this->runGraphQL(
            'mutation ($input: [SalesOrderUpdateInput!]!) { salesOrderUpdate(input: $input) { id version comments } }',
            ['input' => [['id' => $id, 'version' => $version, 'comments' => 'updated']]]
        )['salesOrderUpdate'];
        $this->assertSame('updated', $updated[0]['comments']);
        $this->assertSame($version + 1, (int) $updated[0]['version']);

        $stale = \GraphQL\GraphQL::executeQuery(
            $this->createSchema($this->container),
            'mutation ($input: [SalesOrderUpdateInput!]!) { salesOrderUpdate(input: $input) { id } }',
            null,
            $this->container,
            ['input' => [['id' => $id, 'version' => $version, 'comments' => 'stale']]]
        )->toArray();
        $this->assertSame('FA_REJECTED', $stale['errors'][0]['extensions']['code']);

        $deleted = $this->runGraphQL(
            'mutation ($id: [ID!]!) { salesOrderDelete(id: $id) { id comments } }',
            ['id' => [$id]]
        )['salesOrderDelete'];
        $this->assertSame('updated', $deleted[0]['comments'], 'as it was');
        $this->assertSame([], $this->runGraphQL(
            'query ($q: MangoInput) { salesOrderList(query: $q) { id } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['salesOrderList']);
    }

    /**
     * ModelTypeTestCase::execute() would open the PDO transaction first; this does not.
     * Not run(): PHPUnit's TestCase::run() is public.
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
