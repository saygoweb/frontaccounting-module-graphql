<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting reports validation failures by raising E_USER_ERROR/WARNING/NOTICE
 * through display_error()/display_warning()/display_notification() (fa_trigger_error()
 * in this fork routes all three here directly — see Bootstrap). They are collected
 * so that a write FrontAccounting refused can tell the client why.
 */
final class FaMessages
{
    /** @var string[] */
    private static array $messages = [];

    public static function add(int $level, string $text): void
    {
        self::$messages[] = trim(html_entity_decode(strip_tags($text), ENT_QUOTES));
    }

    /**
     * @return string[]
     */
    public static function drain(): array
    {
        $messages = self::$messages;
        self::$messages = [];

        return $messages;
    }

    public static function reset(): void
    {
        self::$messages = [];
    }
}
