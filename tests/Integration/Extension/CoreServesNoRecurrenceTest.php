<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use GraphQL\Type\Schema;

/**
 * Release 4 spec §3.3: with no extension active for the company — install_hooks()
 * puts an extension in $Hooks only when it is — the core schema has no recurring and
 * no Recurrence types. Only extensions add them.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CoreServesNoRecurrenceTest extends SalesOrderTestCase
{
    public function testWithNoExtensionTheSchemaHasNoRecurrence(): void
    {
        foreach (array_keys($GLOBALS['Hooks'] ?? []) as $name) {
            if ($name !== 'graphql') {
                unset($GLOBALS['Hooks'][$name]);
            }
        }

        $this->assertSame([], $this->container->get(Extensions::class)->loaded()->names());
        $schema = $this->container->get(Schema::class);
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderType')->getFields());
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderCreateInput')->getFields());
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderUpdateInput')->getFields());
        $this->assertNull($schema->getType('Recurrence'));
        $this->assertNull($schema->getType('RecurrenceInput'));
    }
}
