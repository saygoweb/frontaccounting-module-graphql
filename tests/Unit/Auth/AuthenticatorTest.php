<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\Authenticator;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\MachineTokenCheck;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use Lcobucci\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class AuthenticatorTest extends TestCase
{
    private TokenService $tokens;

    protected function setUp(): void
    {
        $this->tokens = new TokenService(
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']),
            new FrozenClock(new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC')))
        );
    }

    public function testNoHeaderIsAnonymous(): void
    {
        $this->assertNull((new Authenticator($this->tokens))->fromAuthorization(null));
    }

    public function testBlankHeaderIsAnonymous(): void
    {
        $this->assertNull((new Authenticator($this->tokens))->fromAuthorization('  '));
    }

    public function testBearerTokenYieldsClaims(): void
    {
        $jwt = $this->tokens->issueAccess(0, 'apitest');

        $claims = (new Authenticator($this->tokens))->fromAuthorization("Bearer $jwt");

        $this->assertSame('apitest', $claims->login);
    }

    public function testSchemeIsCaseInsensitive(): void
    {
        $jwt = $this->tokens->issueAccess(0, 'apitest');

        $this->assertNotNull((new Authenticator($this->tokens))->fromAuthorization("bearer $jwt"));
    }

    public function testAnotherSchemeIsRefusedNotIgnored(): void
    {
        $this->expectException(InvalidToken::class);
        (new Authenticator($this->tokens))->fromAuthorization('Basic dXNlcjpwYXNz');
    }

    public function testEmptyBearerIsRefused(): void
    {
        $this->expectException(InvalidToken::class);
        (new Authenticator($this->tokens))->fromAuthorization('Bearer ');
    }

    /**
     * A lookup that records what it was asked and throws $throw, if given.
     *
     * @return MachineTokenCheck
     */
    private function check(?\Throwable $throw = null)
    {
        return new class ($throw) implements MachineTokenCheck {
            /** @var Claims[] */
            public array $checked = [];
            private ?\Throwable $throw;

            public function __construct(?\Throwable $throw)
            {
                $this->throw = $throw;
            }

            public function check(Claims $claims): void
            {
                $this->checked[] = $claims;
                if ($this->throw !== null) {
                    throw $this->throw;
                }
            }
        };
    }

    public function testAnAccessTokenIsNeverLookedUp(): void
    {
        $check = $this->check(new \LogicException('looked up'));
        $jwt = $this->tokens->issueAccess(0, 'apitest');

        $claims = (new Authenticator($this->tokens, $check))->fromAuthorization("Bearer $jwt");

        $this->assertFalse($claims->machine);
        $this->assertSame([], $check->checked);
    }

    public function testAMachineTokenIsLookedUpOnEveryRequest(): void
    {
        $check = $this->check();
        $issued = $this->tokens->issueMachine(0, 'sgwpanel', 3600);
        $auth = new Authenticator($this->tokens, $check);

        $claims = $auth->fromAuthorization('Bearer ' . $issued->token);
        $auth->fromAuthorization('Bearer ' . $issued->token);

        $this->assertTrue($claims->machine);
        $this->assertCount(2, $check->checked);
        $this->assertSame($issued->jti, $check->checked[0]->jti);
        $this->assertSame('sgwpanel', $check->checked[0]->login);
    }

    public function testAMachineTokenTheLookupRefusesIsRefused(): void
    {
        $issued = $this->tokens->issueMachine(0, 'sgwpanel', 3600);

        $this->expectException(InvalidToken::class);
        $this->expectExceptionMessage('revoked');
        (new Authenticator($this->tokens, $this->check(new InvalidToken('revoked'))))
            ->fromAuthorization('Bearer ' . $issued->token);
    }

    public function testWithoutALookupAMachineTokenIsRefused(): void
    {
        $issued = $this->tokens->issueMachine(0, 'sgwpanel', 3600);

        $this->expectException(InvalidToken::class);
        (new Authenticator($this->tokens))->fromAuthorization('Bearer ' . $issued->token);
    }
}
