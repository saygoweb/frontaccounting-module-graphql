<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\Warnings;
use PHPUnit\Framework\TestCase;

class WarningsTest extends TestCase
{
    protected function tearDown(): void
    {
        Warnings::reset();
    }

    public function testCollectsInOrderAndKeepsARepeatOnce(): void
    {
        Warnings::add('Price below cost');
        Warnings::add('No email contact');
        Warnings::add('Price below cost');

        $this->assertSame(['Price below cost', 'No email contact'], Warnings::all());
    }

    public function testResetEmptiesIt(): void
    {
        Warnings::add('Price below cost');
        Warnings::reset();

        $this->assertSame([], Warnings::all());
    }
}
