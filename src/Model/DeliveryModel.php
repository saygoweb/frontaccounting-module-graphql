<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A delivery (dispatch) of a sales order: FrontAccounting's debtor_trans type 13,
 * which also holds invoices (10) and payments (12). DeliveryType's scope() keeps the
 * API to type 13 (Release 3 spec §2). Written only through FrontAccounting's Cart
 * (DeliveryService); read here.
 */
class DeliveryModel extends Model
{
    /** @var int trans_no */
    public $id;

    /** @var int 13 for a delivery */
    public $transType = 13;

    /** @var int FrontAccounting raises it when the delivery is invoiced */
    public $version = 0;

    /** @var int */
    public $customerId;

    /** @var int */
    public $branchId;

    /**
     * @var \DateTimeInterface
     * @required
     */
    public $date;

    /** @var \DateTimeInterface The dead-line for invoicing; default: the order's delivery date */
    public $dueDate;

    /** @var string Default: the next automatic reference */
    public $reference = '';

    /** @var int The price list */
    public $salesTypeId;

    /**
     * @var int The sales order delivered
     * @required
     */
    public $orderId;

    /** @var float Goods, net of tax */
    public $amount = 0.0;

    /** @var float */
    public $tax = 0.0;

    /** @var float Default: the order's freight less what earlier deliveries charged */
    public $freight = 0.0;

    /** @var float */
    public $freightTax = 0.0;

    /** @var float */
    public $discount = 0.0;

    /** @var float */
    public $allocated = 0.0;

    /** @var float */
    public $prepaymentAmount = 0.0;

    /** @var float The exchange rate to the company currency */
    public $rate = 1.0;

    /** @var int Default: the order's */
    public $shipperId;

    /** @var int */
    public $paymentTermsId;

    /** @var bool Whether prices include tax */
    public $taxIncluded = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'debtor_trans', [
            'id' => 'trans_no',
            'transType' => 'type',
            'version' => 'version',
            'customerId' => 'debtor_no',
            'branchId' => 'branch_code',
            'date' => 'tran_date',
            'dueDate' => 'due_date',
            'reference' => 'reference',
            'salesTypeId' => 'tpe',
            'orderId' => 'order_',
            'amount' => 'ov_amount',
            'tax' => 'ov_gst',
            'freight' => 'ov_freight',
            'freightTax' => 'ov_freight_tax',
            'discount' => 'ov_discount',
            'allocated' => 'alloc',
            'prepaymentAmount' => 'prep_amount',
            'rate' => 'rate',
            'shipperId' => 'ship_via',
            'paymentTermsId' => 'payment_terms',
            'taxIncluded' => 'tax_included',
        ]);
        $mapper->transformers = [
            'tran_date' => new SqlDateTransform(),
            'due_date' => new SqlDateTransform(),
            'tax_included' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
