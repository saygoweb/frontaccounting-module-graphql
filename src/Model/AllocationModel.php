<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * One allocation of a customer payment (or credit) to an invoice (or order):
 * FrontAccounting's cust_allocations. Read-only here; written by FrontAccounting's
 * allocation cart through CustomerPaymentService (Release 3 spec section 5).
 * fromType/toType are FrontAccounting's transaction types (12 payment, 10 invoice,
 * 11 credit note, 30 sales order).
 */
class AllocationModel extends Model
{
    /** @var int */
    public $id;

    /** @var int debtors_master.debtor_no */
    public $customerId;

    /** @var float */
    public $amount;

    /** @var \DateTimeInterface */
    public $date;

    /** @var int */
    public $fromType;

    /** @var int */
    public $fromId;

    /** @var int */
    public $toType;

    /** @var int */
    public $toId;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'cust_allocations', [
            'id' => 'id',
            'customerId' => 'person_id',
            'amount' => 'amt',
            'date' => 'date_alloc',
            'fromType' => 'trans_type_from',
            'fromId' => 'trans_no_from',
            'toType' => 'trans_type_to',
            'toId' => 'trans_no_to',
        ]);
        $mapper->transformers = [
            'date_alloc' => new SqlDateTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
