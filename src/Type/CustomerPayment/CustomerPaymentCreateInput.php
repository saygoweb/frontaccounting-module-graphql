<?php

namespace FA\GraphQL\Type\CustomerPayment;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\CustomerPayment\Base\CustomerPaymentCreateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A payment as customer_payments.php takes it: the generated debtor_trans columns a
 * client sets, the bank side (not debtor_trans columns), and the allocations
 * (Release 3 spec section 5).
 */
class CustomerPaymentCreateInput extends CustomerPaymentCreateInputBase
{
    /** Set by FrontAccounting, never by a client. */
    public const SERVER_SET = ['transType', 'version', 'allocated', 'rate'];

    private AllocationInput $allocationInput;

    public function __construct(AllocationInput $allocationInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->allocationInput = $allocationInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = [];
        foreach (parent::fields() as $field) {
            if (!in_array($field['name'], self::SERVER_SET, true)) {
                $fields[] = $field;
            }
        }
        $fields[] = FieldBuilder::create('bankAccountId', Type::nonNull(Type::id()))
            ->setDescription('The bank account paid into (bankAccountList).')
            ->build();
        $fields[] = FieldBuilder::create('bankAmount', Type::float())
            ->setDescription('In the bank account\'s currency, before any charge. Default: the amount, '
                . 'when the currencies are the same; required otherwise.')
            ->build();
        $fields[] = FieldBuilder::create('charge', Type::float())
            ->setDescription('Bank charge, in the bank account\'s currency. Default 0.')
            ->build();
        $fields[] = FieldBuilder::create('memo', Type::string())
            ->build();
        $fields[] = FieldBuilder::create('allocations', Type::listOf(Type::nonNull($this->allocationInput)))
            ->setDescription('Invoices this payment pays, and how much of each. Only these are allocated.')
            ->build();

        return $fields;
    }
}
