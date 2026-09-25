<?php

/*
    The GraphQL endpoint: POST modules/graphql/ (or modules/graphql/index.php)
    with a JSON body of {"query": "...", "variables": {...}, "operationName": "..."}.

    Builds the request, the configuration, the container and the Slim app, and
    runs it. Once the app runs, JsonErrorMiddleware answers every failure as JSON.
    A failure before that — a missing or unusable config_graphql.php above all —
    is answered here, by hand, and is the only response written outside Slim.
*/

use FA\GraphQL\Config;
use FA\GraphQL\ConfigException;
use FA\GraphQL\RequestInfo;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/vendor/autoload.php';

$fail = static function (string $message): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['errors' => [['message' => $message, 'extensions' => ['code' => 'INTERNAL']]]],
        JSON_INVALID_UTF8_SUBSTITUTE
    );
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
    $fail('The GraphQL module is not configured: ' . $e->getMessage());
    return;
} catch (\Throwable $e) {
    error_log('graphql: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $fail('Internal server error');
    return;
}

$app->run($request);
