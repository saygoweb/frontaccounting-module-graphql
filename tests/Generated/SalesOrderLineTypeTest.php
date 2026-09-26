<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineCreateInput;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineUpdateInput;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase. The Type is
 * read-only (bin/generate: READONLY), so inputClass() is null and the inherited
 * lifecycle writes nothing; its Inputs are input-only, nested in the order's, and
 * checked here.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderLineTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return SalesOrderLineType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'salesOrderLine';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'orderId' => 'ID',
            'transType' => 'Int',
            'stockId' => 'ID',
            'description' => 'String',
            'qtyDelivered' => 'Float',
            'unitPrice' => 'Float',
            'quantity' => 'Float',
            'qtyInvoiced' => 'Float',
            'discountPercent' => 'Float',
        ];
    }

    /**
     * A line Input is the Type's fields less the key and SERVER_SET; stockId and
     * quantity are required to create one.
     */
    public function testTheLineInputsMirrorTheTypeWithoutWhatFrontAccountingSets(): void
    {
        $inputs = [SalesOrderLineCreateInput::class => true, SalesOrderLineUpdateInput::class => false];
        foreach ($inputs as $class => $isCreate) {
            $input = $this->container->get($class);
            $actual = [];
            foreach ($input->getFields() as $name => $field) {
                $actual[$name] = (string) $field->getType();
            }
            $expected = [];
            foreach ($this->expectedFieldTypes() as $name => $type) {
                if ($name === 'id' || in_array($name, SalesOrderLineCreateInput::SERVER_SET, true)) {
                    continue;
                }
                $bare = rtrim($type, '!');
                $expected[$name] = $isCreate && in_array($name, ['stockId', 'quantity'], true) ? $bare . '!' : $bare;
            }
            if (!$isCreate) {
                // An updated line names an existing line by id; without one it is new.
                $expected = ['id' => 'ID'] + $expected;
            }
            ksort($actual);
            ksort($expected);
            $this->assertSame($expected, $actual, $input->name);
        }
    }
}
