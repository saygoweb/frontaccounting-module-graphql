<?php

namespace FA\GraphQL\Tests\Unit\Error;

use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\Forbidden;
use GraphQL\Error\Error;
use PHPUnit\Framework\TestCase;

class ErrorFormatterTest extends TestCase
{
    public function testApiErrorKeepsItsMessageAndCode(): void
    {
        $error = new Error('x', null, null, [], null, new Forbidden('No access to SA_X'));
        $formatted = (new ErrorFormatter(false))($error);

        $this->assertSame('No access to SA_X', $formatted['message']);
        $this->assertSame('FORBIDDEN', $formatted['extensions']['code']);
    }

    public function testFaRejectedCarriesFrontAccountingsMessages(): void
    {
        $error = new FaRejected('FrontAccounting refused the order', ['Credit limit exceeded']);
        $formatted = (new ErrorFormatter(false))(new Error('x', null, null, [], null, $error));

        $this->assertSame('FA_REJECTED', $formatted['extensions']['code']);
        $this->assertSame(['Credit limit exceeded'], $formatted['extensions']['messages']);
    }

    public function testUnexpectedExceptionIsMaskedAndLogged(): void
    {
        $logged = [];
        $formatter = new ErrorFormatter(false, function (\Throwable $e) use (&$logged) {
            $logged[] = $e->getMessage();
        });

        $formatted = $formatter(new Error('x', null, null, [], null, new \RuntimeException('SELECT secret FROM t')));

        $this->assertSame('Internal server error', $formatted['message']);
        $this->assertSame('INTERNAL', $formatted['extensions']['code']);
        $this->assertStringNotContainsString('secret', json_encode($formatted));
        $this->assertSame(['SELECT secret FROM t'], $logged);
    }

    public function testDebugIncludesTheDetail(): void
    {
        $formatted = (new ErrorFormatter(true))(new Error('x', null, null, [], null, new \RuntimeException('boom')));

        $this->assertSame('INTERNAL', $formatted['extensions']['code']);
        $this->assertSame('boom', $formatted['extensions']['debugMessage']);
    }

    public function testValidationErrorPassesThroughUncoded(): void
    {
        $formatted = (new ErrorFormatter(false))(new Error('Cannot query field "nope" on type "Query".'));

        $this->assertSame('Cannot query field "nope" on type "Query".', $formatted['message']);
        $this->assertArrayNotHasKey('extensions', $formatted);
    }
}
