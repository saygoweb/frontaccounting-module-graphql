<?php

namespace FA\GraphQL\Fa;

/**
 * The one place a FrontAccounting password check is bypassed.
 *
 * FaSession sets this for the instant it calls current_user::login() on behalf of a
 * verified access token; hooks_graphql::authenticate() answers true only for exactly
 * this login in exactly this company; FaSession clears it in a finally. At any other
 * time, and for any other caller including the web UI, nothing matches and
 * FrontAccounting checks the password as it always has.
 */
final class VerifiedIdentity
{
    private static ?int $company = null;
    private static ?string $login = null;

    private function __construct()
    {
    }

    public static function set(int $company, string $login): void
    {
        self::$company = $company;
        self::$login = $login;
    }

    public static function clear(): void
    {
        self::$company = null;
        self::$login = null;
    }

    public static function matches(int $company, string $login): bool
    {
        return self::$login !== null
            && self::$login !== ''
            && self::$company === $company
            && self::$login === $login;
    }
}
