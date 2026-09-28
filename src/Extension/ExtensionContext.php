<?php

namespace FA\GraphQL\Extension;

use DI\Container;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\InvoiceMailer;

/**
 * What an extension uses to follow this module's rules (Release 4 spec §2.3). Guard,
 * ServiceCall, FaTransaction, DocumentLock, DateConversion, BadInput and FaRejected
 * are static or plain classes an extension uses directly. Extensions are trusted
 * code: this makes the right thing easy; it does not sandbox.
 */
final class ExtensionContext
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /** The request's container: resolve Types through it so each is one instance per schema. */
    public function container(): Container
    {
        return $this->container;
    }

    public function session(): FaSession
    {
        return $this->container->get(FaSession::class);
    }

    public function mailer(): InvoiceMailer
    {
        return $this->container->get(InvoiceMailer::class);
    }

    /** Include a FrontAccounting file, relative to FrontAccounting's root, after boot. */
    public function includeFa(string $path): void
    {
        Bootstrap::includeFa($path);
    }

    public function company(): int
    {
        return CompanyContext::company();
    }

    public function login(): string
    {
        return (string) $this->session()->user()->loginname;
    }
}
