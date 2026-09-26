<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A delivery's line: FrontAccounting's debtor_trans_details, which also holds invoice
 * and credit lines. DeliveryLineType's scope() keeps the API to type 13. Read-only;
 * its generated DeliveryLineCreateInput nests in DeliveryCreateInput.
 */
class DeliveryLineModel extends Model
{
    /** @var int */
    public $id;

    /** @var int debtor_trans_no */
    public $deliveryId;

    /** @var int 13 for a delivery's line */
    public $transType = 13;

    /** @var string */
    public $stockId = '';

    /** @var string Default: the order line's */
    public $description;

    /** @var float */
    public $unitPrice = 0.0;

    /** @var float */
    public $unitTax = 0.0;

    /**
     * @var float Default: all that is left to deliver of the order line
     * @required
     */
    public $quantity = 0.0;

    /** @var float 0 to 100 */
    public $discountPercent = 0.0;

    /** @var float */
    public $standardCost = 0.0;

    /** @var float Quantity invoiced so far (qty_done) */
    public $qtyInvoiced = 0.0;

    /**
     * @var int The order line delivered (src_id)
     * @required
     */
    public $orderLineId;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'debtor_trans_details', [
            'id' => 'id',
            'deliveryId' => 'debtor_trans_no',
            'transType' => 'debtor_trans_type',
            'stockId' => 'stock_id',
            'description' => 'description',
            'unitPrice' => 'unit_price',
            'unitTax' => 'unit_tax',
            'quantity' => 'quantity',
            'discountPercent' => 'discount_percent',
            'standardCost' => 'standard_cost',
            'qtyInvoiced' => 'qty_done',
            'orderLineId' => 'src_id',
        ]);
        $mapper->transformers = ['discount_percent' => new PercentTransform()];
        parent::__construct($pdo, $mapper);
    }
}
