<?php

namespace FA\GraphQL\Type;

use Anorm\GraphQL\ModelType;
use Anorm\Model;
use DI\Container;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Error\Forbidden;

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
     * @return array<string, string> verb => SA_* code, e.g. ['list' => 'SA_SALESORDER']
     */
    abstract protected function areas(): array;

    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
        Guard::requireFor($this->areas(), $verb);
    }

    private const NO_WRITE_PATH = 'This entity is written through FrontAccounting; no write path is declared.';

    /*
     * Release 2 spec section 2.2: no generated write reaches a FrontAccounting table
     * directly. ModelType's own create/update/delete/upsert would write the model's
     * table with Anorm, bypassing FrontAccounting's references, audit trail, hooks and
     * pricing. A writable Type overrides these to authorize() and then call its
     * service through ServiceCall; one that does not refuses, the same way a Type that
     * forgot areas() cannot be loaded.
     */

    public function resolveCreate($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }

    public function resolveUpsert($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }
}
