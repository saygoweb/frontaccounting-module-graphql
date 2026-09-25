<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A sales type — a price list: FrontAccounting's sales_types. Read-only through the
 * API (bin/generate lists it in READONLY). Drafted by `anorm make`, then given the
 * conventions of spec §4.4: domain names, declared types, PHP defaults for the
 * NOT NULL DEFAULT columns, the company's prefix, and booleans that are booleans.
 */
class SalesTypeModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var bool Prices on this list include tax */
    public $taxIncluded = false;

    /** @var float Multiplier applied to the base price list */
    public $factor = 1.0;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'sales_types', [
            'id' => 'id',
            'name' => 'sales_type',
            'taxIncluded' => 'tax_included',
            'factor' => 'factor',
            'inactive' => 'inactive',
        ]);
        // Keyed by column. tax_included is int(1) and inactive tinyint(1): PDO hands
        // back 0 or 1, and a GraphQL Boolean should not have to guess.
        $mapper->transformers = [
            'tax_included' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
