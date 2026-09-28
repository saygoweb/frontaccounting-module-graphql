<?php

namespace FA\GraphQL\Extension;

/**
 * Filled by the FrontAccounting extensions active for the request's company, through
 * their `graphql_extensions(&$registry, $opts = null)` hook method.
 */
final class ExtensionRegistry
{
    public const HOOK = 'graphql_extensions';
    public const CONTRACT_VERSION = '1.0';

    /** @var Extension[] */
    private array $extensions = [];

    public function register(Extension $extension): void
    {
        $this->extensions[] = $extension;
    }

    /**
     * @return Extension[] in registration order
     */
    public function all(): array
    {
        return $this->extensions;
    }
}
