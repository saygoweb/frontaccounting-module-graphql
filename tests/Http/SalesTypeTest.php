<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

class SalesTypeTest extends TestCase
{
    use GraphQLClient;

    private const LIST = '{ salesTypeList { id } }';

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    public function testApitestListsSalesTypes(): void
    {
        $response = $this->gql(self::LIST, [], $this->login()['accessToken']);

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $this->assertNotEmpty($response['body']['data']['salesTypeList']);
        $this->assertNotEmpty($response['body']['data']['salesTypeList'][0]['id']);
    }

    public function testAMangoSelectorFilters(): void
    {
        $token = $this->login()['accessToken'];
        $all = $this->gql(self::LIST, [], $token)['body']['data']['salesTypeList'];
        $id = $all[0]['id'];

        $one = $this->gql(
            'query ($q: MangoInput) { salesTypeList(query: $q) { id } }',
            ['q' => ['selector' => json_encode(['id' => (int) $id])]],
            $token
        );

        $this->assertSame([['id' => $id]], $one['body']['data']['salesTypeList'], $one['raw']);
    }

    public function testNoTokenIsUnauthenticated(): void
    {
        $response = $this->gql(self::LIST);

        $this->assertSame('UNAUTHENTICATED', $this->code($response));
        $this->assertNull($response['body']['data'] ?? null);
    }

    /**
     * noapi's role lacks SA_GRAPHQL, so it cannot get a token by login; a token
     * minted with the stack's secret gets as far as the session gate, which says 403.
     * That is the outer gate. The inner one — Guard::requireFor through
     * FaModelType — is proven by FaModelType's unit tests (Task 10) and the
     * generated SalesTypeTypeTest (Task 11); here the client-visible answer is
     * what counts.
     */
    public function testNoapiIsForbidden(): void
    {
        $values = require dirname(__DIR__, 2) . '/config_graphql.php';
        $token = (new \FA\GraphQL\Auth\TokenService(
            \FA\GraphQL\Config::fromArray($values),
            new \Lcobucci\Clock\FrozenClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
        ))->issueAccess(0, 'noapi');

        $response = $this->gql(self::LIST, [], $token);

        $this->assertSame(403, $response['status']);
        $this->assertSame('FORBIDDEN', $this->code($response));
    }
}
