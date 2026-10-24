<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\Forbidden;
use GraphQL\GraphQL;
use PHPUnit\Framework\TestCase;

/**
 * priceQuote needs SA_SALESORDER, the area order entry prices a line under (Items
 * and prices spec §3.1), and checks its ids before FrontAccounting is touched.
 */
class PriceQuoteAreaTest extends TestCase
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

    private function execute(string $query): array
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $result = GraphQL::executeQuery(new ApiSchema($builder->build()), $query);

        return [$result->data, $result->errors];
    }

    public function testWithoutSalesOrdersItIsForbidden(): void
    {
        $this->signIn(['SA_SALESTRANSVIEW', 'SA_CUSTOMER']);

        [, $errors] = $this->execute('{ priceQuote(stockId: "101") { price } }');

        $this->assertInstanceOf(Forbidden::class, $errors[0]->getPrevious());
    }

    public function testACustomerIdThatIsNotAWholeNumberIsBadInput(): void
    {
        $this->signIn(['SA_SALESORDER']);

        [, $errors] = $this->execute('{ priceQuote(stockId: "101", customerId: "1 OR 1") { price } }');

        $this->assertInstanceOf(BadInput::class, $errors[0]->getPrevious());
        $this->assertSame('customerId', $errors[0]->getPrevious()->field());
    }
}
