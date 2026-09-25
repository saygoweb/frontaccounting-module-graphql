<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Config;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Tests\Unit\Http\ApplicationTestCase;

/**
 * Spec §3.6 (revised): one company per request. Mutation fields run one after
 * another in one request and share one container, so a field that switches
 * company would reach the second company through the first company's \PDO — the
 * Checkpoint D attack, where `tokenRefresh("1.<company-0 secret>")` after any
 * field that built the PDO signed in to company 1 without its password.
 *
 * Company 1 here is SecondCompany: company 0's database under the same prefix,
 * in this process only — the shape that made the attack work (FrontAccounting
 * lets two companies' databases share a prefix). Nothing on disk changes.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class OneCompanyPerRequestTest extends ApplicationTestCase
{
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $root = Bootstrap::defaultRoot();
        if (!is_file($root . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting here; run in the docker stack.');
        }
        parent::setUp();
        Bootstrap::boot($root);
        SecondCompany::install($root);
    }

    protected function tearDown(): void
    {
        SecondCompany::uninstall();
        if (isset($GLOBALS['db_connections'][0])) {
            $this->db()->exec(
                'DELETE t FROM 0_graphql_refresh_token t JOIN 0_users u ON u.id = t.user_id'
                . " WHERE u.user_id = 'apitest' AND t.client = 'phpunit 127.0.0.1'"
            );
        }
    }

    private function db(): \PDO
    {
        if ($this->db === null) {
            $c = $GLOBALS['db_connections'][0];
            $this->db = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->db;
    }

    /**
     * A live company-0 refresh token for apitest, straight into the table.
     */
    private function companyZeroSecret(): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db()->prepare(
            'INSERT INTO 0_graphql_refresh_token (user_id, token_hash, issued_at, expires_at, client)'
            . " SELECT id, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 1 DAY, 'phpunit 127.0.0.1'"
            . " FROM 0_users WHERE user_id = 'apitest'"
        )->execute([hash('sha256', $secret)]);

        return $secret;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokenRows(): array
    {
        return $this->db()->query(
            'SELECT t.id, t.revoked_at FROM 0_graphql_refresh_token t JOIN 0_users u ON u.id = t.user_id'
            . " WHERE u.user_id = 'apitest' ORDER BY t.id"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $headers = []): array
    {
        $this->createApp(
            new FaSession(Config::fromArray(['secret' => self::SECRET])),
            ['allow_insecure_login' => true]
        );
        $response = $this->request('POST', '/', (string) json_encode(['query' => $query]), $headers);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertRefused(array $body, string $alias): void
    {
        $codes = [];
        foreach ($body['errors'] ?? [] as $error) {
            if (($error['path'][0] ?? null) === $alias) {
                $codes[] = $error['extensions']['code'] ?? null;
            }
        }
        $this->assertSame(['UNAUTHENTICATED'], $codes, (string) json_encode($body));
        $this->assertStringNotContainsString('accessToken', (string) json_encode($body['data'] ?? null));
    }

    public function testATokenSessionCannotRefreshIntoAnotherCompany(): void
    {
        $secret = $this->companyZeroSecret();
        $before = $this->tokenRows();

        $body = $this->graphql(
            'mutation { warm: tokenRevoke(refreshToken: "x")'
            . ' hop: tokenRefresh(refreshToken: "1.' . $secret . '") { accessToken refreshToken } }',
            ['Authorization' => 'Bearer ' . $this->token(0, 'apitest')]
        );

        $this->assertRefused($body, 'hop');
        $this->assertSame($before, $this->tokenRows(), 'the company-0 token was rotated or another issued');
    }

    public function testATokenSessionCannotLogInToAnotherCompany(): void
    {
        $before = $this->tokenRows();
        $body = $this->graphql(
            'mutation { hop: login(company: 1, user: "apitest", password: "password") { accessToken refreshToken } }',
            ['Authorization' => 'Bearer ' . $this->token(0, 'apitest')]
        );

        $this->assertRefused($body, 'hop');
        $this->assertSame($before, $this->tokenRows(), 'a refresh token was issued');
    }

    public function testASecondLoginInOneRequestCannotChangeCompany(): void
    {
        $before = count($this->tokenRows());
        $body = $this->graphql(
            'mutation { first: login(company: 0, user: "apitest", password: "password") { refreshToken }'
            . ' hop: login(company: 1, user: "apitest", password: "password") { accessToken refreshToken } }'
        );

        $this->assertRefused($body, 'hop');
        $this->assertCount($before + 1, $this->tokenRows(), 'only the company-0 login may issue a token');
    }

    public function testReopeningTheSameCompanyIsAllowed(): void
    {
        $session = new FaSession(Config::fromArray(['secret' => self::SECRET]));
        $session->openCompany(0);
        $session->openCompany(0);

        $this->assertSame(0, CompanyContext::company());
    }

    public function testFaSessionRefusesASecondCompany(): void
    {
        $session = new FaSession(Config::fromArray(['secret' => self::SECRET]));
        $session->openCompany(0);

        try {
            $session->openCompany(1);
            $this->fail('a second company was opened');
        } catch (Unauthenticated $e) {
            $this->assertSame(0, CompanyContext::company());
        }
    }

    /**
     * Belt and braces: even if something moved CompanyContext without going
     * through FaSession, the container's PDO refuses to run a statement for any
     * company but the one it was built for.
     */
    public function testThePdoRefusesToServeAnotherCompany(): void
    {
        $factory = require dirname(__DIR__, 2) . '/container.php';
        $container = $factory(Config::fromArray(['secret' => self::SECRET]), new \FA\GraphQL\RequestInfo(false, 'x'));
        (new FaSession(Config::fromArray(['secret' => self::SECRET])))->openCompany(0);
        $pdo = $container->get(\PDO::class);
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());

        CompanyContext::set(1, $GLOBALS['db_connections'][1]);

        foreach (
            [
                'prepare' => function () use ($pdo) {
                    $pdo->prepare('SELECT 1');
                },
                'query' => function () use ($pdo) {
                    $pdo->query('SELECT 1');
                },
                'exec' => function () use ($pdo) {
                    $pdo->exec('DO 1');
                },
                'beginTransaction' => function () use ($pdo) {
                    $pdo->beginTransaction();
                },
                'Connection::current' => function () {
                    Connection::current()->prepare('SELECT 1');
                },
            ] as $name => $use
        ) {
            try {
                $use();
                $this->fail($name . ' ran on company 0\'s connection for company 1');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('company', $e->getMessage(), $name);
            }
        }
    }
}
