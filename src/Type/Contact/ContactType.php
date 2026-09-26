<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use DI\Container;
use FA\GraphQL\Fa\Service\ContactService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Contact\Base\ContactTypeBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Contacts are written through FrontAccounting (spec §2), and `links` says what a
 * contact is for: which customers and branches, in which categories.
 */
class ContactType extends ContactTypeBase
{
    private ContactLinkType $linkType;

    public function __construct(ContactLinkType $linkType)
    {
        $this->linkType = $linkType;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('links', Type::nonNull(Type::listOf(Type::nonNull($this->linkType))))
                ->setDescription('The customers and branches this contact is linked to, and for what.')
                ->setResolver(function (array $row, $args, Container $context): array {
                    return ContactLinks::forPerson($context->get(\PDO::class), (int) $row['id']);
                })
                ->build(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_CUSTOMER',
            self::VERB_CREATE => 'SA_CUSTOMER',
            self::VERB_EDIT => 'SA_CUSTOMER',
            self::VERB_DELETE => 'SA_CUSTOMER',
        ];
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $contacts = $context->get(ContactService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($contacts): int {
            return $contacts->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $contacts = $context->get(ContactService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($contacts): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $contacts->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids);
        $contacts = $context->get(ContactService::class);
        ServiceCall::each($ids, function (int $id) use ($contacts): void {
            $contacts->delete($id);
        });

        return $rows;
    }
}
