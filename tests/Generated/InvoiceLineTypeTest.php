<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\InvoiceLine\InvoiceLineType;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase. The Type is
 * read-only (bin/generate: READONLY), so inputClass() is null and the inherited
 * lifecycle writes nothing; checked here with the en_US demo's invoice lines.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InvoiceLineTypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return InvoiceLineType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'invoiceLine';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'invoiceId' => 'ID',
            'transType' => 'Int',
            'deliveryLineId' => 'ID',
            'stockId' => 'ID',
            'description' => 'String',
            'unitPrice' => 'Float',
            'unitTax' => 'Float',
            'quantity' => 'Float',
            'discountPercent' => 'Float',
            'standardCost' => 'Float',
            'qtyCredited' => 'Float',
        ];
    }

    /**
     * en_US demo: invoice 1 invoiced delivery 1's lines 1 and 2, nothing credited.
     * debtor_trans_details also holds delivery lines; only invoices' are listed.
     */
    public function testTheDemoInvoiceLines(): void
    {
        $this->useDatabase();
        $this->assertSame(
            [
                [
                    'id' => '3', 'invoiceId' => '1', 'transType' => 10, 'deliveryLineId' => '1', 'stockId' => '101',
                    'quantity' => 20.0, 'qtyCredited' => 0.0,
                ],
                [
                    'id' => '4', 'invoiceId' => '1', 'transType' => 10, 'deliveryLineId' => '2', 'stockId' => '301',
                    'quantity' => 3.0, 'qtyCredited' => 0.0,
                ],
            ],
            $this->execute(
                'query ($q: MangoInput) {
                    invoiceLineList(query: $q) { id invoiceId transType deliveryLineId stockId quantity qtyCredited }
                }',
                ['q' => ['selector' => json_encode(['invoiceId' => 1]), 'sort' => ['id']]]
            )['invoiceLineList']
        );
        foreach ($this->execute('{ invoiceLineList { transType } }')['invoiceLineList'] as $row) {
            $this->assertSame(10, $row['transType']);
        }
    }
}
