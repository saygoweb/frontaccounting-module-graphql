<?php

namespace FA\GraphQL\Tests\Support;

/**
 * Cleans up the deliveries, invoices and payments a test wrote through FrontAccounting
 * — and only those (Release 3 spec §8). The FaOrderRows rule: FrontAccounting numbers
 * documents MAX+1 per type, so a test's document can reuse a number an earlier,
 * voided or deleted one had, whose audit, refs and voided rows are still there. So
 * a test marks the tables when it starts, and purge() removes only rows above the
 * mark, documents that did not exist, and refs, comments and voided keys that did
 * not exist (a refs key that did gets its old reference back).
 *
 * The tables are those write_sales_delivery(), write_sales_invoice(),
 * write_customer_payment(), the allocation cart and void_transaction() write
 * (sales/includes/db/sales_delivery_db.inc, sales_invoice_db.inc, payment_db.inc,
 * custalloc_db.inc, admin/db/voiding_db.inc).
 */
final class FaBillingRows
{
    private const TYPES = [10, 12, 13];

    /** Auto-increment column of each table purged by id. */
    private const MARKED = [
        'debtor_trans_details' => 'id',
        'gl_trans' => 'counter',
        'stock_moves' => 'trans_id',
        'trans_tax_details' => 'id',
        'bank_trans' => 'id',
        'cust_allocations' => 'id',
        'audit_trail' => 'id',
    ];

    private \PDO $pdo;

    private string $tb;

    /** @var array<string, int> */
    private array $marks = [];

    /** @var array<string, true> "type/trans_no" of documents that existed */
    private array $documents = [];

    /** @var array<string, string> "type/id" => reference */
    private array $refs = [];

    /** @var array<string, true> */
    private array $comments = [];

    /** @var array<string, true> */
    private array $voided = [];

    private function __construct(\PDO $pdo, string $tb)
    {
        $this->pdo = $pdo;
        $this->tb = $tb;
    }

    public static function mark(\PDO $pdo, string $tb = '0_'): self
    {
        $rows = new self($pdo, $tb);
        $types = implode(',', self::TYPES);
        foreach (self::MARKED as $table => $column) {
            $rows->marks[$table] = (int) $pdo->query("SELECT COALESCE(MAX($column), 0) FROM {$tb}$table")
                ->fetchColumn();
        }
        foreach ($pdo->query("SELECT type, trans_no FROM {$tb}debtor_trans WHERE type IN ($types)") as $row) {
            $rows->documents[$row['type'] . '/' . $row['trans_no']] = true;
        }
        foreach ($pdo->query("SELECT type, id, reference FROM {$tb}refs WHERE type IN ($types)") as $row) {
            $rows->refs[$row['type'] . '/' . $row['id']] = (string) $row['reference'];
        }
        foreach ($pdo->query("SELECT DISTINCT type, id FROM {$tb}comments WHERE type IN ($types)") as $row) {
            $rows->comments[$row['type'] . '/' . $row['id']] = true;
        }
        foreach ($pdo->query("SELECT type, id FROM {$tb}voided WHERE type IN ($types)") as $row) {
            $rows->voided[$row['type'] . '/' . $row['id']] = true;
        }

        return $rows;
    }

    /**
     * Remove every billing row written since the mark.
     */
    public function purge(): void
    {
        $tb = $this->tb;
        $types = implode(',', self::TYPES);
        foreach (self::MARKED as $table => $column) {
            $this->pdo->prepare("DELETE FROM {$tb}$table WHERE $column > ?")->execute([$this->marks[$table]]);
        }
        foreach (
            $this->pdo->query("SELECT type, trans_no FROM {$tb}debtor_trans WHERE type IN ($types)")
            ->fetchAll(\PDO::FETCH_ASSOC) as $row
        ) {
            $key = $row['type'] . '/' . $row['trans_no'];
            if (isset($this->documents[$key])) {
                continue;
            }
            $this->pdo->prepare("DELETE FROM {$tb}debtor_trans WHERE type = ? AND trans_no = ?")
                ->execute([$row['type'], $row['trans_no']]);
            $this->restoreRef((int) $row['type'], (int) $row['trans_no']);
            if (!isset($this->comments[$key])) {
                $this->pdo->prepare("DELETE FROM {$tb}comments WHERE type = ? AND id = ?")
                    ->execute([$row['type'], $row['trans_no']]);
            }
        }
        foreach (
            $this->pdo->query("SELECT type, id FROM {$tb}voided WHERE type IN ($types)")
            ->fetchAll(\PDO::FETCH_ASSOC) as $row
        ) {
            if (!isset($this->voided[$row['type'] . '/' . $row['id']])) {
                $this->pdo->prepare("DELETE FROM {$tb}voided WHERE type = ? AND id = ?")
                    ->execute([$row['type'], $row['id']]);
            }
        }
    }

    /**
     * Remove one document this test created, and what hangs off it — only rows above
     * the mark (a document number FrontAccounting reused keeps an earlier run's rows).
     * A document that existed at the mark is left alone.
     */
    public function purgeTrans(int $type, int $transNo): void
    {
        $key = "$type/$transNo";
        if (isset($this->documents[$key])) {
            return;
        }
        $tb = $this->tb;
        $byTable = [
            'debtor_trans_details' => ['debtor_trans_type = ? AND debtor_trans_no = ?', [$type, $transNo]],
            'gl_trans' => ['type = ? AND type_no = ?', [$type, $transNo]],
            'stock_moves' => ['type = ? AND trans_no = ?', [$type, $transNo]],
            'trans_tax_details' => ['trans_type = ? AND trans_no = ?', [$type, $transNo]],
            'bank_trans' => ['type = ? AND trans_no = ?', [$type, $transNo]],
            'audit_trail' => ['type = ? AND trans_no = ?', [$type, $transNo]],
        ];
        foreach ($byTable as $table => [$where, $params]) {
            $column = self::MARKED[$table];
            $this->pdo->prepare("DELETE FROM {$tb}$table WHERE $where AND $column > ?")
                ->execute(array_merge($params, [$this->marks[$table]]));
        }
        $this->pdo->prepare(
            "DELETE FROM {$tb}cust_allocations WHERE ((trans_type_from = ? AND trans_no_from = ?)"
            . ' OR (trans_type_to = ? AND trans_no_to = ?)) AND id > ?'
        )->execute([$type, $transNo, $type, $transNo, $this->marks['cust_allocations']]);
        $this->pdo->prepare("DELETE FROM {$tb}debtor_trans WHERE type = ? AND trans_no = ?")
            ->execute([$type, $transNo]);
        $this->restoreRef($type, $transNo);
        if (!isset($this->comments[$key])) {
            $this->pdo->prepare("DELETE FROM {$tb}comments WHERE type = ? AND id = ?")->execute([$type, $transNo]);
        }
        if (!isset($this->voided[$key])) {
            $this->pdo->prepare("DELETE FROM {$tb}voided WHERE type = ? AND id = ?")->execute([$type, $transNo]);
        }
    }

    private function restoreRef(int $type, int $id): void
    {
        $key = "$type/$id";
        if (isset($this->refs[$key])) {
            $this->pdo->prepare("REPLACE INTO {$this->tb}refs (id, type, reference) VALUES (?, ?, ?)")
                ->execute([$id, $type, $this->refs[$key]]);
        } else {
            $this->pdo->prepare("DELETE FROM {$this->tb}refs WHERE type = ? AND id = ?")->execute([$type, $id]);
        }
    }
}
