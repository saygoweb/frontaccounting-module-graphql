<?php

namespace FA\GraphQL\Auth;

use FA\GraphQL\Config;
use FA\GraphQL\Error\Unauthenticated;
use Lcobucci\Clock\Clock;

/**
 * Refresh tokens rotate: each use revokes the token and issues its successor. A
 * rotated token turning up again means it was copied, so the user's whole chain
 * is revoked and they sign in again.
 */
class RefreshTokenService
{
    private const INVALID = 'The refresh token is not valid.';

    private RefreshTokenRepository $repo;
    private Clock $clock;
    private Config $config;

    public function __construct(RefreshTokenRepository $repo, Clock $clock, Config $config)
    {
        $this->repo = $repo;
        $this->clock = $clock;
        $this->config = $config;
    }

    public function issue(int $company, int $userId, string $client): string
    {
        return $company . '.' . $this->insert($userId, $client)[1];
    }

    /**
     * The company a token was issued for, so its database can be opened before the
     * token is looked up.
     */
    public static function companyOf(string $token): int
    {
        return self::parse($token)[0];
    }

    /**
     * Spec §3.4, steps 2 to 6. $admit is step 4: given the token's user id, it
     * loads and checks the user and throws to refuse. It runs after the lookup and
     * reuse detection and before anything is written, so a refused user neither
     * uses the token up nor leaves a live successor that nobody holds.
     *
     * @param (callable(int): void)|null $admit
     * @return array{userId: int, token: string}
     */
    public function rotate(string $token, string $client, ?callable $admit = null): array
    {
        list($company, $secret) = self::parse($token);
        $now = $this->clock->now();

        $record = $this->repo->findByHash(hash('sha256', $secret));
        if ($record === null || $record->id === null || $record->expiresAt < $now) {
            throw new Unauthenticated(self::INVALID);
        }
        if ($record->revokedAt !== null) {
            $this->repo->revokeAllForUser($record->userId, $now);
            throw new Unauthenticated(self::INVALID);
        }

        if ($admit !== null) {
            $admit($record->userId);
        }

        // The read above can be stale: a concurrent rotation of the same token may
        // have revoked the row since. So the revoke is conditional and inside the
        // transaction, and it alone decides. Losing it means the token was used
        // twice: the successor inserted here is rolled back and the chain revoked.
        $oldId = $record->id;
        $userId = $record->userId;
        try {
            $new = $this->repo->transactional(function () use ($oldId, $userId, $client, $now) {
                $inserted = $this->insert($userId, $client);
                if (!$this->repo->markRevoked($oldId, $now, $inserted[0])) {
                    throw new Unauthenticated(self::INVALID);
                }

                return $inserted[1];
            });
        } catch (Unauthenticated $e) {
            $this->repo->revokeAllForUser($userId, $now);
            throw $e;
        }

        return ['userId' => $userId, 'token' => $company . '.' . $new];
    }

    public function revoke(string $token, int $userId): bool
    {
        $record = $this->repo->findByHash(hash('sha256', self::parse($token)[1]));
        if ($record === null || $record->id === null || $record->userId !== $userId || $record->revokedAt !== null) {
            return false;
        }
        return $this->repo->markRevoked($record->id, $this->clock->now(), null);
    }

    public function revokeAll(int $userId): int
    {
        return $this->repo->revokeAllForUser($userId, $this->clock->now());
    }

    public function purgeExpired(int $limit = 100): int
    {
        return $this->repo->deleteExpired($this->clock->now(), $limit);
    }

    /**
     * @return array{0: int, 1: string} the new row's id and the secret
     */
    private function insert(int $userId, string $client): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();

        $record = new RefreshTokenRecord();
        $record->userId = $userId;
        $record->tokenHash = hash('sha256', $secret);
        $record->issuedAt = $now;
        $record->expiresAt = $now->modify('+' . $this->config->refreshTtl . ' seconds');
        $record->client = substr($client, 0, 255);

        return [$this->repo->insert($record), $secret];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function parse(string $token): array
    {
        if (!preg_match('/^(\d{1,6})\.([A-Za-z0-9_-]+)$/', $token, $m)) {
            throw new Unauthenticated(self::INVALID);
        }

        return [(int) $m[1], $m[2]];
    }
}
