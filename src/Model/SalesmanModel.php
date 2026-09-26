<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A salesperson: FrontAccounting's salesman. Read-only through the API.
 */
class SalesmanModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $phone = '';

    /** @var string */
    public $fax = '';

    /** @var string */
    public $email = '';

    /** @var float Commission percentage up to the break point */
    public $provision = 0.0;

    /** @var float Turnover at which provision2 applies */
    public $breakPoint = 0.0;

    /** @var float Commission percentage above the break point */
    public $provision2 = 0.0;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'salesman', [
            'id' => 'salesman_code',
            'name' => 'salesman_name',
            'phone' => 'salesman_phone',
            'fax' => 'salesman_fax',
            'email' => 'salesman_email',
            'provision' => 'provision',
            'breakPoint' => 'break_pt',
            'provision2' => 'provision2',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
