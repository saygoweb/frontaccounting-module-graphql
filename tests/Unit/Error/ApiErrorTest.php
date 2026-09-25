<?php

namespace FA\GraphQL\Tests\Unit\Error;

use FA\GraphQL\Error\ApiError;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Error\Unauthenticated;
use PHPUnit\Framework\TestCase;

class ApiErrorTest extends TestCase
{
    /**
     * @return array<string, array{0: ApiError, 1: string, 2: int}>
     */
    public function errors(): array
    {
        return [
            'unauthenticated' => [new Unauthenticated('x'), 'UNAUTHENTICATED', 401],
            'forbidden' => [new Forbidden('x'), 'FORBIDDEN', 403],
            'bad input' => [new BadInput('x'), 'BAD_INPUT', 400],
            'not found' => [new NotFound('x'), 'NOT_FOUND', 404],
            'fa rejected' => [new FaRejected('x', ['Credit limit exceeded']), 'FA_REJECTED', 422],
        ];
    }

    /**
     * @dataProvider errors
     */
    public function testCodeAndHttpStatus(ApiError $error, string $code, int $status): void
    {
        $this->assertSame($code, $error->code());
        $this->assertSame($status, $error->httpStatus());
        $this->assertTrue($error->isClientSafe());
        $this->assertSame($code, $error->getExtensions()['code']);
    }
}
