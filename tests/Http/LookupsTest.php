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

    private const LOOKUPS = [
        'paymentTermsList', 'taxGroupList', 'salesAreaList', 'salesmanList', 'locationList',
        'shipperList', 'creditStatusList', 'currencyList', 'stockItemList', 'salesTypeList',
    ];

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    /**
     * The lookups an order needs are readable by a role that only takes orders:
     * SA_SALESORDER, not FrontAccounting's setup areas (Release 2 spec §4.2).
     */
    public function testAnOrderRoleListsEveryLookup(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        foreach (self::LOOKUPS as $list) {
            $response = $this->gql("{ $list { id } }", [], $token);

            $this->assertSame(200, $response['status'], $list);
            $this->assertArrayNotHasKey('errors', $response['body'], "$list: " . $response['raw']);
            $this->assertNotEmpty($response['body']['data'][$list], "$list is empty on the demo data");
        }
    }

    public function testAnOrderRoleCannotReadCustomers(): void
    {
        $response = $this->gql('{ customerList { id } }', [], $this->login('apiorders')['accessToken']);

        $this->assertSame(200, $response['status']);
        $this->assertSame('FORBIDDEN', $this->code($response), $response['raw']);
    }

    /**
     * The lookups have no mutations.
     */
    public function testLookupsHaveNoMutations(): void
    {
        $response = $this->gql('{ __schema { mutationType { fields { name } } } }', [], $this->login()['accessToken']);
        $names = array_column($response['body']['data']['__schema']['mutationType']['fields'], 'name');

        $lookup = '/^(paymentTerms|taxGroup|salesArea|salesman|location|shipper|creditStatus|currency'
            . '|stockItem|salesType|salesOrderLine)(Create|Update|Delete|Upsert)$/';
        $this->assertSame([], array_values(preg_grep($lookup, $names)));
        foreach (['customer', 'branch', 'contact', 'salesOrder'] as $entity) {
            foreach (['Create', 'Update', 'Delete'] as $verb) {
                $this->assertContains($entity . $verb, $names);
            }
            $this->assertNotContains($entity . 'Upsert', $names, 'create-update generation has no Upsert');
        }
    }

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
