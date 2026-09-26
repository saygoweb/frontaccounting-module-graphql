<?php

namespace FA\GraphQL\Type\Invoice;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Invoice\Base\InvoiceCreateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * The generated Input minus what FrontAccounting sets itself or takes from the
 * deliveries, plus the sources and per-line quantities (Release 3 spec section 4).
 * Exactly one source per item: deliveryIds, or orderId with orderVersion.
 */
class InvoiceCreateInput extends InvoiceCreateInputBase
{
    /** Set by FrontAccounting or taken from the deliveries, never by a client. */
    public const SERVER_SET = [
        'transType', 'version', 'customerId', 'branchId', 'salesTypeId', 'amount', 'tax', 'freightTax',
        'discount', 'allocated', 'prepaymentAmount', 'rate', 'taxIncluded',
    ];

    private InvoiceLineQuantityInput $lineInput;

    public function __construct(InvoiceLineQuantityInput $lineInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
        $fields[] = FieldBuilder::create('deliveryIds', Type::listOf(Type::nonNull(Type::id())))
            ->setDescription('Deliveries of one customer and branch to invoice. Or give orderId instead.')
            ->build();
        $fields[] = FieldBuilder::create('orderVersion', Type::int())
            ->setDescription('With orderId: the version of the order you read. The order is delivered and invoiced '
                . 'in one step.')
            ->build();
        $fields[] = FieldBuilder::create('lines', Type::listOf(Type::nonNull($this->lineInput)))
            ->setDescription('With deliveryIds: how much of each delivery line; lines not named are invoiced in full.')
            ->build();
        $fields[] = FieldBuilder::create('comments', Type::string())
            ->build();

        return $fields;
    }
}
