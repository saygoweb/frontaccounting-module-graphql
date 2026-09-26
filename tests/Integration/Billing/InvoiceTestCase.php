<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\InvoiceService;
use FA\GraphQL\Fa\Service\ServiceCall;

/**
 * Invoice tests against FrontAccounting in-process, as apitest. Orders are purged by
 * SalesOrderTestCase (FaOrderRows); deliveries, invoices and voids by
 * BillingTestCase (FaBillingRows) — first, since an invoice refers to its
 * deliveries. Only rows this test created are removed: FrontAccounting reuses a
 * voided or deleted document's number.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class InvoiceTestCase extends BillingTestCase
{
    protected function invoices(): InvoiceService
    {
        return $this->container->get(InvoiceService::class);
    }

    /**
     * An invoice the way invoiceCreate writes one: the document lock around one
     * ServiceCall.
     *
     * @param array<string, mixed> $input
     */
    protected function invoice(array $input): int
    {
        return DocumentLock::run(function () use ($input): int {
            return ServiceCall::run(function () use ($input): int {
                return $this->invoices()->create($input);
            });
        });
    }

    protected function voidInvoice(int $invoiceNo): void
    {
        DocumentLock::run(function () use ($invoiceNo): void {
            ServiceCall::run(function () use ($invoiceNo): void {
                $this->invoices()->delete($invoiceNo);
            });
        });
    }

    /**
     * A delivery through DeliveryService (Task 3), of everything remaining unless
     * $lines says otherwise.
     *
     * @param array<int, array<string, mixed>>|null $lines [['orderLineId' => .., 'quantity' => ..]]
     * @param array<string, mixed> $overrides
     */
    protected function deliverOrder(int $orderNo, ?array $lines = null, array $overrides = []): int
    {
        return $this->createDelivery($orderNo, array_merge($lines === null ? [] : ['lines' => $lines], $overrides));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    protected function detailRows(int $type, int $transNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT * FROM 0_debtor_trans_details WHERE debtor_trans_type = ? AND debtor_trans_no = ? ORDER BY id'
        );
        $statement->execute([$type, $transNo]);

        return array_map(static function (array $row): array {
            return array_map(static function ($v): ?string {
                return $v === null ? null : (string) $v;
            }, $row);
        }, $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** The sum of a document's GL postings: 0 when it balances. */
    protected function glSum(int $type, int $transNo): float
    {
        $statement = $this->pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM 0_gl_trans WHERE type = ? AND type_no = ?'
        );
        $statement->execute([$type, $transNo]);

        return round((float) $statement->fetchColumn(), 2);
    }

    protected function isVoided(int $type, int $transNo): bool
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_voided WHERE type = ? AND id = ?');
        $statement->execute([$type, $transNo]);

        return (int) $statement->fetchColumn() > 0;
    }
}
