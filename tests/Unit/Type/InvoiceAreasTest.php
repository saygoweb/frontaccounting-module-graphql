<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Type\Invoice\InvoiceType;
use FA\GraphQL\Type\InvoiceLine\InvoiceLineType;
use PHPUnit\Framework\TestCase;

class InvoiceAreasTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['wa_current_user']);
    }

    private function signIn(array $areas): void
    {
        $_SESSION['wa_current_user'] = new class ($areas) {
            private array $areas;

            public function __construct(array $areas)
            {
                $this->areas = $areas;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function logged_in(): bool
            {
                return true;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function can_access(string $area): bool
            {
                return in_array($area, $this->areas, true);
            }
        };
    }

    private static function call(object $object, string $method)
    {
        $m = new \ReflectionMethod($object, $method);
        $m->setAccessible(true);

        return $m->invoke($object);
    }

    private static function invoiceType(): InvoiceType
    {
        return new InvoiceType(new InvoiceLineType());
    }

    public function testReadingNeedsTheViewAreaInvoicingTheInvoiceAreaVoidingTheVoidArea(): void
    {
        $this->assertSame(
            [
                'list' => 'SA_SALESTRANSVIEW',
                'create' => 'SA_SALESINVOICE',
                'delete' => 'SA_VOIDTRANSACTION',
            ],
            self::call(self::invoiceType(), 'areas')
        );
        $this->assertSame(['list' => 'SA_SALESTRANSVIEW'], self::call(new InvoiceLineType(), 'areas'));
    }

    public function testBothAreScopedToInvoices(): void
    {
        $this->assertSame(['transType' => 10], self::call(self::invoiceType(), 'scope'));
        $this->assertSame(['transType' => 10], self::call(new InvoiceLineType(), 'scope'));
    }

    public function testInvoicingWithoutTheInvoiceAreaIsForbiddenBeforeAnythingIsTouched(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTRANSVIEW', 'SA_SALESDELIVERY']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESINVOICE');
        self::invoiceType()->resolveCreate(null, ['input' => [[]]], new Container());
    }

    public function testVoidingWithoutTheVoidAreaIsForbiddenBeforeAnythingIsTouched(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTRANSVIEW', 'SA_SALESINVOICE']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_VOIDTRANSACTION');
        self::invoiceType()->resolveDelete(null, ['id' => ['1']], new Container());
    }

    public function testThereIsNoUpdate(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTRANSVIEW', 'SA_SALESINVOICE', 'SA_VOIDTRANSACTION']);

        $this->expectException(Forbidden::class);
        self::invoiceType()->resolveUpdate(null, ['input' => [['id' => '1']]], new Container());
    }
}
