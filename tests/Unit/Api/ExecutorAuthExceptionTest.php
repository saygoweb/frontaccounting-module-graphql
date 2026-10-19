<?php

namespace FA\GraphQL\Tests\Unit\Api;

use FA\GraphQL\Api\ExecutorAuthException;
use PHPUnit\Framework\TestCase;

final class ExecutorAuthExceptionTest extends TestCase
{
    public function testItCarriesItsReasonAndMessage(): void
    {
        $previous = new \RuntimeException('cause');
        $e = new ExecutorAuthException('FORBIDDEN', 'x', $previous);

        $this->assertSame('FORBIDDEN', $e->getReason());
        $this->assertSame('x', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame(
            ExecutorAuthException::UNAUTHENTICATED,
            (new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, 'y'))->getReason()
        );
    }

    public function testAnUnknownReasonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecutorAuthException('NOT_FOUND', 'x');
    }
}
