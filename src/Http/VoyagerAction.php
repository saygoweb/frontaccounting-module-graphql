<?php

namespace FA\GraphQL\Http;

use FA\GraphQL\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpMethodNotAllowedException;

/**
 * GET on the endpoint. A browser (Accept: text/html) is shown the schema in
 * GraphQL Voyager, which introspects this same endpoint by POST — anonymously, so
 * it draws what an anonymous client can see. Anything else gets the JSON 405 the
 * endpoint has always answered GET with, as does every GET when `voyager` is off.
 */
final class VoyagerAction
{
    private const VERSION = '2.1.0';
    private const SCRIPT_SRI = 'sha384-AFNPWJb6p/jNI0RfFJDZUs1McpGv9J81HF7A8fhwf1GuXhwYVXJlTq5RPBKgZBKG';
    private const STYLE_SRI = 'sha384-49jZSfLwb0TRp7FzQz1V36BlJ08B5dUugyxacqmaSGEFYN1TgGjaSdjkwds4fNNr';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->voyager || stripos($request->getHeaderLine('Accept'), 'text/html') === false) {
            throw (new HttpMethodNotAllowedException($request))->setAllowedMethods(['POST']);
        }

        $response->getBody()->write($this->page());

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function page(): string
    {
        $cdn = 'https://cdn.jsdelivr.net/npm/graphql-voyager@' . self::VERSION . '/dist';
        $scriptSri = self::SCRIPT_SRI;
        $styleSri = self::STYLE_SRI;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FrontAccounting GraphQL — Voyager</title>
  <style>
    body { margin: 0; height: 100vh; overflow: hidden; }
    #voyager { height: 100vh; }
    #voyager > h1 { text-align: center; color: #5d7e86; font-family: sans-serif; }
  </style>
  <link rel="stylesheet" href="{$cdn}/voyager.css" integrity="{$styleSri}" crossorigin="anonymous">
  <script src="{$cdn}/voyager.standalone.js" integrity="{$scriptSri}" crossorigin="anonymous"></script>
</head>
<body>
  <main id="voyager"><h1>Loading…</h1></main>
  <script>
    window.addEventListener('load', function () {
      var introspection = fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: GraphQLVoyager.voyagerIntrospectionQuery }),
        credentials: 'omit'
      }).then(function (response) { return response.json(); });
      GraphQLVoyager.renderVoyager(document.getElementById('voyager'), {
        introspection: introspection,
        displayOptions: { skipRelay: false }
      });
    });
  </script>
</body>
</html>

HTML;
    }
}
