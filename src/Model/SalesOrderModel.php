<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A sales order: FrontAccounting's sales_orders, which also holds quotations
 * (trans_type 32). SalesOrderType's scope() keeps the API to trans_type 30.
 * Written only through FrontAccounting's Cart (SalesOrderService); read here.
 * Drafted by `anorm make`, then given the conventions of the Foundation spec
 * section 4.4.
 */
class SalesOrderModel extends Model
{
    /** @var int order_no */
    public $id;

    /** @var int 30 for a sales order, 32 for a quotation */
    public $transType = 30;

    /** @var int FrontAccounting raises it on every write; salesOrderUpdate must carry the one it read */
    public $version = 0;

    /** @var bool A template order (FrontAccounting's "type") */
    public $template = false;

    /**
     * @var int
     * @required
     */
    public $customerId;

    /**
     * @var int
     * @required
     */
    public $branchId;

    /** @var string Default: the next automatic reference */
    public $reference = '';

    /** @var string The customer's own reference (a purchase order number, say) */
    public $customerRef = '';

    /** @var string */
    public $comments;

    /**
     * @var \DateTimeInterface
     * @required
     */
    public $orderDate;

    /** @var int The price list; default: the customer's */
    public $salesTypeId;

    /** @var int Default: the branch's */
    public $shipperId;

    /** @var string */
    public $deliveryAddress = '';

    /** @var string */
    public $phone;

    /** @var string Read only: FrontAccounting 2.4 never writes this column */
    public $email;

    /** @var string */
    public $deliverTo = '';

    /** @var float */
    public $freight = 0.0;

    /**
     * @var string The location delivered from (from_stk_loc); default: the branch's.
     * A reference, so it ends in Id, as BranchModel's locationId (spec section 4.3).
     */
    public $locationId = '';

    /** @var \DateTimeInterface Default: the order date plus the company's delivery lead time */
    public $deliveryDate;

    /** @var int Default: the customer's */
    public $paymentTermsId;

    /** @var float Computed by FrontAccounting */
    public $total = 0.0;

    /** @var float Required, above 0 and at most the total, for prepaid terms */
    public $prepaymentAmount = 0.0;

    /** @var float Payments allocated to the order */
    public $allocated = 0.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'sales_orders', [
            'id' => 'order_no',
            'transType' => 'trans_type',
            'version' => 'version',
            'template' => 'type',
            'customerId' => 'debtor_no',
            'branchId' => 'branch_code',
            'reference' => 'reference',
            'customerRef' => 'customer_ref',
            'comments' => 'comments',
            'orderDate' => 'ord_date',
            'salesTypeId' => 'order_type',
            'shipperId' => 'ship_via',
            'deliveryAddress' => 'delivery_address',
            'phone' => 'contact_phone',
            'email' => 'contact_email',
            'deliverTo' => 'deliver_to',
            'freight' => 'freight_cost',
            'locationId' => 'from_stk_loc',
            'deliveryDate' => 'delivery_date',
            'paymentTermsId' => 'payment_terms',
            'total' => 'total',
            'prepaymentAmount' => 'prep_amount',
            'allocated' => 'alloc',
        ]);
        $mapper->transformers = [
            'type' => new BooleanTransform(),
            'ord_date' => new SqlDateTransform(),
            'delivery_date' => new SqlDateTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
