<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DateConversion;

/**
 * Bank reconciliation as FrontAccounting's Reconcile Bank Account screen does it
 * (gl/bank_account_reconcile.php; Bank reconciliation spec §3.2, §3.3): its sums are
 * get_max_reconciled()'s, each tick or untick is update_reconciled_values(), and the
 * account keeps the statement's date and closing balance as its last reconciliation.
 */
final class BankReconciliationService
{
    /**
     * @return array{bankAccountId: int, statementDate: string, beginningBalance: float,
     *     reconciledAmount: float, endingBalance: float, accountTotal: float, lastReconciledDate: ?string,
     *     storedEndingBalance: ?float, difference: ?float, statementDates: string[]}
     */
    public function summary(int $bankAccountId, string $statementDate): array
    {
        FaIncludes::banking();
        $account = self::account($bankAccountId);
        $sums = db_fetch(get_max_reconciled(DateConversion::toFa($statementDate), $bankAccountId));
        $beginning = self::money($sums['beg_balance']);
        $ending = self::money($sums['end_balance']);
        $last = DateConversion::fromSql($account['last_reconciled_date']);
        $stored = $last === $statementDate ? self::money($account['ending_reconcile_balance']) : null;

        return [
            'bankAccountId' => $bankAccountId,
            'statementDate' => $statementDate,
            'beginningBalance' => $beginning,
            'reconciledAmount' => self::money($ending - $beginning),
            'endingBalance' => $ending,
            'accountTotal' => self::money($sums['total']),
            'lastReconciledDate' => $last,
            'storedEndingBalance' => $stored,
            'difference' => $stored === null ? null : self::money($stored - $ending),
            'statementDates' => self::statementDates($bankAccountId),
        ];
    }

    /**
     * Ticks `reconcile` to the statement date and unticks `unreconcile`, all or none;
     * records the statement's date and closing balance on the account. Run it inside
     * ServiceCall::run().
     *
     * @param array<string, mixed> $input BankReconcileInput
     */
    public function reconcile(array $input): void
    {
        FaIncludes::banking();
        $accountId = IntKey::parse($input['bankAccountId'], 'bankAccountId');
        self::account($accountId);
        $date = DateConversion::iso($input['statementDate'], 'statementDate');
        $tick = self::ids($input['reconcile'] ?? [], 'reconcile');
        $untick = self::ids($input['unreconcile'] ?? [], 'unreconcile');
        $rows = self::rows(array_merge($tick, $untick));

        $problems = [];
        foreach (array_intersect($tick, $untick) as $id) {
            $problems[] = ['reconcile', "$id is in both reconcile and unreconcile"];
        }
        foreach (['reconcile' => $tick, 'unreconcile' => $untick] as $field => $ids) {
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                if ($row === null || (int) $row['bank_act'] !== $accountId) {
                    $problems[] = [$field, "$id is not an entry of bank account $accountId"];
                    continue;
                }
                // FrontAccounting voids an entry by zeroing it and leaves it reconciled, so a
                // voided entry may still be unticked from its statement (spec §3.3).
                if ($field === 'reconcile' && (float) $row['amount'] == 0.0) {
                    $problems[] = [$field, "$id is voided"];
                    continue;
                }
                $on = $rows[$id]['reconciled'];
                if ($field === 'reconcile' && (string) $row['trans_date'] > $date) {
                    $problems[] = [$field, "$id is dated {$row['trans_date']}, after the statement"];
                } elseif ($on !== null && $on !== $date) {
                    $problems[] = [$field, $field === 'reconcile'
                        ? "$id is reconciled to $on; unreconcile it there first"
                        : "$id is reconciled to $on, not $date"];
                }
            }
        }
        if ($problems !== []) {
            throw new BadInput(
                'Nothing was reconciled: ' . implode('; ', array_column($problems, 1)) . '.',
                $problems[0][0]
            );
        }

        $faDate = DateConversion::toFa($date);
        $balance = sprintf('%.2F', (float) $input['endingBalance']);
        foreach ($tick as $id) {
            if ($rows[$id]['reconciled'] === null) {
                update_reconciled_values($id, db_escape($date), $faDate, $balance, $accountId);
            }
        }
        foreach ($untick as $id) {
            if ($rows[$id]['reconciled'] !== null) {
                update_reconciled_values($id, 'NULL', $faDate, $balance, $accountId);
            }
        }
        // The screen records the statement with each tick; with nothing to tick it is
        // recorded all the same (spec §3.3), with the same SQL.
        db_query(
            'UPDATE ' . TB_PREF . 'bank_accounts SET last_reconciled_date = ' . db_escape($date)
            . ", ending_reconcile_balance = $balance WHERE id = " . db_escape($accountId),
            'could not record the reconciliation'
        );
    }

    /** @return array<string, mixed> the bank_accounts row */
    private static function account(int $id): array
    {
        $row = db_fetch(db_query(
            'SELECT id, last_reconciled_date, ending_reconcile_balance FROM ' . TB_PREF . 'bank_accounts WHERE id = '
            . db_escape($id),
            'could not read the bank account'
        ));
        if (!$row) {
            throw new NotFound("There is no bank account $id.");
        }

        return $row;
    }

    /**
     * @param mixed $values
     * @return int[] distinct, in the order given
     */
    private static function ids($values, string $field): array
    {
        $ids = [];
        foreach ((array) $values as $value) {
            $ids[] = IntKey::parse($value, $field);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $ids
     * Locked FOR UPDATE (reconcile() runs inside FaTransaction's transaction), so no
     * other request changes them between the checks and the writes.
     *
     * @return array<int, array<string, mixed>> bank_trans rows by id, `reconciled` as
     *     DateConversion::fromSql() reads it (null for NULL and the zero date)
     */
    private static function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $result = db_query(
            'SELECT id, bank_act, amount, trans_date, reconciled FROM ' . TB_PREF . 'bank_trans WHERE id IN ('
            . implode(', ', array_map('intval', $ids)) . ') FOR UPDATE',
            'could not read the bank entries'
        );
        $rows = [];
        while ($row = db_fetch($result)) {
            // MySQL's zero date is old data's "not reconciled", as NULL is.
            $row['reconciled'] = DateConversion::fromSql($row['reconciled']);
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /** @return string[] the account's statement dates, newest first */
    private static function statementDates(int $accountId): array
    {
        $result = db_query(
            'SELECT DISTINCT reconciled FROM ' . TB_PREF . 'bank_trans WHERE bank_act = ' . db_escape($accountId)
            . " AND reconciled IS NOT NULL AND reconciled <> '0000-00-00' ORDER BY reconciled DESC",
            'could not read the statement dates'
        );
        $dates = [];
        while ($row = db_fetch_row($result)) {
            $dates[] = (string) $row[0];
        }

        return $dates;
    }

    /** @param mixed $value */
    private static function money($value): float
    {
        $money = round((float) $value, 2);

        return $money == 0.0 ? 0.0 : $money;
    }
}
