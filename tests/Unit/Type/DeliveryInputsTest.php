<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\Delivery\DeliveryCreateInput;
use FA\GraphQL\Type\DeliveryLine\DeliveryLineCreateInput;
use PHPUnit\Framework\TestCase;

/**
 * Release 3 spec §3: what a client sends to deliver an order.
 */
class DeliveryInputsTest extends TestCase
{
    private function fieldTypes(\GraphQL\Type\Definition\InputObjectType $input): array
    {
        $types = [];
        foreach ($input->getFields() as $name => $field) {
            $types[$name] = (string) $field->getType();
        }
        ksort($types);

        return $types;
    }

    public function testTheDeliveryInputIsTheOrderAndWhatAClientMayChoose(): void
    {
        $container = (new \DI\ContainerBuilder())->build();
        $this->assertSame([
            'closeOrder' => 'Boolean',
            'comments' => 'String',
            'date' => 'Date!',
            'dueDate' => 'Date',
            'freight' => 'Float',
            'lines' => '[DeliveryLineCreateInput!]',
            'locationId' => 'ID',
            'orderId' => 'ID!',
            'orderVersion' => 'Int!',
            'reference' => 'String',
            'shipperId' => 'ID',
        ], $this->fieldTypes($container->get(DeliveryCreateInput::class)));
    }

    public function testALineNamesTheOrderLineAndAQuantity(): void
    {
        $container = (new \DI\ContainerBuilder())->build();
        $this->assertSame([
            'description' => 'String',
            'orderLineId' => 'ID!',
            'quantity' => 'Float!',
        ], $this->fieldTypes($container->get(DeliveryLineCreateInput::class)));
    }

    public function testCloseOrderDefaultsToFalse(): void
    {
        $container = (new \DI\ContainerBuilder())->build();
        $this->assertFalse($container->get(DeliveryCreateInput::class)->getField('closeOrder')->defaultValue);
    }
}
