<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A shipping company: FrontAccounting's shippers. Read-only through the API.
 */
class ShipperModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $phone = '';

    /** @var string */
    public $phone2 = '';

    /** @var string */
    public $contact = '';

    /** @var string */
    public $address = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'shippers', [
            'id' => 'shipper_id',
            'name' => 'shipper_name',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'contact' => 'contact',
            'address' => 'address',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
