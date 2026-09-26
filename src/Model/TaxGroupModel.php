<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A tax group: FrontAccounting's tax_groups. Read-only through the API.
 */
class TaxGroupModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'tax_groups', [
            'id' => 'id',
            'name' => 'name',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
