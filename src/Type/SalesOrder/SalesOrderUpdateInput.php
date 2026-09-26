<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\SalesOrder\Base\SalesOrderUpdateInputBase;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineUpdateInput;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A patch: omitted fields are left as they are. version is required — the one the
 * client read; the order is refused if it has changed since (Release 2 spec section
 * 4.4). lines, when given, replaces the order's lines.
 */
class SalesOrderUpdateInput extends SalesOrderUpdateInputBase
{
    /** Set by FrontAccounting (or never written by it), never by a client. */
    public const SERVER_SET = ['transType', 'template', 'total', 'allocated', 'email'];

    private SalesOrderLineUpdateInput $lineInput;

    public function __construct(SalesOrderLineUpdateInput $lineInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = [];
        foreach (parent::fields() as $field) {
            if (in_array($field['name'], self::SERVER_SET, true)) {
                continue;
            }
            if ($field['name'] === 'version') {
                $field['type'] = Type::nonNull(Type::int());
                $field['description'] = 'The version you read. A changed order is refused: read it again.';
            }
            $fields[] = $field;
        }
        $fields[] = FieldBuilder::create('lines', Type::listOf(Type::nonNull($this->lineInput)))
            ->setDescription(
                'When given, replaces the lines: one with an id is updated, one without is added, '
                . 'an omitted one is deleted. A delivered line cannot be deleted.'
            )
            ->build();

        return $fields;
    }
}
