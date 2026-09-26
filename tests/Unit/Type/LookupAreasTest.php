<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Type\CreditStatus\CreditStatusType;
use FA\GraphQL\Type\Currency\CurrencyType;
use FA\GraphQL\Type\Location\LocationType;
use FA\GraphQL\Type\PaymentTerms\PaymentTermsType;
use FA\GraphQL\Type\SalesArea\SalesAreaType;
use FA\GraphQL\Type\Salesman\SalesmanType;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use FA\GraphQL\Type\Shipper\ShipperType;
use FA\GraphQL\Type\StockItem\StockItemType;
use FA\GraphQL\Type\TaxGroup\TaxGroupType;
use PHPUnit\Framework\TestCase;

/**
 * Release 2 spec section 4.2: a role that takes orders reads what an order needs.
 * Every lookup lists with SA_SALESORDER, not FrontAccounting's setup areas
 * (SA_PAYTERMS, SA_CURRENCY, ...), which grant editing in the web UI.
 */
class LookupAreasTest extends TestCase
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

    /**
     * @return array<string, array{0: class-string}>
     */
    public function lookups(): array
    {
        return [
            'SalesType' => [SalesTypeType::class],
            'PaymentTerms' => [PaymentTermsType::class],
            'TaxGroup' => [TaxGroupType::class],
            'SalesArea' => [SalesAreaType::class],
            'Salesman' => [SalesmanType::class],
            'Location' => [LocationType::class],
            'Shipper' => [ShipperType::class],
            'CreditStatus' => [CreditStatusType::class],
            'Currency' => [CurrencyType::class],
            'StockItem' => [StockItemType::class],
        ];
    }

    /**
     * @dataProvider lookups
     */
    public function testListingIsTheOnlyVerbAndItNeedsSalesOrders(string $class): void
    {
        $areas = new \ReflectionMethod($class, 'areas');
        $areas->setAccessible(true);

        $this->assertSame(['list' => 'SA_SALESORDER'], $areas->invoke(new $class()));
    }

    /**
     * @dataProvider lookups
     */
    public function testARoleWithoutSalesOrdersCannotList(string $class): void
    {
        // Setup areas are not enough, and SA_GRAPHQL only lets you into the API.
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTYPES', 'SA_PAYTERMS', 'SA_CURRENCY']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESORDER');
        (new $class())->resolveList(null, [], new Container());
    }
}
