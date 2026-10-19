<?php

namespace FA\GraphQL\Api;

use FA\GraphQL\Auth\Authenticator;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\MachineTokenRepository;
use FA\GraphQL\Auth\MachineTokenService;
use FA\GraphQL\Auth\Model\UserModel;
use FA\GraphQL\Config;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\InvalidToken;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\RequestInfo;
use GraphQL\Type\Schema;
use Psr\Container\ContainerInterface;

/**
 * The supported way for another FrontAccounting module to use this API in the
 * same PHP process (contract 1.0; README "In-process API"). Every call runs as
 * the holder of a bearer token through exactly what POST /modules/graphql/ runs:
 * the Authenticator, the FrontAccounting session (SA_GRAPHQL), the scope rule,
 * the depth and complexity limits and the error formatter (DocumentRunner).
 *
 * FrontAccounting allows one company per PHP process, and so does this: a call
 * for a second company is refused UNAUTHENTICATED, as a second company is over
 * HTTP. Each call builds a fresh container, so nothing leaks between calls but
 * FrontAccounting's own globals.
 *
 * Those globals include the caller's: a call boots FrontAccounting (which resets
 * $_SESSION and the other superglobals it owns) and signs the token's user in to
 * $_SESSION['wa_current_user']. A host must not rely on its own $_SESSION or
 * FrontAccounting identity across an Executor call in the same request.
 */
final class Executor
{
    public const CONTRACT_VERSION = '1.0';

    private Config $config;
    /** @var callable(Config, RequestInfo): ContainerInterface */
    private $containerFactory;

    /**
     * @param (callable(Config, RequestInfo): ContainerInterface)|null $containerFactory
     *     the module's container.php when null
     */
    public function __construct(Config $config, ?callable $containerFactory = null)
    {
        $this->config = $config;
        $this->containerFactory = $containerFactory
            ?? static function (Config $config, RequestInfo $request): ContainerInterface {
                return (require dirname(__DIR__, 2) . '/container.php')($config, $request);
            };
    }

    /**
     * An Executor with the configuration the module serves (config_graphql.php).
     */
    public static function create(?Config $config = null): self
    {
        return new self($config ?? Config::fromFile(dirname(__DIR__, 2) . '/config_graphql.php'));
    }

    /**
     * Run one GraphQL document as the bearer token's holder.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the response envelope: data, errors, extensions
     * @throws ExecutorAuthException where HTTP would answer 401 or 403
     */
    public function execute(
        string $bearer,
        string $document,
        array $variables,
        string $clientIp,
        ?string $operationName = null
    ): array {
        $c = $this->container($clientIp);
        $claims = $this->enter($c, $bearer);

        return $c->get(DocumentRunner::class)->run(
            $c->get(Schema::class),
            $c,
            $document,
            $variables === [] ? null : $variables,
            $operationName,
            $claims
        );
    }

    /**
     * The schema the bearer token's company serves: ApiSchema and its active extensions.
     *
     * @throws ExecutorAuthException
     */
    public function schema(string $bearer): Schema
    {
        $c = $this->container('');
        $this->enter($c, $bearer);

        return $c->get(Schema::class);
    }

    /**
     * @return array<int, string> company id => name
     */
    public function companies(): array
    {
        $this->session($this->container(''))->boot();
        $out = [];
        foreach ($GLOBALS['db_connections'] as $id => $connection) {
            $out[(int) $id] = (string) $connection['name'];
        }

        return $out;
    }

    /**
     * Check a user's password as the login mutation does (FrontAccounting's
     * throttle included).
     *
     * @param string $clientIp the end client's address, as execute() takes it
     * @return array{status: string, login: string} status OK | BAD_CREDENTIALS |
     *     NO_API_ACCESS; login as FrontAccounting stores it ('' unless OK)
     * @throws ExecutorAuthException UNAUTHENTICATED: the company cannot be opened
     *     (unknown, or another company is open in this process)
     */
    public function authenticate(int $company, string $login, string $password, string $clientIp = ''): array
    {
        $c = $this->container($clientIp);
        $session = $this->session($c);
        // Outside the credentials check: a company that cannot be opened is not a
        // wrong password. loginWithPassword() re-opens it, which is allowed.
        $this->openCompany($session, $company);
        try {
            $session->loginWithPassword($company, $login, $password);
        } catch (Forbidden $e) {
            // The password was right, so FrontAccounting signed the user in before
            // SA_GRAPHQL was checked: a refused call leaves nobody signed in.
            $this->openCompany($session, $company);

            return ['status' => 'NO_API_ACCESS', 'login' => ''];
        } catch (Unauthenticated $e) {
            return ['status' => 'BAD_CREDENTIALS', 'login' => ''];
        }

        // current_user keeps the login as typed (MySQL matched it ignoring case);
        // the users row has FrontAccounting's own spelling.
        $user = UserModel::findById($c->get(\PDO::class), $session->userId());

        if ($user === null) {
            return ['status' => 'BAD_CREDENTIALS', 'login' => ''];
        }

        return ['status' => 'OK', 'login' => (string) $user->login];
    }

    /**
     * Issue a machine token (stored in graphql_machine_token, revocable).
     *
     * @param string $clientIp the end client's address, as execute() takes it
     * @return array{token: string, jti: string, expiresAt: int}
     * @param string $login matched as FrontAccounting matches a login (ignoring
     *     case); the token and its row carry FrontAccounting's stored spelling
     * @throws ExecutorAuthException the user is unknown or may no longer use the API
     * @throws \InvalidArgumentException a bad scope, lifetime or label
     */
    public function issueToken(
        int $company,
        string $login,
        string $scope,
        int $ttlSeconds,
        string $label,
        string $clientIp = ''
    ): array {
        $c = $this->container($clientIp);
        $this->openCompany($this->session($c), $company);
        // The lookup bin/fa-token makes: the stored spelling, so `fa-token list`
        // and the machine-token check never see two spellings of one user.
        $user = UserModel::findByLogin($c->get(\PDO::class), $login);
        if ($user === null) {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, 'That user does not exist.');
        }
        $login = (string) $user->login;
        // Becoming the user as a verified token would is what refuses an inactive
        // account or a role that lost SA_GRAPHQL since the user consented.
        $this->enterClaims($c, new Claims($company, $login, '', new \DateTimeImmutable('+1 minute')));
        $issued = $c->get(MachineTokenService::class)->issueSeconds($company, $login, $ttlSeconds, $label, $scope);

        return ['token' => $issued->token, 'jti' => $issued->jti, 'expiresAt' => $issued->expiresAt->getTimestamp()];
    }

    /**
     * @return bool true only if this call revoked it
     * @throws ExecutorAuthException UNAUTHENTICATED: the company cannot be opened
     */
    public function revokeToken(int $company, string $jti): bool
    {
        $c = $this->container('');
        $this->openCompany($this->session($c), $company);

        return $c->get(MachineTokenService::class)->revoke($jti);
    }

    /**
     * Whether a machine token is revoked or unknown. Expiry is not looked at.
     *
     * @throws ExecutorAuthException UNAUTHENTICATED: the company cannot be opened
     */
    public function tokenIsRevoked(int $company, string $jti): bool
    {
        $c = $this->container('');
        $this->openCompany($this->session($c), $company);
        $record = $c->get(MachineTokenRepository::class)->findByJti($jti);

        return $record === null || $record->revokedAt !== null;
    }

    /**
     * The company's connection details, for a module's own tables. The DSN is
     * utf8mb4 on purpose: a module's tables are utf8mb4, while FrontAccounting's
     * data tables may be latin1, so do not read or write those through it.
     *
     * @return array{dsn: string, user: string, password: string, prefix: string}
     * @throws \InvalidArgumentException there is no such company
     */
    public function database(int $company): array
    {
        $this->session($this->container(''))->boot();
        if (!isset($GLOBALS['db_connections'][$company])) {
            throw new \InvalidArgumentException("There is no company $company.");
        }
        $c = $GLOBALS['db_connections'][$company];
        $port = !empty($c['port']) ? $c['port'] : 3306;

        return [
            'dsn' => 'mysql:host=' . $c['host'] . ';port=' . $port . ';dbname=' . $c['dbname'] . ';charset=utf8mb4',
            'user' => (string) $c['dbuser'],
            'password' => (string) $c['dbpassword'],
            'prefix' => (string) $c['tbpref'],
        ];
    }

    private function container(string $clientIp): ContainerInterface
    {
        // In-process callers have applied TLS themselves; $clientIp is the end
        // client's address, which login throttling and refresh rows record.
        return ($this->containerFactory)($this->config, new RequestInfo(true, substr($clientIp, 0, 255)));
    }

    private function session(ContainerInterface $c): FaSession
    {
        return $c->get(FaSession::class);
    }

    private function openCompany(FaSession $session, int $company): void
    {
        try {
            $session->openCompany($company);
        } catch (Unauthenticated $e) {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, $e->getMessage(), $e);
        }
    }

    /**
     * @param string $bearer the token, optionally after "Bearer " (any case) and
     *     surrounded by whitespace
     */
    private function enter(ContainerInterface $c, string $bearer): Claims
    {
        $token = (string) preg_replace('/^bearer(?:\s+|$)/i', '', trim($bearer));
        if ($token === '') {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, 'A bearer token is required.');
        }
        try {
            $claims = $c->get(Authenticator::class)->fromAuthorization('Bearer ' . $token);
        } catch (InvalidToken $e) {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, $e->getMessage(), $e);
        } catch (Forbidden $e) {
            throw new ExecutorAuthException(ExecutorAuthException::FORBIDDEN, $e->getMessage(), $e);
        } catch (Unauthenticated $e) {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, $e->getMessage(), $e);
        }
        if ($claims === null) {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, 'A bearer token is required.');
        }
        $this->enterClaims($c, $claims);

        return $claims;
    }

    private function enterClaims(ContainerInterface $c, Claims $claims): void
    {
        try {
            $this->session($c)->enter($claims);
        } catch (Forbidden $e) {
            throw new ExecutorAuthException(ExecutorAuthException::FORBIDDEN, $e->getMessage(), $e);
        } catch (Unauthenticated $e) {
            throw new ExecutorAuthException(ExecutorAuthException::UNAUTHENTICATED, $e->getMessage(), $e);
        }
    }
}
