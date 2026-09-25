<?php

namespace FA\GraphQL\Auth;

use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;

/**
 * Authorisation against FrontAccounting's own security areas. A panel user gets a
 * role holding SA_GRAPHQL plus the sales areas it needs; nothing here invents a
 * parallel permission scheme.
 */
final class Guard
{
    public static function authenticated(): void
    {
        $user = $_SESSION['wa_current_user'] ?? null;
        if (!is_object($user) || !$user->logged_in()) {
            throw new Unauthenticated('You must sign in to do that.');
        }
    }

    public static function require(string $area): void
    {
        self::authenticated();
        if ($area === '' || !$_SESSION['wa_current_user']->can_access($area)) {
            throw new Forbidden("Your role does not include $area.");
        }
    }

    /**
     * @param array<string, string> $areas verb => SA_* code
     */
    public static function requireFor(array $areas, string $verb): void
    {
        self::authenticated();
        if (!isset($areas[$verb])) {
            // A forgotten mapping must not become an open door.
            throw new Forbidden("No security area is defined for '$verb', so it is not allowed.");
        }
        self::require($areas[$verb]);
    }
}
