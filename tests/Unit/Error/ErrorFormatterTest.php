<?php

namespace FA\GraphQL\Tests\Unit\Error;

use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\Forbidden;
use GraphQL\Error\Error;
use GraphQL\Error\UserError;
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

    /**
     * anorm-graphql's ModelType refuses a bad `query` (an unknown field, a bad sort item)
     * with GraphQL's own UserError. Its message is written for the client, so it is
     * shown, coded as bad input (saygoweb/frontaccounting-module-graphql#15).
     */
    public function testAUserErrorKeepsItsMessageAsBadInput(): void
    {
        $logged = [];
        $formatter = new ErrorFormatter(false, function (\Throwable $e) use (&$logged) {
            $logged[] = $e;
        });
        $message = "Argument 'query.sort' item '-nope' names an unknown field 'nope'. "
            . 'The sortable fields are: date, id';

        $formatted = $formatter(new Error($message, null, null, [], null, new UserError($message)));

        $this->assertSame($message, $formatted['message']);
        $this->assertSame('BAD_INPUT', $formatted['extensions']['code']);
        $this->assertSame([], $logged, 'a client mistake is not a server fault');
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
