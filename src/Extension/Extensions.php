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
        $registry = new ExtensionRegistry();
        foreach ($GLOBALS['Hooks'] as $package => $hook) {
            if (
                !is_object($hook) || !method_exists($hook, ExtensionRegistry::HOOK)
                || !$this->session->isActive((string) $package)
            ) {
                continue;
            }
            $this->invoke((string) $package, $hook, $registry);
        }
        if ($registry->all() === []) {
            // Nothing to judge: the core need not be read.
            return LoadedExtensions::none();
        }

        // The core's names and types, from the request's own ApiSchema, before any
        // extension is accepted (registering one reads no extensible type): a clash
        // with them drops the extension whole.
        $core = CoreSchema::fromContainer($this->container);

        return (new ExtensionLoader($this->log))->load($registry, new ExtensionContext($this->container), $core);
    }

    /**
     * One hook's graphql_extensions(), under its own gettext domain as
     * hook_invoke_all() brackets it: set_ext_domain() is a stack — a path pushes, no
     * path pops — so each push is popped here, whatever the hook does, and a hook
     * with no path pushes nothing. Nothing escapes: a throwing hook is logged.
     */
    private function invoke(string $package, object $hook, ExtensionRegistry $registry): void
    {
        $path = (string) ($hook->path ?? '');
        try {
            if ($path !== '') {
                set_ext_domain($path);
            }
            $method = ExtensionRegistry::HOOK;
            $hook->$method($registry, null);
        } catch (\Throwable $e) {
            ($this->log)("graphql extension hook $package: " . get_class($e) . ': ' . $e->getMessage()
                . '; its extensions were not registered');
        } finally {
            if ($path !== '') {
                try {
                    set_ext_domain();
                } catch (\Throwable $e) {
                    ($this->log)("graphql extension hook $package: restoring the gettext domain: "
                        . get_class($e) . ': ' . $e->getMessage());
                }
            }
        }
    }
}
