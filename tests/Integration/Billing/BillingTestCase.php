<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\DeliveryService;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use FA\GraphQL\Tests\Support\AssertsGlBalanced;
use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Billing tests against FrontAccounting in-process, signed in as apitest. Orders come
 * from SalesOrderTestCase (purged by FaOrderRows); every delivery, invoice, payment,
 * void and posting this test writes is purged first, by FaBillingRows, against the
 * mark setUp took. Demo customers whose credit status a test changes are restored.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class BillingTestCase extends SalesOrderTestCase
{
    use AssertsGlBalanced;

    private ?FaBillingRows $billingRows = null;

    /** @var array<int, int> customer => credit status to put back */
    private array $creditStatus = [];

    protected function setUp(): void
    {
        parent::setUp();
        FaIncludes::billing();
        $this->billingRows = FaBillingRows::mark(FaTestRows::connect());
    }

    protected function tearDown(): void
    {
        foreach ($this->creditStatus as $customerId => $status) {
            $this->pdo()->prepare('UPDATE 0_debtors_master SET credit_status = ? WHERE debtor_no = ?')
                ->execute([$status, $customerId]);
        }
        $this->creditStatus = [];
        if ($this->billingRows !== null) {
            $this->billingRows->purge();
        }
        parent::tearDown();
    }

    protected function deliveries(): DeliveryService
    {
        return $this->container->get(DeliveryService::class);
    }

    /**
     * Deliver an order through DeliveryService, the way deliveryCreate does: the
     * document lock around one ServiceCall.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createDelivery(int $orderNo, array $overrides = []): int
    {
        $input = array_merge([
            'orderId' => $orderNo,
            'orderVersion' => (int) $this->orderRow($orderNo)['version'],
            'date' => new \DateTimeImmutable($this->today()),
        ], $overrides);

        return DocumentLock::run(function () use ($input): int {
            return ServiceCall::run(function () use ($input): int {
                return $this->deliveries()->create($input);
            });
        });
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function deliveryRow(int $no): ?array
    {
        return $this->transRow(13, $no);
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function transRow(int $type, int $no): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_debtor_trans WHERE type = ? AND trans_no = ?');
        $statement->execute([$type, $no]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : array_map(static function ($value): ?string {
            return $value === null ? null : (string) $value;
        }, $row);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    protected function deliveryLines(int $no): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT * FROM 0_debtor_trans_details WHERE debtor_trans_type = 13 AND debtor_trans_no = ? ORDER BY id'
        );
        $statement->execute([$no]);

        return array_map(static function (array $row): array {
            return array_map(static function ($value): ?string {
                return $value === null ? null : (string) $value;
            }, $row);
        }, $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * $write must wait for the order's row lock (Checkpoint B M-1): another
     * connection holds the row FOR UPDATE, FrontAccounting's connection waits at most
     * a second, and the write fails with MariaDB's lock wait timeout (1205). A write
     * that never asks for the row finishes instead.
     */
    protected function assertWaitsForTheOrderRow(int $orderNo, callable $write): void
    {
        $other = FaTestRows::connect();
        $other->beginTransaction();
        $other->prepare('SELECT version FROM 0_sales_orders WHERE order_no = ? AND trans_type = 30 FOR UPDATE')
            ->execute([$orderNo]);
        db_query('SET SESSION innodb_lock_wait_timeout = 1', 'could not set the lock wait timeout');
        try {
            $write();
            $this->fail('the write did not wait for the order row');
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->assertStringContainsString('1205', $e->getMessage(), 'a lock wait timeout');
        } finally {
            $other->rollBack();
            db_query('SET SESSION innodb_lock_wait_timeout = DEFAULT', 'could not reset the lock wait timeout');
        }
    }

    /**
     * Put a demo customer on (or off) hold for one test; restored in tearDown.
     */
    protected function setCreditStatus(int $customerId, int $status): void
    {
        if (!array_key_exists($customerId, $this->creditStatus)) {
            $statement = $this->pdo()->prepare('SELECT credit_status FROM 0_debtors_master WHERE debtor_no = ?');
            $statement->execute([$customerId]);
            $this->creditStatus[$customerId] = (int) $statement->fetchColumn();
        }
        $this->pdo()->prepare('UPDATE 0_debtors_master SET credit_status = ? WHERE debtor_no = ?')
            ->execute([$status, $customerId]);
    }
}
