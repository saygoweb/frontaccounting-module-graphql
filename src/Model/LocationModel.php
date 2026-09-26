<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A stock location: FrontAccounting's locations, keyed by its code ('DEF'). Read-only
 * through the API.
 */
class LocationModel extends Model
{
    /** @var string */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $deliveryAddress = '';

    /** @var string */
    public $phone = '';

    /** @var string */
    public $phone2 = '';

    /** @var string */
    public $fax = '';

    /** @var string */
    public $email = '';

    /** @var string */
    public $contact = '';

    /** @var bool A location for fixed assets, not for selling from */
    public $fixedAsset = false;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'locations', [
            'id' => 'loc_code',
            'name' => 'location_name',
            'deliveryAddress' => 'delivery_address',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'fax' => 'fax',
            'email' => 'email',
            'contact' => 'contact',
            'fixedAsset' => 'fixed_asset',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'fixed_asset' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
