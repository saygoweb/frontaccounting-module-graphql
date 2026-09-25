<?php

namespace FA\GraphQL\Http;

use FA\GraphQL\Auth\Authenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The bearer token to Claims, as the request attribute CLAIMS: null when there is
 * no Authorization header. A header that is present and wrong throws InvalidToken,
 * which JsonErrorMiddleware answers with 401 before the body is even parsed.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public const CLAIMS = 'claims';

    private Authenticator $auth;

    public function __construct(Authenticator $auth)
    {
        $this->auth = $auth;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->hasHeader('Authorization') ? $request->getHeaderLine('Authorization') : null;

        return $handler->handle($request->withAttribute(self::CLAIMS, $this->auth->fromAuthorization($header)));
    }
}
