<?php

namespace FA\GraphQL\Cli;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\MachineTokenRecord;
use FA\GraphQL\Auth\MachineTokenService;
use FA\GraphQL\Auth\Model\UserModel;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Fa\FaSession;
use Psr\Container\ContainerInterface;

/**
 * bin/fa-token: issue, list and revoke machine tokens (Foundation spec §3.7). Only
 * from the command line, run as the web server's user (for example `sudo -u
 * www-data`) — otherwise FrontAccounting may create root-owned files under `tmp/`
 * that the web server can no longer write.
 *
 * It works through the module's own container, so the company is opened, the user
 * checked and the rows written exactly as the API would: FaSession, UserModel,
 * MachineTokenService and its repository on the company's connection.
 *
 * Exit codes: 0 done, 1 refused, 2 usage.
 */
final class TokenCommand
{
    public const USAGE = <<<'TXT'
usage: fa-token issue  --company N --user LOGIN --days D --label TEXT
       fa-token list   --company N
       fa-token revoke --company N <jti>

TXT;

    /** @var callable(): ContainerInterface */
    private $containerFactory;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param callable(): ContainerInterface $containerFactory called only once the
     *        arguments are known to be well formed
     * @param resource $out
     * @param resource $err
     */
    public function __construct(callable $containerFactory, $out, $err)
    {
        $this->containerFactory = $containerFactory;
        $this->out = $out;
        $this->err = $err;
    }

    /**
     * @param string[] $args the arguments after the script's name
     */
    public function run(array $args): int
    {
        $parsed = self::parse($args);
        if ($parsed === null) {
            fwrite($this->err, self::USAGE);

            return 2;
        }
        [$command, $options, $positional] = $parsed;

        try {
            switch ($command) {
                case 'issue':
                    return $this->issue($options, $positional);
                case 'list':
                    return $this->list($options, $positional);
                case 'revoke':
                    return $this->revoke($options, $positional);
            }
        } catch (UsageError $e) {
            fwrite($this->err, 'fa-token: ' . $e->getMessage() . "\n" . self::USAGE);

            return 2;
        } catch (Refused $e) {
            fwrite($this->err, 'fa-token: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite($this->err, self::USAGE);

        return 2;
    }

    /**
     * @param array<string, string> $options
     * @param string[] $positional
     */
    private function issue(array $options, array $positional): int
    {
        self::only($options, ['company', 'user', 'days', 'label'], $positional, 0);
        $company = self::number($options, 'company');
        $days = self::number($options, 'days');
        $login = self::text($options, 'user');
        $label = self::text($options, 'label');

        $container = $this->open($company);
        $user = UserModel::findByLogin($container->get(\PDO::class), $login);
        if ($user === null) {
            throw new Refused("company $company has no user '$login'.");
        }
        // findByLogin() matches case-insensitively (MySQL's default collation); use
        // the login as FrontAccounting stored it, so `list` never shows two
        // spellings of the same user.
        $login = $user->login;
        if ($user->inactive) {
            throw new Refused("user '$login' is inactive.");
        }
        // The same check every request makes: the user becomes itself as a verified
        // token would, which refuses a role without SA_GRAPHQL.
        try {
            $container->get(FaSession::class)->enter(new Claims($company, $login, '', new \DateTimeImmutable()));
        } catch (Forbidden $e) {
            throw new Refused("user '$login' does not have GraphQL API access (SA_GRAPHQL).");
        } catch (Unauthenticated $e) {
            throw new Refused("user '$login' cannot sign in: " . $e->getMessage());
        }

        try {
            $issued = $container->get(MachineTokenService::class)->issue($company, $login, $days, $label);
        } catch (\InvalidArgumentException $e) {
            throw new Refused($e->getMessage());
        } catch (\PDOException $e) {
            throw self::missingTable($company, $e);
        }

        fwrite($this->err, sprintf(
            "Issued machine token %s for %s in company %d, expiring %s UTC.\n"
            . "The token is on stdout and is not stored: it cannot be shown again. Keep it secret.\n"
            . "Revoke it with: fa-token revoke --company %d %s\n",
            $issued->jti,
            $login,
            $company,
            $issued->expiresAt->format('Y-m-d H:i:s'),
            $company,
            $issued->jti
        ));
        fwrite($this->out, $issued->token . "\n");

        return 0;
    }

    /**
     * @param array<string, string> $options
     * @param string[] $positional
     */
    private function list(array $options, array $positional): int
    {
        self::only($options, ['company'], $positional, 0);
        $company = self::number($options, 'company');
        $container = $this->open($company);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $records = $container->get(MachineTokenService::class)->list();
        } catch (\PDOException $e) {
            throw self::missingTable($company, $e);
        }

        fwrite($this->out, implode("\t", ['jti', 'user', 'status', 'issued', 'expires', 'last used', 'label']) . "\n");
        foreach ($records as $record) {
            fwrite($this->out, implode("\t", [
                $record->jti,
                $record->login,
                self::status($record, $now),
                $record->issuedAt->format('Y-m-d H:i:s'),
                $record->expiresAt->format('Y-m-d H:i:s'),
                $record->lastUsedAt === null ? '-' : $record->lastUsedAt->format('Y-m-d H:i:s'),
                str_replace(["\t", "\n", "\r"], ' ', $record->label),
            ]) . "\n");
        }

        return 0;
    }

    /**
     * @param array<string, string> $options
     * @param string[] $positional
     */
    private function revoke(array $options, array $positional): int
    {
        self::only($options, ['company'], $positional, 1);
        $jti = $positional[0];
        $company = self::number($options, 'company');
        $container = $this->open($company);

        try {
            $revoked = $container->get(MachineTokenService::class)->revoke($jti);
        } catch (\PDOException $e) {
            throw self::missingTable($company, $e);
        }
        if (!$revoked) {
            throw new Refused("no live machine token $jti (unknown, or already revoked).");
        }
        fwrite($this->out, "Revoked $jti.\n");

        return 0;
    }

    private function open(int $company): ContainerInterface
    {
        $container = ($this->containerFactory)();
        try {
            $container->get(FaSession::class)->openCompany($company);
        } catch (Unauthenticated $e) {
            throw new Refused("there is no company $company.");
        }

        return $container;
    }

    /**
     * A missing `graphql_machine_token` table (SQLSTATE 42S02) means this
     * company's schema predates the machine-tokens change: refuse with the fix,
     * rather than let a raw PDOException reach the operator. Any other
     * PDOException is a genuine server fault and is left to fail loudly.
     */
    private static function missingTable(int $company, \PDOException $e): \Exception
    {
        if ($e->getCode() === '42S02') {
            return new Refused(
                "company $company has no graphql_machine_token table: re-activate the GraphQL "
                . 'extension for it (Setup → Install/Activate Extensions) or apply '
                . 'sql/update_1.1.sql.'
            );
        }

        return $e;
    }

    private static function status(MachineTokenRecord $record, \DateTimeImmutable $now): string
    {
        if ($record->revokedAt !== null) {
            return 'revoked ' . $record->revokedAt->format('Y-m-d H:i:s');
        }

        return $record->expiresAt <= $now ? 'expired' : 'live';
    }

    /**
     * `--name value` and `--name=value`; everything else positional.
     *
     * @param string[] $args
     * @return array{0: string, 1: array<string, string>, 2: string[]}|null
     */
    private static function parse(array $args): ?array
    {
        $command = array_shift($args);
        if (!in_array($command, ['issue', 'list', 'revoke'], true)) {
            return null;
        }
        $options = [];
        $positional = [];
        while ($args !== []) {
            $arg = (string) array_shift($args);
            if (strncmp($arg, '--', 2) !== 0) {
                $positional[] = $arg;
                continue;
            }
            $name = substr($arg, 2);
            if (strpos($name, '=') !== false) {
                [$name, $value] = explode('=', $name, 2);
            } elseif ($args !== []) {
                $value = (string) array_shift($args);
            } else {
                return null;
            }
            if ($name === '' || isset($options[$name])) {
                return null;
            }
            $options[$name] = $value;
        }

        return [$command, $options, $positional];
    }

    /**
     * @param array<string, string> $options
     * @param string[] $allowed
     * @param string[] $positional
     */
    private static function only(array $options, array $allowed, array $positional, int $positionals): void
    {
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new UsageError('unknown option --' . implode(', --', $unknown) . '.');
        }
        $missing = array_diff($allowed, array_keys($options));
        if ($missing !== []) {
            throw new UsageError('missing --' . implode(', --', $missing) . '.');
        }
        if (count($positional) !== $positionals) {
            throw new UsageError('unexpected arguments.');
        }
    }

    /**
     * @param array<string, string> $options
     */
    private static function number(array $options, string $name): int
    {
        $value = $options[$name];
        if (!ctype_digit($value) || strlen($value) > 9) {
            throw new UsageError("--$name must be a whole number.");
        }

        return (int) $value;
    }

    /**
     * @param array<string, string> $options
     */
    private static function text(array $options, string $name): string
    {
        $value = trim($options[$name]);
        if ($value === '') {
            throw new UsageError("--$name must not be empty.");
        }

        return $value;
    }
}
