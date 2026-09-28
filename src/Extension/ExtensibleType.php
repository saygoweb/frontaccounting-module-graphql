<?php

namespace FA\GraphQL\Extension;

/**
 * A core Type or Input extensions may add fields to (ExtensionLoader::EXTENSIBLE_TYPES,
 * EXTENSIBLE_INPUTS). Its own fields are known before any extension is loaded, so
 * the loader can refuse an extension that would clash with one (Release 4 spec §2.5,
 * revised); the extensions' fields are appended when the type is first used.
 *
 * Invariant: webonyx caches a type's fields on the first read. With a company open,
 * nothing may read an extensible type's fields before Extensions::loaded() has
 * completed — the Schema factory in container.php calls it first — or the type is
 * served without its contributions for the rest of the request.
 */
interface ExtensibleType
{
    /**
     * The core's field configs, as the type was built, without any extension's.
     *
     * @return array<int|string, mixed>
     */
    public function coreFields(): array;
}
