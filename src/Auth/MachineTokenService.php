<?php

namespace FA\GraphQL\Auth;

use FA\GraphQL\Error\InvalidToken;
use Lcobucci\Clock\Clock;

/**
 * Long-lived, revocable tokens for machines (spec §3.7): issued, listed and revoked
 * offline by bin/fa-token, checked on every request that carries one. The JWT is
 * returned once by issue() and never stored; its jti is.
 */
class MachineTokenService
{
    /** last_used_at is written at most this often, in seconds. */
    public const TOUCH_INTERVAL = 60;

    private const INVALID = 'The access token has been revoked or is not known.';

    private TokenService $tokens;
    private MachineTokenRepository $repo;
    private Clock $clock;

    public function __construct(TokenService $tokens, MachineTokenRepository $repo, Clock $clock)
    {
        $this->tokens = $tokens;
        $this->repo = $repo;
        $this->clock = $clock;
    }

    /**
     * Whoever calls this has already checked that $login may use the API
     * (bin/fa-token does, through FaSession); the token is checked again, as that
     * user, on every request.
     *
     * @throws \InvalidArgumentException bad days or label, or longer than machine_ttl_max
     */
    public function issue(int $company, string $login, int $days, string $label): IssuedMachineToken
    {
        $label = trim($label);
        if ($label === '' || strlen($label) > 255) {
            throw new \InvalidArgumentException('The label must be 1 to 255 characters.');
        }
        if ($days < 1) {
            throw new \InvalidArgumentException('A machine token must live at least one day.');
        }

        $issued = $this->tokens->issueMachine($company, $login, $days * 86400);

        $record = new MachineTokenRecord();
        $record->jti = $issued->jti;
        $record->login = $login;
        $record->label = $label;
        $record->issuedAt = $issued->issuedAt;
        $record->expiresAt = $issued->expiresAt;
        $this->repo->insert($record);

        return $issued;
    }

    /** @return MachineTokenRecord[] oldest first */
    public function list(): array
    {
        return $this->repo->all();
    }

    public function revoke(string $jti): bool
    {
        return $this->repo->revoke($jti, $this->clock->now());
    }

    /**
     * The company's table must be the one $claims->company names: the caller opens
     * that company before building this service's repository.
     *
     * @throws InvalidToken unknown, revoked, expired, or stored for another user
     */
    public function check(Claims $claims): void
    {
        $now = $this->clock->now();
        $record = $this->repo->findByJti($claims->jti);
        if (
            $record === null
            || $record->login !== $claims->login
            || $record->revokedAt !== null
            || $record->expiresAt <= $now
        ) {
            throw new InvalidToken(self::INVALID);
        }

        $staleBefore = $now->modify('-' . self::TOUCH_INTERVAL . ' seconds');
        // Read first, so a token in steady use costs one SELECT and no write; the
        // UPDATE repeats the condition, so concurrent requests write it once.
        if ($record->lastUsedAt === null || $record->lastUsedAt <= $staleBefore) {
            $this->repo->touch($claims->jti, $now, $staleBefore);
        }
    }
}
