<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\CreditStatus\CreditStatusType;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase.
 */
class CreditStatusTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return CreditStatusType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'creditStatus';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'description' => 'String',
            'disallowInvoices' => 'Boolean',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'description' => 'description 1',
            'disallowInvoices' => true,
            'inactive' => true,
        ];
    }

    protected function sampleUpdate(): array
    {
        return [
            'description' => 'description 2',
        ];
    }

    public function testTheDatasetsCreditStatusesComeBackTyped(): void
    {
        // en_US-demo: (1, 'Good History', 0, 0), (3, 'No more work until payment received', 1, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Good History', $byId['1']['description']);
        $this->assertFalse($byId['1']['disallowInvoices']);
        $this->assertTrue($byId['3']['disallowInvoices']);
    }
}
