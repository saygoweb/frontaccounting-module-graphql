<?php

namespace FA\GraphQL\Fa\Service;

/**
 * The five references every branch carries, named as the generated Branch Type
 * names them (Task 6): customer_branches.php requires each to exist before a branch
 * can be added (:29-37) and offers them from lists.
 */
final class BranchReferences
{
    public const REFS = [
        'salesmanId' => ['salesman', 'salesman_code', 'salesperson', true],
        'salesAreaId' => ['areas', 'area_code', 'sales area', true],
        'taxGroupId' => ['tax_groups', 'id', 'tax group', true],
        'locationId' => ['locations', 'loc_code', 'location', false],
        'shipperId' => ['shippers', 'shipper_id', 'shipper', true],
    ];

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> just the five, checked
     */
    public static function validated(array $values, string $fieldPrefix = ''): array
    {
        ReferenceCheck::requireAll($values, self::REFS, $fieldPrefix);

        return array_intersect_key($values, self::REFS);
    }
}
