<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Extension\AcceptsContributions;
use FA\GraphQL\Extension\ExtensibleType;
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Type\SalesOrder\Base\SalesOrderCreateInputBase;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineCreateInput;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * The generated Input minus what FrontAccounting sets itself, plus the order's lines
 * (Release 2 spec section 4.4). email is dropped: FrontAccounting 2.4's
 * add_sales_order() never writes contact_email.
 */
class SalesOrderCreateInput extends SalesOrderCreateInputBase implements ExtensibleType
{
    use AcceptsContributions;

    /** Set by FrontAccounting (or never written by it), never by a client. */
    public const SERVER_SET = ['transType', 'version', 'template', 'total', 'allocated', 'email'];

    private SalesOrderLineCreateInput $lineInput;

    private RecurrenceInputType $recurrenceInput;

    /**
     * @param Extensions|null $extensions the request's; null (unit tests) serves the core's fields only
     */
    public function __construct(
        SalesOrderLineCreateInput $lineInput,
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
        $fields = array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
        $fields[] = FieldBuilder::create('lines', Type::nonNull(Type::listOf(Type::nonNull($this->lineInput))))
            ->setDescription('At least one. A kit is expanded into its components.')
            ->build();

        $fields[] = FieldBuilder::create('recurring', $this->recurrenceInput)
            ->setDescription('A recurring schedule. Needs the sgw_sales extension, active for the company.')
            ->build();

        return $fields;
    }
}
