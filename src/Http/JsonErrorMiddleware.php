<?php

namespace FA\GraphQL\Http;

use FA\GraphQL\ConfigException;
use FA\GraphQL\Error\ApiError;
use FA\GraphQL\Error\InvalidToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Psr7\Response;

/**
 * The outermost middleware and the one place an exception becomes an HTTP status.
 * Everything below it throws; nothing below it writes an error response. Whatever
 * is thrown, the client gets JSON: never PHP's error page, never an empty body.
 */
final class JsonErrorMiddleware implements MiddlewareInterface
{
    private const CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        413 => 'PAYLOAD_TOO_LARGE',
    ];

    private bool $debug;

    /** @var callable|null */
    private $logger;

    public function __construct(bool $debug, ?callable $logger = null)
    {
        $this->debug = $debug;
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->render($e);
        }
    }

    private function render(\Throwable $e): ResponseInterface
    {
        $headers = [];
        if ($e instanceof ApiError) {
            $status = $e->httpStatus();
            $error = ['message' => $e->getMessage(), 'extensions' => (array) $e->getExtensions()];
        } elseif ($e instanceof InvalidToken) {
            $status = 401;
            $error = ['message' => $e->getMessage(), 'extensions' => ['code' => 'UNAUTHENTICATED']];
        } elseif ($e instanceof RequestRejected || $e instanceof HttpException) {
            $status = $e instanceof RequestRejected ? $e->status() : (int) $e->getCode();
            $error = ['message' => $e->getMessage(), 'extensions' => ['code' => self::CODES[$status] ?? 'BAD_REQUEST']];
            if ($e instanceof HttpMethodNotAllowedException) {
                $headers['Allow'] = implode(', ', $e->getAllowedMethods());
            }
        } else {
            $status = 500;
            $error = ['message' => 'Internal server error', 'extensions' => ['code' => 'INTERNAL']];
            // A setup problem (no FrontAccounting at fa_root, or not the fork) says
            // what it is, as index.php does for one found before the app exists.
            // Its messages name settings and requirements, never paths or data.
            if ($e instanceof ConfigException) {
                $error['message'] = 'The GraphQL module is not configured: ' . $e->getMessage();
            }
            if ($this->logger !== null) {
                ($this->logger)($e);
            }
            if ($this->debug) {
                $error['extensions']['debugMessage'] = $e->getMessage();
                $error['extensions']['trace'] = explode("\n", $e->getTraceAsString());
            }
        }

        $response = new Response($status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        // This is the last place an error can be turned into JSON: if it fails too,
        // fall back to a fixed literal rather than writing an empty body.
        $json = json_encode(['errors' => [$error]], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = '{"errors":[{"message":"Internal server error","extensions":{"code":"INTERNAL"}}]}';
        }
        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
