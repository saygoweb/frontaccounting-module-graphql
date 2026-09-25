<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\Authenticator;
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
}
