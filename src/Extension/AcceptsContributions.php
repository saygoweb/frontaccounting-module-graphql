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
        $this->coreFields = is_array($core) ? $core : [];
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
