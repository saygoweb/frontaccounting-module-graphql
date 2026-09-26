<?php

namespace FA\GraphQL\Fa;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\MachineTokenCheck;
use FA\GraphQL\Auth\MachineTokenService;
use FA\GraphQL\Error\InvalidToken;
use FA\GraphQL\Error\Unauthenticated;
use Psr\Container\ContainerInterface;

/**
 * A machine token's lookup, in the company its verified `coy` claim names: that
 * company is opened first (the same one FaSession::enter() opens next, so the
 * one-company-per-request rule holds), then its graphql_machine_token is asked.
 *
 * The service comes from the container only now: its repository's \PDO is built
 * for whichever company is open when it is first needed (spec §3.6).
 */
final class CompanyMachineTokenCheck implements MachineTokenCheck
{
    private FaSession $session;
    private ContainerInterface $container;

    public function __construct(FaSession $session, ContainerInterface $container)
    {
        $this->session = $session;
        $this->container = $container;
    }

    public function check(Claims $claims): void
    {
        try {
            $this->session->openCompany($claims->company);
        } catch (Unauthenticated $e) {
            throw new InvalidToken('The access token has been revoked or is not known.', 0, $e);
        }

        $this->container->get(MachineTokenService::class)->check($claims);
    }
}
