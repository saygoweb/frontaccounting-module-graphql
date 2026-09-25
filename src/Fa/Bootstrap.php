<?php

namespace FA\GraphQL\Fa;

use FA\GraphQL\ConfigException;

/**
 * Loads FrontAccounting into this process without includes/session.inc, which would
 * enforce $page_security, render the login page, start a cookie session, and wrap
 * output in output_html() — the wrapper that turns a database error into a 200 with
 * an HTML body.
 *
 * Deviations from the plan this class was written against, found while making the
 * integration suite pass for real (see task-6-report.md "Deviations" for the full
 * account):
 *
 *  - includes/errors.inc is not included. Its fa_trigger_error() calls FA's own
 *    error_handler() *directly* for E_USER_ERROR — a plain function call, not
 *    trigger_error() — specifically to sidestep set_error_handler(); the comment
 *    left in that file ("E_USER_ERROR is deprecated as trigger_error arg since php
 *    8.4") says why. That bypass means installErrorHandler() below can never see
 *    display_error()'s or display_db_error()'s E_USER_ERROR, no matter what go_debug
 *    is set to — confirmed empirically, not just by reading. So this class supplies
 *    its own errors.inc-equivalent (installFaCompat()) instead: fa_trigger_error()
 *    now routes straight to FaMessages for every level, and display_db_error() —
 *    called both from check_db_error() and directly by many admin/db/*.inc files —
 *    throws FaErrorException instead of logging and having check_db_error() fall
 *    through to end_page(); exit(), which would otherwise kill the PHP process
 *    (fatal to a PHPUnit child process, and to a real request).
 *  - includes/app_entries.inc is not included. It only feeds $_SESSION['App'],
 *    which nothing in this task needs (Task 7). It does not echo at include time,
 *    so including it would have been harmless, not wrong — left out on scope alone.
 *  - includes/references.inc is not in the include list: includes/main.inc already
 *    include_once's it, so listing it again would have been a no-op.
 *  - $GLOBALS['SysPrefs']->go_debug is left at whatever config.php sets (0 in the
 *    docker stack). It turned out not to matter: check_db_error()'s exit path was
 *    never go_debug-gated in the first place (see installFaCompat()).
 */
final class Bootstrap
{
    /**
     * In FrontAccounting's own order (includes/session.inc), minus errors.inc
     * (replaced by installFaCompat()) and app_entries.inc (not needed yet — see the
     * class docblock). session_utils.inc exists only in the cambell-prince fork;
     * upstream keeps those functions in session.inc.
     */
    private const INCLUDES = [
        'includes/session_utils.inc',
        'includes/current_user.inc',
        'frontaccounting.php',
        'admin/db/security_db.inc',
        'includes/lang/language.inc',
        'includes/lang/gettext.inc',
        'config_db.php',
        'config.php',
        'includes/ajax.inc',
        'includes/ui/ui_msgs.inc',
        'includes/prefs/sysprefs.inc',
        'includes/hooks.inc',
        'installed_extensions.php',
        'lang/installed_languages.inc',
    ];

    private const INCLUDES_AFTER_PREFS = [
        'includes/access_levels.inc',
        'version.php',
        'includes/main.inc',
        'includes/date_functions.inc',
        'includes/data_checks.inc',
    ];

    private static bool $booted = false;

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    public static function assertRoot(string $root): void
    {
        if (!is_file($root . '/config_db.php') || !is_file($root . '/includes/current_user.inc')) {
            // No path in the message: JsonErrorMiddleware shows it to the client.
            throw new ConfigException(
                'fa_root does not name a FrontAccounting install (set it in config_graphql.php).'
            );
        }
        if (!is_file($root . '/includes/session_utils.inc')) {
            throw new ConfigException(
                'This FrontAccounting has no includes/session_utils.inc. The GraphQL module needs the '
                . 'cambell-prince/frontaccounting fork, which separates those functions from session.inc.'
            );
        }
    }

    public static function boot(string $root): void
    {
        if (self::$booted) {
            return;
        }
        self::assertRoot($root);
        self::isolateFromTheRequest();

        // Nothing FrontAccounting does while loading may print: a legacy config.php
        // switches display_errors on, so it is forced off here and again after the
        // includes, and the handler is in place before the first include.
        ini_set('display_errors', '0');
        self::installFaCompat();
        self::installErrorHandler();

        // mysqli's default error mode changed in PHP 8.1: it now throws
        // mysqli_sql_exception on a query error instead of returning false, which
        // means a failed query would never reach check_db_error() at all — found by
        // running the integration suite on 8.3, where a bad query surfaced as an
        // uncaught mysqli_sql_exception rather than FaErrorException. FrontAccounting
        // itself (still MYSQLI_REPORT_OFF-shaped: it polls mysqli_errno() after every
        // call) is unaffected on 7.4, where MYSQLI_REPORT_OFF was already the default.
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $GLOBALS['path_to_root'] = $root;
        $_SESSION = [];
        if (!defined('VARLIB_PATH')) {
            define('VARLIB_PATH', $root . '/tmp');
        }
        if (!defined('VARLOG_PATH')) {
            define('VARLOG_PATH', $root . '/tmp');
        }
        // FrontAccounting resolves some paths relative to the working directory.
        chdir($root);

        // Whatever FrontAccounting prints or buffers while it loads is discarded,
        // and no output handler it installs outlives boot(): Slim's emitter writes
        // the only body.
        $obLevel = ob_get_level();
        ob_start();
        try {
            self::load($root);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            ini_set('display_errors', '0');
        }

        self::$booted = true;
    }

    /**
     * The API reads its request through PSR-7, built in index.php before the app
     * runs. FrontAccounting reads the superglobals at include time: `path_to_root`
     * in $_GET/$_POST makes frontaccounting.php, config.php and language.inc
     * die("Restricted access"), and `JsHttpRequest=` in QUERY_STRING makes
     * `new Ajax()` install an output handler that rewrites the response as
     * JavaScript and switches display_errors on. So FrontAccounting never sees the
     * HTTP request: nothing in it can change what boot() emits.
     */
    private static function isolateFromTheRequest(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER['QUERY_STRING'] = '';
        unset($GLOBALS['HTTP_RAW_POST_DATA'], $GLOBALS['JsHttpRequest_Active']);
    }

    private static function load(string $root): void
    {
        foreach (self::INCLUDES as $file) {
            self::includeGlobal($root . '/' . $file);
            if ($file === 'config.php') {
                ini_set('display_errors', '0');
            }
        }
        foreach ($GLOBALS['installed_extensions'] as $extension) {
            $hooks = $root . '/' . $extension['path'] . '/hooks.php';
            if (is_file($hooks)) {
                self::includeGlobal($hooks);
            }
        }

        $_SESSION['SysPrefs'] = new \sys_prefs();
        $GLOBALS['SysPrefs'] = &$_SESSION['SysPrefs'];
        // As includes/session.inc does: FrontAccounting's log, which is where
        // spec section 6 sends INTERNAL errors and database errors.
        if ((string) $GLOBALS['SysPrefs']->error_logfile !== '') {
            ini_set('error_log', $GLOBALS['SysPrefs']->error_logfile);
        }

        get_text_init();
        $language = array_search_value($GLOBALS['dflt_lang'], $GLOBALS['installed_languages'], 'code');
        $_SESSION['language'] = new \language(
            $language['name'],
            $language['code'],
            $language['encoding'],
            isset($language['rtl']) && $language['rtl'] === true ? 'rtl' : 'ltr'
        );
        $_SESSION['language']->set_language($_SESSION['language']->code);

        foreach (self::INCLUDES_AFTER_PREFS as $file) {
            self::includeGlobal($root . '/' . $file);
        }

        $GLOBALS['Ajax'] = new \Ajax();
        $GLOBALS['Validate'] = [];
        $GLOBALS['Editors'] = [];
        $GLOBALS['Pagehelp'] = [];
        $GLOBALS['Refs'] = new \references();

        // As includes/session.inc does for an anonymous request: FrontAccounting's
        // own functions (db_query()'s prefix lookup, user_company(), ...) read
        // $_SESSION['wa_current_user'] unconditionally and assume it is a
        // current_user, not just isset(). Without this, PHP auto-vivifies a bare
        // stdClass on first property write (a "Creating default object from empty
        // value" warning — found by running the integration suite) instead of
        // failing predictably. Not logged in: CompanyContext, not FrontAccounting's
        // session, is this module's source of truth for which company a request is
        // for.
        if (!isset($_SESSION['wa_current_user'])) {
            $_SESSION['wa_current_user'] = new \current_user();
        }
    }

    /**
     * Include $__file as though from file scope.
     *
     * FrontAccounting's files assign their settings as plain variables and its
     * functions read them with `global`. Included from a method, those variables
     * would be this method's locals. So: bring every existing global in by reference
     * first, so assignments to them land; and publish whatever is new afterwards.
     *
     * Verified directly (not just read about) on PHP 7.4 under PHPUnit, from inside
     * a static method: a plain-named assignment to an existing global lands in
     * $GLOBALS, and a brand new variable the included file introduces is published
     * to $GLOBALS afterwards. See task-6-report.md for the PHP 8.3 result.
     */
    private static function includeGlobal(string $__file): void
    {
        foreach (array_keys($GLOBALS) as $__name) {
            $isSimpleName = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $__name);
            if ($__name !== 'GLOBALS' && $__name !== 'this' && $isSimpleName) {
                global $$__name;
            }
        }
        unset($__name, $isSimpleName);

        include_once $__file;

        foreach (get_defined_vars() as $__name => $__value) {
            $isNewGlobal = $__name !== '__file' && $__name !== '__name' && $__name !== '__value'
                && !array_key_exists($__name, $GLOBALS);
            if ($isNewGlobal) {
                $GLOBALS[$__name] = $__value;
            }
        }
    }

    /**
     * Loads fa_errors_compat.php, this class's stand-in for includes/errors.inc —
     * see that file for why errors.inc itself is never included, and what replaces
     * it. Kept as a separate, unnamespaced file rather than functions nested in
     * this method: a function declared inside a method of a namespaced class is
     * itself declared under that namespace (FA\GraphQL\Fa\fa_trigger_error), and
     * FrontAccounting's own files call these unqualified, so they would not have
     * found it — found by running the integration suite, not by inspection.
     */
    private static function installFaCompat(): void
    {
        require_once __DIR__ . '/fa_errors_compat.php';

        // errors.inc's own file-scope initialisation, kept here rather than in
        // fa_errors_compat.php: a plain PHP file that only declares functions is
        // the standard, side-effect-free polyfill shape; a method is allowed
        // side effects by construction.
        $GLOBALS['messages'] = [];
        $GLOBALS['before_box'] = '';
    }

    /**
     * Before FrontAccounting's includes (so nothing they raise can print), in
     * place of the handler errors.inc would install, which renders messages into
     * the page. Only reached
     * for genuine PHP-level errors and any trigger_error() call that does not go
     * through fa_trigger_error() — display_error() and friends are handled by
     * installFaCompat() instead (see its docblock).
     */
    private static function installErrorHandler(): void
    {
        set_error_handler(function (int $level, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $level)) {
                return true; // silenced with @
            }
            if ($level === E_USER_ERROR || $level === E_RECOVERABLE_ERROR) {
                throw new FaErrorException(trim(html_entity_decode(strip_tags($message), ENT_QUOTES)));
            }
            if ($level === E_USER_WARNING || $level === E_USER_NOTICE) {
                FaMessages::add($level, $message);
                return true;
            }
            // FrontAccounting is not clean under E_ALL, least of all on PHP 8. Its
            // notices and deprecations are logged; printing them would corrupt JSON.
            error_log("FrontAccounting: $message in $file:$line");

            return true;
        });
    }
}
