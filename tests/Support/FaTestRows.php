<?php

namespace FA\GraphQL\Tests\Support;

/**
 * Cleans up what a test wrote through FrontAccounting.
 *
 * FrontAccounting writes on its own mysqli connection and commits there, so no PDO
 * transaction a test holds can roll those rows back. Every test that writes through
 * a service gives its rows a unique reference prefix, and its tearDown sweeps them.
 */
final class FaTestRows
{
    public static function prefix(): string
    {
        return 'gqlt' . bin2hex(random_bytes(4));
    }

    public static function connect(): \PDO
    {
        $c = $GLOBALS['db_connections'][0];
        $pdo = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Deletes customers and branches whose reference starts with $prefix, the
     * branches of those customers, and every CRM person whose reference starts with
     * it or who is linked to one of them — with their links.
     */
    public static function sweep(\PDO $pdo, string $prefix, string $tb = '0_'): void
    {
        $like = $prefix . '%';
        $customers = self::column($pdo, "SELECT debtor_no FROM {$tb}debtors_master WHERE debtor_ref LIKE ?", [$like]);
        $branchSql = "SELECT branch_code FROM {$tb}cust_branch WHERE branch_ref LIKE ?";
        if ($customers !== []) {
            $branchSql .= ' OR debtor_no IN (' . self::marks($customers) . ')';
        }
        $branches = self::column($pdo, $branchSql, array_merge([$like], $customers));
        $persons = self::column($pdo, "SELECT id FROM {$tb}crm_persons WHERE ref LIKE ?", [$like]);

        foreach ([['customer', $customers], ['cust_branch', $branches]] as [$type, $ids]) {
            if ($ids === []) {
                continue;
            }
            $where = 'type = ? AND entity_id IN (' . self::marks($ids) . ')';
            $params = array_merge([$type], $ids);
            $persons = array_merge(
                $persons,
                self::column($pdo, "SELECT person_id FROM {$tb}crm_contacts WHERE $where", $params)
            );
            self::run($pdo, "DELETE FROM {$tb}crm_contacts WHERE $where", $params);
        }

        $persons = array_values(array_unique($persons));
        if ($persons !== []) {
            $sql = "DELETE FROM {$tb}crm_contacts WHERE person_id IN (" . self::marks($persons) . ')';
            self::run($pdo, $sql, $persons);
            self::run($pdo, "DELETE FROM {$tb}crm_persons WHERE id IN (" . self::marks($persons) . ')', $persons);
        }
        if ($branches !== []) {
            $sql = "DELETE FROM {$tb}cust_branch WHERE branch_code IN (" . self::marks($branches) . ')';
            self::run($pdo, $sql, $branches);
        }
        if ($customers !== []) {
            $sql = "DELETE FROM {$tb}debtors_master WHERE debtor_no IN (" . self::marks($customers) . ')';
            self::run($pdo, $sql, $customers);
        }
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, string>
     */
    private static function column(\PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function run(\PDO $pdo, string $sql, array $params): void
    {
        $pdo->prepare($sql)->execute($params);
    }

    /**
     * @param array<int, mixed> $values
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
