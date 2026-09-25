<?php

namespace FA\GraphQL\Http;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\SessionGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FrontAccounting for the GraphQL route: loaded on every request (login and
 * tokenRefresh need it, and one path is simpler than two), and the verified
 * identity entered when there is one. Inside the pipeline, so a FrontAccounting
 * failure reaches JsonErrorMiddleware as an exception, never as HTML.
 */
final class FaSessionMiddleware implements MiddlewareInterface
{
    private SessionGate $gate;

    public function __construct(SessionGate $gate)
    {
        $this->gate = $gate;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->gate->boot();
        $claims = $request->getAttribute(AuthenticationMiddleware::CLAIMS);
        if ($claims instanceof Claims) {
            $this->gate->enter($claims);
        }

        return $handler->handle($request);
    }
}
