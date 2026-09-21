<?php

/*
    The GraphQL endpoint: POST modules/graphql/ with a JSON body of
    {"query": "...", "variables": {...}, "operationName": "..."}.

    This is the skeleton. It does not start a FrontAccounting session yet, so it
    serves only what needs neither a login nor a database.
*/

require __DIR__ . '/vendor/autoload.php';

$server = new FA\GraphQL\Server();
$response = $server->handle(
    isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
    (string) file_get_contents('php://input')
);

http_response_code($response->status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response->body);
