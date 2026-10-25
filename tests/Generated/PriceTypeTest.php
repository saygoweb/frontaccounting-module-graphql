<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Price\PriceType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class PriceTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return PriceType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'price';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'stockId' => 'String',
            'salesTypeId' => 'ID',
            'currency' => 'String',
            'price' => 'Float',
        ];
    }

    protected function sampleInput(): array
    {
        // Foreign keys are left out: the generator cannot know a valid parent row.
        // If any is required, create the parent here and add: stockId, salesTypeId
        return [
            'currency' => 'currency 1',
            'price' => 1.5,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'currency' => 'currency 2',
        ];
    }
}
