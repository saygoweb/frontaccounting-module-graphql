<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Allocation\AllocationType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 * Read-only (bin/generate: READONLY): allocations are written by FrontAccounting's
 * allocation cart through customerPaymentCreate and customerPaymentUpdate.
 */
class AllocationTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return AllocationType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'allocation';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'customerId' => 'ID',
            'amount' => 'Float',
            'date' => 'Date',
            'fromType' => 'Int',
            'fromId' => 'ID',
            'toType' => 'Int',
            'toId' => 'ID',
        ];
    }

    protected function sampleInput(): array
    {
        // Foreign keys are left out: the generator cannot know a valid parent row.
        // If any is required, create the parent here and add: customerId, fromId, toId
        return [
            'amount' => 1.5,
            'date' => '2026-01-01',
            'fromType' => 1,
            'toType' => 1,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'amount' => 2.5,
        ];
    }

    public function testTheDatasetsAllocationsComeBack(): void
    {
        // en_US-demo: allocation 1 applies payment 1 (type 12) to invoice 1 (type 10), 6240.
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('1', $byId['1']['customerId']);
        $this->assertSame(6240.0, $byId['1']['amount']);
        $this->assertSame('2021-05-10', $byId['1']['date']);
        $this->assertSame(12, $byId['1']['fromType']);
        $this->assertSame('1', $byId['1']['fromId']);
        $this->assertSame(10, $byId['1']['toType']);
        $this->assertSame('1', $byId['1']['toId']);
    }
}
