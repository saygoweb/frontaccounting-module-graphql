<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\StockItem\StockItemType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class StockItemTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return StockItemType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'stockItem';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'categoryId' => 'ID',
            'taxTypeId' => 'ID',
            'description' => 'String',
            'longDescription' => 'String',
            'units' => 'String',
            'mbFlag' => 'String',
            'editable' => 'Boolean',
            'noSale' => 'Boolean',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        // Foreign keys are left out: the generator cannot know a valid parent row.
        // If any is required, create the parent here and add: categoryId, taxTypeId
        return [
            'description' => 'description 1',
            'longDescription' => 'longDescription 1',
            'units' => 'units 1',
            'mbFlag' => 'mbFlag 1',
            'editable' => true,
            'noSale' => true,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'description' => 'description 2',
        ];
    }

    public function testTheDatasetsStockItemsComeBackByCode(): void
    {
        // en_US-demo: ('101', 1, 1, 'iPad Air 2 16GB', '', 'each', 'B', ...),
        //             ('201', 3, 1, 'AP Surf Set', '', 'each', 'M', ...).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('iPad Air 2 16GB', $byId['101']['description']);
        $this->assertSame('B', $byId['101']['mbFlag']);
        $this->assertSame('1', $byId['101']['categoryId']);
        $this->assertFalse($byId['101']['noSale']);
        $this->assertSame('M', $byId['201']['mbFlag']);
    }
}
