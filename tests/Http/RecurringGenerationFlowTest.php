<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaOrderRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use PHPUnit\Framework\TestCase;

/**
 * Release 4 end to end (spec §4.2, §8): a recurring order is due, is generated
 * (delivered and invoiced) and emailed in one call, is no longer due, cannot be
 * billed twice, is paid, and leaves the customer's balance at zero. Items of one
 * recurringGenerate call are independent. Who may list and who may generate.
 *
 * Everything served here for recurrence comes from sgw_sales' extension; this
 * module ships no recurrence code (spec §1, success).
 *
 * tearDown removes everything a test wrote, pass or fail: billing rows written
 * since setUp (FaBillingRows), the orders (FaOrderRows) and their sales_recurring
 * rows, the test's customers (FaTestRows), the refresh tokens its logins created,
 * and the mail it caused.
 */
class RecurringGenerationFlowTest extends TestCase
{
    use GraphQLClient;

    /** A sellable service item (Release 3 BillingFlowTest Step 1): no stock check. */
    private const ITEM = '202';

    /** A home-currency bank account (Release 3 BillingFlowTest Step 1). */
    private const BANK_ACCOUNT = '1';

    private const CUSTOMER_CREATE = 'mutation ($in: [CustomerCreateInput!]!) '
        . '{ customerCreate(input: $in) { id branches { id } } }';

    private const ORDER_CREATE = 'mutation ($in: [SalesOrderCreateInput!]!) '
        . '{ salesOrderCreate(input: $in) { id version recurring { start repeats every monthDay next } } }';

    private const DUE_LIST = 'query ($asOf: Date!) { recurringDueList(asOf: $asOf) '
        . '{ orderId customerId reference customerRef next repeats every monthDay } }';

    private const GENERATE = 'mutation ($in: [RecurringGenerateInput!]!) { recurringGenerate(input: $in) '
        . '{ orderId invoiceId deliveryId next email { id sent recipient messages } error { code message } } }';

    private const INVOICE_FIELDS = '{ id total outstanding orderId deliveryIds voided }';

    private const INVOICE_BY = 'query ($q: MangoInput) { invoiceList(query: $q) ' . self::INVOICE_FIELDS . ' }';

    private const PAYMENT_CREATE = 'mutation ($in: [CustomerPaymentCreateInput!]!) '
        . '{ customerPaymentCreate(input: $in) { id unallocated allocations { toId amount } } }';

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
        if (!$this->hasRecurringTable()) {
            $this->markTestSkipped('Recurring generation needs sgw_sales active (the sales_recurring table).');
        }
        $this->prefix = 'RGEN-' . bin2hex(random_bytes(4));
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
        $this->billingRows->purge();
        $orders = array_unique(array_merge($this->orders, $this->ordersOfTestCustomers()));
        $recurring = $this->pdo()->prepare('DELETE FROM ' . $this->table('sales_recurring') . ' WHERE trans_no = ?');
        foreach ($orders as $orderNo) {
            $recurring->execute([$orderNo]);
            $this->orderRows->purge((int) $orderNo);
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

    public function testADueRecurringOrderIsGeneratedEmailedAndPaidOnce(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createRecurringOrder($customer, 1, 80.0);
        $this->assertSame('YEAR', $order['recurring']['repeats']);

        // Due today: a new schedule has no next date yet, so it is due from its start.
        $due = $this->dueOrderIds();
        $this->assertContains((string) $order['id'], $due, 'the new recurring order is due');

        // Generate it and email the invoice in the same call.
        $result = $this->ok(self::GENERATE, ['in' => [[
            'orderId' => $order['id'],
            'date' => $this->today(),
            'email' => MailCatcher::available(),
        ]]])['recurringGenerate'];
        $this->assertCount(1, $result);
        $item = $result[0];
        $this->assertNull($item['error'], json_encode($item['error']));
        $this->assertSame((string) $order['id'], (string) $item['orderId']);
        $this->assertNotNull($item['invoiceId']);
        $this->assertNotNull($item['deliveryId']);
        $this->assertGreaterThan($this->today(), (string) $item['next'], 'the next date moves on a year');
        if (MailCatcher::available()) {
            $this->assertTrue($item['email']['sent'], implode("\n", $item['email']['messages']));
            $this->assertStringEndsWith('@example.com', (string) $item['email']['recipient']);
            $this->assertCount(1, MailCatcher::newSince($this->mailBefore));
        }

        // The invoice is the order's, delivered from it, and wholly outstanding.
        $invoice = $this->invoice((string) $item['invoiceId']);
        $this->assertSame((string) $order['id'], (string) $invoice['orderId']);
        $this->assertSame([(string) $item['deliveryId']], array_map('strval', $invoice['deliveryIds']));
        $total = (float) $invoice['total'];
        $this->assertGreaterThan(0.0, $total);
        $this->assertEqualsWithDelta($total, (float) $invoice['outstanding'], 0.001);

        // No longer due, and a retry bills nothing twice (spec §4.1).
        $this->assertNotContains((string) $order['id'], $this->dueOrderIds(), 'generated orders leave the due list');
        $retry = $this->ok(self::GENERATE, ['in' => [['orderId' => $order['id'], 'date' => $this->today()]]])
            ['recurringGenerate'][0];
        $this->assertNull($retry['invoiceId']);
        $this->assertNotNull($retry['error']);
        $this->assertSame('NOT_DUE', $retry['error']['code'], (string) $retry['error']['message']);
        $this->assertCount(1, $this->invoicesOfOrder((string) $order['id']), 'exactly one invoice for the period');

        // Pay it; the customer is settled.
        $payment = $this->ok(self::PAYMENT_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'bankAccountId' => self::BANK_ACCOUNT,
            'date' => $this->today(),
            'amount' => $total,
            'allocations' => [['invoiceId' => $item['invoiceId'], 'amount' => $total]],
        ]]])['customerPaymentCreate'][0];
        $this->assertEqualsWithDelta(0.0, (float) $payment['unallocated'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->invoice((string) $item['invoiceId'])['outstanding'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($customer['id']), 0.001);
    }

    public function testItemsOfOneCallAreIndependent(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createRecurringOrder($customer, 1, 25.0);

        // Item 0 names an order that does not exist; item 1 is due. Item 1 still bills.
        $result = $this->ok(self::GENERATE, ['in' => [
            ['orderId' => '999999', 'date' => $this->today()],
            ['orderId' => $order['id'], 'date' => $this->today()],
        ]])['recurringGenerate'];
        $this->assertCount(2, $result);
        $this->assertNotNull($result[0]['error'], 'an unknown order is refused');
        $this->assertNull($result[0]['invoiceId']);
        $this->assertNull($result[1]['error'], json_encode($result[1]['error']));
        $this->assertNotNull($result[1]['invoiceId'], 'a refused item does not stop the next (spec §4.2)');
        $this->assertCount(1, $this->invoicesOfOrder((string) $order['id']));
    }

    public function testAnOrderRoleMayListButNotGenerate(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createRecurringOrder($customer, 1, 10.0);
        $orders = $this->login('apiorders')['accessToken'];   // 3073 + 3075 only (Release 2 seed)

        $listed = $this->gql(self::DUE_LIST, ['asOf' => $this->today()], $orders);
        $this->assertNull($this->code($listed), $listed['raw']);

        $refused = $this->gql(
            self::GENERATE,
            ['in' => [['orderId' => $order['id'], 'date' => $this->today()]]],
            $orders
        );
        $this->assertSame('FORBIDDEN', $this->code($refused), $refused['raw']);
        $this->assertCount(0, $this->invoicesOfOrder((string) $order['id']), 'nothing was billed');
    }

    /** @return string[] */
    private function dueOrderIds(): array
    {
        $due = $this->ok(self::DUE_LIST, ['asOf' => $this->today()])['recurringDueList'];
        return array_map(static function (array $row): string {
            return (string) $row['orderId'];
        }, $due);
    }

    private function createRecurringOrder(array $customer, int $quantity, float $price): array
    {
        $order = $this->ok(self::ORDER_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'orderDate' => $this->today(),
            'customerRef' => $this->prefix . '-order',
            'lines' => [['stockId' => self::ITEM, 'quantity' => $quantity, 'unitPrice' => $price]],
            'recurring' => [
                'start' => $this->today(),
                'repeats' => 'YEAR',
                'every' => 1,
                'monthDay' => substr($this->today(), 5),
            ],
        ]]])['salesOrderCreate'][0];
        $this->orders[] = (int) $order['id'];
        return $order;
    }

    private function invoice(string $id): array
    {
        $rows = $this->ok(self::INVOICE_BY, ['q' => ['selector' => json_encode(['id' => $id])]])['invoiceList'];
        $this->assertCount(1, $rows, "invoice $id");
        return $rows[0];
    }

    /** @return array[] */
    private function invoicesOfOrder(string $orderId): array
    {
        return array_values(array_filter(
            $this->ok(self::INVOICE_BY, ['q' => ['selector' => json_encode(['orderId' => $orderId])]])['invoiceList'],
            static function (array $invoice): bool {
                return !$invoice['voided'];
            }
        ));
    }

    private function hasRecurringTable(): bool
    {
        $found = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $found->execute([$this->table('sales_recurring')]);
        return (int) $found->fetchColumn() === 1;
    }

    // --- Copied verbatim from tests/Http/BillingFlowTest.php (private there). ---

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
    private function readInvoice($id): array
    {
        $list = $this->ok(self::INVOICE_BY, ['q' => ['selector' => json_encode(['id' => (int) $id])]])
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
