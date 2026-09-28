<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Fa\Service\ReportRunner;
use FA\GraphQL\Fa\Service\ReportRun;
use PHPUnit\Framework\TestCase;

/**
 * Machine tokens end to end (Foundation spec §3.7): issued by bin/fa-token for the
 * seeded panel user, used over HTTP, refused once revoked, expired or deactivated,
 * never accepted as a refresh token — and bin/fa-token itself never served.
 *
 * The suite runs in the stack's app container, next to Apache, so bin/fa-token runs
 * here against the same database. tearDown deletes every token a test issued (by
 * its label) and any throwaway user a test created, pass or fail. sgwpanel itself
 * is never deactivated: a teammate's dev token is that same user, so the
 * deactivation tests use a dedicated throwaway user in the same role instead.
 */
class MachineTokenFlowTest extends TestCase
{
    use GraphQLClient;

    private const REFRESH = 'mutation ($t: String!) { tokenRefresh(refreshToken: $t) { accessToken refreshToken } }';

    private string $label;

    private ?\PDO $pdo = null;

    private ?string $testUser = null;

    protected function setUp(): void
    {
        if (!$this->userExists('sgwpanel')) {
            $this->markTestSkipped('sgwpanel is not seeded: run tools/init.sh (or tests/data/seed.sh).');
        }
        $this->label = 'http-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) {
            return;
        }
        $this->deleteTestUser();
        $this->pdo->prepare('DELETE FROM ' . $this->table('graphql_machine_token') . ' WHERE label LIKE ?')
            ->execute([$this->label . '%']);
        $this->setInactive('apitest', false);
    }

    /**
     * A throwaway user in "GraphQL Panel" (sgwpanel's own role), for the tests that
     * need to deactivate a user — never sgwpanel itself, which a teammate's dev
     * token also signs in as. tearDown removes it, pass or fail.
     */
    private function createTestUser(): string
    {
        $login = 'sgwpaneltest' . bin2hex(random_bytes(4));
        $this->pdo()->prepare(
            'INSERT INTO ' . $this->table('users')
                . ' (`user_id`, `password`, `real_name`, `role_id`, `email`, `language`)'
                . ' SELECT ?, CONCAT(\'!unusable-\', SHA2(CONCAT(UUID(), RAND()), 256)), ?, `role_id`, ?, `language`'
                . ' FROM ' . $this->table('users') . " WHERE `user_id` = 'sgwpanel'"
        )->execute([$login, 'GraphQL Panel test user', $login . '@invalid.invalid']);
        $this->testUser = $login;

        return $login;
    }

    private function deleteTestUser(): void
    {
        if ($this->testUser === null) {
            return;
        }
        $this->pdo->prepare('DELETE FROM ' . $this->table('users') . ' WHERE `user_id` = ?')
            ->execute([$this->testUser]);
        $this->testUser = null;
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO(
                'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
                (string) getenv('FA_DB_USER'),
                (string) getenv('FA_DB_PASSWORD')
            );
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    private function table(string $name): string
    {
        return getenv('FA_DB_PREFIX') . $name;
    }

    private function userExists(string $login): bool
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->table('users') . ' WHERE user_id = ?');
        $statement->execute([$login]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function setInactive(string $login, bool $inactive): void
    {
        $this->pdo()->prepare('UPDATE ' . $this->table('users') . ' SET inactive = ? WHERE user_id = ?')
            ->execute([$inactive ? 1 : 0, $login]);
    }

    /**
     * @param string[] $args
     */
    private function faToken(array $args): ReportRun
    {
        return (new ReportRunner())->run(
            array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/fa-token'], $args),
            30
        );
    }

    /**
     * @return array{token: string, jti: string}
     */
    private function issue(string $user = 'sgwpanel', int $days = 1): array
    {
        $run = $this->faToken([
            'issue', '--company', '0', '--user', $user, '--days', (string) $days, '--label', $this->label,
        ]);
        $this->assertSame(0, $run->exitCode, $run->stderr . $run->stdout);
        $token = trim($run->stdout);
        $this->assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $token, 'stdout is the token alone');
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        return ['token' => $token, 'jti' => (string) $payload['jti']];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $jti): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM ' . $this->table('graphql_machine_token') . ' WHERE jti = ?');
        $statement->execute([$jti]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    public function testIssueUseListAndRevoke(): void
    {
        $issued = $this->issue();
        $row = $this->row($issued['jti']);
        $this->assertSame('sgwpanel', $row['login']);
        $this->assertSame($this->label, $row['label']);
        $this->assertNull($row['last_used_at']);

        $me = $this->gql('{ me { login areas } }', [], $issued['token']);
        $this->assertSame(200, $me['status'], $me['raw']);
        $this->assertSame('sgwpanel', $me['body']['data']['me']['login']);

        // What the panel reads, all of it within the role.
        $read = $this->gql(
            '{ customerList { id balance { balance } } salesOrderList { id } invoiceList { id } '
                . 'customerPaymentList { id } }',
            [],
            $issued['token']
        );
        $this->assertSame(200, $read['status'], $read['raw']);
        $this->assertArrayNotHasKey('errors', $read['body'], $read['raw']);
        $this->assertIsArray($read['body']['data']['customerList']);
        $this->assertNotNull($this->row($issued['jti'])['last_used_at'], 'its use is recorded');

        // And nothing beyond it: least privilege.
        $invoice = $this->gql(
            'mutation ($in: [InvoiceCreateInput!]!) { invoiceCreate(input: $in) { id } }',
            ['in' => [['orderId' => '999999', 'orderVersion' => 0, 'date' => date('Y-m-d')]]],
            $issued['token']
        );
        $this->assertSame('FORBIDDEN', $this->code($invoice), $invoice['raw']);

        $list = $this->faToken(['list', '--company', '0']);
        $this->assertSame(0, $list->exitCode, $list->stderr);
        $this->assertMatchesRegularExpression(
            '/^' . $issued['jti'] . "\tsgwpanel\tlive\t.*\t" . preg_quote($this->label, '/') . '$/m',
            $list->stdout
        );
        $this->assertStringNotContainsString($issued['token'], $list->stdout, 'the token is shown only once');

        $revoke = $this->faToken(['revoke', '--company', '0', $issued['jti']]);
        $this->assertSame(0, $revoke->exitCode, $revoke->stderr);
        $this->assertNotNull($this->row($issued['jti'])['revoked_at']);

        $after = $this->gql('{ customerList { id } }', [], $issued['token']);
        $this->assertSame(401, $after['status'], $after['raw']);
        $this->assertSame('UNAUTHENTICATED', $this->code($after));

        $again = $this->faToken(['revoke', '--company', '0', $issued['jti']]);
        $this->assertSame(1, $again->exitCode, 'a revoked token cannot be revoked twice');
        $this->assertMatchesRegularExpression('/^' . $issued['jti'] . "\tsgwpanel\trevoked /m", $this->faToken(
            ['list', '--company', '0']
        )->stdout);
    }

    public function testATokenExpiredInTheTableIs401(): void
    {
        $issued = $this->issue();
        $this->pdo()->prepare('UPDATE ' . $this->table('graphql_machine_token') . ' SET expires_at = ? WHERE jti = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 1), $issued['jti']]);

        $response = $this->gql('{ me { login } }', [], $issued['token']);

        $this->assertSame(401, $response['status'], $response['raw']);
    }

    public function testADeactivatedUsersTokenIs401(): void
    {
        $user = $this->createTestUser();
        $issued = $this->issue($user);
        $this->setInactive($user, true);

        $response = $this->gql('{ customerList { id } }', [], $issued['token']);

        $this->assertSame(401, $response['status'], $response['raw']);
        $this->assertSame('UNAUTHENTICATED', $this->code($response));

        $this->setInactive($user, false);
        $this->assertSame(200, $this->gql('{ me { login } }', [], $issued['token'])['status']);
    }

    public function testAMachineTokenIsNeverARefreshToken(): void
    {
        $issued = $this->issue();

        $anonymous = $this->gql(self::REFRESH, ['t' => $issued['token']]);
        $this->assertSame('UNAUTHENTICATED', $this->code($anonymous), $anonymous['raw']);
        $this->assertNull($anonymous['body']['data'] ?? null);

        $asBearer = $this->gql(self::REFRESH, ['t' => $issued['token']], $issued['token']);
        $this->assertSame('UNAUTHENTICATED', $this->code($asBearer), $asBearer['raw']);
        $this->assertNull($asBearer['body']['data'] ?? null);

        // Still a good bearer token afterwards: nothing was revoked or rotated.
        $this->assertSame(200, $this->gql('{ me { login } }', [], $issued['token'])['status']);
    }

    public function testThePanelUserCannotSignInWithAPassword(): void
    {
        $response = $this->gql(
            'mutation ($u: String!, $p: String!) { login(user: $u, password: $p) { accessToken } }',
            ['u' => 'sgwpanel', 'p' => '']
        );

        $this->assertSame('UNAUTHENTICATED', $this->code($response), $response['raw']);
    }

    /**
     * @return array<string, array{0: string[], 1: string}>
     */
    public function refusedIssues(): array
    {
        return [
            'longer than machine_ttl_max' => [['--user', 'sgwpanel', '--days', '366'], '31536000'],
            'an unknown user' => [['--user', 'nobody-here', '--days', '30'], 'no user'],
            'a user without SA_GRAPHQL' => [['--user', 'noapi', '--days', '30'], 'SA_GRAPHQL'],
        ];
    }

    /**
     * @dataProvider refusedIssues
     * @param string[] $args
     */
    public function testRefusedIssuesWriteNothing(array $args, string $reason): void
    {
        $run = $this->faToken(array_merge(['issue', '--company', '0', '--label', $this->label], $args));

        $this->assertSame(1, $run->exitCode, $run->stderr . $run->stdout);
        $this->assertStringContainsString($reason, $run->stderr);
        $this->assertSame('', $run->stdout);
        $this->assertSame(0, $this->countOurs());
    }

    public function testAnInactiveUserIsRefused(): void
    {
        $user = $this->createTestUser();
        $this->setInactive($user, true);

        $run = $this->faToken([
            'issue', '--company', '0', '--user', $user, '--days', '30', '--label', $this->label,
        ]);

        $this->assertSame(1, $run->exitCode, $run->stderr);
        $this->assertStringContainsString('inactive', $run->stderr);
        $this->assertSame(0, $this->countOurs());
    }

    public function testAnUnknownCompanyIsRefused(): void
    {
        $run = $this->faToken(['list', '--company', '99']);

        $this->assertSame(1, $run->exitCode, $run->stderr);
        $this->assertStringContainsString('no company 99', $run->stderr);
    }

    /**
     * A company activated before the machine-tokens change (Foundation spec §3.7)
     * has no `graphql_machine_token` table: `RENAME TABLE` stands in for that, since
     * it is not transactional (an implicit commit either way) and must be undone by
     * hand — the `finally` restores it even if an assertion fails.
     */
    public function testAMissingTableRefusesWithAnUpgradeHint(): void
    {
        $table = $this->table('graphql_machine_token');
        $backup = $table . '_test_backup';
        $this->pdo()->exec('RENAME TABLE ' . $table . ' TO ' . $backup);

        try {
            $run = $this->faToken(['list', '--company', '0']);

            $this->assertSame(1, $run->exitCode, $run->stdout);
            $this->assertSame('', $run->stdout);
            $this->assertSame(
                'fa-token: company 0 has no graphql_machine_token table: re-activate the GraphQL '
                    . "extension for it (Setup → Install/Activate Extensions) or apply "
                    . "sql/update_1.1.sql.\n",
                $run->stderr
            );
        } finally {
            $this->pdo()->exec('RENAME TABLE ' . $backup . ' TO ' . $table);
        }
    }

    public function testTheScriptIsNotServed(): void
    {
        $response = $this->send('GET', 'bin/fa-token');

        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsString('usage', $response['raw']);
    }

    private function countOurs(): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->table('graphql_machine_token') . ' WHERE label = ?'
        );
        $statement->execute([$this->label]);

        return (int) $statement->fetchColumn();
    }
}
