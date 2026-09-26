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
