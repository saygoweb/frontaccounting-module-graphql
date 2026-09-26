<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Contact\Base\ContactUpdateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 */
class ContactUpdateInput extends ContactUpdateInputBase
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
            FieldBuilder::create('links', Type::listOf(Type::nonNull($this->link)))
                ->setDescription('When given, replaces every link; at least one. Omitted: links are kept.')
                ->build(),
        ]);
    }
}
