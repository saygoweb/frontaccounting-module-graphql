<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\Voider;

/**
 * Release 3 spec §3: deliveryDelete voids, with FrontAccounting's void and one guard
 * of its own (anything invoiced).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeliveryDeleteTest extends BillingTestCase
{
    private function void(int $dn): void
    {
        DocumentLock::run(function () use ($dn): void {
            ServiceCall::run(function () use ($dn): void {
                $this->deliveries()->delete($dn);
            });
        });
    }

    public function testVoidingADeliveryGivesTheOrderItsQuantitiesBack(): void
    {
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $dn = $this->createDelivery($order);

        $this->void($dn);

        $this->assertEqualsWithDelta(0.0, (float) $this->lineRows($order)[0]['qty_sent'], 0.0001);
        $this->assertTrue((new Voider())->isVoided(13, $dn));
        $row = $this->deliveryRow($dn);
        $this->assertEqualsWithDelta(0.0, (float) $row['ov_amount'], 0.0001);
        $this->assertGlBalanced(13, $dn);
        // Delivered again after the void.
        $this->createDelivery($order);
        $this->assertEqualsWithDelta(2.0, (float) $this->lineRows($order)[0]['qty_sent'], 0.0001);
    }

    public function testVoidingWaitsForTheOrderRow(): void
    {
        // Checkpoint B M-1: the void restores the order's quantities, so it holds the
        // order row as an order update does.
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $dn = $this->createDelivery($order);

        $this->assertWaitsForTheOrderRow($order, function () use ($dn): void {
            $this->void($dn);
        });
        $this->assertFalse((new Voider())->isVoided(13, $dn));
        $this->assertEqualsWithDelta(2.0, (float) $this->lineRows($order)[0]['qty_sent'], 0.0001);
    }

    public function testVoidingTwiceIsRefused(): void
    {
        $order = $this->createOrder();
        $dn = $this->createDelivery($order);
        $this->void($dn);
        $this->expectException(FaRejected::class);
        $this->void($dn);
    }

    public function testADeliveryThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->void(999999);
    }

    public function testAnInvoicedDeliveryIsRefused(): void
    {
        $order = $this->createOrder();
        $dn = $this->createDelivery($order);
        // Invoice it with FrontAccounting's own functions (Task 4 brings InvoiceService).
        ServiceCall::run(function () use ($dn): void {
            $invoice = new \Cart(ST_CUSTDELIVERY, [$dn], true);
            $invoice->document_date = \Today();
            $invoice->due_date = \Today();
            $invoice->reference = 'auto';
            $this->assertGreaterThan(0, (int) $invoice->write());
        });

        try {
            $this->void($dn);
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('invoiced', $e->getMessage());
        }
    }
}
