<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\ComputedColumns;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A bank account's entry: FrontAccounting's bank_trans, one row for each account a
 * transaction moves money into or out of. Read-only through the API; reconciling
 * sets `reconciled` through BankReconciliationService (Bank reconciliation spec §3).
 */
class BankTransactionModel extends Model
{
    /** @var int bank_trans.id: the row a reconciliation ticks */
    public $id;

    /** @var int FrontAccounting's transaction type (1 payment, 2 deposit, 4 transfer, 12 customer payment, 22 supplier payment, ...) */
    public $type = 0;

    /** @var int The transaction's number within its type */
    public $transNo = 0;

    /** @var int The bank account (bank_accounts.id) */
    public $bankAccountId;

    /** @var string */
    public $reference = '';

    /** @var \DateTimeInterface */
    public $date;

    /** @var float In the account's currency; negative leaves the account */
    public $amount = 0.0;

    /** @var \DateTimeInterface The statement date it is reconciled to; null when not */
    public $reconciled;

    /** @var int person_type_id: 0 misc, 2 customer, 3 supplier, 4 quick entry, ... */
    public $personType = 0;

    /** @var string person_id: an id, or for misc a free-text name */
    public $personId = '';

    /** @var bool Amount 0: FrontAccounting voids a bank entry by zeroing it; worked out in SQL (ComputedColumns) */
    public $voided;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, ComputedColumns::bankTrans(CompanyContext::prefix(), [
            'voided' => ComputedColumns::bankTransVoided(),
        ]), [
            'id' => 'id',
            'type' => 'type',
            'transNo' => 'trans_no',
            'bankAccountId' => 'bank_act',
            'reference' => 'ref',
            'date' => 'trans_date',
            'amount' => 'amount',
            'reconciled' => 'reconciled',
            'personType' => 'person_type_id',
            'personId' => 'person_id',
            'voided' => 'voided',
        ]);
        $mapper->transformers = [
            'trans_date' => new SqlDateTransform(),
            'reconciled' => new SqlDateTransform(),
            'voided' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
