<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A bank or cash account a customer payment is paid into: FrontAccounting's
 * bank_accounts. Read-only through the API (Release 3 spec §5).
 */
class BankAccountModel extends Model
{
    /** @var int */
    public $id;

    /** @var string The GL account (chart_master.account_code) */
    public $glAccountId = '';

    /** @var int 0 savings, 1 chequing, 2 credit, 3 cash */
    public $accountType = 0;

    /** @var string */
    public $name = '';

    /** @var string */
    public $number = '';

    /** @var string */
    public $bankName = '';

    /** @var string */
    public $bankAddress;

    /** @var string The account's currency (currencies.curr_abrev) */
    public $currencyId = '';

    /** @var bool The default account for its currency */
    public $defaultForCurrency = false;

    /** @var string The GL account bank charges are posted to */
    public $chargeAccountId = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'bank_accounts', [
            'id' => 'id',
            'glAccountId' => 'account_code',
            'accountType' => 'account_type',
            'name' => 'bank_account_name',
            'number' => 'bank_account_number',
            'bankName' => 'bank_name',
            'bankAddress' => 'bank_address',
            'currencyId' => 'bank_curr_code',
            'defaultForCurrency' => 'dflt_curr_act',
            'chargeAccountId' => 'bank_charge_act',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'dflt_curr_act' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
