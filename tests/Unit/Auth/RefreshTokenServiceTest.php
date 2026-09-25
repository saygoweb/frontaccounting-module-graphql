<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\InMemoryRefreshTokenRepository;
use FA\GraphQL\Auth\RefreshTokenRecord;
use FA\GraphQL\Auth\RefreshTokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\Unauthenticated;
use Lcobucci\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class RefreshTokenServiceTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryRefreshTokenRepository $repo;
    private RefreshTokenService $service;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC')));
        $this->repo = new InMemoryRefreshTokenRepository();
        $this->service = new RefreshTokenService(
            $this->repo,
            $this->clock,
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef', 'refresh_ttl' => 3600])
        );
    }

    public function testIssuedTokenNamesItsCompanyAndIsStoredOnlyAsAHash(): void
    {
        $token = $this->service->issue(3, 7, 'Guzzle 10.0.0.1');

        $this->assertSame(3, RefreshTokenService::companyOf($token));
        $secret = substr($token, 2);
        $this->assertGreaterThanOrEqual(43, strlen($secret));
        $record = $this->repo->findByHash(hash('sha256', $secret));
        $this->assertNotNull($record);
        $this->assertSame(7, $record->userId);
        $this->assertSame('Guzzle 10.0.0.1', $record->client);
        $this->assertSame('2026-09-21 11:00:00', $record->expiresAt->format('Y-m-d H:i:s'));
        foreach ($this->repo->all() as $stored) {
            $this->assertStringNotContainsString($secret, serialize($stored));
        }
    }

    public function testRotateIssuesANewTokenAndRevokesTheOld(): void
    {
        $old = $this->service->issue(0, 7, 'c');

        $result = $this->service->rotate($old, 'c2');

        $this->assertSame(7, $result['userId']);
        $this->assertNotSame($old, $result['token']);
        $oldRecord = $this->repo->findByHash(hash('sha256', substr($old, 2)));
        $newRecord = $this->repo->findByHash(hash('sha256', substr($result['token'], 2)));
        $this->assertNotNull($oldRecord->revokedAt);
        $this->assertSame($newRecord->id, $oldRecord->replacedBy);
        $this->assertNull($newRecord->revokedAt);
    }

    /**
     * Spec §3.4: the user is loaded and checked (step 4) before the token is
     * rotated (step 5). A refused user neither uses the token up nor leaves a live
     * successor nobody holds.
     */
    public function testTheUserIsAdmittedBeforeTheTokenIsRotated(): void
    {
        $token = $this->service->issue(0, 7, 'c');
        $seen = null;

        try {
            $this->service->rotate($token, 'c2', function (int $userId) use (&$seen): void {
                $seen = $userId;
                throw new Unauthenticated('refused');
            });
            $this->fail('rotated for a refused user');
        } catch (Unauthenticated $e) {
            $this->assertSame('refused', $e->getMessage());
        }

        $this->assertSame(7, $seen);
        $this->assertCount(1, $this->repo->all(), 'a successor was inserted');
        $record = $this->repo->findByHash(hash('sha256', substr($token, 2)));
        $this->assertNull($record->revokedAt, 'the token was used up');
    }

    public function testAReusedTokenIsRefusedBeforeTheUserIsLookedAt(): void
    {
        $first = $this->service->issue(0, 7, 'c');
        $this->service->rotate($first, 'c');

        $this->expectException(Unauthenticated::class);
        $this->service->rotate($first, 'thief', function (): void {
            $this->fail('admit was asked about a reused token');
        });
    }

    public function testReusingARotatedTokenRevokesTheWholeChain(): void
    {
        $first = $this->service->issue(0, 7, 'c');
        $second = $this->service->rotate($first, 'c')['token'];
        $other = $this->service->issue(0, 7, 'another device');
        $someoneElse = $this->service->issue(0, 8, 'c');

        try {
            $this->service->rotate($first, 'thief');
            $this->fail('a rotated token was accepted');
        } catch (Unauthenticated $e) {
            $this->assertSame('The refresh token is not valid.', $e->getMessage());
        }

        foreach ([$second, $other] as $token) {
            try {
                $this->service->rotate($token, 'c');
                $this->fail('the chain was not revoked');
            } catch (Unauthenticated $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(8, $this->service->rotate($someoneElse, 'c')['userId']);
    }

    /**
     * Two rotations of one live token racing: both read the row before either
     * revokes it. The service must not trust that read — only the conditional
     * revoke inside the transaction decides, and the loser is treated as reuse.
     */
    public function testTheLoserOfTwoConcurrentRotationsIsTreatedAsReuse(): void
    {
        $repo = new class extends InMemoryRefreshTokenRepository {
            /** @var array<string, RefreshTokenRecord> */
            public array $staleReads = [];

            public function findByHash(string $hash): ?RefreshTokenRecord
            {
                // Answer every read with the row as it was first seen: what the
                // second of two concurrent requests sees before the first commits.
                if (!isset($this->staleReads[$hash])) {
                    $record = parent::findByHash($hash);
                    if ($record === null) {
                        return null;
                    }
                    $this->staleReads[$hash] = $record;
                }

                return clone $this->staleReads[$hash];
            }
        };
        $service = new RefreshTokenService(
            $repo,
            $this->clock,
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef', 'refresh_ttl' => 3600])
        );
        $token = $service->issue(0, 7, 'c');

        $winner = $service->rotate($token, 'legitimate client');
        try {
            $service->rotate($token, 'thief');
            $this->fail('a second rotation of the same token was accepted');
        } catch (Unauthenticated $e) {
            $this->assertSame('The refresh token is not valid.', $e->getMessage());
        }

        $this->assertCount(2, $repo->all(), 'the loser\'s successor row was rolled back');
        foreach ($repo->all() as $row) {
            $this->assertNotNull($row->revokedAt, 'the chain, the winner\'s successor included, is revoked');
        }
        $this->assertNotSame($token, $winner['token']);
    }

    public function testMarkRevokedReportsWhetherItRevokedALiveRow(): void
    {
        $this->service->issue(0, 7, 'c');
        $id = $this->repo->all()[0]->id;
        $at = $this->clock->now();

        $this->assertTrue($this->repo->markRevoked($id, $at, null));
        $this->assertFalse($this->repo->markRevoked($id, $at, 99), 'already revoked');
        $this->assertNull($this->repo->all()[0]->replacedBy, 'a failed revoke changes nothing');
        $this->assertFalse($this->repo->markRevoked(12345, $at, null), 'no such row');
    }

    public function testExpiredTokenIsRefused(): void
    {
        $token = $this->service->issue(0, 7, 'c');
        $this->clock->setTo(new \DateTimeImmutable('2026-09-21 11:00:01', new \DateTimeZone('UTC')));

        $this->expectException(Unauthenticated::class);
        $this->service->rotate($token, 'c');
    }

    public function testUnknownTokenIsRefused(): void
    {
        $this->expectException(Unauthenticated::class);
        $this->service->rotate('0.' . str_repeat('A', 43), 'c');
    }

    /**
     * @dataProvider malformed
     */
    public function testMalformedTokenIsRefused(string $token): void
    {
        $this->expectException(Unauthenticated::class);
        RefreshTokenService::companyOf($token);
    }

    public function malformed(): array
    {
        return [[''], ['abc'], ['.abc'], ['x.abc'], ['-1.abc'], ['1.'], ['1.has space'], ['99999999999999999999.abc']];
    }

    public function testRevokeOnlyMyOwnToken(): void
    {
        $mine = $this->service->issue(0, 7, 'c');

        $this->assertFalse($this->service->revoke($mine, 8));
        $this->assertTrue($this->service->revoke($mine, 7));
        $this->assertFalse($this->service->revoke($mine, 7), 'already revoked');
        $this->expectException(Unauthenticated::class);
        $this->service->rotate($mine, 'c');
    }

    public function testRevokeAll(): void
    {
        $this->service->issue(0, 7, 'a');
        $this->service->issue(0, 7, 'b');
        $this->service->issue(0, 8, 'c');

        $this->assertSame(2, $this->service->revokeAll(7));
        $this->assertSame(0, $this->service->revokeAll(7));
    }

    public function testPurgeExpiredRespectsTheLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->issue(0, 7, 'c');
        }
        $this->clock->setTo(new \DateTimeImmutable('2026-09-22 10:00:00', new \DateTimeZone('UTC')));
        $this->service->issue(0, 7, 'live');

        $this->assertSame(3, $this->service->purgeExpired(3));
        $this->assertSame(2, $this->service->purgeExpired());
        $this->assertCount(1, $this->repo->all());
    }
}
