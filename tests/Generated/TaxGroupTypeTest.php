<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\TaxGroup\TaxGroupType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class TaxGroupTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return TaxGroupType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'taxGroup';
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

    public function testTheDatasetsTaxGroupsComeBack(): void
    {
        // en_US-demo: (1, 'Tax', 0), (2, 'Tax Exempt', 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Tax', $byId['1']['name']);
        $this->assertSame('Tax Exempt', $byId['2']['name']);
        $this->assertFalse($byId['2']['inactive']);
    }
}
