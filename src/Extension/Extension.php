<?php

namespace FA\GraphQL\Extension;

/**
 * What another FrontAccounting extension implements to add to this API (Release 4
 * spec §2.2). Registered from its hooks class:
 *
 *     function graphql_extensions(&$registry, $opts = null)
 *     {
 *         if (interface_exists(\FA\GraphQL\Extension\Extension::class)) {
 *             $registry->register(new MyExtension());
 *         }
 *     }
 *
 * Field arrays are `name => webonyx field config`. A config may carry 'name'; it must
 * then equal its key.
 */
interface Extension
{
    /** Unique among extensions: the FrontAccounting extension's folder name. */
    public function name(): string;

    /** The contract version it was built for; another major version is refused. */
    public function contractVersion(): string;

    /** @return array<string, array<string, mixed>> root query fields */
    public function queryFields(ExtensionContext $c): array;

    /** @return array<string, array<string, mixed>> root mutation fields */
    public function mutationFields(ExtensionContext $c): array;

    /** @return array<string, array<string, array<string, mixed>>> ['SalesOrderType' => [name => config]] */
    /**
     * Each field must be nullable: webonyx would null the whole order when a non-null
     * field errors.
     */
    public function typeFields(ExtensionContext $c): array;

    /** @return array<string, array<string, array<string, mixed>>> ['SalesOrderCreateInput' => [...], ...] */
    public function inputFields(ExtensionContext $c): array;

    /** @return object[] objects implementing a participant interface, e.g. SalesOrderParticipant */
    public function participants(ExtensionContext $c): array;
}
