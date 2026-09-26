<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A customer branch: FrontAccounting's cust_branch. Its primary key is
 * (branch_code, debtor_no), but branch_code is AUTO_INCREMENT and unique alone, so
 * it is the key here. Written only through BranchService; the GL accounts and sales
 * group are not API fields (spec §1). Foundation spec §4.4 conventions.
 */
class BranchModel extends Model
{
    /** @var int */
    public $id;

    /**
     * @var int The customer (debtor_no)
     * @required
     */
    public $customerId;

    /**
     * @var string
     * @required
     */
    public $name = '';

    /**
     * @var string Short name (branch_ref)
     * @required
     */
    public $ref = '';

    /** @var string */
    public $address = '';

    /** @var string Postal address; defaults to the address */
    public $postAddress = '';

    /**
     * @var int
     * @required
     */
    public $salesmanId;

    /**
     * @var int Sales area (area)
     * @required
     */
    public $salesAreaId;

    /**
     * @var int
     * @required
     */
    public $taxGroupId;

    /**
     * @var string Default inventory location (default_location)
     * @required
     */
    public $locationId = '';

    /**
     * @var int Default shipper (default_ship_via)
     * @required
     */
    public $shipperId;

    /** @var string */
    public $notes = '';

    /** @var string|null */
    public $bankAccount;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'cust_branch', [
            'id' => 'branch_code',
            'customerId' => 'debtor_no',
            'name' => 'br_name',
            'ref' => 'branch_ref',
            'address' => 'br_address',
            'postAddress' => 'br_post_address',
            'salesmanId' => 'salesman',
            'salesAreaId' => 'area',
            'taxGroupId' => 'tax_group_id',
            'locationId' => 'default_location',
            'shipperId' => 'default_ship_via',
            'notes' => 'notes',
            'bankAccount' => 'bank_account',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
