<?php

namespace FA\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\MangoInput;
use DI\Container;
use FA\GraphQL\Type\Auth\AuthMutations;
use FA\GraphQL\Type\Auth\AuthPayloadType;
use FA\GraphQL\Type\Branch\BranchCreateInput;
use FA\GraphQL\Type\Branch\BranchType;
use FA\GraphQL\Type\Branch\BranchUpdateInput;
use FA\GraphQL\Type\Contact\ContactCreateInput;
use FA\GraphQL\Type\Contact\ContactType;
use FA\GraphQL\Type\Contact\ContactUpdateInput;
use FA\GraphQL\Type\CreditStatus\CreditStatusType;
use FA\GraphQL\Type\Currency\CurrencyType;
use FA\GraphQL\Type\Customer\CustomerCreateInput;
use FA\GraphQL\Type\Customer\CustomerType;
use FA\GraphQL\Type\Customer\CustomerUpdateInput;
use FA\GraphQL\Type\Location\LocationType;
use FA\GraphQL\Type\PaymentTerms\PaymentTermsType;
use FA\GraphQL\Type\SalesArea\SalesAreaType;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use FA\GraphQL\Type\Salesman\SalesmanType;
use FA\GraphQL\Type\Shipper\ShipperType;
use FA\GraphQL\Type\StockItem\StockItemType;
use FA\GraphQL\Type\TaxGroup\TaxGroupType;
use FA\GraphQL\Type\Viewer\ViewerType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Yours to edit. anorm-graphql maintains only the entries under an
 * `// anorm-graphql` comment; remove the comment to take an entry over.
 * Keep the entries of each fields array in alphabetical order.
 *
 * Scaffolded by `bin/generate`. apiVersion, me and the auth mutations are written by
 * hand; everything marked `// anorm-graphql` is the generator's.
 */
class ApiSchema extends Schema
{
    public const VERSION = '0.1.0';

    /** @var Container */
    public $context;

    public function __construct(Container $context)
    {
        $this->context = $context;

        $object = [
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'apiVersion' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The version of the GraphQL module. Needs no token.',
                        'resolve' => function () {
                            return self::VERSION;
                        },
                    ],
                    // anorm-graphql
                    GraphQLUtils::createListField('branchList', $this->type(BranchType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('contactList', $this->type(ContactType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'creditStatusList',
                        $this->type(CreditStatusType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('currencyList', $this->type(CurrencyType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('customerList', $this->type(CustomerType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('locationList', $this->type(LocationType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    'me' => [
                        'type' => Type::nonNull($this->type(ViewerType::class)),
                        'description' => 'The signed-in user.',
                        'resolve' => function () {
                            return $this->type(ViewerType::class)->resolveMe();
                        },
                    ],
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'paymentTermsList',
                        $this->type(PaymentTermsType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesAreaList', $this->type(SalesAreaType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'salesOrderLineList',
                        $this->type(SalesOrderLineType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesOrderList', $this->type(SalesOrderType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesTypeList', $this->type(SalesTypeType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesmanList', $this->type(SalesmanType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('shipperList', $this->type(ShipperType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('stockItemList', $this->type(StockItemType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('taxGroupList', $this->type(TaxGroupType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    // anorm-graphql
                    GraphQLUtils::createListField('branchCreate', $this->type(BranchType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(BranchCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('branchDelete', $this->type(BranchType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('branchUpdate', $this->type(BranchType::class), 'resolveUpdate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(BranchUpdateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('contactCreate', $this->type(ContactType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(ContactCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('contactDelete', $this->type(ContactType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('contactUpdate', $this->type(ContactType::class), 'resolveUpdate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(ContactUpdateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('customerCreate', $this->type(CustomerType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(CustomerCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('customerDelete', $this->type(CustomerType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('customerUpdate', $this->type(CustomerType::class), 'resolveUpdate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(CustomerUpdateInput::class))))
                        )
                        ->build(),
                    'login' => [
                        'type' => Type::nonNull($this->type(AuthPayloadType::class)),
                        'description' => 'Sign in as a FrontAccounting user. Needs no token.',
                        'args' => [
                            'company' => ['type' => Type::int(), 'defaultValue' => 0],
                            'user' => ['type' => Type::nonNull(Type::string())],
                            'password' => ['type' => Type::nonNull(Type::string())],
                        ],
                        'resolve' => function ($root, array $args) {
                            return $this->type(AuthMutations::class)->resolveLogin($root, $args);
                        },
                    ],
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'salesOrderCreate',
                        $this->type(SalesOrderType::class),
                        'resolveCreate'
                    )
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(SalesOrderCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'salesOrderDelete',
                        $this->type(SalesOrderType::class),
                        'resolveDelete'
                    )
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'salesOrderUpdate',
                        $this->type(SalesOrderType::class),
                        'resolveUpdate'
                    )
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(SalesOrderUpdateInput::class))))
                        )
                        ->build(),
                    'tokenRefresh' => [
                        'type' => Type::nonNull($this->type(AuthPayloadType::class)),
                        'description' => 'Exchange a refresh token for a new pair. Needs no token.',
                        'args' => [
                            'refreshToken' => ['type' => Type::nonNull(Type::string())],
                        ],
                        'resolve' => function ($root, array $args) {
                            return $this->type(AuthMutations::class)->resolveTokenRefresh($root, $args);
                        },
                    ],
                    'tokenRevoke' => [
                        'type' => Type::nonNull(Type::boolean()),
                        'description' => 'Revoke one of your refresh tokens, or all of them when none is given.',
                        'args' => [
                            'refreshToken' => ['type' => Type::string()],
                        ],
                        'resolve' => function ($root, array $args) {
                            return $this->type(AuthMutations::class)->resolveTokenRevoke($root, $args);
                        },
                    ],
                ],
            ]),
        ];
        parent::__construct($object);
    }

    private function type(string $name)
    {
        return $this->context->get($name);
    }
}
