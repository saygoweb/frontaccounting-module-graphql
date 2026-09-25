<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting's warnings about work that committed. Its pages abort on any
 * message; an API carries on and tells the client, in the response's top-level
 * extensions.warnings (Release 2 spec section 3.2) — the generated mutations return
 * [<Entity>Type!]!, which has no room for them. Per request: GraphQLAction resets it.
 */
final class Warnings
{
    /** @var string[] */
    private static array $warnings = [];

    public static function add(string $text): void
    {
        if (!in_array($text, self::$warnings, true)) {
            self::$warnings[] = $text;
        }
    }

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return self::$warnings;
    }

    public static function reset(): void
    {
        self::$warnings = [];
    }
}
