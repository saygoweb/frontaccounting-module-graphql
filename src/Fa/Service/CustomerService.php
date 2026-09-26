<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;

/**
 * Customers, written through FrontAccounting's own functions the way
 * sales/manage/customers.php writes them (upstream master line numbers).
 *
 * Run it inside ServiceCall::run(): it opens no transaction of its own. The page's
 * begin_transaction()/commit_transaction() around a new customer (:104, :129) is the
 * caller's one transaction here, and FrontAccounting's own nested begin/commit
 * (add_crm_person) only counts levels inside it.
 *
 * Input is the generated CustomerCreateInput / CustomerUpdateInput as an array:
 * discounts in percent (the page's input_num('discount') / 100, :92), IDs as GraphQL
 * hands them over (strings).
 */
final class CustomerService
{
    /** The generated input fields this service writes (the key and inactive aside). */
    private const FIELDS = [
        'name', 'ref', 'address', 'taxId', 'currencyId', 'salesTypeId', 'creditStatusId',
        'paymentTermsId', 'discountPercent', 'paymentDiscountPercent', 'creditLimit', 'notes',
    ];

    /** Columns that may be NULL; any other explicit null is refused. */
    private const NULLABLE = ['address'];

    private const REFS = [
        'salesTypeId' => ['sales_types', 'id', 'sales type'],
        'paymentTermsId' => ['payment_terms', 'terms_indicator', 'payment terms'],
        'creditStatusId' => ['credit_status', 'id', 'credit status'],
        'currencyId' => ['currencies', 'curr_abrev', 'currency'],
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::customers();
        $customer = $this->validated($input, self::defaults(), null);

        $autoBranch = self::autoCreateBranch();
        if ($autoBranch && !isset($input['branch'])) {
            throw new BadInput(
                'A new customer needs its default branch (auto_create_branch is on): give branch.',
                'branch'
            );
        }
        if (!$autoBranch) {
            foreach (['branch', 'contact'] as $field) {
                if (isset($input[$field])) {
                    throw new BadInput(
                        'auto_create_branch is off, so no branch or contact is created with the customer: '
                        . "leave out $field and use branchCreate or contactCreate.",
                        $field
                    );
                }
            }
        }
        $branch = $autoBranch ? BranchReferences::validated($input['branch'], 'branch.') : null;

        // customers.php:105-110. Dimensions are out of scope (spec §1): 0, as the new-customer form.
        add_customer(
            $customer['name'],
            $customer['ref'],
            (string) $customer['address'],
            $customer['taxId'],
            $customer['currencyId'],
            0,
            0,
            $customer['creditStatusId'],
            $customer['paymentTermsId'],
            self::sqlNumber($customer['discountPercent'] / 100),
            self::sqlNumber($customer['paymentDiscountPercent'] / 100),
            self::sqlNumber($customer['creditLimit']),
            $customer['salesTypeId'],
            $customer['notes']
        );
        $id = (int) db_insert_id();

        if ($branch !== null) {
            // customers.php:112-128: the default branch, its GL accounts from the
            // company preferences, and one CRM person linked to both.
            add_branch(
                $id,
                $customer['name'],
                $customer['ref'],
                (string) $customer['address'],
                $branch['salesmanId'],
                $branch['salesAreaId'],
                $branch['taxGroupId'],
                '',
                get_company_pref('default_sales_discount_act'),
                get_company_pref('debtors_act'),
                get_company_pref('default_prompt_payment_act'),
                $branch['locationId'],
                (string) $customer['address'],
                0,
                $branch['shipperId'],
                $customer['notes'],
                ''
            );
            $branchId = (int) db_insert_id();

            $contact = $input['contact'] ?? [];
            $personId = add_crm_person(
                $customer['ref'],
                $customer['name'],
                '',
                (string) $customer['address'],
                (string) ($contact['phone'] ?? ''),
                (string) ($contact['phone2'] ?? ''),
                (string) ($contact['fax'] ?? ''),
                (string) ($contact['email'] ?? ''),
                '',
                ''
            );
            add_crm_contact('cust_branch', 'general', $branchId, $personId);
            add_crm_contact('customer', 'general', $id, $personId);
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
        $row = get_customer($id);
        if (!$row) {
            throw new NotFound("Customer id '$id' not found");
        }
        $customer = $this->validated($input, self::fromRow($row), $id);

        // customers.php:240-249: the currency is offered for change only while
        // nothing is booked against the customer.
        if ($customer['currencyId'] !== $row['curr_code'] && self::hasTransactionsOrOrders($id)) {
            throw new FaRejected('The currency of a customer with transactions or sales orders cannot be changed.');
        }

        // customers.php:90-96. Dimensions are kept as they are.
        update_customer(
            $id,
            $customer['name'],
            $customer['ref'],
            (string) $customer['address'],
            $customer['taxId'],
            $customer['currencyId'],
            $row['dimension_id'],
            $row['dimension2_id'],
            $customer['creditStatusId'],
            $customer['paymentTermsId'],
            self::sqlNumber($customer['discountPercent'] / 100),
            self::sqlNumber($customer['paymentDiscountPercent'] / 100),
            self::sqlNumber($customer['creditLimit']),
            $customer['salesTypeId'],
            $customer['notes']
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'debtors_master', 'debtor_no');
        }
    }

    public function delete(int $id): void
    {
        FaIncludes::customers();
        if (!get_customer($id)) {
            throw new NotFound("Customer id '$id' not found");
        }
        // customers.php:152-175, in the page's order and with its messages.
        if (key_in_foreign_table($id, 'debtor_trans', 'debtor_no')) {
            throw new FaRejected('This customer cannot be deleted because there are transactions that refer to it.');
        }
        if (key_in_foreign_table($id, 'sales_orders', 'debtor_no')) {
            throw new FaRejected('Cannot delete the customer record because orders have been created against it.');
        }
        if (key_in_foreign_table($id, 'cust_branch', 'debtor_no')) {
            throw new FaRejected('Cannot delete this customer because there are branch records set up against it.');
        }
        delete_customer($id);
    }

    /**
     * $base with the input's fields over it, then checked: can_process()
     * (customers.php:39-77, its order and messages), then what the page gets from
     * its lists and the unique key.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function validated(array $input, array $base, ?int $id): array
    {
        $customer = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null && !in_array($field, self::NULLABLE, true)) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $customer[$field] = $input[$field];
        }

        if (strlen((string) $customer['name']) === 0) {
            throw new BadInput('The customer name cannot be empty.', 'name');
        }
        if (strlen((string) $customer['ref']) === 0) {
            throw new BadInput('The customer short name cannot be empty.', 'ref');
        }
        if (!is_numeric($customer['creditLimit']) || (float) $customer['creditLimit'] < 0) {
            throw new BadInput('The credit limit must be numeric and not less than zero.', 'creditLimit');
        }
        if (!self::isPercent($customer['paymentDiscountPercent'])) {
            throw new BadInput(
                'The payment discount must be numeric and is expected to be less than 100% '
                . 'and greater than or equal to 0.',
                'paymentDiscountPercent'
            );
        }
        if (!self::isPercent($customer['discountPercent'])) {
            throw new BadInput(
                'The discount percentage must be numeric and is expected to be less than 100% '
                . 'and greater than or equal to 0.',
                'discountPercent'
            );
        }

        // debtor_ref is UNIQUE; the page lets the database refuse a duplicate. An API
        // names the field.
        $same = get_customer_by_ref($customer['ref']);
        if ($same && (int) $same['debtor_no'] !== $id) {
            throw new BadInput("A customer with the short name '{$customer['ref']}' already exists.", 'ref');
        }
        ReferenceCheck::requireAll($customer, self::REFS);

        return $customer;
    }

    /**
     * customers.php:196-205, the new-customer form. The credit limit is
     * $SysPrefs->default_credit_limit(), which is this company pref
     * (sysprefs.inc:93-96).
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'name' => '',
            'ref' => '',
            'address' => '',
            'taxId' => '',
            'currencyId' => get_company_currency(),
            'salesTypeId' => null,
            'creditStatusId' => null,
            'paymentTermsId' => null,
            'discountPercent' => 0,
            'paymentDiscountPercent' => 0,
            'creditLimit' => get_company_pref('default_credit_limit'),
            'notes' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row debtors_master
     * @return array<string, mixed>
     */
    private static function fromRow(array $row): array
    {
        return [
            'name' => $row['name'],
            'ref' => $row['debtor_ref'],
            'address' => $row['address'],
            'taxId' => $row['tax_id'],
            'currencyId' => $row['curr_code'],
            'salesTypeId' => $row['sales_type'],
            'creditStatusId' => $row['credit_status'],
            'paymentTermsId' => $row['payment_terms'],
            'discountPercent' => (float) $row['discount'] * 100,
            'paymentDiscountPercent' => (float) $row['pymt_discount'] * 100,
            'creditLimit' => $row['credit_limit'],
            'notes' => $row['notes'],
        ];
    }

    /** customers.php:112 */
    private static function autoCreateBranch(): bool
    {
        return isset($GLOBALS['SysPrefs']->auto_create_branch) && (int) $GLOBALS['SysPrefs']->auto_create_branch === 1;
    }

    private static function hasTransactionsOrOrders(int $id): bool
    {
        return key_in_foreign_table($id, 'debtor_trans', 'debtor_no') > 0
            || key_in_foreign_table($id, 'sales_orders', 'debtor_no') > 0;
    }

    /** @param mixed $value */
    private static function isPercent($value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 100;
    }

    /**
     * add_customer() and update_customer() put discount, pymt_discount and
     * credit_limit into their SQL unquoted (customers_db.inc:19-26, :43-45): a
     * number, never locale-formatted (PHP 7.4's (string) of a float honours
     * LC_NUMERIC), never exponent notation.
     */
    private static function sqlNumber(float $value): string
    {
        return sprintf('%.6F', $value);
    }
}
