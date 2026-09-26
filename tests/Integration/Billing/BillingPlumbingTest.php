<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\BillingChecks;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\Voider;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;

/**
 * Release 3 spec §2.2, §2.3 and the void wrapper, against FrontAccounting in-process,
 * signed in as apitest.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BillingPlumbingTest extends FaTestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'billing-plumbing-test', new \DateTimeImmutable('+5 minutes')));
        FaIncludes::billing();
    }

    public function testBillingBringsEveryFunctionTheServicesCall(): void
    {
        foreach (
            [
                'write_sales_delivery', 'write_sales_invoice', 'write_customer_payment',
                'get_allocatable_to_cust_transactions', 'void_transaction', 'is_closed_trans',
                'get_customer_trans', 'is_date_in_fiscalyear', 'db_has_currency_rates',
                'get_customer_details', 'adjust_shipping_charge', 'get_invoice_duedate',
            ] as $function
        ) {
            $this->assertTrue(function_exists($function), $function);
        }
        $this->assertTrue(class_exists('allocation', false), 'allocation_cart.inc');
    }

    public function testTodayIsInTheFiscalYear(): void
    {
        // Release 2's db load adds fiscal years through the current year.
        BillingChecks::assertInFiscalYear(date('Y-m-d'), 'date');
        BillingChecks::assertOpenToday();
        $this->addToAssertionCount(2);
    }

    public function testADateNoOpenFiscalYearCoversIsBadInputOnItsField(): void
    {
        try {
            BillingChecks::assertInFiscalYear('2000-01-01', 'date', 3);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('date', $e->field());
            $this->assertSame(3, $e->index());
            $this->assertStringContainsString('fiscal year', $e->getMessage());
        }
    }

    public function testTheCompanyCurrencyAlwaysHasARate(): void
    {
        BillingChecks::assertExchangeRate('USD', '2000-01-01', 'date');
        $this->addToAssertionCount(1);
    }

    public function testAForeignCurrencyWithoutARateOnTheDateIsBadInput(): void
    {
        // en_US-demo's EUR rates start in the demo's years; none exists before 1990.
        try {
            BillingChecks::assertExchangeRate('EUR', '1990-01-01', 'date');
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('date', $e->field());
            $this->assertStringContainsString('EUR', $e->getMessage());
        }
    }

    public function testVoidingSomethingThatDoesNotExistIsRefusedWithFrontAccountingsMessage(): void
    {
        try {
            ServiceCall::run(function (): void {
                (new Voider())->void(ST_CUSTDELIVERY, 999999, 'test');
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame('Selected transaction does not exists.', $e->getMessage());
        }
        $this->assertFalse((new Voider())->isVoided(ST_CUSTDELIVERY, 999999));
    }
}
