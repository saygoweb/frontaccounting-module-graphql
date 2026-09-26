<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * Payment terms: FrontAccounting's payment_terms. Read-only through the API. Cash
 * sale when both day counts are 0; prepaid when daysBeforeDue is -1.
 */
class PaymentTermsModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var int Days after the invoice date; -1 for prepaid terms */
    public $daysBeforeDue = 0;

    /** @var int Day of the following month the payment is due; 0 when not used */
    public $dayInFollowingMonth = 0;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'payment_terms', [
            'id' => 'terms_indicator',
            'name' => 'terms',
            'daysBeforeDue' => 'days_before_due',
            'dayInFollowingMonth' => 'day_in_following_month',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
