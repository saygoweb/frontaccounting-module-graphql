<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Tests\Support\FaOrderRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use PHPUnit\Framework\TestCase;

/**
 * The hosting panel's flow end to end (Release 2 spec §7): a customer with its
 * branch and contact, an order with lines and a recurrence, read back, updated with
 * its version, a stale update refused, then deleted — or closed, once delivered.
 *
 * tearDown removes everything a test wrote, pass or fail: its customers (by the
 * test's reference prefix) with their branches, persons and links; its orders with
 * their lines and schedules; and the audit_trail, refs and refresh-token rows it
 * caused — and nothing an earlier run left for a reused order number (FaOrderRows).
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

    /** Every customer this test creates has a reference starting with it. */
    private string $prefix;

    /** @var int[] every order this test created, deleted or not */
    private array $orders = [];

    /** The refresh-token table's highest id at the start. */
    private int $tokenMark = 0;

    private ?FaOrderRows $orderRows = null;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        if (getenv('SGW_SALES_ACTIVE') === 'false' || !$this->hasRecurringTable()) {
            $this->markTestSkipped('The panel flow needs sgw_sales active (recurring orders).');
        }
        $this->prefix = 'PANEL-' . bin2hex(random_bytes(4));
        $this->orderRows = FaOrderRows::mark($this->pdo(), $this->table(''));
        $this->tokenMark = (int) $this->pdo()->query(
            'SELECT COALESCE(MAX(id), 0) FROM ' . $this->table('graphql_refresh_token')
        )->fetchColumn();
        $this->token = $this->login()['accessToken'];
    }

    protected function tearDown(): void
    {
        if ($this->orderRows === null) {
            return;
        }
        $pdo = $this->pdo();
        $customers = $this->column(
            'SELECT debtor_no FROM ' . $this->table('debtors_master') . ' WHERE debtor_ref LIKE ?',
            [$this->prefix . '%']
        );
        $orders = $this->orders;
        if ($customers !== []) {
            $orders = array_merge($orders, $this->column(
                'SELECT order_no FROM ' . $this->table('sales_orders') . ' WHERE trans_type = 30 AND debtor_no IN ('
                    . implode(', ', array_fill(0, count($customers), '?')) . ')',
                $customers
            ));
        }
        foreach (array_unique($orders) as $orderNo) {
            $this->orderRows->purge($orderNo);
        }
        FaTestRows::sweep($pdo, $this->prefix, $this->table(''));
        $pdo->prepare('DELETE FROM ' . $this->table('graphql_refresh_token') . ' WHERE id > ?')
            ->execute([$this->tokenMark]);
        $this->orders = [];
        $this->orderRows = null;
    }

    private function hasRecurringTable(): bool
    {
        $found = $this->pdo()->prepare('SHOW TABLES LIKE ?');
        $found->execute([$this->table('sales_recurring')]);

        return $found->fetch() !== false;
    }

    /**
     * @param array<int, mixed> $params
     * @return int[]
     */
    private function column(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
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
        if ($this->pdo === null) {
            $this->pdo = new \PDO(
                'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
                (string) getenv('FA_DB_USER'),
                (string) getenv('FA_DB_PASSWORD')
            );
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    private function table(string $name): string
    {
        return getenv('FA_DB_PREFIX') . $name;
    }

    /**
     * Today as FrontAccounting's Today() sees it: PHP's default timezone, not UTC.
     */
    private function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * @return array<string, mixed> the created customer
     */
    private function createCustomer(): array
    {
        $ref = $this->prefix . '-' . bin2hex(random_bytes(2));
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
        $this->orders[] = (int) $data['salesOrderCreate'][0]['id'];

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

        $response = $this->gql(self::ORDER_DELETE, ['ids' => [$order['id']]], $this->token);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $this->assertStringContainsString(
            'closed',
            implode("\n", $response['body']['extensions']['warnings'] ?? []),
            'the close is reported in extensions.warnings'
        );

        $closed = $this->readOrder($order['id']);
        $this->assertCount(1, $closed, 'a delivered order is closed, not deleted');
        $this->assertEquals(1, $closed[0]['lines'][0]['quantity'], 'closing sets the quantity to what was delivered');
        $this->assertEquals(1, $closed[0]['lines'][0]['qtyDelivered']);
        $this->assertSame($this->today(), $closed[0]['recurring']['end'], 'closing ends the schedule today');
    }

    /**
     * salesOrderDelete takes ids only (the Task 10 ruling; spec section 4.4, revised):
     * an order updated since it was read is still deleted by its id.
     */
    public function testAnIdOnlyDeleteSucceedsWhateverTheVersion(): void
    {
        $order = $this->createRecurringOrder($this->createCustomer());
        $this->ok(self::ORDER_UPDATE, ['in' => [[
            'id' => $order['id'], 'version' => $order['version'], 'comments' => 'moved on',
        ]]]);

        // The version read before the update is now stale; the delete does not ask for it.
        $deleted = $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);
        $this->assertSame($order['id'], $deleted['salesOrderDelete'][0]['id']);
    }
}
