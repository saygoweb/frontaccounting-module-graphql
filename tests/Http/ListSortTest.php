<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Sorting a list over the real endpoint, on FrontAccounting's demo invoices
 * (saygoweb/frontaccounting-module-graphql#14 and #15).
 */
class ListSortTest extends TestCase
{
    use GraphQLClient;

    private const INVOICES = 'query ($q: MangoInput) { invoiceList(query: $q) { id date } }';

    /** @var string|null */
    private static $token = null;

    private function token(): string
    {
        if (self::$token === null) {
            self::$token = $this->login()['accessToken'];
        }

        return self::$token;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function invoices(array $query): array
    {
        return $this->gql(self::INVOICES, ['q' => $query], $this->token());
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $query): array
    {
        $response = $this->invoices($query);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);

        return $response['body']['data']['invoiceList'];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function keys(array $rows): array
    {
        return array_map(function (array $row): string {
            return $row['date'] . '#' . str_pad((string) $row['id'], 10, '0', STR_PAD_LEFT);
        }, $rows);
    }

    public function testInvoiceListSortsByDateDescending(): void
    {
        $ascending = $this->rows(['sort' => ['date', 'id']]);
        $this->assertGreaterThan(2, count($ascending), 'the demo data has invoices on more than one date');
        $this->assertNotSame($ascending[0]['date'], end($ascending)['date'], 'the demo invoices span several dates');
        $newestFirst = array_reverse($ascending);

        $this->assertSame($newestFirst, $this->rows(['sort' => ['-date', '-id']]));
        $this->assertSame($newestFirst, $this->rows(['sort' => ['date desc', 'id DESC']]));
        $this->assertSame($ascending, $this->rows(['sort' => ['date asc', 'id asc']]));

        $keys = $this->keys($this->rows(['sort' => ['-date', '-id']]));
        $sorted = $keys;
        rsort($sorted);
        $this->assertSame($sorted, $keys, 'newest first by date, then id');

        $this->assertSame(array_slice($newestFirst, 0, 2), $this->rows(['sort' => ['-date', '-id'], 'limit' => 2]));
    }

    /**
     * @dataProvider badSortItems
     */
    public function testABadSortItemIsACodedClientError(string $item, string $says): void
    {
        $response = $this->invoices(['sort' => [$item], 'limit' => 1]);

        $this->assertSame(200, $response['status'], $response['raw']);
        $error = $response['body']['errors'][0] ?? [];
        $this->assertSame('BAD_INPUT', $error['extensions']['code'] ?? null, $response['raw']);
        $this->assertStringContainsString("Argument 'query.sort' item '$item'", $error['message']);
        $this->assertStringContainsString($says, $error['message']);
        $this->assertMatchesRegularExpression('/sortable fields are: .*\bdate\b/', $error['message']);
        $this->assertNull($response['body']['data']['invoiceList'] ?? null);
    }

    /** @return array<string, array<int, string>> */
    public function badSortItems(): array
    {
        return [
            'an unknown field' => ['noSuchField', "unknown field 'noSuchField'"],
            'a column name, not the field' => ['-tran_date', "unknown field 'tran_date'"],
            'a bad direction' => ['date sideways', "unknown direction 'sideways'"],
        ];
    }
}
