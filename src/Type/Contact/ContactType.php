<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use DI\Container;
use FA\GraphQL\Fa\Service\ContactService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Contact\Base\ContactTypeBase;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Contacts are written through FrontAccounting (spec §2), and `links` says what a
 * contact is for: which customers and branches, in which categories.
 *
 * A Contact is a CRM person with at least one customer or branch link (spec §4.3):
 * this Type, guarded by SA_CUSTOMER, never lists, updates or deletes a person who is
 * only, say, a supplier's contact. A delete removes the person's customer and branch
 * links, and the person only when no other link is left (ContactService::delete()).
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

    /**
     * The generated list, narrowed to the Contact scope. ModelType::scope() takes
     * equalities only, so the scope is ANDed into the selector as an `$in` of the
     * persons in scope. The client's selector is embedded as the JSON it sent, after
     * the same object check ModelType makes, so ModelType still checks every field
     * name it holds.
     */
    public function resolveList($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_LIST, null, $context);
        $inScope = ContactLinks::personIdsInScope($context->get(\PDO::class));
        if ($inScope === []) {
            return [];
        }
        $query = isset($args['query']) && is_array($args['query']) ? $args['query'] : [];
        $scope = json_encode(['id' => ['$in' => $inScope]]);
        $selector = $query['selector'] ?? null;
        if ($selector === null || $selector === '') {
            $query['selector'] = $scope;
        } elseif (!json_decode($selector) instanceof \stdClass) {
            throw new UserError("Argument 'query.selector' is not a valid JSON object");
        } else {
            $query['selector'] = '{"$and":[' . $selector . ',' . $scope . ']}';
        }
        $args['query'] = $query;

        return parent::resolveList($root, $args, $context);
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $contacts = $context->get(ContactService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($contacts): int {
            return $contacts->create($input);
        });

        return $this->rowsById($context, $ids, self::VERB_CREATE);
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

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids, self::VERB_DELETE);
        $contacts = $context->get(ContactService::class);
        ServiceCall::each($ids, function (int $id) use ($contacts): void {
            $contacts->delete($id);
        });

        return $rows;
    }
}
