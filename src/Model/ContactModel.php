<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A contact: a FrontAccounting CRM person (crm_persons). Its links to customers and
 * branches are crm_contacts rows, read by ContactType's `links` and written by
 * ContactService. Foundation spec §4.4 conventions.
 */
class ContactModel extends Model
{
    /** @var int */
    public $id;

    /**
     * @var string
     * @required
     */
    public $ref = '';

    /**
     * @var string
     * @required
     */
    public $name = '';

    /** @var string|null */
    public $name2;

    /** @var string|null */
    public $address;

    /** @var string|null */
    public $phone;

    /** @var string|null */
    public $phone2;

    /** @var string|null */
    public $fax;

    /** @var string|null */
    public $email;

    /** @var string|null Document language code */
    public $lang;

    /** @var string */
    public $notes = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'crm_persons', [
            'id' => 'id',
            'ref' => 'ref',
            'name' => 'name',
            'name2' => 'name2',
            'address' => 'address',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'fax' => 'fax',
            'email' => 'email',
            'lang' => 'lang',
            'notes' => 'notes',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
