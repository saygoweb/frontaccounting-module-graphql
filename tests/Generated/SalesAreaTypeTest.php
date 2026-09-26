<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\SalesArea\SalesAreaType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class SalesAreaTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return SalesAreaType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'salesArea';
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
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsSalesAreaComesBack(): void
    {
        // en_US-demo: (1, 'Global', 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Global', $byId['1']['name']);
        $this->assertFalse($byId['1']['inactive']);
    }
}
