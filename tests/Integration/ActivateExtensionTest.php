<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;

/**
 * hooks_graphql::activate_extension() names every table this module creates, each
 * with the sql/ file that creates it, as FrontAccounting's update_databases() needs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ActivateExtensionTest extends FaTestCase
{
    protected function tearDown(): void
    {
        CompanyContext::reset();
    }

    public function testEveryTableIsInPlaceForCompanyZero(): void
    {
        (new FaSession(Config::fromArray(['secret' => str_repeat('x', 32)])))->openCompany(0);
        $hooks = $GLOBALS['Hooks']['graphql'] ?? null;
        $this->assertInstanceOf(\hooks_graphql::class, $hooks, 'the module is active for company 0');

        // What admin/inst_module.php has loaded when it calls the hook.
        Bootstrap::includeFa('admin/db/maintenance_db.inc');
        // Check only: true when each named table exists under the company's prefix.
        $this->assertTrue((bool) $hooks->activate_extension(0, true));
    }

    public function testEachUpdateFileCreatesTheTableItIsListedFor(): void
    {
        $sql = dirname(__DIR__, 2) . '/sql/';
        $refresh = (string) file_get_contents($sql . 'update_1.0.sql');
        $machine = (string) file_get_contents($sql . 'update_1.1.sql');
        $this->assertStringContainsString('`0_graphql_refresh_token`', $refresh);
        $this->assertStringContainsString('`0_graphql_machine_token`', $machine);
        $hooks = (string) file_get_contents(dirname(__DIR__, 2) . '/hooks.php');
        $this->assertStringContainsString("'update_1.1.sql' => array('graphql_machine_token')", $hooks);
    }
}
