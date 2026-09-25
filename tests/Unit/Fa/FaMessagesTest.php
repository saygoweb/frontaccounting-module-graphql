<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\FaMessages;
use PHPUnit\Framework\TestCase;

class FaMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        FaMessages::reset();
    }

    public function testDrainReturnsPlainTextOnce(): void
    {
        FaMessages::add(E_USER_WARNING, 'Credit limit <b>exceeded</b>');
        FaMessages::add(E_USER_NOTICE, 'Order saved');

        $this->assertSame(['Credit limit exceeded', 'Order saved'], FaMessages::drain());
        $this->assertSame([], FaMessages::drain());
    }
}
