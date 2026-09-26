<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use Anorm\Transform\FunctionTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A customer: FrontAccounting's debtors_master. Written only through
 * CustomerService (CustomerType routes the generated create, update and delete
 * there); read through the generated Type. Drafted by `anorm make`, then given the
 * conventions of the Foundation spec §4.4. Dimensions are left out (spec §1).
 * `@required` marks what customerCreate must be given (non-null in
 * CustomerCreateInput).
 */
class CustomerModel extends Model
{
    /** @var int */
    public $id;

    /**
     * @var string
     * @required
     */
    public $name = '';

    /**
     * @var string Short name, unique (debtor_ref)
     * @required
     */
    public $ref = '';

    /** @var string|null */
    public $address;

    /** @var string Tax registration number (the page's "GSTNo") */
    public $taxId = '';

    /** @var string Currency code (curr_code); defaults to the company currency */
    public $currencyId = '';

    /**
     * @var int Price list (sales_type)
     * @required
     */
    public $salesTypeId;

    /**
     * @var int
     * @required
     */
    public $creditStatusId;

    /**
     * @var int
     * @required
     */
    public $paymentTermsId;

    /** @var float 0-100; stored as a fraction */
    public $discountPercent = 0.0;

    /** @var float 0-100; stored as a fraction (pymt_discount) */
    public $paymentDiscountPercent = 0.0;

    /** @var float */
    public $creditLimit = 0.0;

    /** @var string */
    public $notes = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'debtors_master', [
            'id' => 'debtor_no',
            'name' => 'name',
            'ref' => 'debtor_ref',
            'address' => 'address',
            'taxId' => 'tax_id',
            'currencyId' => 'curr_code',
            'salesTypeId' => 'sales_type',
            'creditStatusId' => 'credit_status',
            'paymentTermsId' => 'payment_terms',
            'discountPercent' => 'discount',
            'paymentDiscountPercent' => 'pymt_discount',
            'creditLimit' => 'credit_limit',
            'notes' => 'notes',
            'inactive' => 'inactive',
        ]);
        // FrontAccounting stores discounts as fractions; the API speaks percent (spec §4.3).
        $percent = new FunctionTransform(
            function ($value) {
                return $value === null ? null : round((float) $value * 100, 6);
            },
            function ($value) {
                return $value === null ? null : (float) $value / 100;
            }
        );
        $mapper->transformers = [
            'discount' => $percent,
            'pymt_discount' => $percent,
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
