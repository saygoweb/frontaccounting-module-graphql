<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Location\LocationType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class LocationTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return LocationType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'location';
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
            'deliveryAddress' => 'String',
            'phone' => 'String',
            'phone2' => 'String',
            'fax' => 'String',
            'email' => 'String',
            'contact' => 'String',
            'fixedAsset' => 'Boolean',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'deliveryAddress' => 'deliveryAddress 1',
            'phone' => 'phone 1',
            'phone2' => 'phone2 1',
            'fax' => 'fax 1',
            'email' => 'email 1',
            'contact' => 'contact 1',
            'fixedAsset' => true,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsLocationComesBackByItsCode(): void
    {
        // en_US-demo: ('DEF', 'Default', 'N/A', '', '', '', '', '', 0, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Default', $byId['DEF']['name']);
        $this->assertSame('N/A', $byId['DEF']['deliveryAddress']);
        $this->assertFalse($byId['DEF']['fixedAsset']);
    }
}
