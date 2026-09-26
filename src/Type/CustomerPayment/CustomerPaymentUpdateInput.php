<?php

namespace FA\GraphQL\Type\CustomerPayment;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\CustomerPayment\Base\CustomerPaymentUpdateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A posted payment's allocations, and nothing else, can change (Release 3 spec
 * section 5, ruling 4). The generated fields stay — the schema is generated — and
 * CustomerPaymentService refuses any of them but id.
 */
class CustomerPaymentUpdateInput extends CustomerPaymentUpdateInputBase
{
    private AllocationInput $allocationInput;

    public function __construct(AllocationInput $allocationInput)
    {
        $this->allocationInput = $allocationInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = parent::fields();
        $fields[] = FieldBuilder::create('allocations', Type::listOf(Type::nonNull($this->allocationInput)))
            ->setDescription('Required: replaces the payment\'s allocations; [] removes them. '
                . 'Every other field is refused: void the payment and enter it again.')
            ->build();

        return $fields;
    }
}
