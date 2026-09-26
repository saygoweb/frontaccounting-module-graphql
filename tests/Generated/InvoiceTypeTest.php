<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaOrderRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Invoice\InvoiceCreateInput;
use FA\GraphQL\Type\Invoice\InvoiceType;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The structural tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 * No update (generated --without-update). The lifecycle is this file's own: an
 * invoice is written through FrontAccounting's Cart on its own mysqli connection,
 * which the inherited PDO rollback cannot reach, so what it creates is purged in
 * tearDown — only what it created (FaBillingRows, then FaOrderRows). The create
 * Input differs from the Type by what FrontAccounting sets or takes from the
 * deliveries (SERVER_SET) and by the sources, per-line quantities and comments.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InvoiceTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return InvoiceType::class;
    }

    protected function inputClass(): ?string
    {
        return InvoiceCreateInput::class;
    }

    protected function updateInputClass(): ?string
    {
        return null;
    }

    protected function usesCreateMutation(): bool
    {
        return true;
    }

    protected function requiredFields(): array
    {
        return [
            'date',
        ];
    }

    protected function entityName(): string
    {
        return 'invoice';
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
            'dueDate' => 'Date',
            'reference' => 'String',
            'salesTypeId' => 'ID',
            'orderId' => 'ID',
            'amount' => 'Float',
            'tax' => 'Float',
            'freight' => 'Float',
            'freightTax' => 'Float',
            'discount' => 'Float',
            'allocated' => 'Float',
            'prepaymentAmount' => 'Float',
            'rate' => 'Float',
            'shipperId' => 'ID',
            'paymentTermsId' => 'ID',
            'taxIncluded' => 'Boolean',
            // Computed. Scalars and scalar lists only: the inherited list test selects
            // these. The computed lines are read in the lifecycle.
            'total' => 'Float!',
            'outstanding' => 'Float!',
            'deliveryIds' => '[ID!]!',
            'voided' => 'Boolean!',
        ];
    }

    /** Computed fields: on the Type, never on an Input. */
    private const COMPUTED = ['total', 'outstanding', 'voided'];

    /** @var int[] */
    private array $orders = [];

    private ?FaOrderRows $orderRows = null;

    private ?FaBillingRows $billingRows = null;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = FaTestRows::connect();
        $this->orderRows = FaOrderRows::mark($pdo);
        $this->billingRows = FaBillingRows::mark($pdo);
    }

    protected function tearDown(): void
    {
        // First: it rolls back the PDO transaction a read-only inherited test opened.
        parent::tearDown();
        if ($this->billingRows !== null) {
            $this->billingRows->purge();
        }
        foreach ($this->orders as $orderNo) {
            if ($this->orderRows !== null) {
                $this->orderRows->purge($orderNo);
            }
        }
        $this->orders = [];
    }

    public function testLifecycle(): void
    {
        $this->lifecycleThroughFrontAccounting();
    }

    /**
     * The create Input: the Type's fields less the key, the computed fields and
     * InvoiceCreateInput::SERVER_SET, date required, plus the sources, the per-line
     * quantities and comments.
     */
    public function testInputMirrorsTheType(): void
    {
        $create = [];
        foreach ($this->container->get(InvoiceCreateInput::class)->getFields() as $name => $field) {
            $create[$name] = (string) $field->getType();
        }
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            if ($name === 'id' || in_array($name, self::COMPUTED, true)) {
                $this->assertArrayNotHasKey($name, $create);
                continue;
            }
            if (in_array($name, InvoiceCreateInput::SERVER_SET, true)) {
                $this->assertArrayNotHasKey($name, $create, "FrontAccounting sets $name");
                continue;
            }
            if ($name === 'deliveryIds') {
                continue;
            }
            $bare = rtrim($expected, '!');
            $required = in_array($name, $this->requiredFields(), true);
            $this->assertSame($required ? $bare . '!' : $bare, $create[$name] ?? null, "InvoiceCreateInput.$name");
        }
        $extra = ['deliveryIds', 'orderVersion', 'lines', 'comments'];
        $this->assertSame(
            [],
            array_diff(array_keys($create), array_keys($this->expectedFieldTypes()), $extra),
            'nothing else on the create Input'
        );
        $this->assertSame('Date!', $create['date']);
        $this->assertSame('[ID!]', $create['deliveryIds']);
        $this->assertSame('Int', $create['orderVersion']);
        $this->assertSame('[InvoiceLineQuantityInput!]', $create['lines']);
        $this->assertSame('String', $create['comments']);
    }

    /**
     * en_US demo: invoice 1 invoiced delivery 1 (order 1), fully allocated.
     */
    public function testTheDemoInvoice(): void
    {
        $this->useDatabase();
        $this->assertSame(
            [[
                'id' => '1', 'transType' => 10, 'orderId' => '1', 'total' => 6240.0, 'outstanding' => 0.0,
                'deliveryIds' => ['1'], 'voided' => false,
                'lines' => [['id' => '3', 'deliveryLineId' => '1'], ['id' => '4', 'deliveryLineId' => '2']],
            ]],
            $this->execute(
                'query ($q: MangoInput) {
                    invoiceList(query: $q) {
                        id transType orderId total outstanding deliveryIds voided lines { id deliveryLineId }
                    }
                }',
                ['q' => ['selector' => json_encode(['id' => 1])]]
            )['invoiceList']
        );
        foreach ($this->execute('{ invoiceList { transType } }')['invoiceList'] as $row) {
            $this->assertSame(10, $row['transType']);
        }
    }

    /**
     * Through the schema, with no PDO transaction open (see above): an order through
     * SalesOrderService, invoiced in one step with invoiceCreate, listed back, voided
     * with invoiceDelete (which returns it as it was), then read voided.
     */
    private function lifecycleThroughFrontAccounting(): void
    {
        FaIncludes::orders();
        $orderNo = ServiceCall::run(function (): int {
            return $this->container->get(SalesOrderService::class)->create([
                'customerId' => 1,
                'branchId' => 1,
                'orderDate' => new \DateTimeImmutable(date('Y-m-d')),
                'paymentTermsId' => 3,
                'deliverTo' => 'Donald Easter',
                'deliveryAddress' => '1 Test Street',
                'lines' => [['stockId' => '101', 'quantity' => 1.0]],
            ]);
        });
        $this->orders[] = $orderNo;
        $version = (int) $this->runGraphQL(
            'query ($q: MangoInput) { salesOrderList(query: $q) { version } }',
            ['q' => ['selector' => json_encode(['id' => $orderNo])]]
        )['salesOrderList'][0]['version'];

        $created = $this->runGraphQL(
            'mutation ($input: [InvoiceCreateInput!]!) {
                invoiceCreate(input: $input) {
                    id orderId date total outstanding voided deliveryIds lines { stockId quantity }
                }
            }',
            ['input' => [['orderId' => $orderNo, 'orderVersion' => $version, 'date' => date('Y-m-d')]]]
        )['invoiceCreate'];
        $this->assertCount(1, $created);
        $this->assertSame((string) $orderNo, $created[0]['orderId']);
        $this->assertSame(date('Y-m-d'), $created[0]['date']);
        $this->assertSame([['stockId' => '101', 'quantity' => 1.0]], $created[0]['lines']);
        $this->assertGreaterThan(0, $created[0]['total']);
        $this->assertSame($created[0]['total'], $created[0]['outstanding']);
        $this->assertFalse($created[0]['voided']);
        $this->assertCount(1, $created[0]['deliveryIds']);
        $id = (int) $created[0]['id'];

        $read = $this->runGraphQL(
            'query ($q: MangoInput) { invoiceList(query: $q) { id transType } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['invoiceList'];
        $this->assertSame([['id' => (string) $id, 'transType' => 10]], $read);

        $voided = $this->runGraphQL(
            'mutation ($id: [ID!]!) { invoiceDelete(id: $id) { id voided total lines { quantity } } }',
            ['id' => [$id]]
        )['invoiceDelete'];
        $this->assertSame((string) $id, $voided[0]['id']);
        $this->assertFalse($voided[0]['voided'], 'returned as it was before the void');
        $this->assertSame($created[0]['total'], $voided[0]['total'], 'as it was');
        $this->assertSame([['quantity' => 1.0]], $voided[0]['lines'], 'as it was');

        $after = $this->runGraphQL(
            'query ($q: MangoInput) { invoiceList(query: $q) { voided outstanding } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['invoiceList'];
        $this->assertTrue($after[0]['voided']);
        $this->assertSame(0.0, $after[0]['outstanding']);
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
