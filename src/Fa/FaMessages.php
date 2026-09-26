<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting reports validation failures by raising E_USER_ERROR/WARNING/NOTICE
 * through display_error()/display_warning()/display_notification() (fa_trigger_error()
 * routes all three here — see fa_errors_compat.php). They are collected, with their
 * level, so that a write FrontAccounting refused can tell the client why, and a
 * write it accepted with a warning can say so (Release 2 spec section 3.2).
 */
final class FaMessages
{
    /** @var array<int, array{0: int, 1: string}> level, plain text */
    private static array $messages = [];

    public static function add(int $level, string $text): void
    {
        self::$messages[] = [$level, trim(html_entity_decode(strip_tags($text), ENT_QUOTES))];
    }

    /**
     * Every text, whatever its level, in order; clears the buffer.
     *
     * @return string[]
     */
    public static function drain(): array
    {
        $texts = array_column(self::$messages, 1);
        self::$messages = [];

        return $texts;
    }

    /**
     * @return string[] the E_USER_ERROR texts collected so far; the buffer is kept
     */
    public static function errors(): array
    {
        return self::at('errors');
    }

    /**
     * @return string[] the E_USER_WARNING texts collected so far; the buffer is kept
     */
    public static function warnings(): array
    {
        return self::at('warnings');
    }

    /**
     * @return array{errors: string[], warnings: string[], notices: string[]}
     */
    public static function drainByLevel(): array
    {
        $byLevel = [
            'errors' => self::at('errors'),
            'warnings' => self::at('warnings'),
            'notices' => self::at('notices'),
        ];
        self::$messages = [];

        return $byLevel;
    }

    public static function reset(): void
    {
        self::$messages = [];
    }

    /**
     * @return string[]
     */
    private static function at(string $bucket): array
    {
        $texts = [];
        foreach (self::$messages as [$level, $text]) {
            if (self::bucket($level) === $bucket) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private static function bucket(int $level): string
    {
        if ($level === E_USER_ERROR) {
            return 'errors';
        }
        if ($level === E_USER_WARNING) {
            return 'warnings';
        }

        return 'notices';
    }
}
