<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\DeliveryLine\DeliveryLineCreateInput;
use FA\GraphQL\Type\DeliveryLine\DeliveryLineType;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase. The Type is
 * read-only (bin/generate: READONLY), so inputClass() is null and the inherited
 * lifecycle writes nothing; its create Input is input-only, nested in the delivery's,
 * and checked here, with the en_US demo's delivery lines.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeliveryLineTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return DeliveryLineType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'deliveryLine';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'deliveryId' => 'ID',
            'transType' => 'Int',
            'stockId' => 'ID',
            'description' => 'String',
            'unitPrice' => 'Float',
            'unitTax' => 'Float',
            'quantity' => 'Float',
            'discountPercent' => 'Float',
            'standardCost' => 'Float',
            'qtyInvoiced' => 'Float',
            'orderLineId' => 'ID',
        ];
    }

    /**
     * A line Input names the order line and the quantity; a description is optional.
     */
    public function testTheLineInputIsTheOrderLineAndAQuantity(): void
    {
        $actual = [];
        foreach ($this->container->get(DeliveryLineCreateInput::class)->getFields() as $name => $field) {
            $actual[$name] = (string) $field->getType();
        }
        $expected = [];
        foreach ($this->expectedFieldTypes() as $name => $type) {
            if ($name === 'id' || in_array($name, DeliveryLineCreateInput::SERVER_SET, true)) {
                continue;
            }
            $bare = rtrim($type, '!');
            $expected[$name] = in_array($name, ['orderLineId', 'quantity'], true) ? $bare . '!' : $bare;
        }
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    /**
     * en_US demo: delivery 1 delivered order 1's lines 1 and 2, fully invoiced.
     * debtor_trans_details also holds invoice lines; only deliveries' are listed.
     */
    public function testTheDemoDeliveryLines(): void
    {
        $this->useDatabase();
        $this->assertSame(
            [
                [
                    'id' => '1', 'deliveryId' => '1', 'transType' => 13, 'stockId' => '101',
                    'quantity' => 20.0, 'qtyInvoiced' => 20.0, 'orderLineId' => '1',
                ],
                [
                    'id' => '2', 'deliveryId' => '1', 'transType' => 13, 'stockId' => '301',
                    'quantity' => 3.0, 'qtyInvoiced' => 3.0, 'orderLineId' => '2',
                ],
            ],
            $this->execute(
                'query ($q: MangoInput) {
                    deliveryLineList(query: $q) { id deliveryId transType stockId quantity qtyInvoiced orderLineId }
                }',
                ['q' => ['selector' => json_encode(['deliveryId' => 1]), 'sort' => ['id']]]
            )['deliveryLineList']
        );
        foreach ($this->execute('{ deliveryLineList { transType } }')['deliveryLineList'] as $row) {
            $this->assertSame(13, $row['transType']);
        }
    }
}
