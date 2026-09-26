<?php

namespace FA\GraphQL\Type\Contact;

use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\Service\ContactService;

/**
 * Reads crm_contacts on the container's PDO, bound: which persons a customer or
 * branch has, and what a person is linked to. Only the links this API models are
 * read — customers and branches, in the four system categories
 * (ContactService::LINK_TYPES, LINK_CATEGORIES) — so a supplier link or a custom
 * category never reaches an enum that cannot name it, and a person with no such
 * link is not a Contact at all (spec section 4.3).
 */
final class ContactLinks
{
    /**
     * The persons with at least one link this API models: the Contact scope.
     *
     * @return array<int, int>
     */
    public static function personIdsInScope(\PDO $pdo): array
    {
        [$where, $params] = self::modelled();
        $statement = $pdo->prepare(
            'SELECT DISTINCT person_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . " WHERE $where ORDER BY person_id"
        );
        $statement->execute($params);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, int>
     */
    public static function personIds(\PDO $pdo, string $type, int $entityId): array
    {
        [$where, $params] = self::modelled();
        $statement = $pdo->prepare(
            'SELECT DISTINCT person_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . " WHERE type = ? AND entity_id = ? AND $where ORDER BY person_id"
        );
        $statement->execute(array_merge([$type, (string) $entityId], $params));

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, array{entity: string, id: string, category: string}>
     */
    public static function forPerson(\PDO $pdo, int $personId): array
    {
        [$where, $params] = self::modelled();
        $statement = $pdo->prepare(
            'SELECT type, action, entity_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . " WHERE person_id = ? AND $where ORDER BY id"
        );
        $statement->execute(array_merge([$personId], $params));
        $links = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $links[] = ['entity' => $row['type'], 'id' => (string) $row['entity_id'], 'category' => $row['action']];
        }

        return $links;
    }

    /**
     * @return array{0: string, 1: array<int, string>} the condition and its bound values
     */
    private static function modelled(): array
    {
        $types = ContactService::LINK_TYPES;
        $categories = ContactService::LINK_CATEGORIES;
        $where = 'type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')'
            . ' AND action IN (' . implode(', ', array_fill(0, count($categories), '?')) . ')';

        return [$where, array_merge($types, $categories)];
    }
}
