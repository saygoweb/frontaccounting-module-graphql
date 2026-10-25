<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A price: FrontAccounting's prices, one row per item, list and currency. Read-only
 * as an entity; written by priceSet and priceDelete through PriceService.
 */
class PriceModel extends Model
{
    /** @var int */
    public $id;

    /** @var string Item code (stock_id) */
    public $stockId = '';

    /** @var int Price list (sales_type_id) */
    public $salesTypeId = 0;

    /** @var string Currency code (curr_abrev) */
    public $currency = '';

    /** @var float */
    public $price = 0.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'prices', [
            'id' => 'id',
            'stockId' => 'stock_id',
            'salesTypeId' => 'sales_type_id',
            'currency' => 'curr_abrev',
            'price' => 'price',
        ]);
        parent::__construct($pdo, $mapper);
    }
}
