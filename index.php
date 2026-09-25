<?php

/*
    The GraphQL endpoint: POST modules/graphql/ (or modules/graphql/index.php)
    with a JSON body of {"query": "...", "variables": {...}, "operationName": "..."}.

    OutputCapture starts before anything else runs: nothing PHP or FrontAccounting
    prints, and no header they set, reaches the client (spec section 2.4). Then this
    file builds the request, the configuration, the container and the Slim app, and
    runs it. Once the app runs, JsonErrorMiddleware answers every failure as JSON. A
    failure before that — a missing or unusable config_graphql.php above all — is
    answered here, and is the only response built outside Slim. Every response goes
    out through $send, which ends the capture immediately before emitting.
*/

use FA\GraphQL\Config;
use FA\GraphQL\ConfigException;
use FA\GraphQL\Http\OutputCapture;
use FA\GraphQL\RequestInfo;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\ResponseEmitter;

require __DIR__ . '/vendor/autoload.php';

OutputCapture::start();

$send = static function (ResponseInterface $response): void {
    OutputCapture::end();
    (new ResponseEmitter())->emit($response);
    OutputCapture::afterEmit();
};

$fail = static function (string $message): ResponseInterface {
    $response = new Response(500);
    $response->getBody()->write((string) json_encode(
        ['errors' => [['message' => $message, 'extensions' => ['code' => 'INTERNAL']]]],
        JSON_INVALID_UTF8_SUBSTITUTE
    ));

    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
};

try {
    $request = ServerRequestFactory::createFromGlobals();
    $config = Config::fromFile(__DIR__ . '/config_graphql.php');
    $container = (require __DIR__ . '/container.php')($config, RequestInfo::fromRequest($request, $config));
    // The module's directory, as the browser sees it: '/modules/graphql' in the stack.
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $app = (require __DIR__ . '/app.php')($container, $basePath);
} catch (ConfigException $e) {
    error_log('graphql: ' . $e->getMessage());
    $send($fail('The GraphQL module is not configured: ' . $e->getMessage()));
    return;
} catch (\Throwable $e) {
    error_log('graphql: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $send($fail('Internal server error'));
    return;
}

// What App::run() does, split so the capture ends between handling and emitting.
$send($app->handle($request));
