<?php

/*
    The HTTP pipeline, and nothing else. Slim runs the middleware added last first,
    so the app-level list reads bottom-up: JsonErrorMiddleware (every throwable to
    JSON), then routing (404, 405), then the body limit (413). Authentication and
    the FrontAccounting session belong to the GraphQL route, not the app, so a later
    route (a health check) chooses for itself. Slim's own ErrorMiddleware is not
    added: it renders HTML.
*/

use DI\Container;
use FA\GraphQL\Http\AuthenticationMiddleware;
use FA\GraphQL\Http\BodyLimitMiddleware;
use FA\GraphQL\Http\FaSessionMiddleware;
use FA\GraphQL\Http\GraphQLAction;
use FA\GraphQL\Http\JsonErrorMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;

return function (Container $container, string $basePath = ''): App {
    AppFactory::setContainer($container);
    $app = AppFactory::create();
    $app->setBasePath($basePath);

    // Slim prepends the base path to the pattern. "/[index.php]" matches both the
    // module's directory and its script, which clients name about as often.
    $app->post('/[index.php]', GraphQLAction::class)
        ->add(FaSessionMiddleware::class)
        ->add(AuthenticationMiddleware::class);

    $app->add(BodyLimitMiddleware::class);
    $app->addRoutingMiddleware();
    $app->add(JsonErrorMiddleware::class);

    return $app;
};
