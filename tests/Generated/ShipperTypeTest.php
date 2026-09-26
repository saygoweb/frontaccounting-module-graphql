<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Shipper\ShipperType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class ShipperTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return ShipperType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'shipper';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'name' => 'String',
            'phone' => 'String',
            'phone2' => 'String',
            'contact' => 'String',
            'address' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'phone' => 'phone 1',
            'phone2' => 'phone2 1',
            'contact' => 'contact 1',
            'address' => 'address 1',
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsShipperComesBack(): void
    {
        // en_US-demo: (1, 'Default', '', '', '', '', 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Default', $byId['1']['name']);
        $this->assertFalse($byId['1']['inactive']);
    }
}
