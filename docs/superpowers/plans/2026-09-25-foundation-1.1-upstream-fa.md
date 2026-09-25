# Foundation 1.1 — upstream FrontAccounting — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The module runs on unmodified upstream FrontAccounting `master` (2.4.20) as well as on the `cambell-prince/frontaccounting` fork, and nothing PHP or FrontAccounting prints ever reaches an API client — including when FrontAccounting code calls `exit`/`die` or PHP dies of a fatal error.

**Architecture:** `Bootstrap` loads the fork's `includes/session_utils.inc` when it exists and otherwise the module's own copy of those functions (`src/Fa/fa_session_compat.php`, FrontAccounting's code behind `function_exists` guards, as `modules/api` ships its own `session_utils.inc`). `index.php` starts `OutputCapture` right after the autoloader: one output buffer under the whole request and a shutdown function. Just before Slim's `ResponseEmitter` sends the response, the capture is ended — everything printed and every header set so far is logged and dropped. If the request ends first, the shutdown function drops the output and answers the fixed JSON 500. The docker stack defaults to upstream; CI runs upstream and the fork on PHP 7.4 and 8.3.

**Tech Stack:** unchanged — PHP 7.4 floor (CI also 8.3), Slim 4 + slim/psr7, webonyx/graphql-php ^15.32.3, php-di ^6, saygoweb/anorm ^3.2.1, saygoweb/anorm-graphql ^0.1, PHPUnit 9.6, phpcs PSR-12, PHPStan level 5, Docker (`docker/fa-graphql`).

**Spec:** `docs/superpowers/specs/2026-09-21-foundation-design.md` at `e59a284` — the sections marked *(revised: upstream FA)*: §1 "Decisions" and "What Release 2 inherits from upstream compatibility", §2.1 (`session_utils`, `errors.inc`), §2.4 (output capture), §6 (the new 500 row), §7, §8, §9. Read those first.

## Global Constraints

- PHP `^7.4 || ^8.0`: no enums, no `readonly`, no constructor promotion, no `match`, no named arguments, no union types. Typed properties and arrow functions are fine.
- **Upstream FrontAccounting `master` (`https://github.com/FrontAccountingERP/FA.git`, 2.4.20 at `9464a3ff`) is the target, with no core change.** The fork (`https://github.com/cambell-prince/frontaccounting.git` @ `master-cp`) stays supported. Nothing may require a file only the fork has.
- Every HTTP response body is JSON. No output printed by PHP or FrontAccounting reaches the client, and no header FrontAccounting set does either.
- The fixed body of the shutdown 500 is exactly `{"errors":[{"message":"Internal server error","extensions":{"code":"INTERNAL"}}]}` with `Content-Type: application/json; charset=utf-8`.
- Discarded output is logged truncated to 2048 bytes, with its full length.
- `src/Fa/fa_session_compat.php` is FrontAccounting's code byte for byte, inside `function_exists` guards: never reformat it. It is excluded from phpcs and from PHPStan's analysis.
- Do not patch `sgw_sales` or FrontAccounting from this repository. If something fails only on upstream because of them, stop and report.
- Everything runs in the container: `docker/fa-graphql test`, `lint`, `analyze`, `composer …`, `exec …`. Test suites: `--testsuite unit|integration|http`. `docker/fa-graphql ci` rebuilds the stack it runs on.
- Throwaway stacks never reuse the main stack's ports or `sgw_sales`' (8110/3330/8111) or anorm-graphql's (8096–8099/3316–3319). Use exactly: upstream 8.3 `COMPOSE_PROJECT_NAME=fa-graphql-83 HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106`; second FrontAccounting on 7.4 `COMPOSE_PROJECT_NAME=fa-graphql-alt HTTP_PORT=8107 DB_PORT=3327 PMA_PORT=8108`; second FrontAccounting on 8.3 `COMPOSE_PROJECT_NAME=fa-graphql-alt-83 HTTP_PORT=8112 DB_PORT=3332 PMA_PORT=8113`. Destroy each (`destroy --yes`, same variables) when done. Never touch containers that are not yours.
- PSR-12 for `src/`, `tests/`, `index.php`, `app.php`, `container.php`. Namespace `FA\GraphQL\` → `src/`, `FA\GraphQL\Tests\` → `tests/`.
- Work on branch `feature/upstream-fa`. Commit after every task: a subject line, a blank line, then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` on its own line.

## Review policy (from the user)

**Do not review after every task.** There is one Checkpoint, after Task 3: an independent `/code-review medium`-equivalent over `main..HEAD`, a walk of the *(revised: upstream FA)* spec sections, and all four FrontAccounting × PHP combinations green locally. If you are using subagent-driven-development, skip its per-task review stages.

## Review Focus

Failure modes the spec implies but does not spell out, most likely first. Each is pinned by a test in the task named.

1. **Security tests silently skipped on upstream.** `SessionPipelineTest` and `OneCompanyPerRequestTest` skip when `includes/session_utils.inc` is missing — on upstream they would skip, and CI would stay green without them. Their guards change to `config_db.php` only (Task 1), and on upstream the suites must report no skipped tests (Task 1 Step 8, Task 3 Step 6).
2. **A function defined twice → "Cannot redeclare" fatal.** The compat file loaded after FrontAccounting's own definitions (the fork's file, or any other source) must defer to them (Task 1, `CompatDriftTest::testTheCopyDefersToADefinitionThatAlreadyExists` and `…AfterTheForksFile`).
3. **Headers FrontAccounting set reach the client.** `login_fail()` sends `HTTP/1.1 401` and prints HTML, then `die()`s; an output handler can call `header()` when it is closed. Neither may survive: the exit path answers 500 JSON without them, and the normal path drops them before Slim sets its own (Task 2, `OutputCaptureServerTest` cases `header-then-exit`, `normal-header`, `handler`).
4. **Printing twice, or after the body.** A shutdown after a normal `end()` must print nothing, and output from a shutdown function registered later (an extension's) must not be appended to the response (Task 2, `OutputCaptureTest::testShutdownAfterEndPrintsNothing`, `OutputCaptureServerTest` case `late`).
5. **Nested buffers with callbacks.** FrontAccounting's `output_html` style buffers (`ob_start($callback)`) left open above the capture are closed and discarded, their callback's return value never sent (Task 2, `OutputCaptureTest::testEndClosesEveryBufferAboveItsOwn`, `OutputCaptureServerTest` case `handler`).

## File map

```
src/Fa/fa_session_compat.php        (create, Task 1)  FrontAccounting's session_utils functions, guarded
src/Fa/Bootstrap.php                (modify, Task 1)  fork file or compat; no "needs the fork" refusal
src/Fa/fa_errors_compat.php         (modify, Task 1)  version note in the header
phpcs.xml  phpstan.neon             (modify, Task 1)  exclude the verbatim copy
tests/Unit/Fa/CompatDriftTest.php   (create, Task 1)
tests/Integration/BootstrapTest.php (modify, Task 1)
tests/Integration/SessionPipelineTest.php  tests/Integration/OneCompanyPerRequestTest.php (modify, Task 1)
tests/Http/StackTest.php  tests/Http/AuthFlowTest.php  (modify, Task 1)
src/Http/OutputCapture.php          (create, Task 2)
index.php                           (modify, Task 2)
tests/Unit/Http/OutputCaptureTest.php  tests/Unit/Http/OutputCaptureServerTest.php  (create, Task 2)
tests/Unit/Http/fixtures/capture.php   (create, Task 2)
docker/Dockerfile  docker/docker-compose.yml  docker/fa-graphql  docker/.env.example  docker/README.md (modify, Task 3)
.github/workflows/ci.yml  README.md (modify, Task 3)
```

---

### Task 1: Run on upstream — the session_utils copy and Bootstrap's choice

**Files:**
- Create: `src/Fa/fa_session_compat.php`, `tests/Unit/Fa/CompatDriftTest.php`
- Modify: `src/Fa/Bootstrap.php`, `src/Fa/fa_errors_compat.php` (header), `phpcs.xml`, `phpstan.neon`, `tests/Integration/BootstrapTest.php`, `tests/Integration/SessionPipelineTest.php`, `tests/Integration/OneCompanyPerRequestTest.php`, `tests/Http/StackTest.php`, `tests/Http/AuthFlowTest.php` (a comment)

**Interfaces:**
- Consumes: `Bootstrap::boot/assertRoot/defaultRoot`, `ConfigException`.
- Produces: `Bootstrap::assertRoot(string $root): void` refuses only a root without `config_db.php` or `includes/current_user.inc`. `Bootstrap::boot()` loads `<root>/includes/session_utils.inc` if it is a file, otherwise `src/Fa/fa_session_compat.php`. No new public API.

**How the compat path is tested — and why there is no switch for it.** The stack's default becomes upstream in Task 3, so every local run after that exercises the copy, and CI's fork jobs exercise the fork's file; before Task 3, Step 8 below runs the suites on a throwaway upstream stack. A `Bootstrap` option or a root overlay that forces the copy on the fork would be production code (or elaborate test scaffolding) that exists only for tests. The integration test asserts, through `ReflectionFunction::getFileName()`, that the functions came from whichever source the installed FrontAccounting calls for; the unit tests check the copy itself.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Fa/CompatDriftTest.php`:

```php
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
            $this->markTestSkipped('No FrontAccounting here; run in the docker stack.');
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
            $this->markTestSkipped('No FrontAccounting here; run in the docker stack.');
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
```

`tests/Integration/BootstrapTest.php` — add, after `testBootIsIdempotent()`:

```php
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
```

and replace `testNotAFrontAccountingRoot()` with:

```php
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
```

- [ ] **Step 2: Run to see them fail**

Run: `docker/fa-graphql test --testsuite unit --filter CompatDriftTest`
Expected: FAIL — `file_get_contents(.../src/Fa/fa_session_compat.php): failed to open stream` in the first test (the second passes already: `fa_errors_compat.php` covers `errors.inc`), and `require(...fa_session_compat.php): failed to open stream` in the two separate-process tests.

Run: `docker/fa-graphql test --testsuite integration --filter BootstrapTest`
Expected: FAIL — `testARootWithoutTheForksSessionUtilsIsAccepted`: `ConfigException: This FrontAccounting has no includes/session_utils.inc…`. (`testSessionUtilitiesComeFromTheForkWhenItHasThemOtherwiseFromTheModule` passes on the fork stack as it is; it becomes meaningful on upstream, Step 8.)

- [ ] **Step 3: The copy — `src/Fa/fa_session_compat.php`**

Write exactly the file below. Its function bodies are FrontAccounting's byte for byte (from the fork's `includes/session_utils.inc` lines 109–360, identical to upstream `master:includes/session.inc` lines 112–363, verified 2026-09-25); the only additions are the header comment and one `function_exists` guard around each function. Tabs, spacing and FrontAccounting's own typos stay as they are.

Then check it, from the module root on the host (the host FA checkout is the fork):

```bash
python3 - <<'PY'
import re
fa = open('../../includes/session_utils.inc').read().split('\n')[108:]
ours = open('src/Fa/fa_session_compat.php').read().split('\n')
start = next(i for i, l in enumerate(ours) if l.startswith("if (!function_exists('output_html'))"))
out = []
for l in ours[start:]:
    if re.match(r"^if \(!function_exists\('\w+'\)\) \{$", l):
        continue                       # a guard opening
    if l == '}' and out and out[-1] == '}':
        continue                       # a guard closing, right after the function's own brace
    out.append(l)
print('IDENTICAL' if '\n'.join(out).rstrip() == '\n'.join(fa).rstrip() else 'DIFFERENT')
PY
docker/fa-graphql exec php -l src/Fa/fa_session_compat.php
```

Expected: `IDENTICAL`, then `No syntax errors detected`.

```php
<?php
/**********************************************************************
	Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL,
	as published by the Free Software Foundation, either version 3
	of the License, or (at your option) any later version.
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
	See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
/*
	The GraphQL module's copy of the functions FrontAccounting's includes/session.inc
	defines besides the session itself, for a FrontAccounting that keeps them there
	(upstream) rather than in includes/session_utils.inc (the cambell-prince fork).
	session.inc itself cannot be included: it enforces page security, renders the
	login page and starts a cookie session. Bootstrap loads this file only when
	includes/session_utils.inc is absent.

	Copied from FrontAccounting 2.4.20: upstream master 9464a3ff, includes/session.inc
	(identical, function for function, to the fork's includes/session_utils.inc),
	minus class SessionManager, since the API never starts a PHP session. Each
	function body is FrontAccounting's, byte for byte; only the function_exists
	guard around it is added, so nothing is redeclared when FrontAccounting's own
	definition was loaded first. Kept in FrontAccounting's style, so phpcs and
	PHPStan skip this file; tests/Unit/Fa/CompatDriftTest compares its function list
	with the installed FrontAccounting's on every run.
*/

if (!function_exists('output_html')) {
function output_html($text)
{
	global $before_box, $Ajax, $messages;
	// Fatal errors are not send to error_handler,
	// so we must check the output
	if ($text && preg_match('/\bFatal error(<.*?>)?:(.*)/i', $text, $m)) {
		$Ajax->aCommands = array();  // Don't update page via ajax on errors
		$text = preg_replace('/\bFatal error(<.*?>)?:(.*)/i','', $text);
		$messages[] = array(E_ERROR, $m[2], null, null);
	}
	$Ajax->run();
	return  in_ajax() ? fmt_errors() : ($before_box.fmt_errors().$text);
}
}
//----------------------------------------------------------------------------------------

if (!function_exists('kill_login')) {
function kill_login()
{
	session_unset();
	session_destroy();
}
}
//----------------------------------------------------------------------------------------

if (!function_exists('login_fail')) {
function login_fail()
{
	global $path_to_root;

	header("HTTP/1.1 401 Authorization Required");
	echo "<center><br><br><font size='5' color='red'><b>" . _("Incorrect Password") . "<b></font><br><br>";
	echo "<b>" . _("The user and password combination is not valid for the system.") . "<b><br><br>";
	echo _("If you are not an authorized user, please contact your system administrator to obtain an account to enable you to use the system.");
	echo "<br><a href='$path_to_root/index.php'>" . _("Try again") . "</a>";
	echo "</center>";
	kill_login();
	die();
}
}

if (!function_exists('password_reset_fail')) {
function password_reset_fail()
{
	global $path_to_root;
	
  	echo "<center><br><br><font size='5' color='red'><b>" . _("Incorrect Email") . "<b></font><br><br>";
  	echo "<b>" . _("The email address does not exist in the system, or is used by more than one user.") . "<b><br><br>";

  	echo _("Plase try again or contact your system administrator to obtain new password.");
  	echo "<br><a href='$path_to_root/index.php?reset=1'>" . _("Try again") . "</a>";
  	echo "</center>";

	kill_login();
	die();
}
}

if (!function_exists('password_reset_success')) {
function password_reset_success()
{
	global $path_to_root;

  	echo "<center><br><br><font size='5' color='green'><b>" . _("New password sent") . "<b></font><br><br>";
  	echo "<b>" . _("A new password has been sent to your mailbox.") . "<b><br><br>";

  	echo "<br><a href='$path_to_root/index.php'>" . _("Login here") . "</a>";
  	echo "</center>";
	
	kill_login();
	die();
}
}

if (!function_exists('check_faillog')) {
function check_faillog()
{
	global $SysPrefs, $login_faillog;

	$user = $_SESSION["wa_current_user"]->user;

	$_SESSION["wa_current_user"]->login_attempt++;
	if (@$SysPrefs->login_delay && (@$login_faillog[$user][$_SERVER['REMOTE_ADDR']] >= @$SysPrefs->login_max_attempts) && (time() < $login_faillog[$user]['last'] + $SysPrefs->login_delay))
		return true;

	return false;
}
}

/*
	Ensure file is re-read on next request if php caching is active
*/
if (!function_exists('cache_invalidate')) {
function cache_invalidate($filename)
{
	if (function_exists('opcache_invalidate'))	// OpCode extension
		opcache_invalidate($filename);
}
}

/*
	Simple brute force attack detection is performed before connection to company database is open. Therefore access counters have to be stored in file.
	Login attempts counter is created for every new user IP, which partialy prevent DOS attacks.
*/
if (!function_exists('write_login_filelog')) {
function write_login_filelog($login, $result)
{
	global $login_faillog, $SysPrefs, $path_to_root;

	$user = $_SESSION["wa_current_user"]->user;

	$ip = $_SERVER['REMOTE_ADDR'];

	if (!isset($login_faillog[$user][$ip]) || $result) // init or reset on successfull login
		$login_faillog[$user] = array($ip => 0, 'last' => '');

 	if (!$result)
	{
		if ($login_faillog[$user][$ip] < @$SysPrefs->login_max_attempts) {

 			$login_faillog[$user][$ip]++;
 		} else {
 			$login_faillog[$user][$ip] = 0; // comment out to restart counter only after successfull login.
	 		error_log(sprintf(_("Brute force attack on account '%s' detected. Access for non-logged users temporarily blocked."	), $login));
	 	}
 		$login_faillog[$user]['last'] = time();
	}

	$msg = "<?php\n";
	$msg .= "/*\n";
	$msg .= "Login attempts info.\n";
	$msg .= "*/\n";
	$msg .= "\$login_faillog = " .var_export($login_faillog, true). ";\n";

	$filename = VARLIB_PATH."/faillog.php";

	if ((!file_exists($filename) && is_writable(VARLIB_PATH)) || is_writable($filename))
	{
		file_put_contents($filename, $msg);
		cache_invalidate($filename);
	}
}
}

//----------------------------------------------------------------------------------------

if (!function_exists('check_page_security')) {
function check_page_security($page_security)
{
	global $SysPrefs;
	
	$msg = '';
	
	if (!$_SESSION["wa_current_user"]->check_user_access())
	{
		// notification after upgrade from pre-2.2 version
		$msg = $_SESSION["wa_current_user"]->old_db ?
			 _("Security settings have not been defined for your user account.")
				. "<br>" . _("Please contact your system administrator.")	
			: _("Please remove \$security_groups and \$security_headings arrays from config.php file!");
	} elseif (!$SysPrefs->db_ok && !$_SESSION["wa_current_user"]->can_access('SA_SOFTWAREUPGRADE')) 
	{
		$msg = _('Access to application has been blocked until database upgrade is completed by system administrator.');
	}
	
	if ($msg){
		display_error($msg);
		end_page(@$_REQUEST['popup']);
		kill_login();
		exit;
	}

	if (!$_SESSION["wa_current_user"]->can_access_page($page_security))
	{

		echo "<center><br><br><br><b>";
		echo _("The security settings on your account do not permit you to access this function");
		echo "</b>";
		echo "<br><br><br><br></center>";
		end_page(@$_REQUEST['popup']);
		exit;
	}
	if (!$SysPrefs->db_ok 
		&& !in_array($page_security, array('SA_SOFTWAREUPGRADE', 'SA_OPEN', 'SA_BACKUP')))
	{
		display_error(_('System is blocked after source upgrade until database is updated on System/Software Upgrade page'));
		end_page();
		exit;
	}

}
}
/*
	Helper function for setting page security level depeding on 
	GET start variable and/or some value stored in session variable.
	Before the call $page_security should be set to default page_security value.
*/
if (!function_exists('set_page_security')) {
function set_page_security($value=null, $trans = array(), $gtrans = array())
{
	global $page_security;

	// first check is this is not start page call
	foreach($gtrans as $key => $area)
		if (isset($_GET[$key])) {
			$page_security = $area;
			return;
		}

	// then check session value
	if (isset($trans[$value])) {
		$page_security = $trans[$value];
		return;
	}
}
}

//-----------------------------------------------------------------------------
//	Removing magic quotes from nested arrays/variables
//
if (!function_exists('strip_quotes')) {
function strip_quotes($data)
{
	if(version_compare(phpversion(), '5.4', '<') && get_magic_quotes_gpc()) {
		if(is_array($data)) {
			foreach($data as $k => $v) {
				$data[$k] = strip_quotes($data[$k]);
			}
		} else
			return stripslashes($data);
	}
	return $data;
}
}

/*
	htmlspecialchars does not support certain encodings.
	ISO-8859-2 fortunately has the same special characters positions as 
	ISO-8859-1, so fix is easy. If any other unsupported encoding is used,
	add workaround here.
*/
if (!function_exists('html_specials_encode')) {
function html_specials_encode($str)
{
	return htmlspecialchars($str, ENT_QUOTES, $_SESSION['language']->encoding=='iso-8859-2' ?
		 'ISO-8859-1' : $_SESSION['language']->encoding);
}
}

if (!function_exists('html_cleanup')) {
function html_cleanup(&$parms)
{
	foreach($parms as $name => $value) {
		if (is_array($value))
			html_cleanup($parms[$name]);
		else
			$parms[$name] = html_specials_encode($value);
	}
	reset($parms); // needed for direct key() usage later throughout the sources
}
}

//============================================================================
//
//
if (!function_exists('login_timeout')) {
function login_timeout()
{
	// skip timeout on logout page
	if ($_SESSION["wa_current_user"]->logged) {
		$tout = $_SESSION["wa_current_user"]->timeout;
		if ($tout && (time() > $_SESSION["wa_current_user"]->last_act + $tout))
		{
			$_SESSION["wa_current_user"]->logged = false;
		}
		$_SESSION["wa_current_user"]->last_act = time();
	}
}
}
```

- [ ] **Step 4: Bootstrap loads the fork's file or the copy**

`src/Fa/Bootstrap.php`:

In the class docblock, after the paragraph beginning ` *  - includes/references.inc is not in the include list`, add:

```php
 *  - includes/session_utils.inc exists only in the cambell-prince fork; upstream
 *    FrontAccounting keeps those functions inside session.inc, which cannot be
 *    included. loadSessionUtilities() takes the fork's file when it is there and
 *    the module's own copy (fa_session_compat.php) otherwise.
```

Replace the `INCLUDES` docblock and first entry:

```php
    /**
     * In FrontAccounting's own order (includes/session.inc), minus errors.inc
     * (replaced by installFaCompat()), session_utils.inc (loaded first by
     * loadSessionUtilities(): the fork has it, upstream does not) and
     * app_entries.inc (not needed — see the class docblock).
     */
    private const INCLUDES = [
        'includes/current_user.inc',
```

(the rest of the array is unchanged: `'includes/session_utils.inc',` is the only line removed).

Replace `assertRoot()`:

```php
    public static function assertRoot(string $root): void
    {
        if (!is_file($root . '/config_db.php') || !is_file($root . '/includes/current_user.inc')) {
            // No path in the message: JsonErrorMiddleware shows it to the client.
            throw new ConfigException(
                'fa_root does not name a FrontAccounting install (set it in config_graphql.php).'
            );
        }
    }
```

In `load()`, as its first statement:

```php
        self::loadSessionUtilities($root);
```

and add the method after `load()`:

```php
    /**
     * The functions session.inc defines besides the session itself
     * (html_specials_encode, write_login_filelog, check_faillog, ...). The fork
     * keeps them in includes/session_utils.inc; upstream only inside session.inc,
     * so for upstream the module brings its own copy, as modules/api does.
     */
    private static function loadSessionUtilities(string $root): void
    {
        $fork = $root . '/includes/session_utils.inc';
        if (is_file($fork)) {
            self::includeGlobal($fork);

            return;
        }
        require_once __DIR__ . '/fa_session_compat.php';
    }
```

- [ ] **Step 5: The errors copy names its source; the linters skip the verbatim copy**

`src/Fa/fa_errors_compat.php` — in the header docblock, after the paragraph that ends `…a thrown FaErrorException never leaves a transaction open and never loses its cause.`, add:

```php
 *
 * Mirrors FrontAccounting 2.4.20's includes/errors.inc (upstream master 9464a3ff;
 * the fork's differs by one comment). It stays in the module for good: the module
 * runs on an unmodified upstream core. tests/Unit/Fa/CompatDriftTest checks that
 * every function errors.inc defines, except error_handler() (only ever called from
 * inside errors.inc), is defined here.
```

`phpcs.xml` — after `<exclude-pattern>vendor/*</exclude-pattern>`:

```xml
    <!-- FrontAccounting's own code, byte for byte: never reformatted. -->
    <exclude-pattern>*/src/Fa/fa_session_compat.php</exclude-pattern>
```

`phpstan.neon` — after the `paths:` list:

```yaml
    # FrontAccounting's own code, byte for byte (it still calls
    # get_magic_quotes_gpc() behind a PHP < 5.4 check). FrontAccounting is
    # scanned for symbols, never analysed; this copy of it is no different.
    excludePaths:
        analyse:
            - src/Fa/fa_session_compat.php
```

- [ ] **Step 6: No test may need the fork**

`tests/Integration/SessionPipelineTest.php` — `setUp()` becomes:

```php
    protected function setUp(): void
    {
        if (!is_file(Bootstrap::defaultRoot() . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting here; run in the docker stack.');
        }
        parent::setUp();
    }
```

`tests/Integration/OneCompanyPerRequestTest.php` — in `setUp()`, the guard becomes:

```php
        if (!is_file($root . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting here; run in the docker stack.');
        }
```

`tests/Http/StackTest.php` — replace `testFrontAccountingIsTheForkWithSessionUtils()` with:

```php
    public function testFrontAccountingIsInPlaceWithItsSessionUtilities(): void
    {
        $root = $this->moduleDir() . '/../..';
        $this->assertFileExists($root . '/includes/current_user.inc');
        // The fork keeps these functions in includes/session_utils.inc, upstream
        // inside includes/session.inc; Bootstrap loads the fork's file or its copy.
        $this->assertTrue(
            is_file($root . '/includes/session_utils.inc')
            || strpos((string) file_get_contents($root . '/includes/session.inc'), 'function write_login_filelog(') !== false
        );
    }
```

`tests/Http/AuthFlowTest.php` — in the docblock of `testTheWebLoginStillChecksPasswords()`, replace `(includes/session_utils.inc)` with `(includes/session.inc; session_utils.inc in the fork)`.

- [ ] **Step 7: Run to see them pass (fork stack)**

Run: `docker/fa-graphql test --testsuite unit --filter CompatDriftTest`
Expected: PASS — 4 tests.

Run: `docker/fa-graphql test`
Expected: PASS — every suite; the count is the previous 248 plus the new tests.

Run: `docker/fa-graphql lint && docker/fa-graphql analyze`
Expected: clean.

- [ ] **Step 8: Run on upstream, in a throwaway stack**

```bash
FA_REPO=https://github.com/FrontAccountingERP/FA.git FA_REF=master \
COMPOSE_PROJECT_NAME=fa-graphql-alt HTTP_PORT=8107 DB_PORT=3327 PMA_PORT=8108 \
  docker/fa-graphql up --build
FA_REPO=https://github.com/FrontAccountingERP/FA.git FA_REF=master \
COMPOSE_PROJECT_NAME=fa-graphql-alt HTTP_PORT=8107 DB_PORT=3327 PMA_PORT=8108 \
  docker/fa-graphql info          # FA source: FrontAccountingERP/FA @ master
FA_REPO=https://github.com/FrontAccountingERP/FA.git FA_REF=master \
COMPOSE_PROJECT_NAME=fa-graphql-alt HTTP_PORT=8107 DB_PORT=3327 PMA_PORT=8108 \
  docker/fa-graphql test
```

Expected: `OK (N tests, …)` with **no** "Skipped" (Review Focus 1). `BootstrapTest::testSessionUtilitiesComeFromTheForkWhenItHasThemOtherwiseFromTheModule` now proves the copy is loaded.

If something fails here and not on the fork:
- A missing function or a behaviour difference in FrontAccounting → fix it in the module (the copy, `Bootstrap`), add a test, rerun both stacks.
- `sgw_sales` fails to install, activate or load on upstream → **stop and report** with the error; do not patch `sgw_sales` here.
- Anything else you cannot attribute → stop and report.

Then destroy it (same variables): `… docker/fa-graphql destroy --yes`.

- [ ] **Step 9: Commit**

```bash
git add src/Fa/fa_session_compat.php src/Fa/Bootstrap.php src/Fa/fa_errors_compat.php phpcs.xml phpstan.neon \
  tests/Unit/Fa/CompatDriftTest.php tests/Integration/BootstrapTest.php tests/Integration/SessionPipelineTest.php \
  tests/Integration/OneCompanyPerRequestTest.php tests/Http/StackTest.php tests/Http/AuthFlowTest.php
git commit -m "Run on upstream FrontAccounting: bring session_utils when the fork's file is absent

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Output capture for the whole request

**Files:**
- Create: `src/Http/OutputCapture.php`, `tests/Unit/Http/OutputCaptureTest.php`, `tests/Unit/Http/OutputCaptureServerTest.php`, `tests/Unit/Http/fixtures/capture.php`
- Modify: `index.php`

**Interfaces:**
- Produces: `final class FA\GraphQL\Http\OutputCapture` (static): `const BODY` (the fixed 500 body); `const LOGGED_BYTES = 2048`; `start(): void` (idempotent while capturing; registers the shutdown function once per process); `end(): string` (closes every buffer above the capture's level, returns and logs what they held, removes every header set so far; a second call returns `''`); `afterEmit(): void` (opens a buffer that discards, and logs, anything printed after the response); `onShutdown(): void` (public for `register_shutdown_function`; does nothing unless a capture is still open).
- `index.php`: `OutputCapture::start()` directly after `require __DIR__ . '/vendor/autoload.php'`; every response — including the "not configured" 500 — goes through `$send`, which calls `end()`, `Slim\ResponseEmitter::emit()`, then `afterEmit()`. `$app->run()` is replaced by `$send($app->handle($request))` (what `run()` does, split so the capture ends between the two).

**Why no http-suite test drives `exit` through Apache.** Once `Bootstrap` clears the superglobals (Checkpoint B), no request can reach FrontAccounting's `die()` paths, and adding a route that exits only for tests would be test code in production. `OutputCaptureServerTest` instead serves a fixture script with PHP's built-in web server, so the status line, headers and body are observed exactly as a client sees them, which the CLI cannot show.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Http/OutputCaptureTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Http\OutputCapture;
use PHPUnit\Framework\TestCase;

class OutputCaptureTest extends TestCase
{
    private string $log = '';

    private string $previousLog = '';

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'capture-log');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        OutputCapture::end();
        ini_set('error_log', $this->previousLog);
        @unlink($this->log);
    }

    public function testEndReturnsWhatWasPrintedAndNothingReachesTheClient(): void
    {
        $this->expectOutputString('');

        OutputCapture::start();
        echo '<div class="err_msg">FrontAccounting says hello</div>';

        $this->assertSame('<div class="err_msg">FrontAccounting says hello</div>', OutputCapture::end());
    }

    public function testEndClosesEveryBufferAboveItsOwn(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();

        OutputCapture::start();
        echo 'one ';
        ob_start();
        echo 'two ';
        ob_start(static function (string $buffer): string {
            return '<html>' . $buffer . '</html>';
        });
        echo 'three';

        $this->assertSame('one two three', OutputCapture::end());
        $this->assertSame($level, ob_get_level());
    }

    public function testTheDiscardedOutputIsLoggedTruncatedWithItsLength(): void
    {
        $this->expectOutputString('');

        OutputCapture::start();
        echo str_repeat('a', 5000);
        OutputCapture::end();

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('5000 bytes of output', $log);
        $this->assertStringContainsString(str_repeat('a', OutputCapture::LOGGED_BYTES), $log);
        $this->assertStringNotContainsString(str_repeat('a', OutputCapture::LOGGED_BYTES + 1), $log);
    }

    public function testNothingIsLoggedWhenNothingWasPrinted(): void
    {
        OutputCapture::start();
        OutputCapture::end();

        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function testShutdownAfterEndPrintsNothing(): void
    {
        $this->expectOutputString('');

        OutputCapture::start();
        OutputCapture::end();
        OutputCapture::onShutdown();
    }

    public function testASecondEndReturnsNothing(): void
    {
        OutputCapture::start();
        echo 'x';
        OutputCapture::end();

        $this->assertSame('', OutputCapture::end());
    }
}
```

`tests/Unit/Http/fixtures/capture.php`:

```php
<?php

/*
    Served by OutputCaptureServerTest through PHP's built-in web server: stands in
    for index.php with FrontAccounting misbehaving in the way ?case= names. Test
    code only; never deployed (tests/ is denied by .htaccess).
*/

use FA\GraphQL\Http\OutputCapture;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// As a legacy config.php would: errors printed into the body. The capture must
// still keep them from the client.
ini_set('display_errors', '1');
ini_set('error_log', (string) getenv('CAPTURE_LOG'));

OutputCapture::start();

echo '<html>FrontAccounting says hello</html>';

switch ($_GET['case'] ?? '') {
    case 'exit':
        exit;
    case 'die':
        die('Restricted access');
    case 'fatal':
        undefined_function_for_the_capture_test();
        break;
    case 'user-error':
        trigger_error('FrontAccounting gave up', E_USER_ERROR);
        break;
    case 'header-then-exit':
        header('HTTP/1.1 401 Authorization Required');
        header('X-FrontAccounting: 1');
        exit;
    case 'normal-header':
        header('X-FrontAccounting: 1');
        break;
    case 'handler':
        ob_start(static function (string $buffer): string {
            header('X-FrontAccounting: 1');

            return '<b>' . $buffer . '</b>';
        });
        echo 'inside a handler';
        break;
    case 'late':
        register_shutdown_function(static function (): void {
            echo 'printed by a later shutdown function';
        });
        break;
}

OutputCapture::end();
header('Content-Type: application/json; charset=utf-8');
echo '{"data":{"ok":true}}';
OutputCapture::afterEmit();
```

`tests/Unit/Http/OutputCaptureServerTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Http\OutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * OutputCapture over real HTTP: fixtures/capture.php served by PHP's built-in web
 * server, so the status line, the headers and the body are what a client gets.
 */
class OutputCaptureServerTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            self::markTestSkipped('No local port to serve the fixture on.');
        }
        $name = (string) stream_socket_get_name($probe, false);
        self::$port = (int) substr((string) strrchr($name, ':'), 1);
        fclose($probe);

        self::$log = (string) tempnam(sys_get_temp_dir(), 'capture-server-log');
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', __DIR__ . '/fixtures'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['CAPTURE_LOG' => self::$log])
        );
        if (!is_resource($server)) {
            self::markTestSkipped('Could not start PHP\'s built-in web server.');
        }
        self::$server = $server;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }
        self::fail('The built-in web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(self::$log);
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function get(string $case): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = (string) file_get_contents('http://127.0.0.1:' . self::$port . '/capture.php?case=' . $case, false, $context);
        $status = (int) explode(' ', $http_response_header[0])[1];
        $headers = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }

        return [$status, $headers, $body];
    }

    /**
     * @return array<string, array{string}>
     */
    public function endsBeforeTheResponse(): array
    {
        return [
            'exit' => ['exit'],
            'die' => ['die'],
            'fatal error' => ['fatal'],
            'E_USER_ERROR' => ['user-error'],
            'a 401 header, then exit' => ['header-then-exit'],
        ];
    }

    /**
     * @dataProvider endsBeforeTheResponse
     */
    public function testARequestThatEndsEarlyGetsTheFixedJson500AndNothingElse(string $case): void
    {
        [$status, $headers, $body] = $this->get($case);

        $this->assertSame(500, $status);
        $this->assertSame(OutputCapture::BODY, $body);
        $this->assertStringStartsWith('application/json', $headers['content-type'] ?? '');
        $this->assertArrayNotHasKey('x-frontaccounting', $headers);
    }

    /**
     * @return array<string, array{string}>
     */
    public function misbehavesButCompletes(): array
    {
        return [
            'prints only' => ['none'],
            'sets a header' => ['normal-header'],
            'leaves a handler buffer open' => ['handler'],
            'prints from a later shutdown function' => ['late'],
        ];
    }

    /**
     * @dataProvider misbehavesButCompletes
     */
    public function testACompletedRequestSendsOnlyItsResponse(string $case): void
    {
        [$status, $headers, $body] = $this->get($case);

        $this->assertSame(200, $status);
        $this->assertSame('{"data":{"ok":true}}', $body);
        $this->assertArrayNotHasKey('x-frontaccounting', $headers);
    }

    public function testWhatWasDiscardedIsLogged(): void
    {
        $this->get('fatal');

        $log = (string) file_get_contents(self::$log);
        $this->assertStringContainsString('FrontAccounting says hello', $log);
        $this->assertStringContainsString('undefined_function_for_the_capture_test', $log);
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `docker/fa-graphql test --testsuite unit --filter 'OutputCapture'`
Expected: FAIL — `Class "FA\GraphQL\Http\OutputCapture" not found` (the server tests' fixture fatals, so they fail on the status/body assertions).

- [ ] **Step 3: Implement `src/Http/OutputCapture.php`**

```php
<?php

namespace FA\GraphQL\Http;

/**
 * Keeps everything PHP and FrontAccounting print away from the client (spec §2.4).
 *
 * index.php starts it right after the autoloader and ends it just before Slim's
 * emitter sends the response: what was printed in between, and every header set
 * by then, is logged and dropped. If the request ends first — exit, die, or a
 * fatal error, all of which FrontAccounting code can reach — the shutdown function
 * drops the output instead and answers the fixed JSON 500: nothing has been sent,
 * so it still can.
 */
final class OutputCapture
{
    public const BODY = '{"errors":[{"message":"Internal server error","extensions":{"code":"INTERNAL"}}]}';

    /** How much of the discarded output is written to the log. */
    public const LOGGED_BYTES = 2048;

    /** The output-buffer level below the capture's own buffer; null when not capturing. */
    private static ?int $level = null;

    private static bool $shutdownRegistered = false;

    public static function start(): void
    {
        if (self::$level !== null) {
            return;
        }
        self::$level = ob_get_level();
        ob_start();
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'onShutdown']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Drops everything printed since start(), and every header set so far, and
     * returns the output (which is also logged). Call it immediately before the
     * response is emitted.
     */
    public static function end(): string
    {
        if (self::$level === null) {
            return '';
        }
        $captured = self::drain(self::$level);
        self::$level = null;
        self::forgetHeaders();
        if ($captured !== '') {
            error_log('graphql: discarded ' . self::describe($captured));
        }

        return $captured;
    }

    /**
     * After the response is emitted, whatever a later shutdown function prints
     * would be appended to the body. A buffer that discards it closes that gap.
     */
    public static function afterEmit(): void
    {
        ob_start(static function (string $buffer): string {
            if ($buffer !== '') {
                error_log('graphql: discarded, after the response, ' . self::describe($buffer));
            }

            return '';
        });
    }

    /**
     * Registered by start(); public only so PHP can call it. Does nothing once
     * end() has run.
     */
    public static function onShutdown(): void
    {
        if (self::$level === null) {
            return;
        }
        $captured = self::drain(self::$level);
        self::$level = null;
        $error = error_get_last();
        error_log(
            'graphql: the request ended before its response (exit, die or a fatal error)'
            . ($error !== null ? '; last error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] : '')
            . '; discarded ' . self::describe($captured)
        );
        if (!headers_sent()) {
            self::forgetHeaders();
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo self::BODY;
    }

    /**
     * Closes every buffer above $level, innermost first, and returns what they
     * held in the order it was printed. A handler buffer's callback still runs as
     * it closes (and may call header(): forgetHeaders() follows), but its return
     * value is discarded with the rest.
     */
    private static function drain(int $level): string
    {
        $parts = [];
        while (ob_get_level() > $level) {
            $parts[] = (string) ob_get_contents();
            if (!@ob_end_clean()) {
                // A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be
                // closed: empty it and stop, rather than loop forever.
                @ob_clean();
                break;
            }
        }

        return implode('', array_reverse($parts));
    }

    private static function forgetHeaders(): void
    {
        if (!headers_sent()) {
            header_remove();
        }
    }

    private static function describe(string $output): string
    {
        $excerpt = substr($output, 0, self::LOGGED_BYTES);

        return strlen($output) . ' bytes of output: ' . $excerpt
            . (strlen($output) > strlen($excerpt) ? ' [...]' : '');
    }
}
```

- [ ] **Step 4: Wire it into `index.php`**

Replace `index.php` with:

```php
<?php

/*
    The GraphQL endpoint: POST modules/graphql/ (or modules/graphql/index.php)
    with a JSON body of {"query": "...", "variables": {...}, "operationName": "..."}.

    OutputCapture starts before anything else runs: nothing PHP or FrontAccounting
    prints, and no header they set, reaches the client (spec section 2.4). Then this
    file builds the request, the configuration, the container and the Slim app, and
    runs it. Once the app runs, JsonErrorMiddleware answers every failure as JSON. A
    failure before that — a missing or unusable config_graphql.php above all — is
    answered here, and is the only response built outside Slim. Every response goes
    out through $send, which ends the capture immediately before emitting.
*/

use FA\GraphQL\Config;
use FA\GraphQL\ConfigException;
use FA\GraphQL\Http\OutputCapture;
use FA\GraphQL\RequestInfo;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\ResponseEmitter;

require __DIR__ . '/vendor/autoload.php';

OutputCapture::start();

$send = static function (ResponseInterface $response): void {
    OutputCapture::end();
    (new ResponseEmitter())->emit($response);
    OutputCapture::afterEmit();
};

$fail = static function (string $message): ResponseInterface {
    $response = new Response(500);
    $response->getBody()->write((string) json_encode(
        ['errors' => [['message' => $message, 'extensions' => ['code' => 'INTERNAL']]]],
        JSON_INVALID_UTF8_SUBSTITUTE
    ));

    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
};

try {
    $request = ServerRequestFactory::createFromGlobals();
    $config = Config::fromFile(__DIR__ . '/config_graphql.php');
    $container = (require __DIR__ . '/container.php')($config, RequestInfo::fromRequest($request, $config));
    // The module's directory, as the browser sees it: '/modules/graphql' in the stack.
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $app = (require __DIR__ . '/app.php')($container, $basePath);
} catch (ConfigException $e) {
    error_log('graphql: ' . $e->getMessage());
    $send($fail('The GraphQL module is not configured: ' . $e->getMessage()));
    return;
} catch (\Throwable $e) {
    error_log('graphql: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $send($fail('Internal server error'));
    return;
}

// What App::run() does, split so the capture ends between handling and emitting.
$send($app->handle($request));
```

- [ ] **Step 5: Run to see them pass**

Run: `docker/fa-graphql test --testsuite unit --filter 'OutputCapture'`
Expected: PASS — 6 + 10 tests (5 early-end cases, 4 completed cases, the log test).

If a server case fails, read the fixture's behaviour before changing the class: e.g. if the `fatal` case's log lacks the error text, check `error_get_last()` there; if `late` leaks, check the order shutdown functions ran in.

Run: `docker/fa-graphql test`
Expected: PASS — all suites; the http suite proves index.php still answers every existing case as before (config failure JSON 500 included, `AuthFlowTest`, `RoutingTest`).

By hand, against the running stack (port from `docker/.env`, 8100 by default):

```bash
curl -si -d '{"query":"{ apiVersion }"}' http://localhost:8100/modules/graphql/ | sed -n '1p;/^Content-Type/p;$p'
# HTTP/1.1 200 OK / Content-Type: application/json; charset=utf-8 / {"data":{"apiVersion":"0.1.0"}}
```

Run: `docker/fa-graphql lint && docker/fa-graphql analyze`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Http/OutputCapture.php index.php tests/Unit/Http/OutputCaptureTest.php \
  tests/Unit/Http/OutputCaptureServerTest.php tests/Unit/Http/fixtures/capture.php
git commit -m "Capture the whole request's output; answer exit, die and fatals with JSON

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The stack defaults to upstream; CI runs upstream and the fork

**Files:**
- Modify: `docker/Dockerfile`, `docker/docker-compose.yml`, `docker/fa-graphql`, `docker/.env.example`, `docker/README.md`, `.github/workflows/ci.yml`, `README.md`

**Interfaces:**
- Produces: `FA_REPO` defaults to `https://github.com/FrontAccountingERP/FA.git`, `FA_REF` to `master`, in all three places they are defaulted (Dockerfile `ARG`, compose `args`, the driver's `export`). CI jobs named `FA upstream / PHP 7.4`, `FA upstream / PHP 8.3`, `FA fork / PHP 7.4`, `FA fork / PHP 8.3`.

- [ ] **Step 1: Dockerfile**

Replace the block from `# The fork is required, not just a default:` to `ARG FA_REF=master-cp` with:

```dockerfile
# Upstream FrontAccounting master by default: the module runs on an unmodified
# core. The cambell-prince fork is supported too, and CI builds both:
#
#   FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp
#
# A shallow clone needs a branch or tag name; a bare commit sha will not work.
#
# Changing either needs `fa-graphql up --build`; the clone is a build step, so a
# running container keeps the tree it was built with.
ARG FA_REPO=https://github.com/FrontAccountingERP/FA.git
ARG FA_REF=master
```

- [ ] **Step 2: docker-compose.yml and the driver**

`docker/docker-compose.yml`:

```yaml
        FA_REPO: "${FA_REPO:-https://github.com/FrontAccountingERP/FA.git}"
        FA_REF: "${FA_REF:-master}"
```

`docker/fa-graphql` (the two `export` lines):

```bash
export FA_REPO="${FA_REPO:-https://github.com/FrontAccountingERP/FA.git}"
export FA_REF="${FA_REF:-master}"
```

In its help text, the paragraph under "How it fits together" that begins `FrontAccounting is not a dependency` already says "upstream master by default"; append a sentence to it:

```
  FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp
  builds the fork instead (up --build); CI builds both.
```

- [ ] **Step 3: .env.example and docker/README.md**

`docker/.env.example` — replace the FrontAccounting block's comment and commented values with:

```bash
# The application this module plugs into, cloned into the image at build time.
# Upstream master by default. To build the cambell-prince fork instead:
#
#   FA_REPO=https://github.com/cambell-prince/frontaccounting.git
#   FA_REF=master-cp
#
# A shallow clone needs a branch or a tag; a bare commit sha will not work.
# Changing either needs `docker/fa-graphql up --build`.
#FA_REPO=https://github.com/FrontAccountingERP/FA.git
#FA_REF=master
```

`docker/README.md` — the FrontAccounting row of "How it fits together" becomes:

```markdown
| FrontAccounting | cloned into the image at build time from `FA_REPO` / `FA_REF` — upstream [`FrontAccountingERP/FA`](https://github.com/FrontAccountingERP/FA) `@ master` by default. The [`cambell-prince/frontaccounting`](https://github.com/cambell-prince/frontaccounting) fork (`master-cp`) works too; CI builds both |
```

- [ ] **Step 4: CI matrix**

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  test:
    name: FA ${{ matrix.fa.name }} / PHP ${{ matrix.php }}
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.3']
        fa:
          - name: upstream
            repo: https://github.com/FrontAccountingERP/FA.git
            ref: master
          - name: fork
            repo: https://github.com/cambell-prince/frontaccounting.git
            ref: master-cp
    env:
      PHP_VERSION: ${{ matrix.php }}
      FA_REPO: ${{ matrix.fa.repo }}
      FA_REF: ${{ matrix.fa.ref }}
      COMPOSE_PROJECT_NAME: fa-graphql-ci-${{ matrix.fa.name }}-${{ matrix.php }}
    steps:
      - uses: actions/checkout@v4
      - name: Build, boot, lint, analyze, test
        run: docker/fa-graphql ci
      - name: Logs
        if: failure()
        run: |
          docker/fa-graphql logs app || true
          docker/fa-graphql logs errors || true
```

Validate: `python3 -c 'import yaml,sys; yaml.safe_load(open(".github/workflows/ci.yml"))'` prints nothing.

- [ ] **Step 5: README**

`README.md` — the "Requirements" sentence under "Calling the API" becomes:

```markdown
Requirements: FrontAccounting 2.4 — upstream `master` (tested at 2.4.20), or the
`cambell-prince/frontaccounting` fork; `config_graphql.php` copied
```

(the rest of that paragraph is unchanged).

- [ ] **Step 6: Rebuild the main stack on upstream and run everything**

```bash
docker/fa-graphql destroy --yes
docker/fa-graphql up --build
docker/fa-graphql info        # FA source: https://github.com/FrontAccountingERP/FA.git @ master
docker/fa-graphql test
docker/fa-graphql lint && docker/fa-graphql analyze
```

Expected: `info` shows upstream; `sgw_sales` is listed active; `test` is `OK (N tests, …)` with **no** skipped tests (Review Focus 1); lint and analyze clean.

If `sgw_sales` fails to install or activate, or a suite fails only here: stop and report with the output. Do not patch `sgw_sales` or FrontAccounting from this repository.

- [ ] **Step 7: The fork, in a throwaway stack**

```bash
export FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp
export COMPOSE_PROJECT_NAME=fa-graphql-alt HTTP_PORT=8107 DB_PORT=3327 PMA_PORT=8108
docker/fa-graphql ci
docker/fa-graphql destroy --yes
unset FA_REPO FA_REF COMPOSE_PROJECT_NAME HTTP_PORT DB_PORT PMA_PORT
```

Expected: `ci` green, no skipped tests.

- [ ] **Step 8: Commit**

```bash
git add docker/Dockerfile docker/docker-compose.yml docker/fa-graphql docker/.env.example docker/README.md \
  .github/workflows/ci.yml README.md
git commit -m "Build upstream FrontAccounting by default; CI runs upstream and the fork

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Checkpoint — final

- [ ] An independent reviewer (not the implementer) runs a `/code-review medium`-equivalent over `main..HEAD`: correctness and security of `OutputCapture` (every path ends in JSON; nothing printed twice; headers dropped; no infinite loop), `Bootstrap`'s choice of source, the copy's fidelity (`diff` of function bodies against `../../includes/session.inc` on upstream or `session_utils.inc` on the fork), and that no test or code still requires the fork. Fix confirmed findings; commit as `Address the Foundation 1.1 review`.
- [ ] **Spec walk** of the *(revised: upstream FA)* sections, each confirmed with the test that proves it, or the deviation written into the spec, marked *(revised)*:
  - §1 Decisions — upstream default, fork supported, CI both.
  - §2.1 — `session_utils`: fork file or the module copy; refusal only without `config_db.php` / `current_user.inc`; `errors.inc` copy stays, with its version note and drift test.
  - §2.4 — capture started before the request exists; normal path logs (2 KB, length) and never sends; exit/die/fatal answer the fixed JSON 500 with `application/json`; Bootstrap's own clean-up still there. Also record in §2.4, *(revised)*, the two behaviours this plan added that the spec does not state: headers set before `end()` are dropped, and output after the response is discarded (`afterEmit()`).
  - §6 — the new 500 row.
  - §7 — `OutputCapture.php`, `fa_session_compat.php` listed.
  - §8 — the unit and integration rows' new tests exist.
  - §9 — `FA_REPO`/`FA_REF` defaults; CI matrix.
- [ ] All four combinations green locally, no skipped tests:
  - upstream 7.4 — the main stack (Task 3 Step 6);
  - upstream 8.3 — `PHP_VERSION=8.3 COMPOSE_PROJECT_NAME=fa-graphql-83 HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql ci`, then `… destroy --yes`;
  - fork 7.4 — Task 3 Step 7;
  - fork 8.3 — `PHP_VERSION=8.3 FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp COMPOSE_PROJECT_NAME=fa-graphql-alt-83 HTTP_PORT=8112 DB_PORT=3332 PMA_PORT=8113 docker/fa-graphql ci`, then `… destroy --yes`.
- [ ] Hand off with superpowers:finishing-a-development-branch. Tell the user what Release 2 inherits (spec §1 "What Release 2 inherits from upstream compatibility"), and that `rep107.php` includes `session.inc`, which on upstream would redeclare every function in `fa_session_compat.php` — emailing an invoice needs a different route than including the report.
