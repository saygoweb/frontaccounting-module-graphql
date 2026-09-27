<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use Lcobucci\Clock\FrozenClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC')));
    }

    private function service(array $config = []): TokenService
    {
        return new TokenService(Config::fromArray($config + ['secret' => self::SECRET]), $this->clock);
    }

    public function testRoundTrip(): void
    {
        $claims = $this->service()->verify($this->service()->issueAccess(3, 'apitest'));

        $this->assertSame(3, $claims->company);
        $this->assertSame('apitest', $claims->login);
        $this->assertNotSame('', $claims->jti);
        $this->assertSame('2026-09-21 10:15:00', $claims->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testEachTokenHasItsOwnId(): void
    {
        $a = $this->service()->verify($this->service()->issueAccess(0, 'a'));
        $b = $this->service()->verify($this->service()->issueAccess(0, 'a'));

        $this->assertNotSame($a->jti, $b->jti);
    }

    public function testExpiredTokenIsRefused(): void
    {
        $jwt = $this->service()->issueAccess(0, 'apitest');
        $this->clock->setTo(new \DateTimeImmutable('2026-09-21 10:15:06', new \DateTimeZone('UTC')));

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testFiveSecondsOfLeeway(): void
    {
        $jwt = $this->service()->issueAccess(0, 'apitest');
        $this->clock->setTo(new \DateTimeImmutable('2026-09-21 10:15:04', new \DateTimeZone('UTC')));

        $this->assertSame('apitest', $this->service()->verify($jwt)->login);
    }

    public function testTokenFromTheFutureIsRefused(): void
    {
        $jwt = $this->service()->issueAccess(0, 'apitest');
        $this->clock->setTo(new \DateTimeImmutable('2026-09-21 09:00:00', new \DateTimeZone('UTC')));

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testTamperedPayloadIsRefused(): void
    {
        list($header, $payload, $signature) = explode('.', $this->service()->issueAccess(0, 'apitest'));
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $data['sub'] = 'admin';
        $forged = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        $this->expectException(InvalidToken::class);
        $this->service()->verify("$header.$forged.$signature");
    }

    public function testAnotherSecretIsRefused(): void
    {
        $jwt = $this->service(['secret' => 'ffffffffffffffffffffffffffffffff'])->issueAccess(0, 'apitest');

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testAnotherIssuerIsRefused(): void
    {
        $jwt = $this->service(['issuer' => 'someone-else'])->issueAccess(0, 'apitest');

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testAnotherAlgorithmIsRefusedEvenWithTheRightKey(): void
    {
        $longKey = self::SECRET . self::SECRET;  // 64 bytes for SHA-512
        $other = Configuration::forSymmetricSigner(new Sha512(), InMemory::plainText($longKey));
        $now = $this->clock->now();
        $jwt = $other->builder()->issuedBy('fa-graphql')->relatedTo('apitest')->withClaim('coy', 0)
            ->identifiedBy('x')->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+5 minutes'))
            ->getToken($other->signer(), $other->signingKey())->toString();

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testUnsignedTokenIsRefused(): void
    {
        $b64 = function (array $a): string {
            return rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        };
        $jwt = $b64(['alg' => 'none', 'typ' => 'JWT']) . '.'
            . $b64(['iss' => 'fa-graphql', 'sub' => 'admin', 'coy' => 0, 'exp' => 4102444800]) . '.';

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testMissingCompanyClaimIsRefused(): void
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = $this->clock->now();
        $jwt = $config->builder()->issuedBy('fa-graphql')->relatedTo('apitest')
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+5 minutes'))
            ->getToken($config->signer(), $config->signingKey())->toString();

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testGarbageIsRefused(): void
    {
        $this->expectException(InvalidToken::class);
        $this->service()->verify('not-a-token');
    }

    public function testAnAccessTokenIsNotAMachineToken(): void
    {
        $this->assertFalse($this->service()->verify($this->service()->issueAccess(0, 'apitest'))->machine);
    }

    public function testMachineTokenRoundTrip(): void
    {
        $issued = $this->service()->issueMachine(2, 'sgwpanel', 86400 * 365);
        $claims = $this->service()->verify($issued->token);

        $this->assertTrue($claims->machine);
        $this->assertSame(2, $claims->company);
        $this->assertSame('sgwpanel', $claims->login);
        $this->assertSame($issued->jti, $claims->jti);
        $this->assertSame(32, strlen($issued->jti));
        $this->assertSame('2026-09-21 10:00:00', $issued->issuedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2027-09-21 10:00:00', $issued->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('2027-09-21 10:00:00', $claims->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testAMachineTokenCarriesTypMachine(): void
    {
        $payload = explode('.', $this->service()->issueMachine(0, 'sgwpanel', 60)->token)[1];
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        $this->assertSame('machine', $data['typ']);
        $this->assertSame('fa-graphql', $data['iss']);
        $this->assertSame(0, $data['coy']);
    }

    public function testAMachineTokensTimesAreWholeSeconds(): void
    {
        $this->clock->setTo(new \DateTimeImmutable('2026-09-21 10:00:00.748252', new \DateTimeZone('UTC')));
        $issued = $this->service()->issueMachine(0, 'sgwpanel', 60);
        $payload = explode('.', $issued->token)[1];
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        $this->assertSame('000000', $issued->issuedAt->format('u'));
        $this->assertSame($issued->expiresAt->getTimestamp(), $data['exp']);
        $this->assertSame($issued->issuedAt->getTimestamp(), $data['iat']);
    }

    public function testAMachineTokenMayLiveExactlyTheMaximum(): void
    {
        $issued = $this->service(['machine_ttl_max' => 3600])->issueMachine(0, 'sgwpanel', 3600);

        $this->assertSame('2026-09-21 11:00:00', $issued->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testAMachineTokenLongerThanTheMaximumIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3600');
        $this->service(['machine_ttl_max' => 3600])->issueMachine(0, 'sgwpanel', 3601);
    }

    public function testTheDefaultMaximumIsOneYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issueMachine(0, 'sgwpanel', 31536001);
    }

    public function testANonPositiveLifetimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issueMachine(0, 'sgwpanel', 0);
    }

    public function testAMachineTokenExpires(): void
    {
        $jwt = $this->service()->issueMachine(0, 'sgwpanel', 60)->token;
        $this->clock->setTo(new \DateTimeImmutable('2026-09-21 10:01:06', new \DateTimeZone('UTC')));

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function unknownTypes(): array
    {
        return [
            'refresh' => ['refresh'],
            'Machine, capitalised' => ['Machine'],
            'empty' => [''],
            'a number' => [1],
            'true' => [true],
        ];
    }

    /**
     * Only an absent typ (an access token) or "machine" is accepted: anything else
     * is a token this module did not issue.
     *
     * @dataProvider unknownTypes
     * @param mixed $typ
     */
    public function testAnyOtherTypIsRefused($typ): void
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = $this->clock->now();
        $jwt = $config->builder()->issuedBy('fa-graphql')->relatedTo('apitest')->withClaim('coy', 0)
            ->withClaim('typ', $typ)->identifiedBy('x')
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+5 minutes'))
            ->getToken($config->signer(), $config->signingKey())->toString();

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }

    public function testAMachineTokenWithoutAnIdIsRefused(): void
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = $this->clock->now();
        $jwt = $config->builder()->issuedBy('fa-graphql')->relatedTo('apitest')->withClaim('coy', 0)
            ->withClaim('typ', 'machine')
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+5 minutes'))
            ->getToken($config->signer(), $config->signingKey())->toString();

        $this->expectException(InvalidToken::class);
        $this->service()->verify($jwt);
    }
}
