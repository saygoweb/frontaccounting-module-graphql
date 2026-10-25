<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Config;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * FaTestRows::sweepItems() removes what it names and leaves the demo company alone.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FaTestRowsTest extends FaTestCase
{
    public function testSweepItemsRemovesTheItemsListsAndPricesOfAPrefix(): void
    {
        (new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef'])))->openCompany(0);
        $pdo = FaTestRows::connect();
        $prefix = FaTestRows::prefix();
        $count = function (string $table) use ($pdo): int {
            return (int) $pdo->query("SELECT COUNT(*) FROM 0_$table")->fetchColumn();
        };
        $before = [
            'stock_master' => $count('stock_master'), 'sales_types' => $count('sales_types'),
            'prices' => $count('prices'), 'loc_stock' => $count('loc_stock'), 'item_codes' => $count('item_codes'),
        ];

        try {
            $pdo->exec("INSERT INTO 0_sales_types (sales_type, tax_included, factor) VALUES ('{$prefix}L', 0, 1)");
            $list = (int) $pdo->lastInsertId();
            $pdo->exec(
                "INSERT INTO 0_stock_master (stock_id, category_id, tax_type_id, description, long_description, "
                . "units, mb_flag) VALUES ('{$prefix}I', 1, 1, 'Swept', '', 'each', 'B')"
            );
            $pdo->exec("INSERT INTO 0_loc_stock (loc_code, stock_id) VALUES ('DEF', '{$prefix}I')");
            $pdo->exec(
                "INSERT INTO 0_item_codes (item_code, stock_id, description, category_id, quantity, is_foreign, "
                . "inactive) VALUES ('{$prefix}I', '{$prefix}I', 'Swept', 1, 1, 0, 0)"
            );
            // A price of the swept item on Retail, and a price of a demo item on the swept list.
            $insert = 'INSERT INTO 0_prices (stock_id, sales_type_id, curr_abrev, price) VALUES';
            $pdo->exec("$insert ('{$prefix}I', 1, 'USD', 5)");
            $pdo->exec("$insert ('101', $list, 'USD', 5)");
        } finally {
            FaTestRows::sweepItems($pdo, $prefix);
        }

        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $count($table), $table);
        }
    }
}
