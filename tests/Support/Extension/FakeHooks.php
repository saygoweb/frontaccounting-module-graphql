<?php

namespace FA\GraphQL\Tests\Support\Extension;

use FA\GraphQL\Extension\Extension;

/**
 * Stands in for an installed FrontAccounting extension's hooks object: what
 * install_hooks() puts in $Hooks for a company where the extension is active.
 */
class FakeHooks extends \hooks
{
    /** @var Extension[] */
    private array $extensions;
    private bool $throws;

    /**
     * @param Extension[] $extensions
     */
    public function __construct(array $extensions, bool $throws = false)
    {
        $this->extensions = $extensions;
        $this->throws = $throws;
        $this->module_name = 'fake';
        $this->path = 'modules/fake';
    }

    /**
     * @param mixed $registry the ExtensionRegistry
     * @param mixed $opts
     */
    public function graphql_extensions(&$registry, $opts = null): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        if ($this->throws) {
            throw new \RuntimeException('hook boom');
        }
        foreach ($this->extensions as $extension) {
            $registry->register($extension);
        }
    }
}
