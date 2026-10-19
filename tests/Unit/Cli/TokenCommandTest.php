<?php

namespace FA\GraphQL\Tests\Unit\Cli;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\InMemoryMachineTokenRepository;
use FA\GraphQL\Auth\MachineTokenService;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Cli\TokenCommand;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use Lcobucci\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

/**
 * TokenCommand in-process, with FrontAccounting and the database faked: a FaSession
 * that opens and enters without FrontAccounting, a PDO that finds one active user,
 * and the in-memory machine token repository.
 */
final class TokenCommandTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private TokenService $tokens;

    private InMemoryMachineTokenRepository $repo;

    private bool $containerBuilt = false;

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');
        $config = Config::fromArray(['secret' => self::SECRET]);
        $this->tokens = new TokenService($config, SystemClock::fromUTC());
        $this->repo = new InMemoryMachineTokenRepository();
    }

    protected function tearDown(): void
    {
        CompanyContext::reset();
    }

    private function command(): TokenCommand
    {
        return new TokenCommand(function (): Container {
            $this->containerBuilt = true;

            return $this->container();
        }, $this->out, $this->err);
    }

    private function container(): Container
    {
        $config = Config::fromArray(['secret' => self::SECRET]);
        $session = new class ($config) extends FaSession {
            public function openCompany(int $company): void
            {
            }

            public function enter(Claims $claims): void
            {
            }
        };

        $container = new Container();
        $container->set(FaSession::class, $session);
        $container->set(\PDO::class, $this->pdoWithUser('admin'));
        $container->set(
            MachineTokenService::class,
            new MachineTokenService($this->tokens, $this->repo, SystemClock::fromUTC())
        );

        return $container;
    }

    /**
     * A PDO whose every query finds the one active user $login.
     */
    private function pdoWithUser(string $login): \PDO
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn([
            'id' => 1,
            'user_id' => $login,
            'real_name' => 'Administrator',
            'email' => null,
            'role_id' => 2,
            'inactive' => 0,
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private static function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testIssueReadOnlyPassesReadScope(): void
    {
        $exit = $this->command()->run(
            ['issue', '--company', '0', '--user', 'admin', '--days', '1', '--label', 'x', '--read-only']
        );

        $this->assertSame(0, $exit, self::read($this->err));
        $claims = $this->tokens->verify(trim(self::read($this->out)));
        $this->assertTrue($claims->machine);
        $this->assertSame(Claims::SCOPE_READ, $claims->scope);
        $this->assertStringContainsString("scope: read\n", self::read($this->err));
        $this->assertCount(1, $this->repo->all());
    }

    public function testIssueWithoutReadOnlyIsWrite(): void
    {
        $exit = $this->command()->run(
            ['issue', '--company', '0', '--user', 'admin', '--days', '1', '--label', 'x']
        );

        $this->assertSame(0, $exit, self::read($this->err));
        $this->assertSame(Claims::SCOPE_WRITE, $this->tokens->verify(trim(self::read($this->out)))->scope);
        $this->assertStringContainsString("scope: write\n", self::read($this->err));
    }

    public function testReadOnlyTakesNoValue(): void
    {
        $exit = $this->command()->run(
            ['issue', '--company', '0', '--user', 'admin', '--days', '1', '--label', 'x', '--read-only', 'yes']
        );

        $this->assertSame(2, $exit);
        $this->assertSame('', self::read($this->out));
        $this->assertStringContainsString('unexpected arguments', self::read($this->err));
        $this->assertFalse($this->containerBuilt, 'a usage error is refused before FrontAccounting is touched');
        $this->assertSame([], $this->repo->all());
    }

    public function testReadOnlyWithAnEqualsValueIsRefused(): void
    {
        $exit = $this->command()->run(
            ['issue', '--company', '0', '--user', 'admin', '--days', '1', '--label', 'x', '--read-only=no']
        );

        $this->assertSame(2, $exit);
        $this->assertFalse($this->containerBuilt);
    }

    public function testReadOnlyIsOnlyForIssue(): void
    {
        $this->assertSame(2, $this->command()->run(['list', '--company', '0', '--read-only']));
        $this->assertStringContainsString('unknown option --read-only', self::read($this->err));
    }
}
