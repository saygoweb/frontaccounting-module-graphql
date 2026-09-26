<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\AnormMachineTokenRepository;
use FA\GraphQL\Auth\MachineTokenRecord;
use FA\GraphQL\Fa\CompanyContext;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AnormMachineTokenRepositoryTest extends FaTestCase
{
    private const LOGIN = '__machine_token_test__'; // no such FrontAccounting user

    private AnormMachineTokenRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        CompanyContext::set(0, $GLOBALS['db_connections'][0]);
        $this->repo = new AnormMachineTokenRepository($this->pdo());
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        CompanyContext::reset();
    }

    private function cleanUp(): void
    {
        $this->pdo()->prepare('DELETE FROM 0_graphql_machine_token WHERE login = ?')->execute([self::LOGIN]);
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private function record(string $jti): MachineTokenRecord
    {
        $record = new MachineTokenRecord();
        $record->jti = $jti;
        $record->login = self::LOGIN;
        $record->label = "The \"panel\" 's token";
        $record->issuedAt = self::utc('2026-09-21 10:00:00');
        $record->expiresAt = self::utc('2027-09-21 10:00:00');

        return $record;
    }

    private function lastUsed(string $jti): ?string
    {
        $statement = $this->pdo()->prepare('SELECT last_used_at FROM 0_graphql_machine_token WHERE jti = ?');
        $statement->execute([$jti]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    public function testRoundTrip(): void
    {
        $jti = bin2hex(random_bytes(16));
        $id = $this->repo->insert($this->record($jti));
        $found = $this->repo->findByJti($jti);

        $this->assertSame($id, $found->id);
        $this->assertSame($jti, $found->jti);
        $this->assertSame(self::LOGIN, $found->login);
        $this->assertSame("The \"panel\" 's token", $found->label);
        $this->assertSame('2026-09-21 10:00:00', $found->issuedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2027-09-21 10:00:00', $found->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $found->expiresAt->getTimezone()->getName());
        $this->assertNull($found->revokedAt);
        $this->assertNull($found->lastUsedAt);
    }

    public function testAnUnknownOrHostileJtiFindsNothing(): void
    {
        $jti = bin2hex(random_bytes(16));
        $this->repo->insert($this->record($jti));

        $this->assertNull($this->repo->findByJti(bin2hex(random_bytes(16))));
        $this->assertNull($this->repo->findByJti("x' OR '1'='1"));
        $this->assertNotNull($this->repo->findByJti($jti));
    }

    public function testAJtiIsUnique(): void
    {
        $jti = bin2hex(random_bytes(16));
        $this->repo->insert($this->record($jti));

        $this->expectException(\PDOException::class);
        $this->repo->insert($this->record($jti));
    }

    public function testRevokeIsOnceOnly(): void
    {
        $jti = bin2hex(random_bytes(16));
        $this->repo->insert($this->record($jti));

        $this->assertTrue($this->repo->revoke($jti, self::utc('2026-09-22 08:00:00')));
        $this->assertFalse($this->repo->revoke($jti, self::utc('2026-09-23 08:00:00')));
        $this->assertFalse($this->repo->revoke(bin2hex(random_bytes(16)), self::utc('2026-09-23 08:00:00')));
        $this->assertSame('2026-09-22 08:00:00', $this->repo->findByJti($jti)->revokedAt->format('Y-m-d H:i:s'));
    }

    public function testTouchWritesOnlyAStaleLastUsed(): void
    {
        $jti = bin2hex(random_bytes(16));
        $this->repo->insert($this->record($jti));

        $first = $this->repo->touch($jti, self::utc('2026-09-21 10:00:00'), self::utc('2026-09-21 09:59:00'));
        $this->assertTrue($first);
        $this->assertSame('2026-09-21 10:00:00', $this->lastUsed($jti));

        // 59 seconds later: not stale yet.
        $early = $this->repo->touch($jti, self::utc('2026-09-21 10:00:59'), self::utc('2026-09-21 09:59:59'));
        $this->assertFalse($early);
        $this->assertSame('2026-09-21 10:00:00', $this->lastUsed($jti));

        // A minute later: stale.
        $stale = $this->repo->touch($jti, self::utc('2026-09-21 10:01:00'), self::utc('2026-09-21 10:00:00'));
        $this->assertTrue($stale);
        $this->assertSame('2026-09-21 10:01:00', $this->lastUsed($jti));
    }

    public function testAllIsOldestFirst(): void
    {
        $a = bin2hex(random_bytes(16));
        $b = bin2hex(random_bytes(16));
        $this->repo->insert($this->record($a));
        $this->repo->insert($this->record($b));

        $ours = array_values(array_filter($this->repo->all(), function (MachineTokenRecord $r): bool {
            return $r->login === self::LOGIN;
        }));

        $this->assertSame([$a, $b], array_map(function (MachineTokenRecord $r): string {
            return $r->jti;
        }, $ours));
    }
}
