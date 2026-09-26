<?php

namespace FA\GraphQL\Type;

use Anorm\GraphQL\ModelType;
use Anorm\Model;
use DI\Container;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\NotFound;

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

    /**
     * A client-supplied ID naming an integer key. GraphQL hands IDs over as strings;
     * a string MySQL would cast ("5 anything" is 5) is refused here, not searched.
     *
     * @param mixed $id
     */
    protected static function intId($id, string $field = 'id'): int
    {
        if (is_int($id) && $id > 0) {
            return $id;
        }
        if (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', $id) === 1 && (int) $id <= 2147483647) {
            return (int) $id;
        }
        throw new BadInput("$field must be a positive whole number.", $field);
    }

    /**
     * intId() for each ID of a delete's list, naming the index of a bad one.
     *
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    protected static function intIds(array $ids): array
    {
        $checked = [];
        foreach (array_values($ids) as $index => $id) {
            try {
                $checked[] = self::intId($id);
            } catch (BadInput $e) {
                throw $e->withIndex($index);
            }
        }

        return $checked;
    }

    /**
     * The rows for these keys, read back after a FrontAccounting write the way a list
     * reads them — through resolveList, so scope() and the list verb's area apply —
     * in the order given. FrontAccounting has committed on its own connection by
     * then; the container's PDO, outside any transaction, sees it. A key with no row
     * is NOT_FOUND.
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    protected function rowsById(Container $context, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $found = $this->resolveList(null, ['query' => ['selector' => json_encode(['id' => $id])]], $context);
            if (count($found) !== 1) {
                throw new NotFound($this->name . " id '" . substr((string) $id, 0, 40) . "' not found");
            }
            $rows[] = $found[0];
        }

        return $rows;
    }
}
