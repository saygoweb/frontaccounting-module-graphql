<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\BankAccount\BankAccountType;
use PHPUnit\Framework\TestCase;

/**
 * Release 3 spec §2, §5, §9 ruling 12: which FrontAccounting area each billing verb
 * needs.
 */
class BillingAreasTest extends TestCase
{
    public function testBankAccountsListWithTheCustomerPaymentsArea(): void
    {
        $areas = (new \ReflectionMethod(BankAccountType::class, 'areas'));
        $areas->setAccessible(true);
        $this->assertSame(['list' => 'SA_SALESPAYMNT'], $areas->invoke(new BankAccountType()));
    }

    public function testDeliveriesListAsTransactionsCreateAsDeliveriesAndVoidAsVoids(): void
    {
        $areas = new \ReflectionMethod(\FA\GraphQL\Type\Delivery\DeliveryType::class, 'areas');
        $areas->setAccessible(true);
        $type = (new \ReflectionClass(\FA\GraphQL\Type\Delivery\DeliveryType::class))->newInstanceWithoutConstructor();
        $this->assertSame([
            'list' => 'SA_SALESTRANSVIEW',
            'create' => 'SA_SALESDELIVERY',
            'delete' => 'SA_VOIDTRANSACTION',
        ], $areas->invoke($type));
    }

    public function testDeliveryLinesListAsTransactions(): void
    {
        $areas = new \ReflectionMethod(\FA\GraphQL\Type\DeliveryLine\DeliveryLineType::class, 'areas');
        $areas->setAccessible(true);
        $type = (new \ReflectionClass(\FA\GraphQL\Type\DeliveryLine\DeliveryLineType::class))
            ->newInstanceWithoutConstructor();
        $this->assertSame(['list' => 'SA_SALESTRANSVIEW'], $areas->invoke($type));
    }
}
