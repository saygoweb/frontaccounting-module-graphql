<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Tests\Support\FaTestRows;
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

    private ?string $prefix = null;

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function tearDown(): void
    {
        $prefix = $this->prefix;
        parent::tearDown();
        if ($prefix !== null) {
            FaTestRows::sweepItems(FaTestRows::connect(), $prefix);
        }
    }

    public function testLifecycle(): void
    {
        // Not the inherited lifecycle: it creates sampleInput() twice and a price
        // list name is unique. Lists are written by FrontAccounting and swept by prefix.
        $prefix = $this->testRowPrefix();
        $before = count($this->listAll());

        $created = $this->create([
            $this->sampleInput(),
            array_merge($this->sampleInput(), ['name' => $prefix . ' b']),
        ]);
        $this->assertCount(2, $created);
        foreach ($this->sampleInput() as $name => $value) {
            $this->assertEquals($value, $created[0][$name], "created $name");
        }
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $this->assertCount(1, $this->listWhere(['id' => (int) $id]));

        $updated = $this->update([['id' => $id] + $this->sampleUpdate()]);
        $this->assertCount(1, $updated);
        $this->assertSame($prefix . ' renamed', $updated[0]['name']);
        $this->assertEquals(1.5, $updated[0]['factor'], 'an update keeps what it does not name');
        $this->assertCount($before + 2, $this->listAll());
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
            'name' => $this->testRowPrefix() . ' a',
            'taxIncluded' => true,
            'factor' => 1.5,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => $this->testRowPrefix() . ' renamed',
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
