<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\ReportRunner;
use PHPUnit\Framework\TestCase;

/**
 * The in-process API answers a document exactly as POST /modules/graphql/ does:
 * one machine token (bin/fa-token, as MachineTokenFlowTest issues them), one
 * document, sent over HTTP and run through Executor in this process with the
 * configuration the module serves. The suite runs in the stack's app container,
 * so FrontAccounting is on disk here.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExecutorParityTest extends TestCase
{
    use GraphQLClient;

    private const DOCUMENT = '{ customerList(query: {limit: 3}) { id name } }';

    private string $label;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::defaultRoot() . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting install at ' . Bootstrap::defaultRoot() . '.');
        }
        if (!$this->userExists('sgwpanel')) {
            $this->markTestSkipped('sgwpanel is not seeded: run tools/init.sh (or tests/data/seed.sh).');
        }
        $this->label = 'http-executor-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && isset($this->label)) {
            $this->pdo->prepare('DELETE FROM ' . $this->table('graphql_machine_token') . ' WHERE label = ?')
                ->execute([$this->label]);
        }
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

    private function issue(): string
    {
        $run = (new ReportRunner())->run([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/fa-token',
            'issue', '--company', '0', '--user', 'sgwpanel', '--days', '1', '--label', $this->label,
        ], 30);
        $this->assertSame(0, $run->exitCode, $run->stderr . $run->stdout);

        return trim($run->stdout);
    }

    public function testExecutorAnswersAsTheEndpointDoes(): void
    {
        $token = $this->issue();

        $http = $this->gql(self::DOCUMENT, [], $token);
        $this->assertSame(200, $http['status'], $http['raw']);
        $this->assertArrayNotHasKey('errors', $http['body'], $http['raw']);
        $this->assertNotEmpty($http['body']['data']['customerList'], 'the demo dataset has customers');

        // create() reads the config_graphql.php this module serves.
        $out = Executor::create()->execute($token, self::DOCUMENT, [], '127.0.0.1');

        // As the endpoint encodes it: the comparison is of what a client receives.
        $this->assertSame($http['body'], json_decode((string) json_encode($out), true));
    }
}
