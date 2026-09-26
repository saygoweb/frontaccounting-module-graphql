<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;

/**
 * CRM persons and their links to customers and branches, written through
 * FrontAccounting's own functions (includes/db/crm_contacts_db.inc) with the CRM
 * contact editor's checks (includes/ui/contacts_view.inc:126-142). Run it inside
 * ServiceCall: it opens no transaction of its own.
 *
 * A link is [entity, id, category]: entity 'customer' or 'cust_branch' (crm_contacts.type),
 * category 'general', 'order', 'delivery' or 'invoice' (crm_contacts.action).
 *
 * A contact is a person with at least one such link: this API, guarded by
 * SA_CUSTOMER, is the CRM editor of customers and branches, not of FrontAccounting's
 * other contacts (suppliers', say, which its pages guard with SA_SUPPLIER). A person
 * without one is NOT_FOUND here; an update's links replace only these links; a
 * delete removes only these links, then the person only when no link at all is left
 * (contacts_view.inc db_update()/db_delete()). The spec, section 4.3, says the same.
 */
final class ContactService
{
    /** The crm_contacts types this API models, as ContactLinks reads them. */
    public const LINK_TYPES = ['customer', 'cust_branch'];

    /** The system categories (crm_contacts.action) of those types this API models. */
    public const LINK_CATEGORIES = ['general', 'order', 'delivery', 'invoice'];

    private const FIELDS = ['ref', 'name', 'name2', 'address', 'phone', 'phone2', 'fax', 'email', 'lang', 'notes'];

    private const NULLABLE = ['name2', 'address', 'phone', 'phone2', 'fax', 'email', 'lang'];

    /**
     * No crm_categories type has this name: passed to update_crm_person() as $type,
     * it narrows the link delete of update_person_contacts() (crm_contacts_db.inc:150-174)
     * to nothing, and with no category ids nothing is inserted — the person's links
     * are kept. The service replaces links itself, for several entities at once, which
     * update_person_contacts() cannot (it links one entity).
     */
    private const KEEP_LINKS = 'graphql-keep-links';

    private const ENTITIES = [
        'customer' => ['debtors_master', 'debtor_no', 'customer'],
        'cust_branch' => ['cust_branch', 'branch_code', 'branch'],
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::customers();
        $person = self::validated($input, [
            'ref' => '', 'name' => '', 'name2' => null, 'address' => null, 'phone' => null, 'phone2' => null,
            'fax' => null, 'email' => null, 'lang' => null, 'notes' => '',
        ]);
        $links = self::validatedLinks($input['links'] ?? []);

        // Without $cat_ids: add_crm_person() links only one entity that way, and
        // returns without committing when linking fails (crm_contacts_db.inc:33-37).
        $id = (int) add_crm_person(
            $person['ref'],
            $person['name'],
            $person['name2'],
            $person['address'],
            $person['phone'],
            $person['phone2'],
            $person['fax'],
            $person['email'],
            $person['lang'],
            $person['notes']
        );
        foreach ($links as [$type, $action, $entityId]) {
            add_crm_contact($type, $action, $entityId, $id);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input with an int 'id'
     */
    public function update(array $input): void
    {
        FaIncludes::customers();
        $id = (int) $input['id'];
        $row = self::contact($id);
        $person = self::validated($input, array_intersect_key($row, array_flip(self::FIELDS)));
        $links = array_key_exists('links', $input) && $input['links'] !== null
            ? self::validatedLinks($input['links'])
            : null;

        update_crm_person(
            $id,
            $person['ref'],
            $person['name'],
            $person['name2'],
            $person['address'],
            $person['phone'],
            $person['phone2'],
            $person['fax'],
            $person['email'],
            $person['lang'],
            $person['notes'],
            [],
            null,
            self::KEEP_LINKS
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'crm_persons', 'id');
        }
        if ($links !== null) {
            self::removeOwnLinks($id);
            foreach ($links as [$type, $action, $entityId]) {
                add_crm_contact($type, $action, $entityId, $id);
            }
        }
    }

    /**
     * contacts_view.inc db_delete(): the person's customer and branch links go; the
     * person goes too only when no link is left. One who is still, say, a supplier's
     * contact is kept, with that link.
     */
    public function delete(int $id): void
    {
        FaIncludes::customers();
        self::contact($id);
        self::removeOwnLinks($id);
        if (self::linkCount($id, false) === 0) {
            delete_crm_person($id);
        }
    }

    /**
     * The crm_persons row, read directly: get_crm_person() adds 'contacts' to what
     * db_fetch() returned, which for a missing id is false — auto-vivification PHP
     * deprecated in 8.1. A person with no customer or branch link is not a contact
     * of this API.
     *
     * @return array<string, mixed>
     */
    private static function contact(int $id): array
    {
        $result = db_query(
            'SELECT * FROM ' . TB_PREF . 'crm_persons WHERE id=' . db_escape($id),
            'could not read the contact'
        );
        $row = db_fetch($result);
        if (!$row || self::linkCount($id, true) === 0) {
            throw new NotFound("Contact id '$id' not found");
        }

        return $row;
    }

    /**
     * The person's links: only those this API models, or all of them.
     */
    private static function linkCount(int $id, bool $ownOnly): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . TB_PREF . 'crm_contacts WHERE person_id=' . db_escape($id);
        if ($ownOnly) {
            $sql .= ' AND type IN (' . self::escapedList(self::LINK_TYPES) . ')'
                . ' AND action IN (' . self::escapedList(self::LINK_CATEGORIES) . ')';
        }
        $row = db_fetch_row(db_query($sql, 'could not count the contact links'));

        return (int) $row[0];
    }

    /**
     * Through delete_crm_contacts() (crm_contacts_db.inc), one type and category at
     * a time, as the editor narrows it to its class: every other link is kept.
     */
    private static function removeOwnLinks(int $id): void
    {
        foreach (self::LINK_TYPES as $type) {
            foreach (self::LINK_CATEGORIES as $action) {
                delete_crm_contacts($id, $type, null, $action);
            }
        }
    }

    /**
     * @param string[] $values constants of this class, never client input
     */
    private static function escapedList(array $values): string
    {
        return implode(', ', array_map('db_escape', $values));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function validated(array $input, array $base): array
    {
        $person = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null && !in_array($field, self::NULLABLE, true)) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $person[$field] = $input[$field];
        }
        // contacts_view.inc:127-136
        if (strlen((string) $person['name']) === 0) {
            throw new BadInput('The contact name cannot be empty.', 'name');
        }
        if (strlen((string) $person['ref']) === 0) {
            throw new BadInput('Contact reference cannot be empty.', 'ref');
        }

        return $person;
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function validatedLinks(array $links): array
    {
        // contacts_view.inc:137-141
        if ($links === []) {
            throw new BadInput('You have to select at least one category.', 'links');
        }
        $checked = [];
        foreach (array_values($links) as $i => $link) {
            $type = (string) ($link['entity'] ?? '');
            $action = (string) ($link['category'] ?? '');
            $entityId = (string) ($link['id'] ?? '');
            if (!isset(self::ENTITIES[$type])) {
                throw new BadInput("A contact links to a customer or a branch, not '$type'.", "links.$i.entity");
            }
            [$table, $column, $noun] = self::ENTITIES[$type];
            $known = preg_match('/^[1-9][0-9]{0,9}$/', $entityId) === 1
                && ReferenceCheck::exists($table, $column, $entityId);
            if (!$known) {
                throw new BadInput("There is no $noun '$entityId'.", "links.$i.id");
            }
            if (!in_array($action, self::LINK_CATEGORIES, true) || !self::categoryInUse($type, $action)) {
                throw new BadInput("There is no contact category $type/$action in use.", "links.$i.category");
            }
            $checked[] = [$type, $action, $entityId];
        }

        return $checked;
    }

    private static function categoryInUse(string $type, string $action): bool
    {
        $result = db_query(
            'SELECT inactive FROM ' . TB_PREF . 'crm_categories WHERE type=' . db_escape($type)
            . ' AND action=' . db_escape($action),
            'could not read the contact category'
        );
        $row = db_fetch($result);

        return $row && !$row['inactive'];
    }
}
