<?php

namespace FA\GraphQL\Type;

use Anorm\GraphQL\ModelType;
use Anorm\Model;
use DI\Container;
use FA\GraphQL\Auth\Guard;

/**
 * The base every generated Type extends: bin/generate passes it to anorm-graphql as
 * --type-base, so each <Entity>TypeBase extends this rather than ModelType.
 *
 * Authorisation is FrontAccounting's. Each Type maps the verbs it allows —
 * ModelType::VERB_LIST, VERB_CREATE, VERB_EDIT, VERB_DELETE — to SA_* areas, and Guard
 * checks the signed-in user's role. It fails closed twice: a generated Type that does
 * not declare areas() cannot be instantiated, and a verb it did not map is Forbidden.
 *
 * Models are built on the container's \PDO, which ModelType::newModel() already does.
 */
abstract class FaModelType extends ModelType
{
    /**
     * @return array<string, string> verb => SA_* code, e.g. ['list' => 'SA_SALESTYPES']
     */
    abstract protected function areas(): array;

    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
        Guard::requireFor($this->areas(), $verb);
    }
}
