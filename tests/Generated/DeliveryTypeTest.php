<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaOrderRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Delivery\DeliveryCreateInput;
use FA\GraphQL\Type\Delivery\DeliveryType;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The structural tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 * No update (generated --without-update). The lifecycle is this file's own: a
 * delivery is written through FrontAccounting's Cart on its own mysqli connection,
 * which the inherited PDO rollback cannot reach, so what it creates is purged in
 * tearDown — only what it created (FaBillingRows, then FaOrderRows). The create
 * Input differs from the Type by what FrontAccounting takes from the order
 * (SERVER_SET) and by the order version, location, comments, closeOrder and lines.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeliveryTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return DeliveryType::class;
    }

    protected function inputClass(): ?string
    {
        return DeliveryCreateInput::class;
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
            'orderId',
            'date',
        ];
    }

    protected function entityName(): string
    {
        return 'delivery';
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
            // Scalars only: the inherited list test selects these. The computed lines
            // are read in the lifecycle.
            'voided' => 'Boolean!',
        ];
    }

    /** A computed field: on the Type, never on an Input. */
    private const COMPUTED = ['voided'];

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
     * DeliveryCreateInput::SERVER_SET, the required ones non-null, plus what a client
     * chooses about the delivery.
     */
    public function testInputMirrorsTheType(): void
    {
        $create = [];
        foreach ($this->container->get(DeliveryCreateInput::class)->getFields() as $name => $field) {
            $create[$name] = (string) $field->getType();
        }
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            if ($name === 'id' || in_array($name, self::COMPUTED, true)) {
                $this->assertArrayNotHasKey($name, $create);
                continue;
            }
            if (in_array($name, DeliveryCreateInput::SERVER_SET, true)) {
                $this->assertArrayNotHasKey($name, $create, "FrontAccounting sets $name");
                continue;
            }
            $bare = rtrim($expected, '!');
            $required = in_array($name, $this->requiredFields(), true);
            $this->assertSame($required ? $bare . '!' : $bare, $create[$name] ?? null, "DeliveryCreateInput.$name");
        }
        $extra = ['orderVersion', 'locationId', 'comments', 'closeOrder', 'lines'];
        $this->assertSame(
            [],
            array_diff(array_keys($create), array_keys($this->expectedFieldTypes()), $extra),
            'nothing else on the create Input'
        );
        $this->assertSame('[DeliveryLineCreateInput!]', $create['lines']);
        $this->assertSame('Int!', $create['orderVersion']);
    }

    /**
     * Through the schema, with no PDO transaction open (see above): an order through
     * SalesOrderService, deliver it with deliveryCreate, list it back with its lines,
     * void it with deliveryDelete (which returns it as it was), then read it voided.
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
                'lines' => [['stockId' => '101', 'quantity' => 2.0]],
            ]);
        });
        $this->orders[] = $orderNo;
        $version = (int) $this->runGraphQL(
            'query ($q: MangoInput) { salesOrderList(query: $q) { version } }',
            ['q' => ['selector' => json_encode(['id' => $orderNo])]]
        )['salesOrderList'][0]['version'];

        $created = $this->runGraphQL(
            'mutation ($input: [DeliveryCreateInput!]!) {
                deliveryCreate(input: $input) { id orderId date voided lines { stockId quantity orderLineId } }
            }',
            ['input' => [['orderId' => $orderNo, 'orderVersion' => $version, 'date' => date('Y-m-d')]]]
        )['deliveryCreate'];
        $this->assertCount(1, $created);
        $this->assertSame((string) $orderNo, $created[0]['orderId']);
        $this->assertSame(date('Y-m-d'), $created[0]['date']);
        $this->assertFalse($created[0]['voided']);
        $this->assertSame('101', $created[0]['lines'][0]['stockId']);
        $this->assertSame(2.0, $created[0]['lines'][0]['quantity']);
        $id = (int) $created[0]['id'];

        $read = $this->runGraphQL(
            'query ($q: MangoInput) { deliveryList(query: $q) { id transType lines { deliveryId } } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['deliveryList'];
        $this->assertCount(1, $read);
        $this->assertSame(13, $read[0]['transType']);
        $this->assertSame([['deliveryId' => (string) $id]], $read[0]['lines']);

        $deleted = $this->runGraphQL(
            'mutation ($id: [ID!]!) { deliveryDelete(id: $id) { id voided lines { quantity } } }',
            ['id' => [$id]]
        )['deliveryDelete'];
        $this->assertSame((string) $id, $deleted[0]['id']);
        $this->assertFalse($deleted[0]['voided'], 'as it was');
        $this->assertSame([['quantity' => 2.0]], $deleted[0]['lines'], 'as it was');

        $after = $this->runGraphQL(
            'query ($q: MangoInput) { deliveryList(query: $q) { id voided } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['deliveryList'];
        $this->assertTrue($after[0]['voided']);
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
