<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Every lookup over the real endpoint, as apitest. The ids are rows of FrontAccounting's
 * en_US-demo dataset, which the stack loads.
 */
class LookupsTest extends TestCase
{
    use GraphQLClient;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function lookups(): array
    {
        return [
            'payment terms' => ['paymentTermsList', '4'],
            'tax groups' => ['taxGroupList', '1'],
            'sales areas' => ['salesAreaList', '1'],
            'salespeople' => ['salesmanList', '1'],
            'locations' => ['locationList', 'DEF'],
            'shippers' => ['shipperList', '1'],
            'credit statuses' => ['creditStatusList', '1'],
            'currencies' => ['currencyList', 'USD'],
            'stock items' => ['stockItemList', '101'],
            'sales types' => ['salesTypeList', '1'],
        ];
    }

    /**
     * @dataProvider lookups
     */
    public function testApitestListsIt(string $field, string $knownId): void
    {
        $response = $this->gql('{ ' . $field . ' { id } }', [], $this->login()['accessToken']);

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $this->assertContains($knownId, array_column($response['body']['data'][$field], 'id'), $response['raw']);
    }

    public function testAMangoSelectorOnAStringKeyFilters(): void
    {
        $response = $this->gql(
            'query ($q: MangoInput) { currencyList(query: $q) { id symbol } }',
            ['q' => ['selector' => json_encode(['id' => 'USD'])]],
            $this->login()['accessToken']
        );

        $this->assertSame(
            [['id' => 'USD', 'symbol' => '$']],
            $response['body']['data']['currencyList'],
            $response['raw']
        );
    }

    public function testNoTokenIsUnauthenticated(): void
    {
        $response = $this->gql('{ paymentTermsList { id } }');

        $this->assertSame('UNAUTHENTICATED', $response['body']['errors'][0]['extensions']['code'] ?? null);
    }
}
