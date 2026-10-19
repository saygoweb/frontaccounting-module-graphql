<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use Lcobucci\Clock\FrozenClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;

final class TokenServiceScopeTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-at-least-32-bytes-long!!';

    /** Config's default issuer. */
    private const ISSUER = 'fa-graphql';

    private function service(): TokenService
    {
        return new TokenService(
            Config::fromArray(['secret' => self::SECRET]),
            new FrozenClock(new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('UTC')))
        );
    }

    public function testAccessTokenDefaultsToWrite(): void
    {
        $s = $this->service();
        $this->assertSame(Claims::SCOPE_WRITE, $s->verify($s->issueAccess(0, 'admin'))->scope);
    }

    public function testReadAccessTokenVerifiesAsRead(): void
    {
        $s = $this->service();
        $this->assertSame(Claims::SCOPE_READ, $s->verify($s->issueAccess(0, 'admin', 'read'))->scope);
    }

    public function testReadMachineTokenVerifiesAsRead(): void
    {
        $s = $this->service();
        $claims = $s->verify($s->issueMachine(0, 'admin', 3600, 'read')->token);
        $this->assertTrue($claims->machine);
        $this->assertSame(Claims::SCOPE_READ, $claims->scope);
    }

    public function testTokenWithoutScpClaimIsWrite(): void
    {
        // A token issued before this release: no scp claim.
        $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('UTC'));
        $token = $jwt->builder()->issuedBy(self::ISSUER)->identifiedBy('x')->relatedTo('admin')
            ->withClaim('coy', 0)->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+60 seconds'))
            ->getToken($jwt->signer(), $jwt->signingKey())->toString();
        $this->assertSame(Claims::SCOPE_WRITE, $this->service()->verify($token)->scope);
    }

    public function testUnknownScopeIsRefusedAtIssue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issueAccess(0, 'admin', 'admin');
    }

    public function testUnknownScopeClaimIsRefusedAtVerify(): void
    {
        $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('UTC'));
        $token = $jwt->builder()->issuedBy(self::ISSUER)->identifiedBy('x')->relatedTo('admin')
            ->withClaim('coy', 0)->withClaim('scp', 'root')->issuedAt($now)->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+60 seconds'))->getToken($jwt->signer(), $jwt->signingKey())->toString();
        $this->expectException(InvalidToken::class);
        $this->service()->verify($token);
    }
}
