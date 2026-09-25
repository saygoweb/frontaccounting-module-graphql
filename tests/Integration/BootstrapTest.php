<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\ConfigException;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\FaErrorException;
use FA\GraphQL\Fa\FaMessages;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BootstrapTest extends FaTestCase
{
    /**
     * Set before Bootstrap::boot() runs (see setUp()) because PHPUnit itself holds
     * one output buffer open in an isolated process, so 0 is not the right
     * expectation — "unchanged by boot()" is.
     */
    private int $obLevelBeforeBoot = 0;

    protected function setUp(): void
    {
        $this->obLevelBeforeBoot = ob_get_level();
        // A legacy config.php switches this on; Bootstrap must leave it off.
        ini_set('display_errors', '1');
        parent::setUp();
    }

    public function testFrontAccountingsGlobalsAreGlobal(): void
    {
        $this->assertTrue(Bootstrap::isBooted());
        $this->assertArrayHasKey(0, $GLOBALS['db_connections']);
        $this->assertArrayHasKey('SA_SALESORDER', $GLOBALS['security_areas']);
        $this->assertArrayHasKey('installed_extensions', $GLOBALS);
        $this->assertInstanceOf(\sys_prefs::class, $GLOBALS['SysPrefs']);
        $this->assertSame($GLOBALS['SysPrefs'], $_SESSION['SysPrefs']);
        $this->assertTrue(function_exists('db_query'));
        $this->assertTrue(class_exists('hooks_graphql', false));
    }

    public function testNoSessionNoOutputNoHeaders(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame($this->obLevelBeforeBoot, ob_get_level());
        $this->assertSame([], headers_list());
    }

    public function testDisplayErrorsIsForcedOff(): void
    {
        $this->assertContains(ini_get('display_errors'), ['0', '']);
    }

    public function testBootIsIdempotent(): void
    {
        $prefs = $GLOBALS['SysPrefs'];
        Bootstrap::boot(Bootstrap::defaultRoot());

        $this->assertSame($prefs, $GLOBALS['SysPrefs']);
    }

    public function testSessionUtilitiesComeFromTheForkWhenItHasThemOtherwiseFromTheModule(): void
    {
        $fork = Bootstrap::defaultRoot() . '/includes/session_utils.inc';
        $expected = is_file($fork)
            ? realpath($fork)
            : realpath(dirname(__DIR__, 2) . '/src/Fa/fa_session_compat.php');

        foreach (['html_specials_encode', 'write_login_filelog', 'check_faillog', 'cache_invalidate'] as $function) {
            $this->assertSame($expected, (new \ReflectionFunction($function))->getFileName(), $function);
        }
    }

    public function testADatabaseErrorIsAnException(): void
    {
        set_global_connection(0);

        $this->expectException(FaErrorException::class);
        db_query('SELECT * FROM ' . TB_PREF . 'no_such_table', 'could not read');
    }

    public function testTheDatabaseErrorTextIsKeptAndLoggedToFrontAccountingsErrorLog(): void
    {
        set_global_connection(0);
        $log = VARLOG_PATH . '/errors.log';
        $before = is_file($log) ? (int) filesize($log) : 0;

        try {
            db_query('SELECT * FROM ' . TB_PREF . 'no_such_table', 'could not read the thing');
            $this->fail('the query did not throw');
        } catch (FaErrorException $e) {
            // Read before the rollback, which resets mysqli's error number.
            $this->assertStringContainsString('could not read the thing', $e->getMessage());
            $this->assertStringContainsString("doesn't exist", $e->getMessage());
        }

        $this->assertSame($log, ini_get('error_log'), 'errors go to FrontAccounting\'s tmp/errors.log');
        clearstatcache();
        $written = (string) file_get_contents($log, false, null, $before);
        $this->assertStringContainsString('could not read the thing', $written);
        $this->assertStringContainsString("doesn't exist", $written);
    }

    /**
     * FrontAccounting ends the request on any failed db_query($sql, $msg), a
     * duplicate key included: nothing after it may run, or the statements after
     * the rolled-back BEGIN autocommit one by one.
     */
    public function testNothingRunsAfterAFailedQueryAndNothingIsCommitted(): void
    {
        set_global_connection(0);
        $table = TB_PREF . 'graphql_test_dup';
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS 0_graphql_test_dup (id INT PRIMARY KEY, note VARCHAR(40)) ENGINE=InnoDB'
        );
        $this->pdo()->exec('DELETE FROM 0_graphql_test_dup');
        $continued = false;
        try {
            try {
                begin_transaction();
                db_query("INSERT INTO $table VALUES (1, 'header')", 'insert header');
                db_query("INSERT INTO $table VALUES (1, 'dup')", 'insert dup');
                $continued = true;
                db_query("INSERT INTO $table VALUES (2, 'line after failure')", 'insert line');
                commit_transaction();
                $this->fail('a duplicate key did not end the write');
            } catch (FaErrorException $e) {
                $this->assertStringContainsString('Duplicate entry', $e->getMessage());
            }
            $this->assertFalse($continued, 'code after the failed query ran');
            $this->assertSame(0, $GLOBALS['transaction_level'], 'the transaction is over');

            $rows = $this->pdo()->query('SELECT id FROM 0_graphql_test_dup')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([], $rows, 'nothing was committed');
            $this->assertContains(
                'The entered information is a duplicate. Please go back and enter different values.',
                FaMessages::drain()
            );
        } finally {
            $this->pdo()->exec('DROP TABLE IF EXISTS 0_graphql_test_dup');
        }
    }

    public function testAFailedQueryWithoutExitReturnsAfterRollingBack(): void
    {
        set_global_connection(0);
        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS 0_graphql_test_dup (id INT PRIMARY KEY) ENGINE=InnoDB");
        try {
            $table = TB_PREF . 'graphql_test_dup';
            db_query("INSERT INTO $table VALUES (1)", 'insert');
            // As FrontAccounting itself does for an optional write: check, don't exit.
            @db_query("INSERT INTO $table VALUES (1)");
            $this->assertSame(1062, check_db_error('dup', '', false));
        } finally {
            $this->pdo()->exec('DROP TABLE IF EXISTS 0_graphql_test_dup');
        }
    }

    public function testValidationMessagesAreCollectedNotPrinted(): void
    {
        ob_start();
        display_error('Credit limit exceeded');
        $printed = ob_get_clean();

        $this->assertSame('', $printed);
        $this->assertSame(['Credit limit exceeded'], FaMessages::drain());
    }

    public function testNotAFrontAccountingRoot(): void
    {
        $this->expectException(ConfigException::class);
        Bootstrap::assertRoot(sys_get_temp_dir());
    }

    public function testARootWithoutTheForksSessionUtilsIsAccepted(): void
    {
        $root = sys_get_temp_dir() . '/fa-graphql-upstream-root-' . getmypid();
        mkdir($root . '/includes', 0777, true);
        touch($root . '/config_db.php');
        touch($root . '/includes/current_user.inc');
        try {
            Bootstrap::assertRoot($root);
            $this->addToAssertionCount(1);
        } finally {
            unlink($root . '/includes/current_user.inc');
            unlink($root . '/config_db.php');
            rmdir($root . '/includes');
            rmdir($root);
        }
    }
}
