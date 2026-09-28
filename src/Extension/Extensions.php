<?php

namespace FA\GraphQL\Extension;

use DI\Container;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;

/**
 * The request's extensions (Release 4 spec §2.1). After a company is open, every
 * hooks object FrontAccounting installed for it (install_hooks(), in
 * FaSession::openCompany()) is asked, through its graphql_extensions() method, to
 * register; the registry then goes through ExtensionLoader once and the result is
 * kept for the request. Before a company is open there is nothing to ask, and nothing
 * is cached, so a later call after the company opens discovers.
 *
 * The hooks are walked here rather than through hook_invoke_all() so one throwing
 * hook is logged and skipped instead of aborting the others; the gettext domain is
 * bracketed as hook_invoke_all() brackets it (includes/hooks.inc:294-315).
 */
final class Extensions
{
    private Container $container;
    private FaSession $session;
    private ?LoadedExtensions $loaded = null;
    private bool $loading = false;

    /** @var callable(string): void */
    private $log;

    public function __construct(Container $container, FaSession $session, ?callable $log = null)
    {
        $this->container = $container;
        $this->session = $session;
        $this->log = $log ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function loaded(): LoadedExtensions
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        if (!CompanyContext::isSet() || !isset($GLOBALS['Hooks']) || !is_array($GLOBALS['Hooks'])) {
            return LoadedExtensions::none();
        }

        if ($this->loading) {
            // An extensible type's fields, read while the extensions are loading,
            // would be cached without their contributions.
            throw new \LogicException('The extensions were asked for while they were being loaded.');
        }
        $this->loading = true;
        try {
            $this->loaded = $this->discover();
        } finally {
            $this->loading = false;
        }

        return $this->loaded;
    }

    private function discover(): LoadedExtensions
    {
        // The core's names and types, from the request's own ApiSchema, before any
        // extension is asked: a clash with them drops the extension whole.
        $core = CoreSchema::fromContainer($this->container);

        $registry = new ExtensionRegistry();
        foreach ($GLOBALS['Hooks'] as $package => $hook) {
            if (
                !is_object($hook) || !method_exists($hook, ExtensionRegistry::HOOK)
                || !$this->session->isActive((string) $package)
            ) {
                continue;
            }
            try {
                set_ext_domain($hook->path ?? '');
                $method = ExtensionRegistry::HOOK;
                $hook->$method($registry, null);
            } catch (\Throwable $e) {
                ($this->log)("graphql extension hook $package: " . get_class($e) . ': ' . $e->getMessage()
                    . '; its extensions were not registered');
            }
        }
        set_ext_domain();

        return (new ExtensionLoader($this->log))->load($registry, new ExtensionContext($this->container), $core);
    }
}
