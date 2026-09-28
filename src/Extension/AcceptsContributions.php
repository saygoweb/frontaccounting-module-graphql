<?php

namespace FA\GraphQL\Extension;

/**
 * For an ExtensibleType (an ObjectType or InputObjectType). Call acceptContributions()
 * after parent::__construct(): the fields built so far become the core's, and the
 * type's fields turn into a thunk that appends the company's extension fields when
 * webonyx first reads them.
 *
 * Lazily, not in fields(): the loader must know the core's root fields, which come
 * from ApiSchema, and ApiSchema is built from this very type.
 */
trait AcceptsContributions
{
    /** @var array<int|string, mixed> */
    private array $coreFields = [];

    private function acceptContributions(?Extensions $extensions): void
    {
        $core = $this->config['fields'];
        if (is_callable($core)) {
            $core = $core();
        }
        if (!is_array($core)) {
            // Anything else would silently serve the extensions' fields alone.
            throw new \LogicException("{$this->name}'s core fields must be an array or a thunk returning one");
        }
        $this->coreFields = $core;
        if ($extensions === null) {
            return;
        }
        $target = $this->name;
        $fields = $this->coreFields;
        $this->config['fields'] = static function () use ($extensions, $target, $fields): array {
            return $extensions->loaded()->appendTo($target, $fields);
        };
    }

    /**
     * @return array<int|string, mixed>
     */
    public function coreFields(): array
    {
        return $this->coreFields;
    }
}
