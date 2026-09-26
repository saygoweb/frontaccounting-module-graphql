<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Currency\CurrencyType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class CurrencyTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return CurrencyType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'currency';
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
            'symbol' => 'String',
            'country' => 'String',
            'hundredsName' => 'String',
            'autoUpdate' => 'Boolean',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'symbol' => 'symbol 1',
            'country' => 'country 1',
            'hundredsName' => 'hundredsName 1',
            'autoUpdate' => true,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsCurrenciesComeBackByCode(): void
    {
        // en_US-demo: ('US Dollars', 'USD', '$', 'United States', 'Cents', 1, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('US Dollars', $byId['USD']['name']);
        $this->assertSame('$', $byId['USD']['symbol']);
        $this->assertSame('Cents', $byId['USD']['hundredsName']);
        $this->assertTrue($byId['USD']['autoUpdate']);
    }
}
