<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The module's two copies of FrontAccounting code — fa_session_compat.php and
 * fa_errors_compat.php — against the FrontAccounting installed next to it. A
 * function added upstream, or renamed, shows up here rather than as an undefined
 * function in some request. Skipped without a FrontAccounting (outside the stack).
 */
class CompatDriftTest extends TestCase
{
    private static function compat(string $file): string
    {
        return dirname(__DIR__, 3) . '/src/Fa/' . $file;
    }

    private static function faFile(string $relative): string
    {
        return Bootstrap::defaultRoot() . '/' . $relative;
    }

    public function testTheSessionCopyDefinesExactlyWhatFrontAccountingDefines(): void
    {
        // The fork keeps these functions in session_utils.inc; upstream inside
        // session.inc, beside class SessionManager and the page bootstrap.
        $source = is_file(self::faFile('includes/session_utils.inc'))
            ? self::faFile('includes/session_utils.inc')
            : self::faFile('includes/session.inc');
        if (!is_file($source)) {
            $this->markTestSkipped('No FrontAccounting here; run in the FrontAccounting CI image.');
        }

        $this->assertSame(
            self::functionsIn($source),
            self::functionsIn(self::compat('fa_session_compat.php')),
            'fa_session_compat.php must define every function ' . basename($source) . ' does, and nothing else'
        );
    }

    public function testTheErrorsCopyDefinesEveryFunctionOtherFilesCall(): void
    {
        $errors = self::faFile('includes/errors.inc');
        if (!is_file($errors)) {
            $this->markTestSkipped('No FrontAccounting here; run in the FrontAccounting CI image.');
        }
        // error_handler() is only ever called from inside errors.inc itself (by
        // fa_trigger_error() and exception_handler(), both replaced), so it has no
        // stand-in: see fa_errors_compat.php.
        $expected = array_values(array_diff(self::functionsIn($errors), ['error_handler']));

        $this->assertSame(
            [],
            array_values(array_diff($expected, self::functionsIn(self::compat('fa_errors_compat.php')))),
            'functions errors.inc defines that fa_errors_compat.php does not'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheCopyDefersToADefinitionThatAlreadyExists(): void
    {
        eval('function write_login_filelog($login, $result) { return "theirs"; }');

        require self::compat('fa_session_compat.php');

        $this->assertSame('theirs', write_login_filelog('x', true));
        $this->assertTrue(function_exists('html_specials_encode'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheCopyLoadsAfterTheForksFileWithoutRedeclaringAnything(): void
    {
        $fork = self::faFile('includes/session_utils.inc');
        if (!is_file($fork)) {
            $this->markTestSkipped('Upstream FrontAccounting: there is no fork file to load first.');
        }

        require $fork;
        require self::compat('fa_session_compat.php');

        $this->assertSame(
            realpath($fork),
            (new \ReflectionFunction('write_login_filelog'))->getFileName()
        );
    }

    /**
     * Names of the functions a file declares outside any class body, lower-cased
     * and sorted. Methods of FrontAccounting's SessionManager are not functions the
     * rest of FrontAccounting calls by name.
     *
     * @return string[]
     */
    private static function functionsIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $names = [];
        $depth = 0;
        $classDepth = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $opens = $token === '{'
                || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($opens) {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                if ($classDepth !== null && $depth === $classDepth) {
                    $classDepth = null;
                }
                continue;
            }
            if (!is_array($token) || $classDepth !== null) {
                continue;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $next = $tokens[self::next($tokens, $i)] ?? null;
                if (is_array($next) && $next[0] === T_STRING) {
                    $classDepth = $depth;
                }
                continue;
            }
            if ($token[0] === T_FUNCTION) {
                $j = self::next($tokens, $i);
                if (($tokens[$j] ?? null) === '&') {
                    $j = self::next($tokens, $j);
                }
                $name = $tokens[$j] ?? null;
                if (is_array($name) && $name[0] === T_STRING) {
                    $names[] = strtolower($name[1]);
                }
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private static function next(array $tokens, int $i): int
    {
        do {
            $i++;
        } while (
            isset($tokens[$i]) && is_array($tokens[$i])
            && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        );

        return $i;
    }
}
