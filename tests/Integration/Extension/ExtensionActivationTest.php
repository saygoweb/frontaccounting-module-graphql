<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

/**
 * Extensions are per company (spec §2.1, §3.3): with sgw_sales active for the
 * token's company its fields are in the schema; with it inactive they are absent —
 * not null, not refused, absent — and the core schema is unchanged.
 *
 * Inactive is simulated in-process, as Release 2's recurrence tests did:
 * install_hooks() puts an extension in $Hooks only when it is active for the
 * company, and Extensions loads through hook_invoke_all over $Hooks — so removing
 * sgw_sales from $Hooks before the schema is first built is what "inactive for this
 * company" looks like to the loader.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExtensionActivationTest extends FaTestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'extension-activation-test', new \DateTimeImmutable('+5 minutes')));
    }

    public function testSgwSalesFieldsAreServedWhereItIsActive(): void
    {
        if (!isset($GLOBALS['Hooks']['sgw_sales'])) {
            $this->markTestSkipped('sgw_sales is not active for company 0 on this stack.');
        }
        $schema = $this->container->get(Schema::class);

        $this->assertTrue($schema->getQueryType()->hasField('recurringDueList'));
        $this->assertTrue($schema->getMutationType()->hasField('recurringGenerate'));
        $this->assertTrue($this->objectType($schema, 'SalesOrderType')->hasField('recurring'));
        $this->assertTrue($this->inputType($schema, 'SalesOrderCreateInput')->hasField('recurring'));
        $this->assertTrue($this->inputType($schema, 'SalesOrderUpdateInput')->hasField('recurring'));
    }

    public function testSgwSalesFieldsAreAbsentWhereItIsInactive(): void
    {
        unset($GLOBALS['Hooks']['sgw_sales']);   // before the schema is first built
        $schema = $this->container->get(Schema::class);

        $this->assertFalse($schema->getQueryType()->hasField('recurringDueList'));
        $this->assertFalse($schema->getMutationType()->hasField('recurringGenerate'));
        $this->assertFalse($this->objectType($schema, 'SalesOrderType')->hasField('recurring'));
        $this->assertFalse($this->inputType($schema, 'SalesOrderCreateInput')->hasField('recurring'));
        $this->assertFalse($this->inputType($schema, 'SalesOrderUpdateInput')->hasField('recurring'));
        $this->assertNull($schema->getType('Recurrence'), 'no sgw_sales type leaks into the schema');

        // The core is unchanged.
        $this->assertTrue($schema->getQueryType()->hasField('salesOrderList'));
        $this->assertTrue($schema->getMutationType()->hasField('salesOrderCreate'));
        $this->assertTrue($this->objectType($schema, 'SalesOrderType')->hasField('lines'));
    }

    private function objectType(Schema $schema, string $name): ObjectType
    {
        $type = $schema->getType($name);
        $this->assertInstanceOf(ObjectType::class, $type, $name);
        return $type;
    }

    private function inputType(Schema $schema, string $name): InputObjectType
    {
        $type = $schema->getType($name);
        $this->assertInstanceOf(InputObjectType::class, $type, $name);
        return $type;
    }
}
