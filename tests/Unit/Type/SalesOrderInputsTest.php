<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineCreateInput;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineUpdateInput;
use PHPUnit\Framework\TestCase;

/**
 * The generated Inputs, trimmed and extended in their once-only files
 * (Release 2 spec section 4.4).
 */
class SalesOrderInputsTest extends TestCase
{
    /**
     * @return array<string, string> field => type as printed
     */
    private function fieldsOf(object $input): array
    {
        $fields = [];
        foreach ($input->getFields() as $name => $field) {
            $fields[$name] = (string) $field->getType();
        }

        return $fields;
    }

    public function testUpdateNeedsTheIdAndTheVersionAndTakesAnOptionalLineSet(): void
    {
        $fields = $this->fieldsOf(new SalesOrderUpdateInput(new SalesOrderLineUpdateInput()));

        $this->assertSame('ID!', $fields['id']);
        $this->assertSame('Int!', $fields['version']);
        $this->assertSame('[SalesOrderLineUpdateInput!]', $fields['lines']);
        $this->assertSame('ID', $fields['customerId'], 'a patch: nothing else is required');
        foreach (SalesOrderUpdateInput::SERVER_SET as $name) {
            $this->assertArrayNotHasKey($name, $fields);
        }
    }

    public function testAnUpdatedLineMayOmitItsIdToBeNew(): void
    {
        $fields = $this->fieldsOf(new SalesOrderLineUpdateInput());

        $this->assertSame('ID', $fields['id']);
        $this->assertArrayHasKey('quantity', $fields);
        foreach (SalesOrderLineUpdateInput::SERVER_SET as $name) {
            $this->assertArrayNotHasKey($name, $fields);
        }
    }

    public function testCreateStillRequiresItsFieldsAndLines(): void
    {
        $fields = $this->fieldsOf(new SalesOrderCreateInput(new SalesOrderLineCreateInput()));

        $this->assertSame('ID!', $fields['customerId']);
        $this->assertSame('[SalesOrderLineCreateInput!]!', $fields['lines']);
        $this->assertArrayNotHasKey('version', $fields);
        $this->assertArrayNotHasKey('id', $fields);
    }
}
