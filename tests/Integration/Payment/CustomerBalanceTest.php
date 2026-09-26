<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\ApiSchema;
use GraphQL\GraphQL;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerBalanceTest extends PaymentTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function balance(int $customerId): array
    {
        $result = GraphQL::executeQuery(
            $this->container->get(ApiSchema::class),
            'query ($q: MangoInput) {
                customerList(query: $q) { id balance { balance due overdue1 overdue2 currency } }
            }',
            null,
            $this->container,
            ['q' => ['selector' => json_encode(['id' => $customerId])]]
        )->toArray();
        $this->assertArrayNotHasKey('errors', $result, json_encode($result));

        return $result['data']['customerList'][0]['balance'];
    }

    public function testTheBalanceFollowsInvoicesAndPayments(): void
    {
        $start = $this->balance(self::HOME_CUSTOMER);
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);

        $invoiced = $this->balance(self::HOME_CUSTOMER);
        $this->assertEqualsWithDelta($start['balance'] + $total, $invoiced['balance'], 0.001);
        $this->assertSame('USD', $invoiced['currency']);

        $this->pay(['amount' => $total, 'allocations' => [['invoiceId' => $invoice, 'amount' => $total]]]);

        $paid = $this->balance(self::HOME_CUSTOMER);
        $this->assertEqualsWithDelta($start['balance'], $paid['balance'], 0.001);
    }

    /**
     * get_customer_details($id, null, false) returns no row for a customer with
     * nothing unallocated (its WHERE drops the LEFT JOIN's null row): that customer
     * owes nothing, and reads as zeros in its currency, not null.
     */
    public function testASettledCustomerOwesNothing(): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $this->pay(['amount' => $total, 'allocations' => [['invoiceId' => $invoice, 'amount' => $total]]]);
        $open = (int) $this->pdo()->query(
            'SELECT COUNT(*) FROM 0_debtor_trans WHERE debtor_no = 1 AND type IN (10, 11, 12)
             AND ABS(ABS(ov_amount) + ov_gst + ov_freight + ov_freight_tax + ov_discount - alloc) > 0.004'
        )->fetchColumn();
        if ($open > 0) {
            $this->markTestSkipped('Demo customer 1 has open items on this database.');
        }

        $this->assertSame(
            ['balance' => 0.0, 'due' => 0.0, 'overdue1' => 0.0, 'overdue2' => 0.0, 'currency' => 'USD'],
            $this->balance(self::HOME_CUSTOMER)
        );
    }

    public function testTheBalanceNeedsTheTransactionsView(): void
    {
        global $security_areas;
        $user = $_SESSION['wa_current_user'];
        $user->role_set = array_values(array_diff($user->role_set, [$security_areas['SA_SALESTRANSVIEW'][0]]));
        $this->assertTrue($user->can_access('SA_CUSTOMER'));

        $result = GraphQL::executeQuery(
            $this->container->get(ApiSchema::class),
            'query ($q: MangoInput) { customerList(query: $q) { id balance { balance } } }',
            null,
            $this->container,
            ['q' => ['selector' => json_encode(['id' => self::HOME_CUSTOMER])]]
        )->toArray();

        $this->assertSame('1', $result['data']['customerList'][0]['id'], json_encode($result));
        $this->assertNull($result['data']['customerList'][0]['balance']);
        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
    }
}
