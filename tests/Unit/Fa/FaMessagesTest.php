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

    public function testMessagesKeepTheirLevel(): void
    {
        FaMessages::add(E_USER_ERROR, 'Customer <b>not found</b>');
        FaMessages::add(E_USER_WARNING, 'Price below cost');
        FaMessages::add(E_USER_NOTICE, 'Order saved');
        FaMessages::add(E_USER_DEPRECATED, 'Something old');

        $this->assertSame(['Customer not found'], FaMessages::errors());
        $this->assertSame(['Price below cost'], FaMessages::warnings());
        // errors() and warnings() look; they do not take.
        $this->assertSame(
            [
                'errors' => ['Customer not found'],
                'warnings' => ['Price below cost'],
                'notices' => ['Order saved', 'Something old'],
            ],
            FaMessages::drainByLevel()
        );
        $this->assertSame([], FaMessages::drain());
    }

    public function testDrainStillReturnsEveryTextInOrder(): void
    {
        FaMessages::add(E_USER_ERROR, 'One');
        FaMessages::add(E_USER_NOTICE, 'Two');

        $this->assertSame(['One', 'Two'], FaMessages::drain());
        $this->assertSame(['errors' => [], 'warnings' => [], 'notices' => []], FaMessages::drainByLevel());
    }
}
