<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Contact\Base\ContactCreateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * A contact is created with at least one link (contacts_view.inc:137-141).
 */
class ContactCreateInput extends ContactCreateInputBase
{
    private ContactLinkInput $link;

    public function __construct(ContactLinkInput $link)
    {
        $this->link = $link;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('links', Type::nonNull(Type::listOf(Type::nonNull($this->link))))
                ->setDescription('At least one: the customers and branches this contact is for, and in which category.')
                ->build(),
        ]);
    }
}
