<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use PHPUnit\Framework\TestCase;

class SalesTypeAreasTest extends TestCase
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

    public function testOnlyListingIsMappedAndItNeedsSalesTypes(): void
    {
        $areas = new \ReflectionMethod(SalesTypeType::class, 'areas');
        $areas->setAccessible(true);

        $this->assertSame(['list' => 'SA_SALESTYPES'], $areas->invoke(new SalesTypeType()));
    }

    public function testARoleWithoutSalesTypesCannotList(): void
    {
        // SA_GRAPHQL lets you into the API; it does not let you read every table.
        $this->signIn(['SA_GRAPHQL']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESTYPES');
        (new SalesTypeType())->resolveList(null, [], new Container());
    }
}
