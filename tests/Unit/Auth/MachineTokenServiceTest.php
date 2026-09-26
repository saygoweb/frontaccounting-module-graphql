<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\InMemoryMachineTokenRepository;
use FA\GraphQL\Auth\MachineTokenService;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use Lcobucci\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class MachineTokenServiceTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    private FrozenClock $clock;
    private InMemoryMachineTokenRepository $repo;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC')));
        $this->repo = new InMemoryMachineTokenRepository();
    }

    private function tokens(array $config = []): TokenService
    {
        return new TokenService(Config::fromArray($config + ['secret' => self::SECRET]), $this->clock);
    }

    private function service(array $config = []): MachineTokenService
    {
        return new MachineTokenService($this->tokens($config), $this->repo, $this->clock);
    }

    private function clockAt(string $time): void
    {
        $this->clock->setTo(new \DateTimeImmutable($time, new \DateTimeZone('UTC')));
    }

    private function claimsOf(string $jwt): Claims
    {
        return $this->tokens()->verify($jwt);
    }

    public function testIssueStoresARowAndReturnsTheTokenOnce(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 365, 'my.saygoweb.com dev');

        $rows = $this->repo->all();
        $this->assertCount(1, $rows);
        $this->assertSame($issued->jti, $rows[0]->jti);
        $this->assertSame('sgwpanel', $rows[0]->login);
        $this->assertSame('my.saygoweb.com dev', $rows[0]->label);
        $this->assertSame('2026-09-21 10:00:00', $rows[0]->issuedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2027-09-21 10:00:00', $rows[0]->expiresAt->format('Y-m-d H:i:s'));
        $this->assertNull($rows[0]->revokedAt);
        $this->assertNull($rows[0]->lastUsedAt);
        // The token itself is never stored.
        $this->assertStringNotContainsString($issued->token, serialize($rows));
        $this->assertTrue($this->claimsOf($issued->token)->machine);
    }

    public function testIssuingLongerThanTheMaximumIsRefusedAndStoresNothing(): void
    {
        try {
            $this->service()->issue(0, 'sgwpanel', 366, 'too long');
            $this->fail('a 366-day token was issued');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('31536000', $e->getMessage());
        }
        $this->assertSame([], $this->repo->all());
    }

    public function testTheMaximumComesFromTheConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service(['machine_ttl_max' => 86400 * 30])->issue(0, 'sgwpanel', 31, 'a month and a day');
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public function badIssues(): array
    {
        return [
            'zero days' => [0, 'label'],
            'negative days' => [-1, 'label'],
            'an empty label' => [30, ''],
            'a blank label' => [30, "  \t"],
            'a label over 255 characters' => [30, str_repeat('x', 256)],
        ];
    }

    /**
     * @dataProvider badIssues
     */
    public function testBadIssuesAreRefused(int $days, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issue(0, 'sgwpanel', $days, $label);
    }

    public function testAnIssuedTokenPassesTheCheck(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 30, 'panel');

        $this->service()->check($this->claimsOf($issued->token));

        $this->assertSame('2026-09-21 10:00:00', $this->repo->all()[0]->lastUsedAt->format('Y-m-d H:i:s'));
    }

    public function testAnUnknownJtiIsRefused(): void
    {
        $jwt = $this->tokens()->issueMachine(0, 'sgwpanel', 3600)->token;

        $this->expectException(InvalidToken::class);
        $this->service()->check($this->claimsOf($jwt));
    }

    public function testARevokedTokenIsRefused(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 30, 'panel');
        $this->assertTrue($this->service()->revoke($issued->jti));

        $this->expectException(InvalidToken::class);
        $this->service()->check($this->claimsOf($issued->token));
    }

    public function testATokenPastItsStoredExpiryIsRefused(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 30, 'panel');
        $row = $this->repo->all()[0];
        $row->expiresAt = new \DateTimeImmutable('2026-09-21 09:59:59', new \DateTimeZone('UTC'));
        $this->repo->replace($row);

        $this->expectException(InvalidToken::class);
        $this->service()->check($this->claimsOf($issued->token));
    }

    public function testARowForAnotherUserIsRefused(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 30, 'panel');
        $claims = $this->claimsOf($issued->token);
        $other = new Claims($claims->company, 'admin', $claims->jti, $claims->expiresAt, true);

        $this->expectException(InvalidToken::class);
        $this->service()->check($other);
    }

    public function testLastUsedIsWrittenAtMostOnceAMinute(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 30, 'panel');
        $claims = $this->claimsOf($issued->token);

        $this->service()->check($claims);
        $this->clockAt('2026-09-21 10:00:59');
        $this->service()->check($claims);
        $this->assertSame('2026-09-21 10:00:00', $this->repo->all()[0]->lastUsedAt->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->repo->touches);

        $this->clockAt('2026-09-21 10:01:00');
        $this->service()->check($claims);
        $this->assertSame('2026-09-21 10:01:00', $this->repo->all()[0]->lastUsedAt->format('Y-m-d H:i:s'));
        $this->assertSame(2, $this->repo->touches);
    }

    public function testRevokeIsOnceOnly(): void
    {
        $issued = $this->service()->issue(0, 'sgwpanel', 30, 'panel');

        $this->assertTrue($this->service()->revoke($issued->jti));
        $this->assertFalse($this->service()->revoke($issued->jti));
        $this->assertFalse($this->service()->revoke('no-such-jti'));
        $this->assertSame('2026-09-21 10:00:00', $this->repo->all()[0]->revokedAt->format('Y-m-d H:i:s'));
    }

    public function testListReturnsEveryRowOldestFirst(): void
    {
        $a = $this->service()->issue(0, 'sgwpanel', 30, 'first');
        $b = $this->service()->issue(0, 'apitest', 30, 'second');

        $listed = $this->service()->list();

        $this->assertSame([$a->jti, $b->jti], array_map(function ($r) {
            return $r->jti;
        }, $listed));
    }
}
