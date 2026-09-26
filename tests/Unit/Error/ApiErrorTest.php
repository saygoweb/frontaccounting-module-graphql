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

    public function testARefusalOfOurOwnCarriesItsMessageInMessages(): void
    {
        // Release 2 spec section 5: FA_REJECTED carries its messages in
        // extensions.messages, the guards of this module's services included.
        $this->assertSame(['Guarded.'], (new FaRejected('Guarded.'))->getExtensions()['messages']);
        $this->assertSame(['a', 'b'], (new FaRejected('a', ['a', 'b']))->getExtensions()['messages']);
    }

    public function testRefusalsAndNotFoundNameTheirBatchIndex(): void
    {
        $rejected = (new FaRejected('Guarded.', ['Guarded.']))->withIndex(2);
        $this->assertSame(2, $rejected->index());
        $this->assertSame('Guarded.', $rejected->getMessage());
        $this->assertSame(
            ['code' => 'FA_REJECTED', 'messages' => ['Guarded.'], 'index' => 2],
            $rejected->getExtensions()
        );

        $missing = (new NotFound('Customer id 9 not found'))->withIndex(1);
        $this->assertSame(1, $missing->index());
        $this->assertSame('Customer id 9 not found', $missing->getMessage());
        $this->assertSame(['code' => 'NOT_FOUND', 'index' => 1], $missing->getExtensions());
        $this->assertNull((new NotFound('x'))->index());
        $this->assertSame(['code' => 'NOT_FOUND'], (new NotFound('x'))->getExtensions());
    }
}
