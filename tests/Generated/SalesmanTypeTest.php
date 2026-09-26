<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Salesman\SalesmanType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class SalesmanTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return SalesmanType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'salesman';
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
            'fax' => 'String',
            'email' => 'String',
            'provision' => 'Float',
            'breakPoint' => 'Float',
            'provision2' => 'Float',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'phone' => 'phone 1',
            'fax' => 'fax 1',
            'email' => 'email 1',
            'provision' => 1.5,
            'breakPoint' => 1.5,
            'provision2' => 1.5,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsSalespersonComesBackTyped(): void
    {
        // en_US-demo: (1, 'Sales Person', '', '', '', 5, 1000, 4, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Sales Person', $byId['1']['name']);
        $this->assertSame(5.0, $byId['1']['provision']);
        $this->assertSame(1000.0, $byId['1']['breakPoint']);
        $this->assertSame(4.0, $byId['1']['provision2']);
    }
}
