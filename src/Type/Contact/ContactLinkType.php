<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

final class ContactLinkType extends ObjectType
{
    public function __construct(ContactEntityType $entity, ContactCategoryType $category)
    {
        parent::__construct([
            'name' => 'ContactLink',
            'fields' => [
                'entity' => ['type' => Type::nonNull($entity)],
                'id' => ['type' => Type::nonNull(Type::id())],
                'category' => ['type' => Type::nonNull($category)],
            ],
        ]);
    }
}
