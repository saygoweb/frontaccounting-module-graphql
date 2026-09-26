<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A sales invoice: FrontAccounting's debtor_trans row of type 10. The table also
 * holds deliveries (13), payments (12) and credit notes (11); InvoiceType's scope()
 * keeps the entity to type 10. Written only through FrontAccounting's Cart
 * (InvoiceService); read here. The key is trans_no, unique within a type.
 */
class InvoiceModel extends Model
{
    /** @var int trans_no */
    public $id;

    /** @var int 10 for an invoice */
    public $transType = 10;

    /** @var int FrontAccounting raises it on every write to the document */
    public $version = 0;

    /** @var int */
    public $customerId;

    /** @var int */
    public $branchId;

    /**
     * @var \DateTimeInterface The invoice date: in the current fiscal year, not on or before the GL closing date
     * @required
     */
    public $date;

    /** @var \DateTimeInterface Default: from the payment terms and the invoice date */
    public $dueDate;

    /** @var string Default: the next invoice reference */
    public $reference = '';

    /** @var int The price list */
    public $salesTypeId;

    /** @var int The sales order invoiced (order_); give it, with orderVersion, to invoice an order in one step */
    public $orderId;

    /** @var float Items, net of discount, before tax (ov_amount) */
    public $amount = 0.0;

    /** @var float Tax on the items (ov_gst) */
    public $tax = 0.0;

    /** @var float Default: the freight of those deliveries none of whose lines was invoiced before */
    public $freight = 0.0;

    /** @var float Tax on the freight */
    public $freightTax = 0.0;

    /** @var float ov_discount */
    public $discount = 0.0;

    /** @var float Payments allocated to the invoice */
    public $allocated = 0.0;

    /** @var float The invoiced part of a prepaid order (not written by this API) */
    public $prepaymentAmount = 0.0;

    /** @var float The customer currency's rate to the home currency */
    public $rate = 1.0;

    /** @var int Default: the delivery's */
    public $shipperId;

    /** @var int Default: the delivery's (the customer's at the time) */
    public $paymentTermsId;

    /** @var bool Prices include tax (the price list's) */
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
