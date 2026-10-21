<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * <entity>Count beside every <entity>List, over the real endpoint on FrontAccounting's
 * demo data (saygoweb/frontaccounting-module-graphql#17): the number of rows the list
 * returns for the same query with no limit, under the same scope and authorisation.
 */
class ListCountTest extends TestCase
{
    use GraphQLClient;

    /** @var array<string, string> */
    private static $tokens = [];

    private function token(string $user = 'apitest'): string
    {
        if (!isset(self::$tokens[$user])) {
            self::$tokens[$user] = $this->login($user)['accessToken'];
        }

        return self::$tokens[$user];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed> data, after asserting there are no errors
     */
    private function data(string $document, ?array $query = null, string $user = 'apitest'): array
    {
        $response = $this->gql($document, $query === null ? [] : ['q' => $query], $this->token($user));
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);

        return $response['body']['data'];
    }

    /**
     * @param array<string, mixed>|null $query
     */
    private function counted(string $entity, ?array $query = null, string $user = 'apitest'): int
    {
        return $this->data("query (\$q: MangoInput) { {$entity}Count(query: \$q) }", $query, $user)[$entity . 'Count'];
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $entity, string $selection, ?array $query = null): array
    {
        $document = "query (\$q: MangoInput) { {$entity}List(query: \$q) $selection }";

        return $this->data($document, $query)[$entity . 'List'];
    }

    /**
     * The entities whose list takes a query, read from the schema the endpoint serves.
     *
     * @return array<int, string>
     */
    private function entities(): array
    {
        $fields = $this->data('{ __schema { queryType { fields { name args { name } } } } }')
            ['__schema']['queryType']['fields'];
        $entities = [];
        foreach ($fields as $field) {
            $takesAQuery = in_array('query', array_column($field['args'], 'name'), true);
            if (substr($field['name'], -4) === 'List' && $takesAQuery) {
                $entities[] = substr($field['name'], 0, -4);
            }
        }

        return $entities;
    }

    public function testEveryCountIsTheLengthOfItsUnlimitedList(): void
    {
        $entities = $this->entities();
        $this->assertContains('invoice', $entities);
        $this->assertContains('contact', $entities, 'contacts are narrowed by their own scope, beyond scope()');
        foreach ($entities as $entity) {
            $this->assertSame(
                count($this->rows($entity, '{ __typename }')),
                $this->counted($entity),
                "{$entity}Count"
            );
        }
    }

    public function testACountTakesTheListsSelector(): void
    {
        $invoices = $this->rows('invoice', '{ id date }', ['sort' => ['date', 'id']]);
        $this->assertGreaterThan(2, count($invoices), 'the demo data has several invoices');
        $earliest = $invoices[0]['date'];
        $this->assertNotSame($earliest, end($invoices)['date'], 'the demo invoices span several dates');
        $query = ['selector' => (string) json_encode(['date' => ['$gt' => $earliest]])];

        $matching = $this->rows('invoice', '{ id date }', $query);
        $this->assertNotEmpty($matching);
        $this->assertLessThan(count($invoices), count($matching), 'the selector leaves some invoices out');
        $this->assertSame(count($matching), $this->counted('invoice', $query));
        $this->assertSame(0, $this->counted('invoice', ['selector' => '{"id": "-1"}']));

        $contacts = $this->rows('contact', '{ id }');
        $this->assertNotEmpty($contacts, 'the demo data has contacts');
        $one = ['selector' => (string) json_encode(['id' => $contacts[0]['id']])];
        $this->assertSame(1, $this->counted('contact', $one));
    }

    /**
     * A client passes the list's query as it is: limit, skip and sort do not change the total.
     */
    public function testACountIgnoresLimitSkipAndSort(): void
    {
        $total = $this->counted('invoice');
        $this->assertGreaterThan(2, $total);
        $page = ['sort' => ['-date', '-id'], 'limit' => 2, 'skip' => 1];

        $this->assertCount(2, $this->rows('invoice', '{ id }', $page));
        $this->assertSame($total, $this->counted('invoice', $page));
        $this->assertSame($total, $this->counted('invoice', ['limit' => 1]));
    }

    /**
     * A count is read under the list's area: a role that cannot list customers cannot
     * count them either, and one that can list a lookup can count it.
     */
    public function testACountIsAuthorisedAsItsList(): void
    {
        foreach (['customerList { id }', 'customerCount'] as $field) {
            $response = $this->gql("{ $field }", [], $this->token('apiorders'));
            $this->assertSame(200, $response['status'], $response['raw']);
            $this->assertSame('FORBIDDEN', $this->code($response), "$field: " . $response['raw']);
            $this->assertNull($response['body']['data'] ?? null, $field);
        }

        $this->assertSame(
            count($this->data('{ paymentTermsList { id } }', null, 'apiorders')['paymentTermsList']),
            $this->counted('paymentTerms', null, 'apiorders')
        );
    }

    /**
     * @dataProvider badSelectors
     */
    public function testABadSelectorIsBadInput(string $selector, string $says): void
    {
        $response = $this->gql(
            'query ($q: MangoInput) { invoiceCount(query: $q) }',
            ['q' => ['selector' => $selector]],
            $this->token()
        );

        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame('BAD_INPUT', $this->code($response), $response['raw']);
        $this->assertStringContainsString($says, $response['body']['errors'][0]['message']);
        $this->assertNull($response['body']['data'] ?? null);
    }

    /** @return array<string, array<int, string>> */
    public function badSelectors(): array
    {
        return [
            'not JSON' => ['{not json', "Argument 'query.selector'"],
            'an unknown field' => ['{"tran_date": "2020-01-01"}', "unknown field 'tran_date'"],
            'an unknown operator' => ['{"date": {"$nope": 1}}', "Argument 'query.selector' is not valid"],
        ];
    }
}
