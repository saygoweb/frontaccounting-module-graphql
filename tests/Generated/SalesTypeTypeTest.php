<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\SalesType\SalesTypeCreateInput;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use FA\GraphQL\Type\SalesType\SalesTypeUpdateInput;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesTypeTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return SalesTypeType::class;
    }

    protected function inputClass(): ?string
    {
        return SalesTypeCreateInput::class;
    }

    protected function updateInputClass(): ?string
    {
        return SalesTypeUpdateInput::class;
    }

    protected function requiredFields(): array
    {
        return ['name'];
    }

    protected function hasDeleteMutation(): bool
    {
        return false;
    }

    public function testLifecycle(): void
    {
        // Until SalesTypeService (Release B, Task 2) the writes answer FORBIDDEN; its
        // own test takes over the lifecycle.
        $this->markTestSkipped('SalesTypeServiceTest covers the lifecycle.');
    }

    protected function entityName(): string
    {
        return 'salesType';
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
            'taxIncluded' => 'Boolean',
            'factor' => 'Float',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'taxIncluded' => true,
            'factor' => 1.5,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsSalesTypesComeBackTyped(): void
    {
        // en_US-demo: ('1', 'Retail', '1', '1', '0'), ('2', 'Wholesale', '0', '0.7', '0').
        $this->useDatabase();
        $byName = array_column($this->listAll(), null, 'name');

        $this->assertTrue($byName['Retail']['taxIncluded']);
        $this->assertFalse($byName['Wholesale']['taxIncluded']);
        $this->assertSame(0.7, $byName['Wholesale']['factor']);
        $this->assertFalse($byName['Retail']['inactive']);
    }

    public function testOnlyTheCompanysBaseListIsBase(): void
    {
        // en_US-demo: 1 Retail is base_sales, 2 Wholesale is not.
        $this->useDatabase();
        $rows = $this->execute('{ salesTypeList { id isBase } }')['salesTypeList'];
        $byId = array_column($rows, 'isBase', 'id');

        $this->assertTrue($byId['1']);
        $this->assertFalse($byId['2']);
    }
}
