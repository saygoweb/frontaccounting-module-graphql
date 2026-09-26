<?php

namespace FA\GraphQL\Tests\Support;

/**
 * Cleans up the sales orders a test wrote through FrontAccounting — and only those.
 *
 * FrontAccounting numbers orders and deliveries max+1 (get_next_trans_no()), so a
 * test's order can take the number of one deleted earlier, whose audit_trail and
 * refs rows are still there. Deleting "every audit row for order N" would take that
 * earlier order's rows with it. So a test marks the tables when it starts, and the
 * purge removes only what came after: rows whose auto-increment id is above the
 * mark, deliveries and comments that did not exist, and refs keys that did not
 * exist — a refs key that did (FrontAccounting REPLACEs it) gets its old reference
 * back.
 *
 * The tables are those add_sales_order(), delete_sales_order(),
 * write_sales_delivery() and sgw_sales write (sales/includes/db/sales_order_db.inc,
 * sales_delivery_db.inc).
 */
final class FaOrderRows
{
    /** Auto-increment column of each table the purge may delete from. */
    private const MARKED = [
        'audit_trail' => 'id',
        'debtor_trans_details' => 'id',
        'stock_moves' => 'trans_id',
        'gl_trans' => 'counter',
        'trans_tax_details' => 'id',
        'sales_order_details' => 'id',
        'cust_allocations' => 'id',
        'sales_recurring' => 'id',
    ];

    private \PDO $pdo;

    private string $tb;

    /** @var array<string, int> table => its highest id at the mark */
    private array $marks = [];

    /** @var array<string, string> "type/id" => reference, for orders' and deliveries' refs */
    private array $refs = [];

    /** @var array<string, true> "type/id" of orders' and deliveries' comments */
    private array $comments = [];

    /** @var array<int, true> the deliveries (debtor_trans type 13) that existed */
    private array $deliveries = [];

    private bool $hasRecurring;

    private function __construct(\PDO $pdo, string $tb)
    {
        $this->pdo = $pdo;
        $this->tb = $tb;
        $found = $pdo->prepare('SHOW TABLES LIKE ?');
        $found->execute([$tb . 'sales_recurring']);
        $this->hasRecurring = $found->fetch() !== false;
    }

    /**
     * Take the mark: call before the test writes anything.
     */
    public static function mark(\PDO $pdo, string $tb = '0_'): self
    {
        $rows = new self($pdo, $tb);
        foreach (self::MARKED as $table => $column) {
            if ($table === 'sales_recurring' && !$rows->hasRecurring) {
                continue;
            }
            $rows->marks[$table] = (int) $pdo->query("SELECT COALESCE(MAX($column), 0) FROM {$tb}$table")
                ->fetchColumn();
        }
        foreach ($pdo->query("SELECT type, id, reference FROM {$tb}refs WHERE type IN (13, 30)") as $row) {
            $rows->refs[$row['type'] . '/' . $row['id']] = (string) $row['reference'];
        }
        foreach ($pdo->query("SELECT DISTINCT type, id FROM {$tb}comments WHERE type IN (13, 30)") as $row) {
            $rows->comments[$row['type'] . '/' . $row['id']] = true;
        }
        foreach ($pdo->query("SELECT trans_no FROM {$tb}debtor_trans WHERE type = 13") as $row) {
            $rows->deliveries[(int) $row['trans_no']] = true;
        }

        return $rows;
    }

    /**
     * Remove an order the test created — deleted, closed or still open — with its
     * lines, schedule and deliveries, and the audit, refs and comments rows the test
     * caused for them.
     */
    public function purge(int $orderNo): void
    {
        $tb = $this->tb;
        $deliveries = $this->pdo->prepare("SELECT trans_no FROM {$tb}debtor_trans WHERE type = 13 AND order_ = ?");
        $deliveries->execute([$orderNo]);
        foreach ($deliveries->fetchAll(\PDO::FETCH_COLUMN) as $dn) {
            $dn = (int) $dn;
            if (isset($this->deliveries[$dn])) {
                continue;
            }
            $this->delete('debtor_trans_details', 'debtor_trans_type = 13 AND debtor_trans_no = ?', $dn);
            $this->delete('stock_moves', 'type = 13 AND trans_no = ?', $dn);
            $this->delete('gl_trans', 'type = 13 AND type_no = ?', $dn);
            $this->delete('trans_tax_details', 'trans_type = 13 AND trans_no = ?', $dn);
            $this->delete('audit_trail', 'type = 13 AND trans_no = ?', $dn);
            $this->restoreRef(13, $dn);
            $this->deleteComments(13, $dn);
            $this->pdo->prepare("DELETE FROM {$tb}debtor_trans WHERE type = 13 AND trans_no = ?")->execute([$dn]);
        }
        $this->delete('sales_order_details', 'trans_type = 30 AND order_no = ?', $orderNo);
        // The test created this order: no order with its number existed at the mark.
        $this->pdo->prepare("DELETE FROM {$tb}sales_orders WHERE trans_type = 30 AND order_no = ?")
            ->execute([$orderNo]);
        $this->delete('audit_trail', 'type = 30 AND trans_no = ?', $orderNo);
        $this->restoreRef(30, $orderNo);
        $this->deleteComments(30, $orderNo);
        $this->delete('cust_allocations', 'trans_type_to = 30 AND trans_no_to = ?', $orderNo);
        if ($this->hasRecurring) {
            $this->delete('sales_recurring', 'trans_no = ?', $orderNo);
        }
    }

    /**
     * Delete the rows matching $where that came after the mark.
     */
    private function delete(string $table, string $where, int $key): void
    {
        $column = self::MARKED[$table];
        $this->pdo->prepare("DELETE FROM {$this->tb}$table WHERE $where AND $column > ?")
            ->execute([$key, $this->marks[$table]]);
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

    private function deleteComments(int $type, int $id): void
    {
        if (!isset($this->comments["$type/$id"])) {
            $this->pdo->prepare("DELETE FROM {$this->tb}comments WHERE type = ? AND id = ?")->execute([$type, $id]);
        }
    }
}
