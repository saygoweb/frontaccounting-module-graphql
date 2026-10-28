<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\BankTransaction\BankTransactionType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class BankTransactionTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return BankTransactionType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'bankTransaction';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'transType' => 'Int',
            'transNo' => 'Int',
            'bankAccountId' => 'ID',
            'reference' => 'String',
            'date' => 'Date',
            'amount' => 'Float',
            'reconciled' => 'Date',
            'personType' => 'Int',
            'personId' => 'String',
            'voided' => 'Boolean!',
        ];
    }

    protected function sampleInput(): array
    {
        // Foreign keys are left out: the generator cannot know a valid parent row.
        // If any is required, create the parent here and add: bankAccountId, personId
        return [
            'transType' => 1,
            'transNo' => 1,
            'reference' => 'reference 1',
            'date' => '2026-01-01',
            'amount' => 1.5,
            'reconciled' => '2026-01-01',
            'personType' => 1,
            'voided' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'transType' => 2,
        ];
    }
}
