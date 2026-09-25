<?php

namespace FA\GraphQL\Fa;

/**
 * Which company this request is for: its number, table prefix and database
 * credentials. Static because Anorm constructs models itself, as `new $class($pdo)`,
 * so a model has nowhere else to learn its table prefix.
 *
 * Unset, it answers FrontAccounting's stock prefix and never queries — that is the
 * state `anorm-graphql make` and the unit tests run in.
 */
final class CompanyContext
{
    private const DEFAULT_PREFIX = '0_';

    private static ?int $company = null;

    /** @var array<string, mixed> */
    private static array $connection = [];

    private static string $charset = 'utf8';

    /**
     * @param array<string, mixed> $connection one entry of FrontAccounting's $db_connections
     */
    public static function set(int $company, array $connection, string $charset = 'utf8'): void
    {
        self::$company = $company;
        self::$connection = $connection;
        self::$charset = $charset;
    }

    public static function reset(): void
    {
        self::$company = null;
        self::$connection = [];
        self::$charset = 'utf8';
    }

    public static function isSet(): bool
    {
        return self::$company !== null;
    }

    public static function company(): int
    {
        return self::$company === null ? 0 : self::$company;
    }

    public static function prefix(): string
    {
        return self::$company === null ? self::DEFAULT_PREFIX : (string) (self::$connection['tbpref'] ?? '');
    }

    public static function name(): string
    {
        return (string) (self::$connection['name'] ?? '');
    }

    public static function charset(): string
    {
        return self::$charset;
    }

    /**
     * @return array<string, mixed>
     */
    public static function credentials(): array
    {
        if (self::$company === null) {
            throw new \LogicException('No company is selected, so there are no database credentials.');
        }

        return self::$connection;
    }
}
