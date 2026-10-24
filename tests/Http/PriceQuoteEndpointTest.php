<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * priceQuote over the endpoint, with the Panel's own document (Items and prices
 * spec §3.1), as a role that only takes orders (SA_SALESORDER).
 */
class PriceQuoteEndpointTest extends TestCase
{
    use GraphQLClient;

    private const PANEL_DOCUMENT = 'query priceQuote($stockId: String!, $customerId: ID) '
        . '{ priceQuote(stockId: $stockId, customerId: $customerId) { stockId price currency } }';

    public function testThePanelsDocumentQuotesACustomer(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        $response = $this->gql(self::PANEL_DOCUMENT, ['stockId' => '101', 'customerId' => '1'], $token);

        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $quote = $response['body']['data']['priceQuote'];
        $this->assertSame('101', $quote['stockId']);
        $this->assertSame('USD', $quote['currency']);
        $this->assertEquals(300, $quote['price']);
    }

    public function testAnUnpricedItemIsNull(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        $response = $this->gql(self::PANEL_DOCUMENT, ['stockId' => '202', 'customerId' => '1'], $token);

        $this->assertNull($response['body']['data']['priceQuote']['price'], $response['raw']);
    }

    public function testAnUnknownItemIsNotFound(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        $response = $this->gql(self::PANEL_DOCUMENT, ['stockId' => 'NOPE', 'customerId' => '1'], $token);

        $this->assertSame('NOT_FOUND', $response['body']['errors'][0]['extensions']['code'] ?? null, $response['raw']);
    }
}
