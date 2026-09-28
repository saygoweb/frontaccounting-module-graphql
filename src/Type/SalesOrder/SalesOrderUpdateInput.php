<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Extension\AcceptsContributions;
use FA\GraphQL\Extension\ExtensibleType;
use FA\GraphQL\Extension\Extensions;
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
class SalesOrderUpdateInput extends SalesOrderUpdateInputBase implements ExtensibleType
{
    use AcceptsContributions;

    /** Set by FrontAccounting (or never written by it), never by a client. */
    public const SERVER_SET = ['transType', 'template', 'total', 'allocated', 'email'];

    private SalesOrderLineUpdateInput $lineInput;

    private RecurrenceInputType $recurrenceInput;

    /**
     * @param Extensions|null $extensions the request's; null (unit tests) serves the core's fields only
     */
    public function __construct(
        SalesOrderLineUpdateInput $lineInput,
        RecurrenceInputType $recurrenceInput,
        ?Extensions $extensions = null
    ) {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        $this->recurrenceInput = $recurrenceInput;
        parent::__construct();
        // The fields above are the core's; the extensions' (nullable, Release 4 spec
        // §2.5) are appended on first use.
        $this->acceptContributions($extensions);
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

        $fields[] = FieldBuilder::create('recurring', $this->recurrenceInput)
            ->setDescription('Set or replace the recurring schedule; to end it, give an end. Needs sgw_sales.')
            ->build();

        return $fields;
    }
}
