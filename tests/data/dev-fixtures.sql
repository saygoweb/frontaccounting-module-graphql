-- Dev-stack hosting-billing fixtures: realistic items and prices for developers and
-- the panel app to build against. Loaded by `docker/fa-graphql db fixtures`, never by
-- the test suites (tests/data/seed.sql is untouched and stays the only dataset they
-- load). Idempotent, and the 0_ prefix is rewritten on the way in when DB_PREFIX
-- differs, exactly as seed.sql is (docker/fa-graphql's prefix_filter).
--
-- The item unit and two items mirror what FrontAccounting's own add_item()
-- (inventory/includes/db/items_db.inc) would write for a service item: one
-- stock_master row, one loc_stock row per location, one item_codes row (the
-- item's own code, add_item_code() semantics: quantity 1, not foreign).

-- 'yr': FrontAccounting ships 'each' and 'hr' only. Hosting and domain items are
-- billed per year.
INSERT INTO `0_item_units` (`abbr`, `name`, `decimals`, `inactive`)
SELECT 'yr', 'Year', 0, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_item_units` WHERE `abbr` = 'yr');

-- Category 4 ("Services" in the demo data) supplies the accounts add_item() would
-- default to: sales 4010, cogs 5010, inventory 1510, adjustment 5040, wip 1530
-- (0_stock_category row for category_id = 4). Both items are demo-real prices: USD
-- 29.00 for a domain, USD 80.00 for hosting.
INSERT INTO `0_stock_master` (
    `stock_id`, `category_id`, `tax_type_id`, `description`, `long_description`,
    `units`, `mb_flag`, `sales_account`, `cogs_account`, `inventory_account`,
    `adjustment_account`, `wip_account`, `no_sale`, `no_purchase`, `editable`,
    `depreciation_method`, `depreciation_rate`, `depreciation_factor`
)
SELECT 'HDOM', 4, 1, 'Domain Registration', 'Domain Registration', 'yr', 'D',
       '4010', '5010', '1510', '5040', '1530', 0, 0, 1, 'D', 100, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_stock_master` WHERE `stock_id` = 'HDOM');

INSERT INTO `0_stock_master` (
    `stock_id`, `category_id`, `tax_type_id`, `description`, `long_description`,
    `units`, `mb_flag`, `sales_account`, `cogs_account`, `inventory_account`,
    `adjustment_account`, `wip_account`, `no_sale`, `no_purchase`, `editable`,
    `depreciation_method`, `depreciation_rate`, `depreciation_factor`
)
SELECT 'HGEN1', 4, 1, 'Hosting', 'Hosting', 'yr', 'D',
       '4010', '5010', '1510', '5040', '1530', 0, 0, 1, 'D', 100, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_stock_master` WHERE `stock_id` = 'HGEN1');

-- One loc_stock row per location, as add_item()'s
-- "INSERT INTO loc_stock SELECT loc_code, ? FROM locations" does.
INSERT INTO `0_loc_stock` (`loc_code`, `stock_id`)
SELECT l.`loc_code`, 'HDOM'
FROM `0_locations` l
WHERE EXISTS (SELECT 1 FROM `0_stock_master` WHERE `stock_id` = 'HDOM')
  AND NOT EXISTS (SELECT 1 FROM `0_loc_stock` ls WHERE ls.`stock_id` = 'HDOM' AND ls.`loc_code` = l.`loc_code`);

INSERT INTO `0_loc_stock` (`loc_code`, `stock_id`)
SELECT l.`loc_code`, 'HGEN1'
FROM `0_locations` l
WHERE EXISTS (SELECT 1 FROM `0_stock_master` WHERE `stock_id` = 'HGEN1')
  AND NOT EXISTS (SELECT 1 FROM `0_loc_stock` ls WHERE ls.`stock_id` = 'HGEN1' AND ls.`loc_code` = l.`loc_code`);

-- add_item_code($stock_id, $stock_id, $description, $category_id, 1, 0): the item's
-- own code, quantity 1, not foreign.
INSERT INTO `0_item_codes` (`item_code`, `stock_id`, `description`, `category_id`, `quantity`, `is_foreign`)
SELECT 'HDOM', 'HDOM', 'Domain Registration', 4, 1, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_item_codes` WHERE `stock_id` = 'HDOM' AND `item_code` = 'HDOM');

INSERT INTO `0_item_codes` (`item_code`, `stock_id`, `description`, `category_id`, `quantity`, `is_foreign`)
SELECT 'HGEN1', 'HGEN1', 'Hosting', 4, 1, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_item_codes` WHERE `stock_id` = 'HGEN1' AND `item_code` = 'HGEN1');

-- Home-currency (USD) prices on both demo sales types. No EUR rows: FrontAccounting
-- converts the home-currency price at the exchange rate for a EUR customer's order
-- date (sales/includes/sales_db.inc get_price()) — verified on the stack for
-- customer 2 (EUR): see docker/fixtures.php's report and docker/README.md.
INSERT INTO `0_prices` (`stock_id`, `sales_type_id`, `curr_abrev`, `price`)
SELECT 'HDOM', 1, 'USD', 29.00
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_prices` WHERE `stock_id` = 'HDOM' AND `sales_type_id` = 1 AND `curr_abrev` = 'USD');

INSERT INTO `0_prices` (`stock_id`, `sales_type_id`, `curr_abrev`, `price`)
SELECT 'HDOM', 2, 'USD', 29.00
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_prices` WHERE `stock_id` = 'HDOM' AND `sales_type_id` = 2 AND `curr_abrev` = 'USD');

INSERT INTO `0_prices` (`stock_id`, `sales_type_id`, `curr_abrev`, `price`)
SELECT 'HGEN1', 1, 'USD', 80.00
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_prices` WHERE `stock_id` = 'HGEN1' AND `sales_type_id` = 1 AND `curr_abrev` = 'USD');

INSERT INTO `0_prices` (`stock_id`, `sales_type_id`, `curr_abrev`, `price`)
SELECT 'HGEN1', 2, 'USD', 80.00
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_prices` WHERE `stock_id` = 'HGEN1' AND `sales_type_id` = 2 AND `curr_abrev` = 'USD');
