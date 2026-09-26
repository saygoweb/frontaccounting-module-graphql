<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Tests\Support\ReportFiles;
use PHPUnit\Framework\TestCase;

/**
 * invoiceEmail over the real endpoint: the rep107 child started from Apache's PHP,
 * whose PHP_BINARY is not a PHP CLI. Demo invoice 1 belongs to customer 1, whose
 * contact has no email address, so nothing is sent and nothing is written but the
 * report's PDF, which FrontAccounting leaves behind and this test removes.
 */
class InvoiceEmailEndpointTest extends TestCase
{
    use GraphQLClient;

    private const MUTATION = 'mutation ($ids: [ID!]!) { invoiceEmail(id: $ids) { id sent recipient messages } }';

    public function testTheReportChildRunsFromTheWebServer(): void
    {
        // phpunit runs in the web server's container: the FA tree is two levels up.
        $faRoot = dirname(__DIR__, 4);
        $pdfBefore = ReportFiles::files($faRoot);
        try {
            $response = $this->gql(self::MUTATION, ['ids' => ['1']], $this->login()['accessToken']);
        } finally {
            ReportFiles::deleteNew($faRoot, $pdfBefore);
        }

        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $result = $response['body']['data']['invoiceEmail'][0];
        $this->assertSame('1', $result['id']);
        $this->assertFalse($result['sent']);
        $this->assertNull($result['recipient']);
        $this->assertStringContainsString('no email contact', implode("\n", $result['messages']), $response['raw']);
    }

    public function testARoleWithoutTheInvoiceAreasIsForbidden(): void
    {
        $response = $this->gql(self::MUTATION, ['ids' => ['1']], $this->login('apiorders')['accessToken']);

        $this->assertSame('FORBIDDEN', $response['body']['errors'][0]['extensions']['code'] ?? null, $response['raw']);
    }
}
