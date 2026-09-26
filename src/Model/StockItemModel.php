<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A stock item: FrontAccounting's stock_master, keyed by its code ('101'). Read-only
 * through the API, and only the columns an order needs: GL accounts, costs and
 * depreciation are left out.
 *
 * Sellable, as FrontAccounting's order entry offers items: mbFlag is not 'F' (fixed
 * asset), and neither inactive nor noSale. A client filters with a Mango selector.
 */
class StockItemModel extends Model
{
    /** @var string */
    public $id;

    /** @var int */
    public $categoryId = 0;

    /** @var int */
    public $taxTypeId = 0;

    /** @var string */
    public $description = '';

    /** @var string */
    public $longDescription = '';

    /** @var string */
    public $units = 'each';

    /** @var string 'B' bought, 'M' manufactured, 'D' service, 'F' fixed asset */
    public $mbFlag = 'B';

    /** @var bool The description may be changed on an order line */
    public $editable = false;

    /** @var bool */
    public $noSale = false;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'stock_master', [
            'id' => 'stock_id',
            'categoryId' => 'category_id',
            'taxTypeId' => 'tax_type_id',
            'description' => 'description',
            'longDescription' => 'long_description',
            'units' => 'units',
            'mbFlag' => 'mb_flag',
            'editable' => 'editable',
            'noSale' => 'no_sale',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'editable' => new BooleanTransform(),
            'no_sale' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
