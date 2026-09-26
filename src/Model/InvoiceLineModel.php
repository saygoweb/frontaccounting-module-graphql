<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A line of a sales invoice: FrontAccounting's debtor_trans_details of type 10.
 * Read-only (bin/generate: READONLY); written with its invoice through the Cart.
 * InvoiceLineType's scope() keeps it to invoices' lines.
 */
class InvoiceLineModel extends Model
{
    /** @var int */
    public $id;

    /** @var int debtor_trans_no */
    public $invoiceId;

    /** @var int 10 for an invoice's line */
    public $transType = 10;

    /** @var int The delivery line this invoices (src_id) */
    public $deliveryLineId;

    /** @var string */
    public $stockId;

    /** @var string */
    public $description;

    /** @var float The line price before discount; tax-inclusive when the price list is */
    public $unitPrice = 0.0;

    /** @var float Tax per unit */
    public $unitTax = 0.0;

    /** @var float */
    public $quantity = 0.0;

    /** @var float 0 to 100 */
    public $discountPercent = 0.0;

    /** @var float */
    public $standardCost = 0.0;

    /** @var float Quantity credited so far (qty_done) */
    public $qtyCredited = 0.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'debtor_trans_details', [
            'id' => 'id',
            'invoiceId' => 'debtor_trans_no',
            'transType' => 'debtor_trans_type',
            'deliveryLineId' => 'src_id',
            'stockId' => 'stock_id',
            'description' => 'description',
            'unitPrice' => 'unit_price',
            'unitTax' => 'unit_tax',
            'quantity' => 'quantity',
            'discountPercent' => 'discount_percent',
            'standardCost' => 'standard_cost',
            'qtyCredited' => 'qty_done',
        ]);
        $mapper->transformers = ['discount_percent' => new PercentTransform()];
        parent::__construct($pdo, $mapper);
    }
}
