<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

final class ContactLinkInput extends InputObjectType
{
    public function __construct(ContactEntityType $entity, ContactCategoryType $category)
    {
        parent::__construct([
            'name' => 'ContactLinkInput',
            'fields' => [
                'entity' => ['type' => Type::nonNull($entity)],
                'id' => ['type' => Type::nonNull(Type::id())],
                'category' => ['type' => Type::nonNull($category)],
            ],
        ]);
    }
}
