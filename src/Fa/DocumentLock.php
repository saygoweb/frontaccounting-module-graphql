<?php

namespace FA\GraphQL\Fa;

use FA\GraphQL\Error\FaRejected;

/**
 * Release 3 spec §2.1. FrontAccounting numbers a document MAX(trans_no)+1 per type
 * without a lock (admin/db/transactions_db.inc get_next_trans_no()), so two writers
 * can take the same number. Every document write takes this named lock first.
 *
 * It wraps the whole FaTransaction (DocumentLock::run(fn () => ServiceCall::each(...))):
 * GET_LOCK is not transactional, and a lock released before COMMIT would let the
 * next writer read the old maximum. Taken before BEGIN, the writer's InnoDB snapshot
 * is taken after it.
 */
final class DocumentLock
{
    public const BUSY = 'FrontAccounting is busy; try again.';

    public static function name(): string
    {
        return 'fa_graphql_docs_' . CompanyContext::company();
    }

    /**
     * @return mixed what $work returns
     */
    public static function run(callable $work, int $timeoutSeconds = 10)
    {
        $name = db_escape(self::name());
        $got = db_fetch_row(db_query("SELECT GET_LOCK($name, " . (int) $timeoutSeconds . ')', 'could not lock'));
        if (!$got || (string) $got[0] !== '1') {
            throw new FaRejected(self::BUSY, [self::BUSY]);
        }
        try {
            return $work();
        } finally {
            self::release($name);
        }
    }

    /**
     * A failing RELEASE_LOCK (the connection broke) must not hide the work's own error,
     * nor fail a write that committed: the lock goes with the connection either way
     * (Release 3 Checkpoint B M-5). Logged, as FaTransaction::cancel() swallows a
     * failing ROLLBACK.
     */
    private static function release(string $name): void
    {
        try {
            db_query("SELECT RELEASE_LOCK($name)", 'could not unlock');
        } catch (\Throwable $e) {
            error_log('graphql: could not release the document lock: ' . $e->getMessage());
        }
    }
}
