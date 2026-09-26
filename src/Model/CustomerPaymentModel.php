<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A customer payment: FrontAccounting's debtor_trans of type 12. The same table holds
 * deliveries (13) and invoices (10); CustomerPaymentType's scope() keeps the API to
 * type 12. Written only through FrontAccounting (CustomerPaymentService). The bank
 * side — account, bank amount, charge — lives in bank_trans and gl_trans; the Type
 * reads it as computed fields (Release 3 spec section 5).
 */
class CustomerPaymentModel extends Model
{
    /** @var int trans_no */
    public $id;

    /** @var int 12: a customer payment */
    public $transType = 12;

    /** @var int */
    public $version = 0;

    /**
     * @var int
     * @required
     */
    public $customerId;

    /** @var int The branch paid against; omit for a customer with no branches */
    public $branchId;

    /**
     * @var \DateTimeInterface The date banked
     * @required
     */
    public $date;

    /** @var string Default: the next automatic reference */
    public $reference = '';

    /**
     * @var float In the customer's currency, before discount
     * @required
     */
    public $amount;

    /** @var float Prompt-payment discount allowed, in the customer's currency */
    public $discount = 0.0;

    /** @var float Allocated to invoices so far */
    public $allocated = 0.0;

    /** @var float The customer currency's rate to the company currency on the date */
    public $rate = 1.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'debtor_trans', [
            'id' => 'trans_no',
            'transType' => 'type',
            'version' => 'version',
            'customerId' => 'debtor_no',
            'branchId' => 'branch_code',
            'date' => 'tran_date',
            'reference' => 'reference',
            'amount' => 'ov_amount',
            'discount' => 'ov_discount',
            'allocated' => 'alloc',
            'rate' => 'rate',
        ]);
        $mapper->transformers = [
            'tran_date' => new SqlDateTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
