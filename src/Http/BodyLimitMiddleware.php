<?php

namespace FA\GraphQL\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;

/**
 * max_body_bytes, enforced on what arrives rather than on what the client claims.
 * Content-Length is a fast refusal; the body itself is then read, never more than
 * one byte past the limit, because php://input's size cannot be known in advance.
 * What is read is handed on as an in-memory stream, so later readers start at 0.
 */
final class BodyLimitMiddleware implements MiddlewareInterface
{
    private const CHUNK = 8192;

    private int $maxBytes;

    public function __construct(int $maxBytes)
    {
        $this->maxBytes = $maxBytes;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $this->maxBytes) {
            throw $this->tooLarge();
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $read = '';
        while (!$body->eof() && strlen($read) <= $this->maxBytes) {
            $chunk = $body->read(min(self::CHUNK, $this->maxBytes + 1 - strlen($read)));
            if ($chunk === '') {
                break;
            }
            $read .= $chunk;
        }
        if (strlen($read) > $this->maxBytes) {
            throw $this->tooLarge();
        }

        return $handler->handle($request->withBody((new StreamFactory())->createStream($read)));
    }

    private function tooLarge(): RequestRejected
    {
        return new RequestRejected(413, 'The request body is too large.');
    }
}
