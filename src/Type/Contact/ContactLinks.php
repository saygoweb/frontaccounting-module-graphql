<?php

namespace FA\GraphQL\Type\Contact;

use FA\GraphQL\Fa\CompanyContext;

/**
 * Reads crm_contacts on the container's PDO, bound: which persons a customer or
 * branch has, and what a person is linked to. Only the links this API models are
 * returned — customers and branches, in the four system categories — so a
 * supplier link or a custom category never reaches an enum that cannot name it.
 */
final class ContactLinks
{
    private const TYPES = "('customer', 'cust_branch')";

    private const ACTIONS = "('general', 'order', 'delivery', 'invoice')";

    /**
     * @return array<int, int>
     */
    public static function personIds(\PDO $pdo, string $type, int $entityId): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT person_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . ' WHERE type = ? AND entity_id = ? ORDER BY person_id'
        );
        $statement->execute([$type, (string) $entityId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, array{entity: string, id: string, category: string}>
     */
    public static function forPerson(\PDO $pdo, int $personId): array
    {
        $statement = $pdo->prepare(
            'SELECT type, action, entity_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . ' WHERE person_id = ? AND type IN ' . self::TYPES . ' AND action IN ' . self::ACTIONS . ' ORDER BY id'
        );
        $statement->execute([$personId]);
        $links = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $links[] = ['entity' => $row['type'], 'id' => (string) $row['entity_id'], 'category' => $row['action']];
        }

        return $links;
    }
}
