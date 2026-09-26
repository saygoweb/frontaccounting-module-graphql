<?php

namespace FA\GraphQL\Auth;

/**
 * Whether a verified machine token is still live: its jti stored, not revoked, not
 * expired (spec §3.7). Authenticator asks this for every request carrying one.
 */
interface MachineTokenCheck
{
    /**
     * @throws \FA\GraphQL\Error\InvalidToken the token is unknown, revoked or expired
     */
    public function check(Claims $claims): void;
}
