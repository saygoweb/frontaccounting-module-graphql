<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\FaRejected;

/**
 * FrontAccounting's void (admin/void_transaction.php, admin/db/voiding_db.inc:17),
 * without the page. Runs inside its caller's ServiceCall and DocumentLock.
 */
class Voider
{
    public function void(int $type, int $transNo, string $memo): void
    {
        FaIncludes::billing();
        // check_valid_entries() (admin/void_transaction.php:264-269)
        if (is_closed_trans($type, $transNo)) {
            $message = 'This transaction was closed for edition and cannot be voided.';
            throw new FaRejected($message, [$message]);
        }
        BillingChecks::assertOpenToday();
        // voiding_db.inc:17-133: an error string on refusal, false once voided.
        $refused = void_transaction($type, $transNo, \Today(), $memo);
        if ($refused) {
            throw new FaRejected((string) $refused, [(string) $refused]);
        }
    }

    public function isVoided(int $type, int $transNo): bool
    {
        FaIncludes::billing();

        return get_voided_entry($type, $transNo) != null;
    }
}
