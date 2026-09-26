<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A credit status: FrontAccounting's credit_status. Read-only through the API. A
 * customer whose status disallows invoices cannot be put on an order
 * (get_customer_details_to_order).
 */
class CreditStatusModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $description = '';

    /** @var bool The customer is on hold */
    public $disallowInvoices = false;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'credit_status', [
            'id' => 'id',
            'description' => 'reason_description',
            // FrontAccounting's spelling, in the column only.
            'disallowInvoices' => 'dissallow_invoices',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'dissallow_invoices' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
