<?php

namespace FA\GraphQL\Fa;

/**
 * One FrontAccounting transaction around a unit of work (Release 2 spec section 3.1).
 *
 * FrontAccounting's transactions nest by counting (includes/db/sql_functions.inc):
 * begin_transaction() issues BEGIN only at level 0, commit_transaction() issues
 * COMMIT only when the count returns to 0. Its own functions (Cart::write(),
 * add_crm_person()) call them inside ours, so they only count. On any throwable this
 * calls cancel_transaction(), which rolls back and — unlike a bare ROLLBACK — sets the
 * level back to 0, so a later write in the same request still issues BEGIN.
 */
final class FaTransaction
{
    /**
     * @return mixed what $work returns
     */
    public static function run(callable $work)
    {
        begin_transaction();
        try {
            $result = $work();
        } catch (\Throwable $e) {
            self::cancel();
            throw $e;
        }
        commit_transaction();

        return $result;
    }

    /**
     * A failing ROLLBACK must not hide the error that caused it: the connection is
     * broken either way, and the original error is the one worth reporting.
     */
    private static function cancel(): void
    {
        try {
            cancel_transaction();
        } catch (\Throwable $ignored) {
            $GLOBALS['transaction_level'] = 0;
        }
    }
}
