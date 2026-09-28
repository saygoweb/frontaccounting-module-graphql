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
use FA\GraphQL\Auth\AnormMachineTokenRepository;
use FA\GraphQL\Auth\AnormRefreshTokenRepository;
use FA\GraphQL\Auth\Authenticator;
use FA\GraphQL\Auth\MachineTokenCheck;
use FA\GraphQL\Auth\MachineTokenRepository;
use FA\GraphQL\Auth\RefreshTokenRepository;
use FA\GraphQL\Config;
use FA\GraphQL\Db\CompanyPdo;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Extension\SchemaAssembler;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\CompanyMachineTokenCheck;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Http\BodyLimitMiddleware;
use FA\GraphQL\Http\JsonErrorMiddleware;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use GraphQL\Type\Schema;
use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;
use Psr\Container\ContainerInterface;

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
        // The served schema: ApiSchema plus the extensions active for the company
        // (Release 4 spec §2.6). Built by GraphQLAction after the session middleware
        // has entered the token's company.
        Schema::class => static function (ContainerInterface $c): Schema {
            return SchemaAssembler::build($c->get(ApiSchema::class), $c->get(Extensions::class)->loaded());
        },
        // One per request. Named for the types and the service below: each takes
        // Extensions as an optional last parameter (so unit tests can build them
        // without one), and autowiring would give it null.
        Extensions::class => DI\autowire(),
        SalesOrderService::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SalesOrderType::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SalesOrderCreateInput::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SalesOrderUpdateInput::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SessionGate::class => DI\get(FaSession::class),
        RefreshTokenRepository::class => DI\autowire(AnormRefreshTokenRepository::class),
        // Machine tokens (spec §3.7): the lookup opens the token's company, then
        // asks its table. Built only when a machine token arrives.
        MachineTokenRepository::class => DI\autowire(AnormMachineTokenRepository::class),
        MachineTokenCheck::class => DI\autowire(CompanyMachineTokenCheck::class),
        // Named: autowiring gives an optional parameter its default (null), and an
        // Authenticator without a check refuses every machine token.
        Authenticator::class => DI\autowire()->constructorParameter('machine', DI\get(MachineTokenCheck::class)),

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
