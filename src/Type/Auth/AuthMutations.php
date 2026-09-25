<?php

namespace FA\GraphQL\Type\Auth;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Auth\Model\UserModel;
use FA\GraphQL\Auth\RefreshTokenService;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\RequestInfo;

/**
 * login, tokenRefresh, tokenRevoke.
 *
 * The refresh-token service and \PDO are taken from the container when needed, not
 * injected: they need a company's database, and which company is only known once a
 * resolver has read its arguments.
 */
class AuthMutations
{
    private Config $config;
    private RequestInfo $request;
    private FaSession $session;
    private TokenService $tokens;
    private Container $container;

    public function __construct(
        Config $config,
        RequestInfo $request,
        FaSession $session,
        TokenService $tokens,
        Container $container
    ) {
        $this->config = $config;
        $this->request = $request;
        $this->session = $session;
        $this->tokens = $tokens;
        $this->container = $container;
    }

    /**
     * @param mixed $root
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function resolveLogin($root, array $args): array
    {
        if (!$this->request->https && !$this->config->allowInsecureLogin) {
            throw new BadInput('login is only accepted over HTTPS.');
        }

        $company = (int) ($args['company'] ?? 0);
        $this->session->loginWithPassword($company, (string) $args['user'], (string) $args['password']);

        $refresh = $this->refreshTokens();
        $refresh->purgeExpired();

        return $this->payload($company, $this->session->user()->loginname, $refresh->issue(
            $company,
            $this->session->userId(),
            $this->request->client
        ));
    }

    /**
     * @param mixed $root
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function resolveTokenRefresh($root, array $args): array
    {
        $token = (string) $args['refreshToken'];
        $company = RefreshTokenService::companyOf($token);
        try {
            $this->session->openCompany($company);
        } catch (Unauthenticated $e) {
            throw new Unauthenticated('The refresh token is not valid.');
        }

        // Spec §3.4 step 4, run by rotate() before it writes anything (step 5).
        $login = '';
        $admit = function (int $userId) use ($company, &$login): void {
            $user = UserModel::findById($this->container->get(\PDO::class), $userId);
            if ($user === null) {
                throw new Unauthenticated('The refresh token is not valid.');
            }
            $login = (string) $user->login;
            // Becomes the user the same way a bearer token does, which is also what
            // refuses a deactivated account or a role that lost SA_GRAPHQL.
            $this->session->enter(new Claims($company, $login, '', new \DateTimeImmutable()));
        };
        $rotated = $this->refreshTokens()->rotate($token, $this->request->client, $admit);

        return $this->payload($company, $login, $rotated['token']);
    }

    /**
     * @param mixed $root
     * @param array<string, mixed> $args
     */
    public function resolveTokenRevoke($root, array $args): bool
    {
        Guard::require('SA_GRAPHQL');

        $refresh = $this->refreshTokens();
        if (isset($args['refreshToken'])) {
            $token = (string) $args['refreshToken'];
            try {
                $company = RefreshTokenService::companyOf($token);
            } catch (Unauthenticated $e) {
                // Malformed, same as any other token this caller does not own: not
                // revoked, not an error — tokenRevoke reports success or failure,
                // never a reason.
                return false;
            }
            if ($company !== CompanyContext::company()) {
                return false;
            }

            return $refresh->revoke($token, $this->session->userId());
        }

        return $refresh->revokeAll($this->session->userId()) > 0;
    }

    private function refreshTokens(): RefreshTokenService
    {
        return $this->container->get(RefreshTokenService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $company, string $login, string $refreshToken): array
    {
        return [
            'accessToken' => $this->tokens->issueAccess($company, $login),
            'expiresIn' => $this->tokens->accessTtl(),
            'refreshToken' => $refreshToken,
        ];
    }
}
