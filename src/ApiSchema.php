<?php

namespace FA\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\DateType;
use Anorm\GraphQL\Type\MangoInput;
use DI\Container;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\FaText;
use FA\GraphQL\Fa\Service\BankReconciliationService;
use FA\GraphQL\Fa\Service\IntKey;
use FA\GraphQL\Fa\Service\InvoiceMailer;
use FA\GraphQL\Fa\Service\PriceQuoteService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Allocation\AllocationType;
use FA\GraphQL\Type\Auth\AuthMutations;
use FA\GraphQL\Type\Auth\AuthPayloadType;
use FA\GraphQL\Type\BankAccount\BankAccountType;
use FA\GraphQL\Type\BankReconciliation\BankReconcileInput;
use FA\GraphQL\Type\BankReconciliation\BankReconciliationType;
use FA\GraphQL\Type\BankTransaction\BankTransactionType;
use FA\GraphQL\Type\Branch\BranchCreateInput;
use FA\GraphQL\Type\Branch\BranchType;
use FA\GraphQL\Type\Branch\BranchUpdateInput;
use FA\GraphQL\Type\Contact\ContactCreateInput;
use FA\GraphQL\Type\Contact\ContactType;
use FA\GraphQL\Type\Contact\ContactUpdateInput;
use FA\GraphQL\Type\CreditStatus\CreditStatusType;
use FA\GraphQL\Type\Currency\CurrencyType;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentCreateInput;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentType;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentUpdateInput;
use FA\GraphQL\Type\Customer\CustomerCreateInput;
use FA\GraphQL\Type\Customer\CustomerType;
use FA\GraphQL\Type\Customer\CustomerUpdateInput;
use FA\GraphQL\Type\DeliveryLine\DeliveryLineType;
use FA\GraphQL\Type\Delivery\DeliveryCreateInput;
use FA\GraphQL\Type\Delivery\DeliveryType;
use FA\GraphQL\Type\FaModelType;
use FA\GraphQL\Type\InvoiceLine\InvoiceLineType;
use FA\GraphQL\Type\Invoice\InvoiceCreateInput;
use FA\GraphQL\Type\Invoice\InvoiceEmailResultType;
use FA\GraphQL\Type\Invoice\InvoiceType;
use FA\GraphQL\Type\Location\LocationType;
use FA\GraphQL\Type\PaymentTerms\PaymentTermsType;
use FA\GraphQL\Type\Price\PriceQuoteType;
use FA\GraphQL\Type\Price\PriceKeyInput;
use FA\GraphQL\Type\Price\PriceSetInput;
use FA\GraphQL\Type\Price\PriceType;
use FA\GraphQL\Type\SalesArea\SalesAreaType;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use FA\GraphQL\Type\SalesType\SalesTypeCreateInput;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use FA\GraphQL\Type\SalesType\SalesTypeUpdateInput;
use FA\GraphQL\Type\Salesman\SalesmanType;
use FA\GraphQL\Type\Shipper\ShipperType;
use FA\GraphQL\Type\StockItem\StockItemCreateInput;
use FA\GraphQL\Type\StockItem\StockItemType;
use FA\GraphQL\Type\StockItem\StockItemUpdateInput;
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
 * Scaffolded by `bin/generate`. apiVersion, me, the <entity>Count fields and the auth mutations are written by
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
                    $this->countField('allocationCount', AllocationType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('allocationList', $this->type(AllocationType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    'apiVersion' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The version of the GraphQL module. Needs no token.',
                        'resolve' => function () {
                            return self::VERSION;
                        },
                    ],
                    $this->countField('bankAccountCount', BankAccountType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('bankAccountList', $this->type(BankAccountType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    'bankReconciliation' => [
                        'type' => Type::nonNull($this->type(BankReconciliationType::class)),
                        'description' => "A bank account's reconciliation at a statement date, as FrontAccounting's "
                            . 'Reconcile Bank Account screen shows it. Needs SA_RECONCILE.',
                        'args' => [
                            'bankAccountId' => ['type' => Type::nonNull(Type::id())],
                            'statementDate' => ['type' => Type::nonNull(DateType::instance())],
                        ],
                        'resolve' => function ($root, array $args) {
                            Guard::require('SA_RECONCILE');

                            return $this->type(BankReconciliationService::class)->summary(
                                IntKey::parse($args['bankAccountId'], 'bankAccountId'),
                                DateConversion::iso($args['statementDate'], 'statementDate')
                            );
                        },
                    ],
                    $this->countField('bankTransactionCount', BankTransactionType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'bankTransactionList',
                        $this->type(BankTransactionType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('branchCount', BranchType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('branchList', $this->type(BranchType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('contactCount', ContactType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('contactList', $this->type(ContactType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('creditStatusCount', CreditStatusType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'creditStatusList',
                        $this->type(CreditStatusType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('currencyCount', CurrencyType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('currencyList', $this->type(CurrencyType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('customerCount', CustomerType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('customerList', $this->type(CustomerType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('customerPaymentCount', CustomerPaymentType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'customerPaymentList',
                        $this->type(CustomerPaymentType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('deliveryCount', DeliveryType::class),
                    $this->countField('deliveryLineCount', DeliveryLineType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'deliveryLineList',
                        $this->type(DeliveryLineType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('deliveryList', $this->type(DeliveryType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('invoiceCount', InvoiceType::class),
                    $this->countField('invoiceLineCount', InvoiceLineType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('invoiceLineList', $this->type(InvoiceLineType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('invoiceList', $this->type(InvoiceType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('locationCount', LocationType::class),
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
                    $this->countField('paymentTermsCount', PaymentTermsType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'paymentTermsList',
                        $this->type(PaymentTermsType::class),
                        'resolveList'
                    )
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('priceCount', PriceType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('priceList', $this->type(PriceType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    'priceQuote' => [
                        'type' => Type::nonNull($this->type(PriceQuoteType::class)),
                        'description' => "What FrontAccounting would charge for an item: get_price() on the "
                            . "customer's price list in its currency, or on the given list (which wins), or on "
                            . 'the base list. Needs SA_SALESORDER.',
                        'args' => [
                            'stockId' => ['type' => Type::nonNull(Type::string())],
                            'customerId' => ['type' => Type::id()],
                            'salesTypeId' => ['type' => Type::id()],
                        ],
                        'resolve' => function ($root, array $args) {
                            Guard::require('SA_SALESORDER');
                            $customerId = isset($args['customerId'])
                                ? IntKey::parse($args['customerId'], 'customerId')
                                : null;
                            $salesTypeId = isset($args['salesTypeId'])
                                ? IntKey::parse($args['salesTypeId'], 'salesTypeId')
                                : null;

                            // Cleaned as a mutation's input is (ServiceCall::each()), so the
                            // code matches as a line's stockId does.
                            return $this->type(PriceQuoteService::class)
                                ->quote(FaText::clean($args['stockId']), $customerId, $salesTypeId);
                        },
                    ],
                    $this->countField('salesAreaCount', SalesAreaType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesAreaList', $this->type(SalesAreaType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('salesOrderCount', SalesOrderType::class),
                    $this->countField('salesOrderLineCount', SalesOrderLineType::class),
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
                    $this->countField('salesTypeCount', SalesTypeType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesTypeList', $this->type(SalesTypeType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('salesmanCount', SalesmanType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesmanList', $this->type(SalesmanType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('shipperCount', ShipperType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('shipperList', $this->type(ShipperType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('stockItemCount', StockItemType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('stockItemList', $this->type(StockItemType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    $this->countField('taxGroupCount', TaxGroupType::class),
                    // anorm-graphql
                    GraphQLUtils::createListField('taxGroupList', $this->type(TaxGroupType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    'bankReconcile' => [
                        'type' => Type::nonNull($this->type(BankReconciliationType::class)),
                        'description' => 'Tick bank entries reconciled to a statement date, or untick them, and record '
                            . "the statement's closing balance, as the Reconcile Bank Account screen does; all or "
                            . 'nothing. Repeating a call changes nothing. Needs SA_RECONCILE.',
                        'args' => [
                            'input' => ['type' => Type::nonNull($this->type(BankReconcileInput::class))],
                        ],
                        'resolve' => function ($root, array $args) {
                            Guard::require('SA_RECONCILE');
                            $service = $this->type(BankReconciliationService::class);
                            $input = $args['input'];
                            ServiceCall::run(function () use ($service, $input): void {
                                $service->reconcile($input);
                            });

                            return $service->summary(
                                IntKey::parse($input['bankAccountId'], 'bankAccountId'),
                                DateConversion::iso($input['statementDate'], 'statementDate')
                            );
                        },
                    ],
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
                    GraphQLUtils::createListField(
                        'customerPaymentCreate',
                        $this->type(CustomerPaymentType::class),
                        'resolveCreate'
                    )
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(CustomerPaymentCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'customerPaymentDelete',
                        $this->type(CustomerPaymentType::class),
                        'resolveDelete'
                    )
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField(
                        'customerPaymentUpdate',
                        $this->type(CustomerPaymentType::class),
                        'resolveUpdate'
                    )
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(CustomerPaymentUpdateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('customerUpdate', $this->type(CustomerType::class), 'resolveUpdate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(CustomerUpdateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('deliveryCreate', $this->type(DeliveryType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(DeliveryCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('deliveryDelete', $this->type(DeliveryType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('invoiceCreate', $this->type(InvoiceType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(InvoiceCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('invoiceDelete', $this->type(InvoiceType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    'invoiceEmail' => [
                        'type' => Type::nonNull(
                            Type::listOf(Type::nonNull($this->type(InvoiceEmailResultType::class)))
                        ),
                        'description' => "Email invoices through FrontAccounting's invoice report, to each "
                            . "customer's invoice (else general) contact. Runs after any writes in the same "
                            . 'request have committed.',
                        'args' => [
                            'id' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::id())))],
                        ],
                        'resolve' => function ($root, array $args) {
                            Guard::require('SA_SALESTRANSVIEW');
                            Guard::require('SA_SALESINVOICE');
                            return $this->type(InvoiceMailer::class)->send($args['id']);
                        },
                    ],
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
                    'priceDelete' => [
                        'type' => Type::nonNull(Type::listOf(Type::nonNull($this->type(PriceType::class)))),
                        'description' => 'Remove prices by item, list and currency; returns the rows removed. '
                            . 'Needs SA_SALESPRICE.',
                        'args' => [
                            'input' => [
                                'type' => Type::nonNull(Type::listOf(Type::nonNull($this->type(PriceKeyInput::class)))),
                            ],
                        ],
                        'resolve' => fn ($root, $args) => $this->type(PriceType::class)
                            ->resolveRemove($root, $args, $this->context),
                    ],
                    'priceSet' => [
                        'type' => Type::nonNull(Type::listOf(Type::nonNull($this->type(PriceType::class)))),
                        'description' => "Add or change prices, one per item, list and currency, as FA's sales "
                            . 'pricing page does. Needs SA_SALESPRICE.',
                        'args' => [
                            'input' => [
                                'type' => Type::nonNull(Type::listOf(Type::nonNull($this->type(PriceSetInput::class)))),
                            ],
                        ],
                        'resolve' => fn ($root, $args) => $this->type(PriceType::class)
                            ->resolveSet($root, $args, $this->context),
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
                    // anorm-graphql
                    GraphQLUtils::createListField('salesTypeCreate', $this->type(SalesTypeType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(SalesTypeCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('salesTypeUpdate', $this->type(SalesTypeType::class), 'resolveUpdate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(SalesTypeUpdateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('stockItemCreate', $this->type(StockItemType::class), 'resolveCreate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(StockItemCreateInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('stockItemUpdate', $this->type(StockItemType::class), 'resolveUpdate')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(StockItemUpdateInput::class))))
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
        $object['query'] = self::withQueryDescriptions($object['query']);
        parent::__construct($object);
    }

    private function type(string $name)
    {
        return $this->context->get($name);
    }

    /**
     * <entity>Count beside <entity>List (issue #17), written by hand: anorm-graphql
     * generates lists only. Resolved by the Type's resolveCount(), which counts what its
     * resolveList() returns with no limit.
     *
     * @return array<string, mixed>
     */
    private function countField(string $name, string $typeClass): array
    {
        $list = substr($name, 0, -strlen('Count')) . 'List';

        /** @var FaModelType $type */
        $type = $this->type($typeClass);

        return GraphQLUtils::createCountField($name, $type)
            ->addArgument('query', $this->type(MangoInput::class), $type->queryDescription())
            ->setDescription(
                "How many rows $list returns for this query with no limit: the same selector, scope and "
                . "access. limit, skip and sort are ignored, so a page's query can be passed as it is."
            )
            ->build();
    }

    /**
     * $query with the description of each generated list's query argument set to
     * the fields it accepts (issue #21). The generated entries stay as anorm-graphql
     * writes them; they are described here, in the field config, which is what
     * SchemaAssembler builds the served Query from.
     */
    private static function withQueryDescriptions(ObjectType $query): ObjectType
    {
        $fields = $query->config['fields'];
        foreach ($fields as $key => $field) {
            if (!is_array($field) || !isset($field['args']['query']) || isset($field['args']['query']['description'])) {
                continue;
            }
            $type = Type::getNamedType($field['type']);
            if ($type instanceof FaModelType) {
                $fields[$key]['args']['query']['description'] = $type->queryDescription();
            }
        }

        return new ObjectType([
            'name' => $query->name,
            'description' => $query->description,
            'fields' => $fields,
        ]);
    }
}
