<?php

namespace FA\GraphQL\Tests\Unit\Model;

use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Model\SalesOrderLineModel;
use FA\GraphQL\Model\SalesOrderModel;
use PHPUnit\Framework\TestCase;

/**
 * Constructing a model runs no query (Foundation spec section 4.4): the generator
 * builds every model on a PDO that cannot run one.
 */
class SalesOrderModelsTest extends TestCase
{
    private function pdo(): \PDO
    {
        // As ModelConstructionTest: a PDO that cannot query (the image has no pdo_sqlite).
        return new class extends \PDO {
            public function __construct()
            {
            }

            #[\ReturnTypeWillChange]
            public function setAttribute($attribute, $value)
            {
                return true;
            }
        };
    }

    public function testAnOrderMapsFrontAccountingsColumns(): void
    {
        $model = new SalesOrderModel($this->pdo());
        $mapper = $model->mapper();

        $this->assertSame('0_sales_orders', $mapper->table);
        $this->assertSame('order_no', $mapper->map['id']);
        $this->assertSame('debtor_no', $mapper->map['customerId']);
        $this->assertSame('branch_code', $mapper->map['branchId']);
        $this->assertSame('ord_date', $mapper->map['orderDate']);
        $this->assertSame('order_type', $mapper->map['salesTypeId']);
        $this->assertSame('ship_via', $mapper->map['shipperId']);
        $this->assertSame('from_stk_loc', $mapper->map['locationId']);
        $this->assertSame('delivery_date', $mapper->map['deliveryDate']);
        $this->assertSame('payment_terms', $mapper->map['paymentTermsId']);
        $this->assertSame('prep_amount', $mapper->map['prepaymentAmount']);
        $this->assertSame('alloc', $mapper->map['allocated']);
        $this->assertInstanceOf(SqlDateTransform::class, $mapper->transformers['ord_date']);
        $this->assertInstanceOf(SqlDateTransform::class, $mapper->transformers['delivery_date']);
        $this->assertInstanceOf(BooleanTransform::class, $mapper->transformers['type']);
        $this->assertSame(30, $model->transType);
    }

    public function testALineMapsItsColumnsAndShowsItsDiscountAsAPercent(): void
    {
        $mapper = (new SalesOrderLineModel($this->pdo()))->mapper();

        $this->assertSame('0_sales_order_details', $mapper->table);
        $this->assertSame('order_no', $mapper->map['orderId']);
        $this->assertSame('stk_code', $mapper->map['stockId']);
        $this->assertSame('qty_sent', $mapper->map['qtyDelivered']);
        $this->assertSame('invoiced', $mapper->map['qtyInvoiced']);
        $this->assertSame('unit_price', $mapper->map['unitPrice']);
        $this->assertInstanceOf(PercentTransform::class, $mapper->transformers['discount_percent']);
    }

    /**
     * anorm-graphql 0.2 makes an @required property non-null in the Create input.
     */
    public function testTheRequiredPropertiesAreMarked(): void
    {
        foreach (
            [
            [SalesOrderModel::class, ['customerId', 'branchId', 'orderDate']],
            [SalesOrderLineModel::class, ['stockId', 'quantity']],
            ] as [$class, $required]
        ) {
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getName()[0] === '_') {
                    continue; // Anorm's own $_mapper
                }
                $marked = strpos((string) $property->getDocComment(), '@required') !== false;
                $this->assertSame(
                    in_array($property->getName(), $required, true),
                    $marked,
                    "$class::\${$property->getName()}"
                );
            }
        }
    }
}
