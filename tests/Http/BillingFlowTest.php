<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaOrderRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use PHPUnit\Framework\TestCase;

/**
 * The panel's billing flow end to end (Release 3 spec §8): invoice an order in one
 * step, email it, take a payment allocated to it, deallocate, void the payment,
 * void the invoice; the partial-delivery path; who may do it; and the document lock
 * (spec §2.1): concurrent payments get distinct numbers, and every document write
 * waits on the lock.
 *
 * tearDown removes everything a test wrote, pass or fail — customers by the test's
 * reference prefix (FaTestRows), orders (FaOrderRows), every billing row written
 * since setUp (FaBillingRows: debtor_trans, details, gl_trans, stock_moves,
 * bank_trans, cust_allocations, trans_tax_details, comments, refs, audit_trail,
 * voided), the refresh tokens its logins created, and the mail it caused — and
 * nothing an earlier run left.
 */
class BillingFlowTest extends TestCase
{
    use GraphQLClient;

    /**
     * A sellable service item: no stock check on delivery. From Step 1's query:
     * SELECT stock_id, mb_flag FROM 0_stock_master WHERE inactive = 0 AND no_sale = 0
     * AND mb_flag <> 'F' ORDER BY mb_flag = 'D' DESC, stock_id LIMIT 5 — first row
     * back was stock_id 202, mb_flag 'D' (service item).
     */
    private const ITEM = '202';

    /**
     * A home-currency bank account. From Step 1's query: bank account 1 ("Current
     * account") is bank_curr_code USD, and 0_sys_prefs curr_default is USD too.
     */
    private const BANK_ACCOUNT = '1';

    private const CUSTOMER_CREATE = 'mutation ($in: [CustomerCreateInput!]!) '
        . '{ customerCreate(input: $in) { id branches { id } } }';

    private const ORDER_CREATE = 'mutation ($in: [SalesOrderCreateInput!]!) '
        . '{ salesOrderCreate(input: $in) { id version } }';

    private const INVOICE_FIELDS = '{ id reference total outstanding voided deliveryIds '
        . 'lines { id stockId quantity unitPrice } }';

    private const INVOICE_CREATE = 'mutation ($in: [InvoiceCreateInput!]!) { invoiceCreate(input: $in) '
        . self::INVOICE_FIELDS . ' }';

    private const INVOICE_BY_ID = 'query ($q: MangoInput) { invoiceList(query: $q) ' . self::INVOICE_FIELDS . ' }';

    private const INVOICE_DELETE = 'mutation ($ids: [ID!]!) { invoiceDelete(id: $ids) { id } }';

    private const INVOICE_EMAIL = 'mutation ($ids: [ID!]!) { invoiceEmail(id: $ids) { id sent recipient messages } }';

    private const DELIVERY_CREATE = 'mutation ($in: [DeliveryCreateInput!]!) '
        . '{ deliveryCreate(input: $in) { id lines { id quantity qtyInvoiced } } }';

    private const DELIVERY_DELETE = 'mutation ($ids: [ID!]!) { deliveryDelete(id: $ids) { id } }';

    private const PAYMENT_FIELDS = '{ id amount unallocated allocations { toId amount } }';

    private const PAYMENT_CREATE = 'mutation ($in: [CustomerPaymentCreateInput!]!) '
        . '{ customerPaymentCreate(input: $in) ' . self::PAYMENT_FIELDS . ' }';

    private const PAYMENT_UPDATE = 'mutation ($in: [CustomerPaymentUpdateInput!]!) '
        . '{ customerPaymentUpdate(input: $in) ' . self::PAYMENT_FIELDS . ' }';

    private const PAYMENT_DELETE = 'mutation ($ids: [ID!]!) { customerPaymentDelete(id: $ids) { id } }';

    /**
     * CustomerType.balance is a CustomerBalance object (balance, due, overdue1,
     * overdue2, currency), not a scalar — Step 1's introspection of CustomerType and
     * CustomerBalance. Zero on every bucket once a customer is settled.
     */
    private const CUSTOMER_BALANCE = 'query ($q: MangoInput) { customerList(query: $q) { id balance { balance } } }';

    private string $token;
    private string $prefix;
    /** @var int[] */
    private array $orders = [];
    private int $tokenMark = 0;
    /** @var string[] */
    private array $mailBefore = [];
    private ?FaOrderRows $orderRows = null;
    private ?FaBillingRows $billingRows = null;
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $this->prefix = 'BILL-' . bin2hex(random_bytes(4));
        $this->orderRows = FaOrderRows::mark($this->pdo(), $this->table(''));
        $this->billingRows = FaBillingRows::mark($this->pdo(), $this->table(''));
        $this->tokenMark = (int) $this->pdo()->query(
            'SELECT COALESCE(MAX(id), 0) FROM ' . $this->table('graphql_refresh_token')
        )->fetchColumn();
        $this->mailBefore = MailCatcher::available() ? MailCatcher::files() : [];
        $this->token = $this->login()['accessToken'];
    }

    protected function tearDown(): void
    {
        if ($this->orderRows === null) {
            return;
        }
        // Billing rows first: they reference the orders and customers.
        $this->billingRows->purge();
        foreach (array_unique(array_merge($this->orders, $this->ordersOfTestCustomers())) as $orderNo) {
            $this->orderRows->purge($orderNo);
        }
        FaTestRows::sweep($this->pdo(), $this->prefix, $this->table(''));
        $this->pdo()->prepare('DELETE FROM ' . $this->table('graphql_refresh_token') . ' WHERE id > ?')
            ->execute([$this->tokenMark]);
        if (MailCatcher::available()) {
            MailCatcher::delete(MailCatcher::newSince($this->mailBefore));
        }
        $this->orders = [];
        $this->orderRows = null;
        $this->billingRows = null;
    }

    public function testTheBillingFlow(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, 2, 40.0);

        // Invoice the order in one step: FrontAccounting delivers what remains, then invoices it.
        $invoice = $this->ok(self::INVOICE_CREATE, ['in' => [[
            'orderId' => $order['id'],
            'orderVersion' => $order['version'],
            'date' => $this->today(),
        ]]])['invoiceCreate'][0];
        $this->assertGreaterThan(0, (float) $invoice['total']);
        $this->assertEqualsWithDelta((float) $invoice['total'], (float) $invoice['outstanding'], 0.001);
        $this->assertCount(1, $invoice['deliveryIds'], 'one-step invoicing makes one delivery');
        $this->assertCount(1, $invoice['lines']);
        $this->assertFalse($invoice['voided']);
        $total = (float) $invoice['total'];
        $this->assertEqualsWithDelta($total, $this->balance($customer['id']), 0.001);

        // Email it: the customer's contact has an email address.
        if (MailCatcher::available()) {
            $sent = $this->ok(self::INVOICE_EMAIL, ['ids' => [$invoice['id']]])['invoiceEmail'][0];
            $this->assertTrue($sent['sent'], implode("\n", $sent['messages']));
            $this->assertStringEndsWith('@example.com', (string) $sent['recipient']);
            $this->assertCount(1, MailCatcher::newSince($this->mailBefore));
        }

        // Pay it in full, allocated to the invoice.
        $payment = $this->ok(self::PAYMENT_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'bankAccountId' => self::BANK_ACCOUNT,
            'date' => $this->today(),
            'amount' => $total,
            'allocations' => [['invoiceId' => $invoice['id'], 'amount' => $total]],
        ]]])['customerPaymentCreate'][0];
        $this->assertEqualsWithDelta(0.0, (float) $payment['unallocated'], 0.001);
        $this->assertCount(1, $payment['allocations']);
        $this->assertEqualsWithDelta(0.0, (float) $this->readInvoice($invoice['id'])['outstanding'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($customer['id']), 0.001);

        // An allocated invoice cannot be voided (spec §4, ruling 3).
        $refused = $this->gql(self::INVOICE_DELETE, ['ids' => [$invoice['id']]], $this->token);
        $this->assertSame('FA_REJECTED', $this->code($refused), $refused['raw']);

        // Deallocate: the payment is unallocated, the invoice outstanding again.
        $payment = $this->ok(self::PAYMENT_UPDATE, ['in' => [['id' => $payment['id'], 'allocations' => []]]])
            ['customerPaymentUpdate'][0];
        $this->assertEqualsWithDelta($total, (float) $payment['unallocated'], 0.001);
        $this->assertSame([], $payment['allocations']);
        $this->assertEqualsWithDelta($total, (float) $this->readInvoice($invoice['id'])['outstanding'], 0.001);

        // A posted payment cannot be changed beyond its allocations.
        $refused = $this->gql(self::PAYMENT_UPDATE, ['in' => [['id' => $payment['id'], 'amount' => 1]]], $this->token);
        $this->assertSame('BAD_INPUT', $this->code($refused), $refused['raw']);

        // Void the payment, then the invoice.
        $this->ok(self::PAYMENT_DELETE, ['ids' => [$payment['id']]]);
        $this->assertEqualsWithDelta($total, $this->balance($customer['id']), 0.001);
        $this->ok(self::INVOICE_DELETE, ['ids' => [$invoice['id']]]);
        $voided = $this->readInvoice($invoice['id']);
        $this->assertTrue($voided['voided']);
        $this->assertEqualsWithDelta(0.0, $this->balance($customer['id']), 0.001);
    }

    public function testPartialDeliveriesAreInvoicedAndGuarded(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, 3, 10.0);

        // A stale order version is refused.
        $stale = $this->gql(self::DELIVERY_CREATE, ['in' => [[
            'orderId' => $order['id'], 'orderVersion' => $order['version'] + 1, 'date' => $this->today(),
        ]]], $this->token);
        $this->assertSame('FA_REJECTED', $this->code($stale), $stale['raw']);

        $lineId = $this->orderLineIds((int) $order['id'])[0];
        $delivery = $this->ok(self::DELIVERY_CREATE, ['in' => [[
            'orderId' => $order['id'],
            'orderVersion' => $order['version'],
            'date' => $this->today(),
            'lines' => [['orderLineId' => $lineId, 'quantity' => 1]],
        ]]])['deliveryCreate'][0];
        $this->assertEquals(1, $delivery['lines'][0]['quantity']);

        $invoice = $this->ok(self::INVOICE_CREATE, ['in' => [[
            'deliveryIds' => [$delivery['id']], 'date' => $this->today(),
        ]]])['invoiceCreate'][0];
        $this->assertSame([(string) $delivery['id']], array_map('strval', $invoice['deliveryIds']));
        $this->assertEqualsWithDelta(10.0, (float) $invoice['total'], 0.5, 'one of three at 10 (plus any tax)');

        // A delivery that has been invoiced cannot be voided.
        $refused = $this->gql(self::DELIVERY_DELETE, ['ids' => [$delivery['id']]], $this->token);
        $this->assertSame('FA_REJECTED', $this->code($refused), $refused['raw']);

        // Void the invoice; then the delivery can be voided.
        $this->ok(self::INVOICE_DELETE, ['ids' => [$invoice['id']]]);
        $this->ok(self::DELIVERY_DELETE, ['ids' => [$delivery['id']]]);
    }

    public function testARoleWithoutBillingAreasIsForbidden(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, 1, 5.0);
        $orders = $this->login('apiorders')['accessToken'];   // SA_SALESORDER only (Release 2 seed)

        $response = $this->gql(self::INVOICE_CREATE, ['in' => [[
            'orderId' => $order['id'], 'orderVersion' => $order['version'], 'date' => $this->today(),
        ]]], $orders);
        $this->assertSame('FORBIDDEN', $this->code($response), $response['raw']);

        $response = $this->gql(self::INVOICE_EMAIL, ['ids' => [1]], $orders);
        $this->assertSame('FORBIDDEN', $this->code($response), $response['raw']);
    }

    /**
     * Release 3 spec §2.1 and §8: two payments posted at once, by two requests on two
     * connections, get distinct numbers and both commit. The test holds the document
     * lock while both requests start, so both are provably in flight together —
     * waiting on it — before it lets them race for it.
     */
    public function testConcurrentPaymentsGetDistinctNumbers(): void
    {
        $customers = [$this->createCustomer(), $this->createCustomer()];
        $payload = function (array $customer): array {
            return [self::PAYMENT_CREATE, ['in' => [[
                'customerId' => $customer['id'],
                'branchId' => $customer['branches'][0]['id'],
                'bankAccountId' => self::BANK_ACCOUNT,
                'date' => $this->today(),
                'amount' => 10.0,
            ]]]];
        };

        $run = $this->whileTheDocumentLockIsHeld([$payload($customers[0]), $payload($customers[1])], true);

        $this->assertTrue($run['waited'], 'both requests must wait on the document lock together');
        $ids = [];
        foreach ($run['responses'] as $index => $response) {
            $this->assertSame(200, $response['status'], $response['raw']);
            $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
            $ids[$index] = (int) $response['body']['data']['customerPaymentCreate'][0]['id'];
        }
        $this->assertNotSame($ids[0], $ids[1], 'two payments, two numbers');

        // Both committed, each for its own customer.
        $statement = $this->pdo()->prepare(
            'SELECT trans_no, debtor_no FROM ' . $this->table('debtor_trans')
            . ' WHERE type = 12 AND trans_no IN (?, ?) ORDER BY trans_no'
        );
        $statement->execute([$ids[0], $ids[1]]);
        $rows = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame((string) $customers[0]['id'], (string) ($rows[$ids[0]] ?? ''));
        $this->assertSame((string) $customers[1]['id'], (string) ($rows[$ids[1]] ?? ''));
    }

    /**
     * Every document write takes the document lock (spec §2.1): while another
     * connection holds it, each of the seven write mutations waits on it — all seven
     * are seen waiting at once — and gives up after its 10 seconds as busy.
     *
     * Each request is one FrontAccounting would refuse if it ran, so a mutation that
     * skipped the lock writes nothing, answers with a different error, and fails
     * the test.
     */
    public function testEveryDocumentWriteWaitsForTheLockAndIsRefusedAsBusy(): void
    {
        // An invoiced delivery and an allocated invoice: neither can be voided.
        $customer = $this->createCustomer();
        $order = $this->createOrder($customer, 1, 20.0);
        $invoice = $this->ok(self::INVOICE_CREATE, ['in' => [[
            'orderId' => $order['id'], 'orderVersion' => $order['version'], 'date' => $this->today(),
        ]]])['invoiceCreate'][0];
        $total = (float) $invoice['total'];
        $this->ok(self::PAYMENT_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'bankAccountId' => self::BANK_ACCOUNT,
            'date' => $this->today(),
            'amount' => $total,
            'allocations' => [['invoiceId' => $invoice['id'], 'amount' => $total]],
        ]]]);
        $stale = ['orderId' => $order['id'], 'orderVersion' => $order['version'] + 5, 'date' => $this->today()];
        $unknown = 999999999;

        $requests = [
            'deliveryCreate' => [self::DELIVERY_CREATE, ['in' => [$stale]]],
            'deliveryDelete' => [self::DELIVERY_DELETE, ['ids' => [$invoice['deliveryIds'][0]]]],
            'invoiceCreate' => [self::INVOICE_CREATE, ['in' => [$stale]]],
            'invoiceDelete' => [self::INVOICE_DELETE, ['ids' => [$invoice['id']]]],
            'customerPaymentCreate' => [self::PAYMENT_CREATE, ['in' => [[
                'customerId' => $customer['id'],
                'bankAccountId' => self::BANK_ACCOUNT,
                'date' => $this->today(),
                'amount' => 0,
            ]]]],
            'customerPaymentUpdate' => [self::PAYMENT_UPDATE, ['in' => [['id' => $unknown, 'allocations' => []]]]],
            'customerPaymentDelete' => [self::PAYMENT_DELETE, ['ids' => [$unknown]]],
        ];

        $run = $this->whileTheDocumentLockIsHeld(array_values($requests), false);

        foreach (array_keys($requests) as $index => $field) {
            $response = $run['responses'][$index];
            $this->assertSame('FA_REJECTED', $this->code($response), "$field: " . $response['raw']);
            $this->assertSame(
                'FrontAccounting is busy; try again.',
                $response['body']['errors'][0]['message'] ?? null,
                "$field: " . $response['raw']
            );
        }
        // Busy answers are the proof per mutation; this, that they were waiting and not failing to connect.
        $this->assertTrue($run['waited'], 'all seven must be seen waiting on the document lock at once');
    }

    /**
     * Holds the document lock (DocumentLock::name() for company 0, pinned by
     * DocumentLockTest) on this test's own connection, sends every request at once,
     * and watches the server's connections until each request waits on the lock
     * ("User lock" in the process list). With $release, it then lets them go (or
     * after 5 seconds, when they are not all waiting); without, it holds on until
     * every request has answered, so a request that skipped the lock answers with
     * something other than busy. The lock is released whatever happens.
     *
     * @param array<int, array{0: string, 1: array<string, mixed>}> $requests query and variables
     * @return array{
     *     waited: bool,
     *     responses: array<int, array{status: int, body: array<string, mixed>|null, raw: string}>
     * }
     */
    private function whileTheDocumentLockIsHeld(array $requests, bool $release): array
    {
        $lock = $this->pdo()->quote('fa_graphql_docs_0');
        $this->assertSame('1', (string) $this->pdo()->query("SELECT GET_LOCK($lock, 0)")->fetchColumn());
        $waiting = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE = 'User lock' AND INFO LIKE ?"
        );
        $multi = curl_multi_init();
        $handles = [];
        $held = true;
        $waited = false;
        try {
            foreach ($requests as [$query, $variables]) {
                $handle = curl_init($this->url());
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => (object) $variables]),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'User-Agent: fa-graphql-tests',
                        'Authorization: Bearer ' . $this->token,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }
            // Long enough for every request to reach GET_LOCK; far short of its 10 s.
            $deadline = microtime(true) + 5;
            do {
                curl_multi_exec($multi, $running);
                if ($held && !$waited) {
                    $waiting->execute(['%fa_graphql_docs_0%']);
                    $waited = (int) $waiting->fetchColumn() >= count($requests);
                    if ($release && ($waited || microtime(true) > $deadline)) {
                        $this->pdo()->query("SELECT RELEASE_LOCK($lock)");
                        $held = false;
                    }
                }
                curl_multi_select($multi, 0.05);
            } while ($running > 0);

            $responses = [];
            foreach ($handles as $handle) {
                $raw = (string) curl_multi_getcontent($handle);
                $decoded = json_decode($raw, true);
                $responses[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => is_array($decoded) ? $decoded : null,
                    'raw' => $raw,
                ];
            }

            return ['waited' => $waited, 'responses' => $responses];
        } finally {
            if ($held) {
                $this->pdo()->query("SELECT RELEASE_LOCK($lock)");
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    /** @return array<string, mixed> */
    private function createCustomer(): array
    {
        $ref = $this->prefix . '-' . bin2hex(random_bytes(2));
        return $this->ok(self::CUSTOMER_CREATE, ['in' => [[
            'name' => "Billing customer $ref",
            'ref' => $ref,
            'address' => "1 Billing Street\nHosting Town",
            'salesTypeId' => '1',
            'paymentTermsId' => '1',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => ['email' => strtolower($ref) . '@example.com'],
        ]]])['customerCreate'][0];
    }

    /** @return array<string, mixed> */
    private function createOrder(array $customer, int $quantity, float $price): array
    {
        $order = $this->ok(self::ORDER_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'orderDate' => $this->today(),
            'lines' => [['stockId' => self::ITEM, 'quantity' => $quantity, 'unitPrice' => $price]],
        ]]])['salesOrderCreate'][0];
        $this->orders[] = (int) $order['id'];
        return $order;
    }

    /** @return array<string, mixed> */
    private function readInvoice($id): array
    {
        $list = $this->ok(self::INVOICE_BY_ID, ['q' => ['selector' => json_encode(['id' => (int) $id])]])
            ['invoiceList'];
        $this->assertCount(1, $list);
        return $list[0];
    }

    private function balance($customerId): float
    {
        $list = $this->ok(self::CUSTOMER_BALANCE, ['q' => ['selector' => json_encode(['id' => (int) $customerId])]])
            ['customerList'];
        $this->assertCount(1, $list);
        $balance = $list[0]['balance'];
        // Step 1 decides the shape: a scalar, or an object whose total is read here.
        return (float) (is_array($balance) ? ($balance['balance'] ?? $balance['total'] ?? 0) : $balance);
    }

    /** @return int[] */
    private function orderLineIds(int $orderNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id FROM ' . $this->table('sales_order_details')
            . ' WHERE trans_type = 30 AND order_no = ? ORDER BY id'
        );
        $statement->execute([$orderNo]);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return int[] */
    private function ordersOfTestCustomers(): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT o.order_no FROM ' . $this->table('sales_orders') . ' o JOIN ' . $this->table('debtors_master')
            . ' d ON d.debtor_no = o.debtor_no WHERE o.trans_type = 30 AND d.debtor_ref LIKE ?'
        );
        $statement->execute([$this->prefix . '%']);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    /** @return array<string, mixed> the response's data */
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

    /** Today as FrontAccounting's Today() sees it: PHP's default timezone. */
    private function today(): string
    {
        return date('Y-m-d');
    }
}
