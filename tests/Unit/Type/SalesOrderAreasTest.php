<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Type\SalesOrder\RecurrenceRepeatsType;
use FA\GraphQL\Type\SalesOrder\RecurrenceType;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use PHPUnit\Framework\TestCase;

class SalesOrderAreasTest extends TestCase
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

    private function areasOf(object $type): array
    {
        $areas = new \ReflectionMethod($type, 'areas');
        $areas->setAccessible(true);

        return $areas->invoke($type);
    }

    public function testReadingNeedsTheViewAreaAndWritingTheOrderArea(): void
    {
        $this->assertSame(
            [
                'list' => 'SA_SALESTRANSVIEW',
                'create' => 'SA_SALESORDER',
                'edit' => 'SA_SALESORDER',
                'delete' => 'SA_SALESORDER',
            ],
            $this->areasOf(self::orderType())
        );
        $this->assertSame(['list' => 'SA_SALESTRANSVIEW'], $this->areasOf(new SalesOrderLineType()));
    }

    public function testCreatingWithoutTheOrderAreaIsForbiddenBeforeAnythingIsTouched(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTRANSVIEW']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESORDER');
        (self::orderType())->resolveCreate(null, ['input' => [[]]], new Container());
    }

    /**
     * Update and delete are wired through SalesOrderService; both need the order area
     * before anything is touched, as create does.
     */
    public function testUpdatingOrDeletingWithoutTheOrderAreaIsForbiddenBeforeAnythingIsTouched(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTRANSVIEW']);
        $type = self::orderType();

        $calls = ['resolveUpdate' => ['input' => [['id' => '1']]], 'resolveDelete' => ['id' => ['1']]];
        foreach ($calls as $method => $args) {
            try {
                $type->$method(null, $args, new Container());
                $this->fail("$method was not refused");
            } catch (Forbidden $e) {
                $this->assertStringContainsString('SA_SALESORDER', $e->getMessage());
            }
        }
    }

    public function testTheLineTypeIsScopedToSalesOrders(): void
    {
        $scope = new \ReflectionMethod(SalesOrderLineType::class, 'scope');
        $scope->setAccessible(true);

        $this->assertSame(['transType' => 30], $scope->invoke(new SalesOrderLineType()));
    }

    private static function orderType(): SalesOrderType
    {
        return new SalesOrderType(new SalesOrderLineType(), new RecurrenceType(new RecurrenceRepeatsType()));
    }
}
