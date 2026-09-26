<?php

namespace FA\GraphQL\Type\Delivery;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Delivery\Base\DeliveryCreateInputBase;
use FA\GraphQL\Type\DeliveryLine\DeliveryLineCreateInput;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * The generated Input minus what FrontAccounting takes from the order or computes,
 * plus the order's version, location, comments, the lines and closeOrder (Release 3
 * spec §3).
 */
class DeliveryCreateInput extends DeliveryCreateInputBase
{
    /** Taken from the order or computed by FrontAccounting, never set by a client. */
    public const SERVER_SET = [
        'transType', 'version', 'customerId', 'branchId', 'salesTypeId', 'amount', 'tax', 'freightTax',
        'discount', 'allocated', 'prepaymentAmount', 'rate', 'paymentTermsId', 'taxIncluded',
    ];

    private DeliveryLineCreateInput $lineInput;

    public function __construct(DeliveryLineCreateInput $lineInput)
    {
        $this->lineInput = $lineInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
        $fields[] = FieldBuilder::create('orderVersion', Type::nonNull(Type::int()))
            ->setDescription('The version of the order you read; a stale one is refused.')
            ->build();
        $fields[] = FieldBuilder::create('locationId', Type::id())
            ->setDescription('The location delivered from. Default: the order\'s.')
            ->build();
        $fields[] = FieldBuilder::create('comments', Type::string())->build();
        $closeOrder = FieldBuilder::create('closeOrder', Type::boolean())
            ->setDescription('Cancel whatever of the order this delivery leaves undelivered.')
            ->build();
        $closeOrder['defaultValue'] = false;
        $fields[] = $closeOrder;
        $fields[] = FieldBuilder::create('lines', Type::listOf(Type::nonNull($this->lineInput)))
            ->setDescription(
                'Default: every order line\'s remaining quantity. When given, lines not listed deliver nothing.'
            )
            ->build();

        return $fields;
    }
}
