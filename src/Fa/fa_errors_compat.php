<?php

/**
 * Stand-in for FrontAccounting's includes/errors.inc, loaded by Bootstrap::boot()
 * instead of the real file. Deliberately global-namespace (no `namespace`
 * declaration): FrontAccounting's own files call these unqualified, so a
 * declaration under FA\GraphQL\Fa (as a method of Bootstrap would produce) is
 * invisible to them — see Bootstrap's class docblock for how this was found.
 *
 * FrontAccounting's own errors.inc prints an error <div> into the page, and for
 * E_USER_ERROR either logs it (go_debug off) or hands it to error_handler() — but
 * always by calling that function directly by name, bypassing whatever is
 * registered via set_error_handler(). There is no page here, and no way to
 * intercept that direct call, so this replaces the functions other FrontAccounting
 * files call rather than trying to catch what they produce:
 *
 *  - fa_trigger_error() (display_error()/display_warning()/display_notification()
 *    all funnel through it) always collects into FaMessages, regardless of level.
 *    A validation message is a validation message whether FrontAccounting calls it
 *    an "error" or a "notice" — the distinction that matters here is the one below,
 *    not PHP's error level.
 *  - display_db_error() — called both by check_db_error() and directly by many
 *    admin/db/*.inc files when a query fails outright — throws FaErrorException.
 *    A failed query is not something a GraphQL request can recover from.
 *  - check_db_error() ends the request, as FrontAccounting's does, by throwing
 *    FaErrorException whenever $exit_if_error is set. A duplicate key is still
 *    "friendly" (its message is collected into FaMessages for FA_REJECTED), but it
 *    still ends the write. The error text is read and logged (to FrontAccounting's
 *    tmp/errors.log, which Bootstrap points error_log at) before the rollback, so
 *    a thrown FaErrorException never leaves a transaction open and never loses
 *    its cause.
 */

use FA\GraphQL\Fa\FaErrorException;
use FA\GraphQL\Fa\FaMessages;

// The standard function_exists() guard, so a second Bootstrap::boot() call (or a
// double require_once from elsewhere) does not try to redeclare these. Nothing
// but declarations sits at this file's top level — the $GLOBALS initialisation
// errors.inc itself does lives in Bootstrap::installFaCompat() instead — so this
// file only ever does the one thing PSR1 wants a file with symbols to do.
if (!function_exists('fa_trigger_error')) {
    function fa_trigger_error($msg, $error_level = E_USER_NOTICE): void
    {
        FaMessages::add($error_level, (string) $msg);
    }

    /**
     * FrontAccounting's "DATABASE ERROR" text for the error mysqli holds now, as
     * errors.inc builds it: the caller's message, the error code and message, and
     * the SQL when $SysPrefs->debug is set. The SQL goes to the log only.
     *
     * @return array{0: string, 1: string} the client-safe text, and the text to log
     */
    function fa_graphql_db_error_text($msg, $sql_statement): array
    {
        global $db, $SysPrefs;

        $text = $msg !== null && $msg !== '' ? (string) $msg : 'Database error';
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES));
        $errno = function_exists('db_error_no') ? db_error_no() : 0;
        if ($errno !== 0) {
            $text .= ': error code ' . $errno . ': ' . db_error_msg($db);
        }
        $logged = 'DATABASE ERROR: ' . $text;
        if (isset($SysPrefs) && $SysPrefs->debug == 1 && $sql_statement !== null && $sql_statement !== '') {
            $logged .= ' | sql that failed was: ' . $sql_statement;
        }

        return [$text, $logged];
    }

    function display_db_error($msg, $sql_statement = null, $exit = true): void
    {
        list($text, $logged) = fa_graphql_db_error_text($msg, $sql_statement);
        error_log($logged);
        throw new FaErrorException($text);
    }

    function frindly_db_error($db_error): bool
    {
        if (defined('DB_DUPLICATE_ERROR') && $db_error == DB_DUPLICATE_ERROR) {
            FaMessages::add(
                E_USER_WARNING,
                'The entered information is a duplicate. Please go back and enter different values.'
            );

            return true;
        }

        return false;
    }

    /**
     * FrontAccounting's check_db_error() ends the request (end_page(); exit)
     * whenever $exit_if_error is set, a friendly duplicate-key error included. Here
     * "ends the request" is a thrown FaErrorException: returning instead would let
     * the caller carry on after its transaction was rolled back, and every later
     * statement would autocommit. Only $exit_if_error = false returns, and only for
     * a friendly error; any other failed query throws, which is stricter than
     * FrontAccounting and deliberate.
     *
     * The error text is read and logged before the rollback: the rollback succeeds
     * and resets mysqli's error number.
     */
    function check_db_error($msg, $sql_statement, $exit_if_error = true, $rollback_if_error = true): int
    {
        $db_error = db_error_no();
        if ($db_error != 0) {
            list($text, $logged) = fa_graphql_db_error_text($msg, $sql_statement);
            error_log($logged);
            $friendly = frindly_db_error($db_error);
            if ($rollback_if_error) {
                db_query('rollback');
                $GLOBALS['transaction_level'] = 0;
            }
            if ($exit_if_error || !$friendly) {
                throw new FaErrorException($text);
            }
        }

        return $db_error;
    }

    function get_backtrace($html = false, $skip = 0): string
    {
        return '';
    }

    function exception_handler($exception): void
    {
        throw $exception;
    }

    function fmt_errors($center = false): string
    {
        return '';
    }

    function error_box(): void
    {
    }

    function end_flush(): void
    {
    }
}
