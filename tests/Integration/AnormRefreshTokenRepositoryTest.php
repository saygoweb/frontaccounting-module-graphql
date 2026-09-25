<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\AnormRefreshTokenRepository;
use FA\GraphQL\Auth\RefreshTokenRecord;
use FA\GraphQL\Fa\CompanyContext;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AnormRefreshTokenRepositoryTest extends FaTestCase
{
    private const USER = 32000; // no such FrontAccounting user; rows are ours to delete

    private AnormRefreshTokenRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        CompanyContext::set(0, $GLOBALS['db_connections'][0]);
        $this->repo = new AnormRefreshTokenRepository($this->pdo());
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        CompanyContext::reset();
    }

    private function cleanUp(): void
    {
        $this->pdo()->exec(
            'DELETE FROM 0_graphql_refresh_token WHERE user_id IN (' . self::USER . ', ' . (self::USER + 1) . ')'
        );
    }

    private function record(
        string $hash,
        string $expires = '2030-01-01 00:00:00',
        int $user = self::USER
    ): RefreshTokenRecord {
        $record = new RefreshTokenRecord();
        $record->userId = $user;
        $record->tokenHash = $hash;
        $record->issuedAt = new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC'));
        $record->expiresAt = new \DateTimeImmutable($expires, new \DateTimeZone('UTC'));
        $record->client = "Guzzle's \"client\" 10.0.0.1";

        return $record;
    }

    public function testRoundTrip(): void
    {
        $id = $this->repo->insert($this->record(str_repeat('a', 64)));
        $found = $this->repo->findByHash(str_repeat('a', 64));

        $this->assertSame($id, $found->id);
        $this->assertSame(self::USER, $found->userId);
        $this->assertSame('2026-09-21 10:00:00', $found->issuedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2030-01-01 00:00:00', $found->expiresAt->format('Y-m-d H:i:s'));
        $this->assertNull($found->revokedAt);
        $this->assertNull($found->replacedBy);
        $this->assertSame("Guzzle's \"client\" 10.0.0.1", $found->client);
    }

    public function testAHostileHashFindsNothingAndBreaksNothing(): void
    {
        $this->repo->insert($this->record(str_repeat('a', 64)));

        $this->assertNull($this->repo->findByHash("x' OR '1'='1"));
        $this->assertNull($this->repo->findByHash("'; DROP TABLE 0_graphql_refresh_token; --"));
        $this->assertNotNull($this->repo->findByHash(str_repeat('a', 64)));
    }

    public function testMarkRevoked(): void
    {
        $old = $this->repo->insert($this->record(str_repeat('a', 64)));
        $new = $this->repo->insert($this->record(str_repeat('b', 64)));

        $at = new \DateTimeImmutable('2026-09-21 11:00:00', new \DateTimeZone('UTC'));
        $this->assertTrue($this->repo->markRevoked($old, $at, $new));

        $found = $this->repo->findByHash(str_repeat('a', 64));
        $this->assertSame('2026-09-21 11:00:00', $found->revokedAt->format('Y-m-d H:i:s'));
        $this->assertSame($new, $found->replacedBy);
    }

    /**
     * markRevoked is a single bound `UPDATE ... WHERE id = ? AND revoked_at IS
     * NULL`, so a row already revoked never changes again — the same guard
     * RefreshTokenService::rotate relies on inside transactional() (spec §3.4 step
     * 5, revised).
     */
    public function testMarkRevokedIsConditionalOnStillBeingLive(): void
    {
        $id = $this->repo->insert($this->record(str_repeat('a', 64)));
        $at = new \DateTimeImmutable('2026-09-21 11:00:00', new \DateTimeZone('UTC'));

        $this->assertTrue($this->repo->markRevoked($id, $at, null));
        $this->assertFalse($this->repo->markRevoked($id, $at, null));
    }

    public function testRevokeAllForUserLeavesOthersAndCountsOnlyLiveOnes(): void
    {
        $this->repo->insert($this->record(str_repeat('a', 64)));
        $this->repo->insert($this->record(str_repeat('b', 64)));
        $this->repo->insert($this->record(str_repeat('c', 64), '2030-01-01 00:00:00', self::USER + 1));
        $at = new \DateTimeImmutable('2026-09-21 11:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(2, $this->repo->revokeAllForUser(self::USER, $at));
        $this->assertSame(0, $this->repo->revokeAllForUser(self::USER, $at));
        $this->assertNull($this->repo->findByHash(str_repeat('c', 64))->revokedAt);
    }

    public function testDeleteExpiredRespectsTheLimit(): void
    {
        foreach (['a', 'b', 'c'] as $c) {
            $this->repo->insert($this->record(str_repeat($c, 64), '2026-01-01 00:00:00'));
        }
        $this->repo->insert($this->record(str_repeat('d', 64)));
        $now = new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(2, $this->repo->deleteExpired($now, 2));
        $this->assertSame(1, $this->repo->deleteExpired($now, 100));
        $this->assertNotNull($this->repo->findByHash(str_repeat('d', 64)));
    }

    /**
     * Matches InMemoryRefreshTokenRepository: a non-positive limit deletes nothing,
     * not the one row an earlier `max(1, $limit)` forced.
     */
    public function testDeleteExpiredWithANonPositiveLimitDeletesNothing(): void
    {
        $this->repo->insert($this->record(str_repeat('a', 64), '2026-01-01 00:00:00'));
        $now = new \DateTimeImmutable('2026-09-21 10:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(0, $this->repo->deleteExpired($now, 0));
        $this->assertNotNull($this->repo->findByHash(str_repeat('a', 64)));
    }

    public function testTransactionalRollsBackOnThrow(): void
    {
        try {
            $this->repo->transactional(function () {
                $this->repo->insert($this->record(str_repeat('a', 64)));
                throw new \RuntimeException('no');
            });
            $this->fail('the exception was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertNull($this->repo->findByHash(str_repeat('a', 64)));
        }
    }

    public function testTransactionalReturnsTheResult(): void
    {
        $this->assertSame(42, $this->repo->transactional(function () {
            return 42;
        }));
    }
}
