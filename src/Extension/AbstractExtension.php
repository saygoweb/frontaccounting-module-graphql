<?php

namespace FA\GraphQL\Extension;

/**
 * Contributes nothing until a subclass says otherwise.
 */
abstract class AbstractExtension implements Extension
{
    public function contractVersion(): string
    {
        return ExtensionRegistry::CONTRACT_VERSION;
    }

    public function queryFields(ExtensionContext $c): array
    {
        return [];
    }

    public function mutationFields(ExtensionContext $c): array
    {
        return [];
    }

    public function typeFields(ExtensionContext $c): array
    {
        return [];
    }

    public function inputFields(ExtensionContext $c): array
    {
        return [];
    }

    public function participants(ExtensionContext $c): array
    {
        return [];
    }
}
