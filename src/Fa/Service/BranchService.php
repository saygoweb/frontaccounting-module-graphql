<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;

/**
 * Customer branches, written through FrontAccounting's own functions the way
 * sales/manage/customer_branches.php writes them (upstream master line numbers).
 * Run it inside ServiceCall: it opens no transaction of its own.
 */
final class BranchService
{
    private const FIELDS = [
        'name', 'ref', 'address', 'postAddress', 'salesmanId', 'salesAreaId', 'taxGroupId',
        'locationId', 'shipperId', 'notes', 'bankAccount',
    ];

    /** bank_account is the one nullable column; any other explicit null is refused. */
    private const NULLABLE = ['bankAccount'];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::customers();
        $customerId = $input['customerId'] ?? null;
        $customerKnown = $customerId !== null && $customerId !== ''
            && ReferenceCheck::exists('debtors_master', 'debtor_no', IntKey::parse($customerId, 'customerId'));
        if (!$customerKnown) {
            throw new BadInput("There is no customer '$customerId'.", 'customerId');
        }
        $base = [
            'name' => '', 'ref' => '', 'address' => '', 'postAddress' => null, 'notes' => '', 'bankAccount' => null,
            'salesmanId' => null, 'salesAreaId' => null, 'taxGroupId' => null, 'locationId' => null,
            'shipperId' => null,
        ];
        $branch = self::validated($input, $base);
        // customer_branches.php:210: the page offers the address as the postal address.
        $branch['postAddress'] = $branch['postAddress'] ?? $branch['address'];
        $isFirst = !ReferenceCheck::exists('cust_branch', 'debtor_no', $customerId);

        // customer_branches.php:94-99, with the new-branch GL defaults of :213-223.
        add_branch(
            $customerId,
            $branch['name'],
            $branch['ref'],
            $branch['address'],
            $branch['salesmanId'],
            $branch['salesAreaId'],
            $branch['taxGroupId'],
            '',
            get_company_pref('default_sales_discount_act'),
            get_company_pref('debtors_act'),
            get_company_pref('default_prompt_payment_act'),
            $branch['locationId'],
            $branch['postAddress'],
            0,
            $branch['shipperId'],
            $branch['notes'],
            (string) $branch['bankAccount']
        );
        $id = (int) db_insert_id();

        // customer_branches.php:101-105: every new branch gets a CRM person, named
        // and referenced by the contact name. The page defaults that name to
        // "Main Branch" for a customer's first branch (:209) and leaves it blank
        // otherwise; a blank-named person is no use to anyone, so a later branch's
        // person takes the branch's name.
        $contact = $input['contact'] ?? [];
        $name = (string) ($contact['name'] ?? ($isFirst ? _('Main Branch') : $branch['name']));
        $personId = add_crm_person(
            $name,
            $name,
            '',
            $branch['postAddress'],
            (string) ($contact['phone'] ?? ''),
            (string) ($contact['phone2'] ?? ''),
            (string) ($contact['fax'] ?? ''),
            (string) ($contact['email'] ?? ''),
            (string) ($contact['lang'] ?? ''),
            ''
        );
        add_crm_contact('cust_branch', 'general', $id, $personId);

        return $id;
    }

    /**
     * @param array<string, mixed> $input with an int 'id'
     */
    public function update(array $input): void
    {
        FaIncludes::customers();
        $id = (int) $input['id'];
        $row = self::row($id);
        if ($row === null) {
            throw new NotFound("Branch id '$id' not found");
        }
        if (isset($input['customerId']) && (string) $input['customerId'] !== (string) $row['debtor_no']) {
            throw new BadInput('A branch cannot move to another customer.', 'customerId');
        }
        $branch = self::validated($input, [
            'name' => $row['br_name'],
            'ref' => $row['branch_ref'],
            'address' => $row['br_address'],
            'postAddress' => $row['br_post_address'],
            'salesmanId' => $row['salesman'],
            'salesAreaId' => $row['area'],
            'taxGroupId' => $row['tax_group_id'],
            'locationId' => $row['default_location'],
            'shipperId' => $row['default_ship_via'],
            'notes' => $row['notes'],
            'bankAccount' => $row['bank_account'],
        ]);

        // customer_branches.php:84-88. The GL accounts and the sales group are not
        // API fields (spec §1): kept as they are.
        update_branch(
            $row['debtor_no'],
            $id,
            $branch['name'],
            $branch['ref'],
            $branch['address'],
            $branch['salesmanId'],
            $branch['salesAreaId'],
            $branch['taxGroupId'],
            $row['sales_account'],
            $row['sales_discount_account'],
            $row['receivables_account'],
            $row['payment_discount_account'],
            $branch['locationId'],
            $branch['postAddress'],
            $row['group_no'],
            $branch['shipperId'],
            $branch['notes'],
            (string) $branch['bankAccount']
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'cust_branch', 'branch_code');
        }
    }

    public function delete(int $id): void
    {
        FaIncludes::customers();
        $row = self::row($id);
        if ($row === null) {
            throw new NotFound("Branch id '$id' not found");
        }
        // customer_branches.php:122-138, in the page's order and with its messages.
        if (branch_in_foreign_table($row['debtor_no'], $id, 'debtor_trans')) {
            throw new FaRejected(
                'Cannot delete this branch because customer transactions have been created to this branch.'
            );
        }
        if (branch_in_foreign_table($row['debtor_no'], $id, 'sales_orders')) {
            throw new FaRejected(
                'Cannot delete this branch because sales orders exist for it. Purge old sales orders first.'
            );
        }
        delete_branch($row['debtor_no'], $id);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function validated(array $input, array $base): array
    {
        $branch = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null && !in_array($field, self::NULLABLE, true)) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $branch[$field] = $input[$field];
        }
        // customer_branches.php:64-76
        if (strlen((string) $branch['name']) === 0) {
            throw new BadInput('The Branch name cannot be empty.', 'name');
        }
        if (strlen((string) $branch['ref']) === 0) {
            throw new BadInput('The Branch short name cannot be empty.', 'ref');
        }
        BranchReferences::validated($branch);

        return $branch;
    }

    /**
     * The branch row by its key alone. get_branch() (branches_db.inc:82-93) inner-joins
     * the salesperson, so it would miss a branch whose salesperson is gone.
     *
     * @return array<string, mixed>|null
     */
    private static function row(int $id): ?array
    {
        $result = db_query(
            'SELECT * FROM ' . TB_PREF . 'cust_branch WHERE branch_code=' . db_escape($id),
            'could not read the branch'
        );
        $row = db_fetch($result);

        return $row ?: null;
    }
}
