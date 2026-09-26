<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A line of a sales order: FrontAccounting's sales_order_details. Read-only as an
 * entity; its Inputs are nested in the order's (bin/generate: READONLY and
 * INPUT_ONLY), and SalesOrderService writes it through the Cart.
 */
class SalesOrderLineModel extends Model
{
    /** @var int */
    public $id;

    /** @var int order_no */
    public $orderId;

    /** @var int 30 for a sales order's line */
    public $transType = 30;

    /**
     * @var string The item — or a kit, which is expanded into its components on entry
     * @required
     */
    public $stockId;

    /** @var string Default: the item's; honoured only for items whose description is editable */
    public $description;

    /** @var float Quantity delivered so far (qty_sent) */
    public $qtyDelivered = 0.0;

    /** @var float Default: the price list's price */
    public $unitPrice = 0.0;

    /**
     * @var float
     * @required
     */
    public $quantity;

    /** @var float Quantity invoiced so far */
    public $qtyInvoiced = 0.0;

    /** @var float 0 to 100; default: the customer's discount */
    public $discountPercent = 0.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'sales_order_details', [
            'id' => 'id',
            'orderId' => 'order_no',
            'transType' => 'trans_type',
            'stockId' => 'stk_code',
            'description' => 'description',
            'qtyDelivered' => 'qty_sent',
            'unitPrice' => 'unit_price',
            'quantity' => 'quantity',
            'qtyInvoiced' => 'invoiced',
            'discountPercent' => 'discount_percent',
        ]);
        $mapper->transformers = ['discount_percent' => new PercentTransform()];
        parent::__construct($pdo, $mapper);
    }
}
