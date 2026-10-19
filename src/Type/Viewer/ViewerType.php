<?php

namespace FA\GraphQL\Type\Viewer;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Psr\Container\ContainerInterface;

/**
 * Who the API thinks you are. Exists so a client can check its credentials and its
 * role before it needs them.
 */
class ViewerType extends ObjectType
{
    /**
     * The session is looked up when `me` resolves, not when the schema is built:
     * building the schema needs no FrontAccounting and no configuration.
     */
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct([
            'name' => 'Viewer',
            'fields' => [
                'login' => ['type' => Type::nonNull(Type::string())],
                'name' => ['type' => Type::nonNull(Type::string())],
                'email' => ['type' => Type::string()],
                'company' => ['type' => Type::nonNull(Type::int())],
                'companyName' => ['type' => Type::nonNull(Type::string())],
                'areas' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => "The FrontAccounting security areas (SA_*) this user's role holds.",
                ],
                'scope' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => "What this token may do: 'read' (queries only) or 'write'.",
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveMe(): array
    {
        Guard::require('SA_GRAPHQL');
        $user = $_SESSION['wa_current_user'];

        $areas = [];
        foreach ($GLOBALS['security_areas'] as $code => $area) {
            if (in_array($area[0], $user->role_set)) {
                $areas[] = $code;
            }
        }
        sort($areas);
        $claims = $this->container->get(FaSession::class)->claims();

        return [
            'login' => (string) $user->loginname,
            'name' => (string) $user->name,
            'email' => $user->email === '' ? null : $user->email,
            'company' => CompanyContext::company(),
            'companyName' => CompanyContext::name(),
            'areas' => $areas,
            'scope' => $claims !== null ? $claims->scope : Claims::SCOPE_WRITE,
        ];
    }
}
