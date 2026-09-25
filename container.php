<?php

/*
    The request's object graph. Returns a factory rather than a container because
    two things differ per request and come from index.php: the configuration and
    what is known about the HTTP request. The same container is given to Slim
    (app.php), so middleware and the action resolve through it, and it is the
    GraphQL context value every resolver receives.
*/

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Auth\AnormRefreshTokenRepository;
use FA\GraphQL\Auth\RefreshTokenRepository;
use FA\GraphQL\Config;
use FA\GraphQL\Db\CompanyPdo;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Http\BodyLimitMiddleware;
use FA\GraphQL\Http\JsonErrorMiddleware;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use GraphQL\Type\Schema;
use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;

return function (Config $config, RequestInfo $request): DI\Container {
    $log = static function (\Throwable $e): void {
        error_log('graphql: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    };

    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions([
        Config::class => $config,
        RequestInfo::class => $request,
        Clock::class => new SystemClock(new DateTimeZone('UTC')),
        ErrorFormatter::class => new ErrorFormatter($config->debug, $log),
        JsonErrorMiddleware::class => new JsonErrorMiddleware($config->debug, $log),
        BodyLimitMiddleware::class => new BodyLimitMiddleware($config->maxBodyBytes),
        Schema::class => DI\get(ApiSchema::class),
        SessionGate::class => DI\get(FaSession::class),
        RefreshTokenRepository::class => DI\autowire(AnormRefreshTokenRepository::class),

        // Anorm's connection: a second one to the database FrontAccounting is
        // already using through mysqli. Built on first use, which is after a company
        // has been opened. It is *the* connection — anorm-graphql's ModelType asks
        // the container for \PDO — and separate transactions from FrontAccounting's:
        // within one mutation, write through one side only.
        //
        // It is one company's connection: CompanyPdo remembers the company it was
        // built for and refuses every statement once CompanyContext names another
        // (spec §3.6, one company per request).
        PDO::class => function (): PDO {
            $c = CompanyContext::credentials();
            // As includes/db/connect_db_mysqli.inc::set_global_connection() connects:
            // the host is used as given, and the port comes only from a separate
            // 'port' key, defaulting to 3306 when absent — FrontAccounting never
            // splits a port out of the host string itself.
            $port = !empty($c['port']) ? $c['port'] : 3306;
            $pdo = new CompanyPdo(
                CompanyContext::company(),
                'mysql:host=' . $c['host'] . ';port=' . $port
                    . ';dbname=' . $c['dbname'] . ';charset=' . CompanyContext::charset(),
                (string) $c['dbuser'],
                (string) $c['dbpassword'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            Connection::set($pdo);

            return $pdo;
        },
    ]);

    return $builder->build();
};
