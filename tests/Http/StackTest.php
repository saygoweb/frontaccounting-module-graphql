<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * The stack itself: what later tasks assume is there.
 */
class StackTest extends TestCase
{
    private function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testFrontAccountingIsInPlaceWithItsSessionUtilities(): void
    {
        $root = $this->moduleDir() . '/../..';
        $this->assertFileExists($root . '/includes/current_user.inc');
        // The fork keeps these functions in includes/session_utils.inc, upstream
        // inside includes/session.inc; Bootstrap loads the fork's file or its copy.
        $this->assertTrue(
            is_file($root . '/includes/session_utils.inc')
            || strpos(
                (string) file_get_contents($root . '/includes/session.inc'),
                'function write_login_filelog('
            ) !== false
        );
    }

    public function testSgwSalesIsInstalledWithItsVendor(): void
    {
        $this->assertFileExists($this->moduleDir() . '/../sgw_sales/hooks.php');
        $this->assertFileExists($this->moduleDir() . '/../sgw_sales/vendor/autoload.php');
    }

    public function testConfigWasGeneratedWithASecret(): void
    {
        $config = require $this->moduleDir() . '/config_graphql.php';
        $this->assertGreaterThanOrEqual(32, strlen($config['secret']));
        $this->assertTrue($config['allow_insecure_login']);
    }

    public function testSeedUsersExist(): void
    {
        $pdo = new \PDO(
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            (string) getenv('FA_DB_USER'),
            (string) getenv('FA_DB_PASSWORD')
        );
        $prefix = (string) getenv('FA_DB_PREFIX');
        $rows = $pdo->query(
            "SELECT u.user_id, r.areas FROM {$prefix}users u JOIN {$prefix}security_roles r ON r.id = u.role_id"
            . " WHERE u.user_id IN ('apitest', 'noapi') ORDER BY u.user_id"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame(['apitest', 'noapi'], array_keys($rows));
        $area = (string) $this->graphqlArea();
        $this->assertContains($area, explode(';', $rows['apitest']));
        $this->assertNotContains($area, explode(';', $rows['noapi']));
        // SA_SALESORDER = SS_SALES | 3 = (12 << 8) | 3: every lookup lists with it
        // (Release 2 spec section 4.2).
        $this->assertContains('3075', explode(';', $rows['apitest']));
    }

    private function pdo(): \PDO
    {
        return new \PDO(
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            (string) getenv('FA_DB_USER'),
            (string) getenv('FA_DB_PASSWORD')
        );
    }

    /**
     * SA_GRAPHQL as FrontAccounting numbers it for graphql's extension id:
     * (id << 16) | (100 << 8) | 100. seed.sql is written for extension 1
     * (91236); the FrontAccounting CI image numbers modules in the order they
     * are activated.
     */
    private function graphqlArea(): int
    {
        $installed_extensions = [];
        include dirname($this->moduleDir(), 2) . '/company/0/installed_extensions.php';
        foreach ($installed_extensions as $id => $ext) {
            if ($ext['package'] === 'graphql') {
                return ($id << 16) | (100 << 8) | 100;
            }
        }
        throw new \RuntimeException('graphql is not registered in company/0/installed_extensions.php');
    }

    /**
     * Release 2 spec section 7: the installer datasets end with fiscal 2022, and a
     * document dated today needs a fiscal year around today. db load adds them.
     */
    public function testTodayIsInsideTheCurrentFiscalYear(): void
    {
        $prefix = (string) getenv('FA_DB_PREFIX');
        $covering = $this->pdo()->query(
            "SELECT id FROM {$prefix}fiscal_year WHERE `begin` <= CURDATE() AND `end` >= CURDATE()"
        )->fetchColumn();
        $current = $this->pdo()->query("SELECT value FROM {$prefix}sys_prefs WHERE name = 'f_year'")->fetchColumn();

        $this->assertNotFalse($covering, 'no fiscal year covers today');
        $this->assertSame((string) $covering, (string) $current);
    }

    private function baseUrl(): string
    {
        $url = getenv('FA_GRAPHQL_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }

        return rtrim((string) (getenv('FA_URL') ?: 'http://localhost'), '/') . '/modules/graphql/';
    }

    public function testConfigAndSqlAreNotServed(): void
    {
        $base = $this->baseUrl();
        // Files that exist from the start and for good: src/Server.php is deleted in
        // Task 4, so the directory rule is proven with vendor/ and the file rule with
        // hooks.php.
        $paths = [
            'config_graphql.php',
            'config_graphql.example.php',
            'sql/update_1.0.sql',
            'sql/update_1.1.sql',
            'vendor/autoload.php',
            'hooks.php',
            'bin/fa-report',
            'bin/fa-token',
            'bin/generate',
            'tools/ci.sh',
            'tools/fixtures.php',
        ];
        foreach ($paths as $path) {
            $this->assertIs403($base . $path, $path);
        }
    }

    /**
     * I-1: a renamed or backup copy of config_graphql.php must be denied by prefix,
     * not by a `.php$` suffix, or the secret it carries is served as a static file.
     */
    public function testConfigBackupCopyIsNotServed(): void
    {
        $moduleDir = $this->moduleDir();
        $backup = $moduleDir . '/config_graphql.php.bak';
        file_put_contents($backup, "<?php\nreturn ['secret' => 'not-a-real-secret'];\n");
        try {
            $base = $this->baseUrl();
            $this->assertIs403($base . 'config_graphql.php.bak', 'config_graphql.php.bak');
        } finally {
            unlink($backup);
        }
    }

    /**
     * M-5: the module's own .htaccess, the only protection on a production install
     * without the dev vhost's rules, must deny dot-files/dot-directories and every
     * other file that is not index.php.
     */
    public function testDotFilesAndStrayFilesAreNotServed(): void
    {
        $base = $this->baseUrl();
        $paths = [
            '.superpowers/sdd/2026-09-21-foundation/checkpoint-A-review.md',
            'README.md',
            'LICENSE',
            'phpunit.xml',
            'phpcs.xml',
            'composer.json',
        ];
        foreach ($paths as $path) {
            $this->assertIs403($base . $path, $path);
        }
    }

    private function assertIs403(string $url, string $message): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        file_get_contents($url, false, $context);
        $this->assertStringContainsString(' 403', $http_response_header[0], $message);
    }
}
