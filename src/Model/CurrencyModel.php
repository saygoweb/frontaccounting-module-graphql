<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A currency: FrontAccounting's currencies, keyed by its code ('USD'). Read-only
 * through the API.
 */
class CurrencyModel extends Model
{
    /** @var string */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $symbol = '';

    /** @var string */
    public $country = '';

    /** @var string The name of the hundredth part ('Cents') */
    public $hundredsName = '';

    /** @var bool Exchange rates are updated automatically */
    public $autoUpdate = true;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'currencies', [
            'id' => 'curr_abrev',
            'name' => 'currency',
            'symbol' => 'curr_symbol',
            'country' => 'country',
            'hundredsName' => 'hundreds_name',
            'autoUpdate' => 'auto_update',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'auto_update' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
