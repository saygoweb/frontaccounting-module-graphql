<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\Authenticator;
use FA\GraphQL\Auth\MachineTokenService;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\RequestInfo;

/**
 * The real container's Authenticator against company 0's graphql_machine_token:
 * a machine token is accepted only while its jti is stored, live and unexpired,
 * and its use is recorded at most once a minute (spec §3.7).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MachineTokenAuthenticationTest extends FaTestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    private string $label;

    /** @var \DI\Container */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->label = 'itest-' . bin2hex(random_bytes(4));
        $factory = require dirname(__DIR__, 2) . '/container.php';
        $this->container = $factory(
            Config::fromArray(['secret' => self::SECRET]),
            new RequestInfo(false, 'phpunit 127.0.0.1')
        );
    }

    protected function tearDown(): void
    {
        $this->pdo()->prepare('DELETE FROM 0_graphql_machine_token WHERE label = ?')->execute([$this->label]);
        CompanyContext::reset();
    }

    private function authenticator(): Authenticator
    {
        return $this->container->get(Authenticator::class);
    }

    /**
     * Issued as bin/fa-token does: the company open, then the service.
     */
    private function issue(int $days = 30): \FA\GraphQL\Auth\IssuedMachineToken
    {
        $this->container->get(FaSession::class)->openCompany(0);

        return $this->container->get(MachineTokenService::class)->issue(0, 'apitest', $days, $this->label);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $jti): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_graphql_machine_token WHERE jti = ?');
        $statement->execute([$jti]);

        return (array) $statement->fetch(\PDO::FETCH_ASSOC);
    }

    public function testAnIssuedTokenIsAcceptedAndItsUseRecorded(): void
    {
        $issued = $this->issue();

        $claims = $this->authenticator()->fromAuthorization('Bearer ' . $issued->token);

        $this->assertTrue($claims->machine);
        $this->assertSame('apitest', $claims->login);
        $this->assertNotNull($this->row($issued->jti)['last_used_at']);
    }

    public function testAnUnknownJtiIsRefused(): void
    {
        $forged = $this->container->get(TokenService::class)->issueMachine(0, 'apitest', 3600);

        $this->expectException(InvalidToken::class);
        $this->authenticator()->fromAuthorization('Bearer ' . $forged->token);
    }

    public function testARevokedTokenIsRefused(): void
    {
        $issued = $this->issue();
        $this->assertTrue($this->container->get(MachineTokenService::class)->revoke($issued->jti));

        $this->expectException(InvalidToken::class);
        $this->authenticator()->fromAuthorization('Bearer ' . $issued->token);
    }

    public function testATokenExpiredInTheTableIsRefused(): void
    {
        $issued = $this->issue();
        $this->pdo()->prepare('UPDATE 0_graphql_machine_token SET expires_at = ? WHERE jti = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 1), $issued->jti]);

        $this->expectException(InvalidToken::class);
        $this->authenticator()->fromAuthorization('Bearer ' . $issued->token);
    }

    public function testATokenForACompanyThatDoesNotExistIsRefused(): void
    {
        $forged = $this->container->get(TokenService::class)->issueMachine(99, 'apitest', 3600);

        $this->expectException(InvalidToken::class);
        $this->authenticator()->fromAuthorization('Bearer ' . $forged->token);
    }

    public function testLastUsedIsWrittenAtMostOnceAMinute(): void
    {
        $issued = $this->issue();
        $header = 'Bearer ' . $issued->token;
        $this->authenticator()->fromAuthorization($header);

        // Recent: a second request leaves it alone.
        $recent = gmdate('Y-m-d H:i:s', time() - 30);
        $this->pdo()->prepare('UPDATE 0_graphql_machine_token SET last_used_at = ? WHERE jti = ?')
            ->execute([$recent, $issued->jti]);
        $this->authenticator()->fromAuthorization($header);
        $this->assertSame($recent, $this->row($issued->jti)['last_used_at']);

        // Over a minute old: the next request writes it.
        $stale = gmdate('Y-m-d H:i:s', time() - 61);
        $this->pdo()->prepare('UPDATE 0_graphql_machine_token SET last_used_at = ? WHERE jti = ?')
            ->execute([$stale, $issued->jti]);
        $this->authenticator()->fromAuthorization($header);
        $this->assertGreaterThan($stale, $this->row($issued->jti)['last_used_at']);
    }

    public function testAnAccessTokenNeverOpensTheTable(): void
    {
        $jwt = $this->container->get(TokenService::class)->issueAccess(0, 'apitest');

        $this->authenticator()->fromAuthorization("Bearer $jwt");

        // No lookup means no company opened, so no connection was ever built.
        $this->assertFalse(CompanyContext::isSet());
    }
}
