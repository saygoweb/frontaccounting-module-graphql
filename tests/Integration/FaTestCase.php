<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Fa\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests that load FrontAccounting in-process. Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 *
 * because FrontAccounting defines its functions, constants and globals once.
 */
abstract class FaTestCase extends TestCase
{
    protected function setUp(): void
    {
        $root = Bootstrap::defaultRoot();
        if (!is_file($root . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting install at ' . $root . ' — run in the docker stack.');
        }
        Bootstrap::boot($root);
    }

    private ?\PDO $pdo = null;

    /**
     * One connection per test, shared with whatever the test hands it to: a
     * transaction test means nothing across two connections.
     */
    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $c = $GLOBALS['db_connections'][0];
            $this->pdo = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }
}
