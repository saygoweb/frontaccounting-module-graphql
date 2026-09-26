<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\Allocation\AllocationType;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentType;
use PHPUnit\Framework\TestCase;

class CustomerPaymentAreasTest extends TestCase
{
    private static function call(object $object, string $method)
    {
        $m = new \ReflectionMethod($object, $method);
        $m->setAccessible(true);

        return $m->invoke($object);
    }

    public function testPaymentsReadTheViewAreaAndWriteThePaymentAllocationAndVoidAreas(): void
    {
        $payments = new CustomerPaymentType(new AllocationType());
        $this->assertSame(
            [
                'list' => 'SA_SALESTRANSVIEW',
                'create' => 'SA_SALESPAYMNT',
                'edit' => 'SA_SALESALLOC',
                'delete' => 'SA_VOIDTRANSACTION',
            ],
            self::call($payments, 'areas')
        );
        $this->assertSame(['transType' => 12], self::call($payments, 'scope'));
        $this->assertSame(['list' => 'SA_SALESTRANSVIEW'], self::call(new AllocationType(), 'areas'));
    }
}
