<?php

namespace FA\GraphQL\Tests\Generated;

use Anorm\GraphQL\Testing\ModelTypeTestCase;
use DI\Container;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use GraphQL\Type\Schema;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Every generated test extends this. The container is the one a request gets
 * (container.php), and the session is real: FrontAccounting booted and `apitest`
 * entered through the SessionGate, exactly as a bearer token for apitest would be. So
 * a generated Type's areas() are checked by the real Guard against the real role, and
 * the \PDO the models use is the container's — the one ModelTypeTestCase begins its
 * transaction on and rolls back after each test.
 *
 * FrontAccounting defines its functions, constants and globals once per process, so
 * every subclass must itself carry (PHPUnit reads them from the test class):
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class TestCase extends ModelTypeTestCase
{
    /** The seeded user the generated tests run as (tests/data/seed.sql). */
    protected const LOGIN = 'apitest';

    protected function createContainer(): Container
    {
        $root = Bootstrap::defaultRoot();
        if (!is_file($root . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting install at ' . $root . ' — run in the docker stack.');
        }

        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => $root,
        ]);
        $factory = require dirname(__DIR__, 2) . '/container.php';
        $container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));

        $gate = $container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, static::LOGIN, 'generated-test', new \DateTimeImmutable('+5 minutes')));

        return $container;
    }

    protected function createSchema(Container $container): Schema
    {
        return $container->get(ApiSchema::class);
    }
}
