<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\BankAccount\BankAccountType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class BankAccountTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return BankAccountType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'bankAccount';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'glAccountId' => 'ID',
            'accountType' => 'Int',
            'name' => 'String',
            'number' => 'String',
            'bankName' => 'String',
            'bankAddress' => 'String',
            'currencyId' => 'ID',
            'defaultForCurrency' => 'Boolean',
            'chargeAccountId' => 'ID',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        // Foreign keys are left out: the generator cannot know a valid parent row.
        // If any is required, create the parent here and add: glAccountId, currencyId, chargeAccountId
        return [
            'accountType' => 1,
            'name' => 'name 1',
            'number' => 'number 1',
            'bankName' => 'bankName 1',
            'bankAddress' => 'bankAddress 1',
            'defaultForCurrency' => true,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'accountType' => 2,
        ];
    }

    public function testTheDatasetsBankAccountsComeBack(): void
    {
        // en_US-demo: 1 'Current account' (1060, USD, default), 2 'Petty Cash account' (1065, cash).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Current account', $byId['1']['name']);
        $this->assertSame('1060', $byId['1']['glAccountId']);
        $this->assertSame('USD', $byId['1']['currencyId']);
        $this->assertTrue($byId['1']['defaultForCurrency']);
        $this->assertSame(3, $byId['2']['accountType']);
    }
}
