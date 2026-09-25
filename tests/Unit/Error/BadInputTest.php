<?php

namespace FA\GraphQL\Tests\Unit\Error;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use PHPUnit\Framework\TestCase;

class BadInputTest extends TestCase
{
    public function testAPlainBadInputCarriesOnlyItsCode(): void
    {
        $this->assertSame(['code' => 'BAD_INPUT'], (new BadInput('Name is required.'))->getExtensions());
    }

    public function testFieldAndIndexReachTheClient(): void
    {
        $e = new BadInput('Name is required.', 'name', 2);

        $this->assertSame('name', $e->field());
        $this->assertSame(2, $e->index());
        $this->assertSame(['code' => 'BAD_INPUT', 'field' => 'name', 'index' => 2], $e->getExtensions());
    }

    public function testWithIndexKeepsMessageAndField(): void
    {
        $e = (new BadInput('Name is required.', 'name'))->withIndex(1);

        $this->assertSame('Name is required.', $e->getMessage());
        $this->assertSame(['code' => 'BAD_INPUT', 'field' => 'name', 'index' => 1], $e->getExtensions());
    }

    public function testFaRejectedCarriesItsIndexWhenGiven(): void
    {
        $this->assertSame(
            ['code' => 'FA_REJECTED', 'messages' => ['Credit limit exceeded'], 'index' => 0],
            (new FaRejected('Credit limit exceeded', ['Credit limit exceeded'], 0))->getExtensions()
        );
        $this->assertSame(
            ['code' => 'FA_REJECTED', 'messages' => []],
            (new FaRejected('Refused'))->getExtensions()
        );
    }
}
