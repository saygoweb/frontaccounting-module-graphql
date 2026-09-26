<?php

namespace FA\GraphQL\Type;

use Anorm\GraphQL\ModelType;
use Anorm\Model;
use DI\Container;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\IntKey;

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

    /**
     * Set while rowsById() reads back a write's own rows: the verb of that write.
     */
    private ?string $readBackVerb = null;

    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
        // The ledger's ruling (Checkpoint B review M-3): a write's read-back of its
        // own rows is authorised by the write's area, not the list area.
        if ($verb === self::VERB_LIST && $this->readBackVerb !== null) {
            $verb = $this->readBackVerb;
        }
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
        return IntKey::parse($id, $field);
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
     * The rows for these keys, read the way a list reads them — through
     * resolveList, so scope() applies — in the order given, and authorised by $verb:
     * the write they are read for (its create or update read-back, or a delete's
     * read before it deletes), never the list area. FrontAccounting has committed
     * on its own connection by then; the container's PDO, outside any transaction,
     * sees it. A key with no row is NOT_FOUND, naming its index in $ids.
     *
     * @param array<int, int|string> $ids
     * @param string $verb ModelType::VERB_CREATE, VERB_EDIT or VERB_DELETE
     * @return array<int, array<string, mixed>>
     */
    protected function rowsById(Container $context, array $ids, string $verb): array
    {
        $entity = (string) preg_replace('/Type$/', '', (string) $this->name);
        $rows = [];
        $this->readBackVerb = $verb;
        try {
            foreach (array_values($ids) as $index => $id) {
                $found = $this->resolveList(null, ['query' => ['selector' => json_encode(['id' => $id])]], $context);
                if (count($found) !== 1) {
                    throw new NotFound("$entity id '" . substr((string) $id, 0, 40) . "' not found", $index);
                }
                $rows[] = $found[0];
            }
        } finally {
            $this->readBackVerb = null;
        }

        return $rows;
    }
}
