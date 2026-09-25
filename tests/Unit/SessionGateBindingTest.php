<?php

namespace FA\GraphQL\Tests\Unit;

use FA\GraphQL\Config;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use PHPUnit\Framework\TestCase;

class SessionGateBindingTest extends TestCase
{
    public function testTheContainerBindsTheGateToFaSession(): void
    {
        $factory = require dirname(__DIR__, 2) . '/container.php';
        $container = $factory(
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']),
            new RequestInfo(false, 'phpunit 127.0.0.1')
        );

        $this->assertInstanceOf(FaSession::class, $container->get(SessionGate::class));
    }

    public function testTheStandInIsGone(): void
    {
        $this->assertFalse(class_exists('FA\\GraphQL\\Http\\ClosedSessionGate'));
    }
}
