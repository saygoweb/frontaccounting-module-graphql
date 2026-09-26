<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\PaymentTerms\PaymentTermsType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class PaymentTermsTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return PaymentTermsType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'paymentTerms';
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
            'daysBeforeDue' => 'Int',
            'dayInFollowingMonth' => 'Int',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'name 1',
            'daysBeforeDue' => 1,
            'dayInFollowingMonth' => 1,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'name' => 'name 2',
        ];
    }

    public function testTheDatasetsPaymentTermsComeBackTyped(): void
    {
        // en_US-demo: (1, 'Due 15th Of the Following Month', 0, 17, 0), (4, 'Cash Only', 0, 0, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Due 15th Of the Following Month', $byId['1']['name']);
        $this->assertSame(17, $byId['1']['dayInFollowingMonth']);
        $this->assertSame(0, $byId['4']['daysBeforeDue']);
        $this->assertFalse($byId['4']['inactive']);
    }
}
