<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Api\ExecutorAuthException;
use FA\GraphQL\Auth\AnormMachineTokenRepository;
use FA\GraphQL\Auth\MachineTokenRecord;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\RequestInfo;
use Psr\Container\ContainerInterface;

/**
 * The in-process API (contract 1.0) against company 0: the seeded users apitest
 * (SA_GRAPHQL) and noapi (no SA_GRAPHQL), both with the password "password".
 * tearDown deletes every token a test issued (by its label) and reactivates
 * apitest, pass or fail.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExecutorTest extends FaTestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    private string $label;

    protected function setUp(): void
    {
        parent::setUp();
        $this->label = 'executor-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->pdo()->prepare('DELETE FROM 0_graphql_machine_token WHERE label = ?')->execute([$this->label]);
        $this->setInactive('apitest', false);
        CompanyContext::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function configArray(): array
    {
        return ['secret' => self::SECRET];
    }

    private function executor(): Executor
    {
        return new Executor(Config::fromArray($this->configArray()));
    }

    private function login(): string
    {
        return 'apitest';
    }

    private function password(): string
    {
        return 'password';
    }

    private function setInactive(string $login, bool $inactive): void
    {
        $this->pdo()->prepare('UPDATE 0_users SET inactive = ? WHERE user_id = ?')
            ->execute([$inactive ? 1 : 0, $login]);
    }

    private function countOurs(): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_graphql_machine_token WHERE label = ?');
        $statement->execute([$this->label]);

        return (int) $statement->fetchColumn();
    }

    private function assertReason(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("expected $reason");
        } catch (ExecutorAuthException $e) {
            $this->assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }

    public function testIssuedTokenExecutesAsTheUser(): void
    {
        $e = $this->executor();
        $t = $e->issueToken(0, $this->login(), 'write', 600, $this->label);
        $out = $e->execute($t['token'], '{ me { login scope } }', [], '127.0.0.1');
        $this->assertSame($this->login(), $out['data']['me']['login']);
        $this->assertSame('write', $out['data']['me']['scope']);
        $this->assertSame(32, strlen($t['jti']));
        $this->assertGreaterThan(time(), $t['expiresAt']);
        $this->assertLessThanOrEqual(time() + 600, $t['expiresAt']);
        $this->assertSame(1, $this->countOurs());
    }

    public function testVariablesAndOperationNameReachTheDocument(): void
    {
        $e = $this->executor();
        $t = $e->issueToken(0, $this->login(), 'read', 600, $this->label);
        $out = $e->execute(
            $t['token'],
            'query A { me { login } } query B($n: Int!) { customerList(query: {limit: $n}) { id } }',
            ['n' => 1],
            '127.0.0.1',
            'B'
        );
        $this->assertArrayNotHasKey('errors', $out, (string) json_encode($out));
        $this->assertLessThanOrEqual(1, count($out['data']['customerList']));
    }

    public function testReadTokenMutationIsForbiddenEnvelope(): void
    {
        $e = $this->executor();
        $t = $e->issueToken(0, $this->login(), 'read', 600, $this->label);
        $out = $e->execute($t['token'], 'mutation { tokenRevoke }', [], '127.0.0.1');
        $this->assertSame('FORBIDDEN', $out['errors'][0]['extensions']['code']);
        $this->assertFalse($e->tokenIsRevoked(0, $t['jti']), 'the mutation did not run');
    }

    public function testGarbageBearerIsUnauthenticated(): void
    {
        $this->expectExceptionObject(new ExecutorAuthException('UNAUTHENTICATED', 'The access token is malformed.'));
        $this->executor()->execute('nope', '{ me { login } }', [], '127.0.0.1');
    }

    public function testAnEmptyBearerIsUnauthenticated(): void
    {
        $this->expectExceptionObject(new ExecutorAuthException('UNAUTHENTICATED', 'A bearer token is required.'));
        $this->executor()->execute('  ', '{ me { login } }', [], '127.0.0.1');
    }

    public function testRevokedTokenIsUnauthenticatedAndReportedRevoked(): void
    {
        $e = $this->executor();
        $t = $e->issueToken(0, $this->login(), 'write', 600, $this->label);
        $this->assertFalse($e->tokenIsRevoked(0, $t['jti']));
        $this->assertTrue($e->revokeToken(0, $t['jti']));
        $this->assertTrue($e->tokenIsRevoked(0, $t['jti']));
        $this->assertFalse($e->revokeToken(0, $t['jti']));
        try {
            $e->execute($t['token'], '{ me { login } }', [], '127.0.0.1');
            $this->fail('expected UNAUTHENTICATED');
        } catch (ExecutorAuthException $x) {
            $this->assertSame('UNAUTHENTICATED', $x->getReason());
        }
    }

    public function testUnknownJtiCountsAsRevoked(): void
    {
        $this->assertTrue($this->executor()->tokenIsRevoked(0, str_repeat('0', 32)));
    }

    public function testExpiredButUnrevokedTokenIsNotRevoked(): void
    {
        // Inserted as AnormMachineTokenRepositoryTest does: the row expired an hour
        // ago and was never revoked.
        CompanyContext::set(0, $GLOBALS['db_connections'][0]);
        $utc = new \DateTimeZone('UTC');
        $record = new MachineTokenRecord();
        $record->jti = bin2hex(random_bytes(16));
        $record->login = $this->login();
        $record->label = $this->label;
        $record->issuedAt = new \DateTimeImmutable('-2 hours', $utc);
        $record->expiresAt = new \DateTimeImmutable('-1 hour', $utc);
        (new AnormMachineTokenRepository($this->pdo()))->insert($record);
        CompanyContext::reset();

        // tokenIsRevoked() ignores expiry: the refresh path depends on it (spec §5.4).
        $this->assertFalse($this->executor()->tokenIsRevoked(0, $record->jti));
    }

    public function testUserWithoutApiAccessIsForbidden(): void
    {
        $e = $this->executor();
        $this->assertReason(ExecutorAuthException::FORBIDDEN, function () use ($e): void {
            $e->issueToken(0, 'noapi', 'write', 600, $this->label);
        });
        $this->assertSame(0, $this->countOurs(), 'no token was stored');
        $this->assertSame(
            ['status' => 'NO_API_ACCESS', 'login' => ''],
            $e->authenticate(0, 'noapi', $this->password())
        );
    }

    public function testInactiveUserCannotGetAToken(): void
    {
        $this->setInactive($this->login(), true);
        $e = $this->executor();
        $this->assertReason(ExecutorAuthException::UNAUTHENTICATED, function () use ($e): void {
            $e->issueToken(0, $this->login(), 'write', 600, $this->label);
        });
        $this->assertSame(0, $this->countOurs(), 'no token was stored');
    }

    public function testAnUnknownUserCannotGetAToken(): void
    {
        $e = $this->executor();
        $this->assertReason(ExecutorAuthException::UNAUTHENTICATED, function () use ($e): void {
            $e->issueToken(0, 'nobody-here', 'write', 600, $this->label);
        });
        $this->assertSame(0, $this->countOurs());
    }

    public function testAuthenticate(): void
    {
        $e = $this->executor();
        $this->assertSame(['status' => 'BAD_CREDENTIALS', 'login' => ''], $e->authenticate(0, $this->login(), 'wrong'));
        $ok = $e->authenticate(0, strtoupper($this->login()), $this->password());
        $this->assertSame('OK', $ok['status']);
        $this->assertSame($this->login(), $ok['login']);   // FA's own spelling
    }

    public function testCompaniesAndDatabase(): void
    {
        $e = $this->executor();
        $companies = $e->companies();
        $this->assertArrayHasKey(0, $companies);
        $this->assertSame($GLOBALS['db_connections'][0]['name'], $companies[0]);
        $db = $e->database(0);
        $pdo = new \PDO($db['dsn'], $db['user'], $db['password']);
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
        $this->assertSame('0_', $db['prefix']);
    }

    public function testDatabaseOfAnUnknownCompanyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->executor()->database(99);
    }

    public function testSchemaIncludesActiveExtensions(): void
    {
        $e = $this->executor();
        $t = $e->issueToken(0, $this->login(), 'read', 600, $this->label);
        $this->assertNotNull($e->schema($t['token'])->getQueryType()->getField('customerList'));
    }

    public function testSchemaNeedsAGoodBearer(): void
    {
        $this->expectException(ExecutorAuthException::class);
        $this->executor()->schema('nope');
    }

    public function testRevokeTokenForAnUnknownCompanyIsUnauthenticated(): void
    {
        $e = $this->executor();
        $this->assertReason(ExecutorAuthException::UNAUTHENTICATED, function () use ($e): void {
            $e->revokeToken(99, str_repeat('0', 32));
        });
    }

    public function testTokenIsRevokedForAnUnknownCompanyIsUnauthenticated(): void
    {
        $e = $this->executor();
        $this->assertReason(ExecutorAuthException::UNAUTHENTICATED, function () use ($e): void {
            $e->tokenIsRevoked(99, str_repeat('0', 32));
        });
    }

    public function testRevokeTokenForASecondCompanyIsUnauthenticated(): void
    {
        $e = $this->executor();
        $this->assertTrue($e->tokenIsRevoked(0, str_repeat('0', 32)));   // company 0 is open
        try {
            $e->revokeToken(1, str_repeat('0', 32));
            $this->fail('expected UNAUTHENTICATED');
        } catch (ExecutorAuthException $x) {
            $this->assertSame(ExecutorAuthException::UNAUTHENTICATED, $x->getReason());
            $this->assertStringContainsString('One company per request', $x->getMessage());
        }
    }

    public function testAuthenticateForAnUnknownCompanyIsUnauthenticatedNotBadCredentials(): void
    {
        $e = $this->executor();
        try {
            $e->authenticate(99, $this->login(), $this->password());
            $this->fail('expected UNAUTHENTICATED');
        } catch (ExecutorAuthException $x) {
            $this->assertSame(ExecutorAuthException::UNAUTHENTICATED, $x->getReason());
            $this->assertSame('That company does not exist.', $x->getMessage());
        }
    }

    public function testAuthenticateForASecondCompanyIsUnauthenticatedNotBadCredentials(): void
    {
        $e = $this->executor();
        $this->assertSame('OK', $e->authenticate(0, $this->login(), $this->password())['status']);
        try {
            $e->authenticate(1, $this->login(), $this->password());
            $this->fail('expected UNAUTHENTICATED');
        } catch (ExecutorAuthException $x) {
            $this->assertSame(ExecutorAuthException::UNAUTHENTICATED, $x->getReason());
            $this->assertStringContainsString('One company per request', $x->getMessage());
        }
    }

    public function testAuthenticateAndIssueTokenRecordTheClientIp(): void
    {
        $seen = [];
        $root = dirname(__DIR__, 2);
        $e = new Executor(
            Config::fromArray($this->configArray()),
            static function (Config $config, RequestInfo $request) use (&$seen, $root): ContainerInterface {
                $seen[] = $request->client;

                return (require $root . '/container.php')($config, $request);
            }
        );

        $this->assertSame('OK', $e->authenticate(0, $this->login(), $this->password(), '203.0.113.7')['status']);
        $e->issueToken(0, $this->login(), 'read', 600, $this->label, '203.0.113.8');
        $this->assertSame(['203.0.113.7', '203.0.113.8'], $seen);
    }
}
