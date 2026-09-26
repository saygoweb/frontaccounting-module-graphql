<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\FaSession;

/**
 * An order's recurring schedule: sgw_sales' sales_recurring row (Release 2 spec
 * section 4.5). Written with FrontAccounting's db_query(), inside the order's
 * transaction — not with sgw_sales' SalesRecurringModel, which writes on its own PDO
 * connection, outside it. The columns mean what sgw_sales' page makes them mean
 * (modules/sgw_sales sales_order_entry.php :549-576): occur is "MM-DD" for a yearly
 * schedule and the day of the month for a monthly one; dt_next is sgw_sales' own state,
 * NULL until its generation computes it (includes/service/RecurrenceSchedule.php).
 */
final class RecurringSchedule
{
    public const PACKAGE = 'sgw_sales';
    public const NOT_ACTIVE = 'Recurring orders need the sgw_sales extension, which is not active for this company.';

    private FaSession $session;

    private ?bool $upgraded = null;

    public function __construct(FaSession $session)
    {
        $this->session = $session;
    }

    public function isAvailable(): bool
    {
        return $this->session->isActive(self::PACKAGE) && $this->isUpgraded();
    }

    /**
     * Refuse a schedule sgw_sales cannot hold: not active for the company is the
     * client's problem (BAD_INPUT); active but without update_1.4.sql's table shape is
     * the installation's (FA_REJECTED) — activate_extension() applies only
     * update_1.0.sql.
     */
    public function assertWritable(string $field): void
    {
        if (!$this->session->isActive(self::PACKAGE)) {
            throw new BadInput(self::NOT_ACTIVE, $field);
        }
        if (!$this->isUpgraded()) {
            $message = 'sgw_sales\' table ' . CompanyContext::prefix() . 'sales_recurring is missing or not '
                . 'upgraded: apply modules/sgw_sales/sql/update_1.4.sql.';
            throw new FaRejected($message, [$message]);
        }
    }

    /**
     * @return array<string, mixed>|null a Recurrence, or null: none, or sgw_sales not active
     */
    public function read(int $orderNo): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $row = db_fetch(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo),
            'could not read the recurring schedule'
        ));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Set or replace an order's schedule. dt_next is kept unless the rhythm changes.
     *
     * @param array<string, mixed> $recurrence a RecurrenceInput
     */
    public function write(int $orderNo, array $recurrence): void
    {
        $this->assertWritable('recurring');
        $c = self::toColumns($recurrence);
        $existing = db_fetch(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo) . ' FOR UPDATE',
            'could not read the recurring schedule'
        ));

        if (!$existing) {
            db_query(
                'INSERT INTO ' . TB_PREF . 'sales_recurring'
                . ' (trans_no, dt_start, dt_end, dt_next, auto, every, repeats, occur)'
                . ' VALUES (' . db_escape($orderNo) . ', ' . db_escape($c['dt_start'])
                . ', ' . self::sqlDate($c['dt_end']) . ', NULL, ' . $c['auto'] . ', ' . $c['every']
                . ', ' . db_escape($c['repeats']) . ', ' . db_escape($c['occur']) . ')',
                'could not add the recurring schedule'
            );

            return;
        }

        $rhythmChanged = $existing['dt_start'] !== $c['dt_start']
            || $existing['repeats'] !== $c['repeats']
            || (int) $existing['every'] !== $c['every']
            || (string) $existing['occur'] !== $c['occur'];
        db_query(
            'UPDATE ' . TB_PREF . 'sales_recurring SET dt_start = ' . db_escape($c['dt_start'])
            . ', dt_end = ' . self::sqlDate($c['dt_end'])
            . ', auto = ' . $c['auto'] . ', every = ' . $c['every']
            . ', repeats = ' . db_escape($c['repeats']) . ', occur = ' . db_escape($c['occur'])
            . ($rhythmChanged ? ', dt_next = NULL' : '')
            . ' WHERE trans_no = ' . db_escape($orderNo),
            'could not update the recurring schedule'
        );
    }

    /**
     * With its order, whether or not sgw_sales is still active: a deactivated
     * extension's rows would otherwise stay behind and reattach to a later order that
     * reuses the number.
     */
    public function delete(int $orderNo): void
    {
        if (!$this->isUpgraded()) {
            return;
        }
        db_query(
            'DELETE FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo),
            'could not delete the recurring schedule'
        );
    }

    /**
     * End a schedule on $isoDate, unless it already ends earlier.
     */
    public function end(int $orderNo, string $isoDate): void
    {
        if (!$this->isUpgraded()) {
            return;
        }
        $date = db_escape(DateConversion::iso($isoDate));
        db_query(
            'UPDATE ' . TB_PREF . "sales_recurring SET dt_end = $date WHERE trans_no = " . db_escape($orderNo)
            . " AND (dt_end IS NULL OR dt_end > $date)",
            'could not end the recurring schedule'
        );
    }

    /**
     * A RecurrenceInput as sales_recurring columns, validated. repeats arrives as the
     * enum's value ('month' | 'year'), which is sgw_sales' own.
     *
     * @param array<string, mixed> $r
     * @return array{dt_start: string, dt_end: ?string, auto: int, every: int, repeats: string, occur: string}
     */
    public static function toColumns(array $r): array
    {
        if (!isset($r['start'])) {
            throw new BadInput('A schedule needs a start date.', 'recurring.start');
        }
        $start = DateConversion::iso($r['start'], 'recurring.start');
        $end = isset($r['end']) ? DateConversion::iso($r['end'], 'recurring.end') : null;
        if ($end !== null && $end < $start) {
            throw new BadInput('A schedule cannot end before it starts.', 'recurring.end');
        }
        $repeats = (string) ($r['repeats'] ?? '');
        if (!in_array($repeats, ['month', 'year'], true)) {
            throw new BadInput('A schedule repeats MONTH or YEAR.', 'recurring.repeats');
        }
        // every is tinyint(4) in sales_recurring.
        $every = (int) ($r['every'] ?? 0);
        if ($every < 1 || $every > 127) {
            throw new BadInput('every must be from 1 to 127.', 'recurring.every');
        }

        if ($repeats === 'month') {
            $day = $r['day'] ?? null;
            if ($day === null || (int) $day < 1 || (int) $day > 31) {
                throw new BadInput('A monthly schedule needs its day of the month, from 1 to 31.', 'recurring.day');
            }
            if (isset($r['monthDay'])) {
                throw new BadInput('monthDay is for a yearly schedule; a monthly one takes day.', 'recurring.monthDay');
            }
            $occur = (string) (int) $day;
        } else {
            $monthDay = (string) ($r['monthDay'] ?? '');
            // 2000 is a leap year: 02-29 is a valid yearly date.
            if (
                !preg_match('/^(\d{2})-(\d{2})\z/', $monthDay, $m)
                || !checkdate((int) $m[1], (int) $m[2], 2000)
            ) {
                throw new BadInput('A yearly schedule needs its date as MM-DD.', 'recurring.monthDay');
            }
            if (isset($r['day'])) {
                throw new BadInput('day is for a monthly schedule; a yearly one takes monthDay.', 'recurring.day');
            }
            $occur = $monthDay;
        }

        return [
            'dt_start' => $start,
            'dt_end' => $end,
            'auto' => ($r['auto'] ?? true) ? 1 : 0,
            'every' => $every,
            'repeats' => $repeats,
            'occur' => $occur,
        ];
    }

    /**
     * @param array<string, mixed> $row a sales_recurring row
     * @return array<string, mixed> a Recurrence
     */
    public static function fromRow(array $row): array
    {
        $monthly = $row['repeats'] === 'month';

        return [
            'start' => DateConversion::fromSql($row['dt_start']),
            'end' => DateConversion::fromSql($row['dt_end'] ?? null),
            'next' => DateConversion::fromSql($row['dt_next'] ?? null),
            'repeats' => $row['repeats'],
            'every' => (int) $row['every'],
            'day' => $monthly ? (int) $row['occur'] : null,
            'monthDay' => $monthly ? null : (string) $row['occur'],
            'auto' => (bool) $row['auto'],
        ];
    }

    private static function sqlDate(?string $date): string
    {
        return $date === null ? 'NULL' : db_escape($date);
    }

    /**
     * update_1.4.sql's shape: an AUTO_INCREMENT id and a unique trans_no (one schedule
     * per order). Asked once per request.
     */
    private function isUpgraded(): bool
    {
        if ($this->upgraded === null) {
            $table = db_escape(CompanyContext::prefix() . 'sales_recurring');
            $unique = db_fetch_row(db_query(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = $table AND COLUMN_NAME = 'trans_no' AND NON_UNIQUE = 0",
                'could not inspect sales_recurring'
            ));
            $autoId = db_fetch_row(db_query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = $table AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
                'could not inspect sales_recurring'
            ));
            $this->upgraded = (int) $unique[0] > 0 && (int) $autoId[0] > 0;
        }

        return $this->upgraded;
    }
}
