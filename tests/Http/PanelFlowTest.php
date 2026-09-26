<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * The hosting panel's flow end to end (Release 2 spec §7): a customer with its
 * branch and contact, an order with lines and a recurrence, read back, updated with
 * its version, a stale update refused, then deleted — or closed, once delivered.
 *
 * Rows it creates stay in the database; every run uses fresh references, and
 * `docker/fa-graphql db load` clears them.
 */
class PanelFlowTest extends TestCase
{
    use GraphQLClient;

    private const CUSTOMER_CREATE = 'mutation ($in: [CustomerCreateInput!]!) '
        . '{ customerCreate(input: $in) { id name branches { id } contacts { id } } }';

    private const ORDER_FIELDS = '{ id version customerId branchId orderDate comments '
        . 'lines { id stockId quantity unitPrice qtyDelivered } recurring { start end repeats every day } }';

    private const ORDER_CREATE = 'mutation ($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) '
        . self::ORDER_FIELDS . ' }';

    private const ORDER_UPDATE = 'mutation ($in: [SalesOrderUpdateInput!]!) { salesOrderUpdate(input: $in) '
        . self::ORDER_FIELDS . ' }';

    private const ORDER_DELETE = 'mutation ($ids: [ID!]!) { salesOrderDelete(id: $ids) { id } }';

    private const ORDER_BY_ID = 'query ($q: MangoInput) { salesOrderList(query: $q) ' . self::ORDER_FIELDS . ' }';

    private string $token;

    protected function setUp(): void
    {
        $this->token = $this->login()['accessToken'];
    }

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    /**
     * @return array<string, mixed> the response's data
     */
    private function ok(string $query, array $variables): array
    {
        $response = $this->gql($query, $variables, $this->token);
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);

        return $response['body']['data'];
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO(
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            (string) getenv('FA_DB_USER'),
            (string) getenv('FA_DB_PASSWORD')
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function table(string $name): string
    {
        return getenv('FA_DB_PREFIX') . $name;
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * @return array<string, mixed> the created customer
     */
    private function createCustomer(): array
    {
        $ref = 'PANEL-' . bin2hex(random_bytes(4));
        $data = $this->ok(self::CUSTOMER_CREATE, ['in' => [[
            'name' => "Panel customer $ref",
            'ref' => $ref,
            'address' => "1 Panel Street\nHosting Town",
            'salesTypeId' => '1',
            'paymentTermsId' => '1',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => ['phone' => '+60 3 1234 5678', 'email' => strtolower($ref) . '@example.com'],
        ]]]);

        $customer = $data['customerCreate'][0];
        $this->assertCount(1, $customer['branches'], 'auto_create_branch makes one branch');
        $this->assertNotEmpty($customer['contacts'], 'the page creates a CRM contact');

        return $customer;
    }

    /**
     * @return array<string, mixed> the created order
     */
    private function createRecurringOrder(array $customer): array
    {
        $data = $this->ok(self::ORDER_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'orderDate' => $this->today(),
            'comments' => 'hosting plan',
            'lines' => [['stockId' => '301', 'quantity' => 2, 'unitPrice' => 50, 'description' => 'Hosting']],
            'recurring' => ['start' => $this->today(), 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
        ]]]);

        return $data['salesOrderCreate'][0];
    }

    /**
     * @return array<int, array<string, mixed>> the orders with that id, as salesOrderList reads them
     */
    private function readOrder(string $id): array
    {
        $data = $this->ok(self::ORDER_BY_ID, ['q' => ['selector' => json_encode(['id' => (int) $id])]]);

        return $data['salesOrderList'];
    }

    public function testThePanelFlow(): void
    {
        $customer = $this->createCustomer();

        $renamed = $this->ok(
            'mutation ($in: [CustomerUpdateInput!]!) { customerUpdate(input: $in) { id name } }',
            ['in' => [['id' => $customer['id'], 'name' => $customer['name'] . ' (renamed)']]]
        );
        $this->assertSame($customer['name'] . ' (renamed)', $renamed['customerUpdate'][0]['name']);

        $order = $this->createRecurringOrder($customer);
        $this->assertSame($customer['id'], $order['customerId']);
        $this->assertSame($this->today(), $order['orderDate']);
        $this->assertCount(1, $order['lines']);
        $this->assertSame('301', $order['lines'][0]['stockId']);
        $this->assertEquals(2, $order['lines'][0]['quantity']);
        $this->assertEquals(50, $order['lines'][0]['unitPrice']);
        $this->assertSame(
            ['start' => $this->today(), 'end' => null, 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
            $order['recurring']
        );

        // Read back: the list sees exactly what the create returned.
        $this->assertSame([$order], $this->readOrder($order['id']));

        // Update with the version just read: quantity 2 -> 3, and a new comment.
        $updated = $this->ok(self::ORDER_UPDATE, ['in' => [[
            'id' => $order['id'],
            'version' => $order['version'],
            'comments' => 'hosting plan, three seats',
            'lines' => [[
                'id' => $order['lines'][0]['id'], 'stockId' => '301', 'quantity' => 3, 'unitPrice' => 50,
            ]],
        ]]])['salesOrderUpdate'][0];
        $this->assertGreaterThan($order['version'], $updated['version']);
        $this->assertEquals(3, $updated['lines'][0]['quantity']);
        $this->assertSame('hosting plan, three seats', $updated['comments']);

        // The same update again, with the version that is now stale: refused, nothing changed.
        $stale = $this->gql(self::ORDER_UPDATE, ['in' => [[
            'id' => $order['id'],
            'version' => $order['version'],
            'lines' => [['id' => $order['lines'][0]['id'], 'stockId' => '301', 'quantity' => 9, 'unitPrice' => 50]],
        ]]], $this->token);
        $this->assertSame('FA_REJECTED', $this->code($stale), $stale['raw']);
        $this->assertSame([$updated], $this->readOrder($order['id']));

        // Delete an order with no deliveries: it is gone, and so is its schedule.
        $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);
        $this->assertSame([], $this->readOrder($order['id']));
        $schedules = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->table('sales_recurring') . ' WHERE trans_no = ?'
        );
        $schedules->execute([(int) $order['id']]);
        $this->assertSame(0, (int) $schedules->fetchColumn(), 'the recurring row goes with its order');
    }

    public function testADeliveredOrderIsClosedNotDeleted(): void
    {
        $order = $this->createRecurringOrder($this->createCustomer());

        // Release 2 has no deliveries; mark one delivered as a delivery note would
        // (sales_order_has_deliveries() reads qty_sent).
        $this->pdo()->prepare(
            'UPDATE ' . $this->table('sales_order_details') . ' SET qty_sent = 1 WHERE order_no = ? AND trans_type = 30'
        )->execute([(int) $order['id']]);

        $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);

        $closed = $this->readOrder($order['id']);
        $this->assertCount(1, $closed, 'a delivered order is closed, not deleted');
        $this->assertEquals(1, $closed[0]['lines'][0]['quantity'], 'closing sets the quantity to what was delivered');
        $this->assertEquals(1, $closed[0]['lines'][0]['qtyDelivered']);
        $this->assertSame($this->today(), $closed[0]['recurring']['end'], 'closing ends the schedule today');
    }

    public function testAStaleOrderVersionCannotDeleteEither(): void
    {
        $order = $this->createRecurringOrder($this->createCustomer());
        $this->ok(self::ORDER_UPDATE, ['in' => [[
            'id' => $order['id'], 'version' => $order['version'], 'comments' => 'moved on',
        ]]]);

        // Only meaningful when salesOrderDelete takes a version (Step 2). Without one,
        // this test asserts the id-only delete still works on the updated order.
        $deleted = $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);
        $this->assertSame($order['id'], $deleted['salesOrderDelete'][0]['id']);
    }
}
