-- Users the suite logs in as. Loaded after the dataset by `docker/fa-graphql db load`;
-- idempotent. The 0_ prefix is rewritten on the way in when DB_PREFIX differs.
--
-- 91136 / 91236 are SS_GRAPHQL / SA_GRAPHQL as FrontAccounting renumbers them for
-- extension id 1: section (1 << 16) | (100 << 8), first area section | 100. The
-- docker entrypoint registers this module as extension 1. Core areas keep their
-- fixed codes (includes/access_levels.inc).

INSERT INTO `0_security_roles` (`role`, `description`, `sections`, `areas`, `inactive`)
SELECT 'GraphQL API', 'System Administrator plus GraphQL API access',
       CONCAT(`sections`, ';91136'), CONCAT(`areas`, ';91236'), 0
FROM `0_security_roles` src
WHERE src.`id` = 2
  AND NOT EXISTS (SELECT 1 FROM `0_security_roles` r WHERE r.`role` = 'GraphQL API');

-- md5('password')
INSERT INTO `0_users` (`user_id`, `password`, `real_name`, `role_id`, `email`, `language`)
SELECT 'apitest', '5f4dcc3b5aa765d61d8327deb882cf99', 'API Test', r.`id`, 'apitest@example.com', 'C'
FROM `0_security_roles` r
WHERE r.`role` = 'GraphQL API'
  AND NOT EXISTS (SELECT 1 FROM `0_users` u WHERE u.`user_id` = 'apitest');

INSERT INTO `0_users` (`user_id`, `password`, `real_name`, `role_id`, `email`, `language`)
SELECT 'noapi', '5f4dcc3b5aa765d61d8327deb882cf99', 'No API', 2, 'noapi@example.com', 'C'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_users` u WHERE u.`user_id` = 'noapi');

-- SA_SALESORDER: every lookup lists with it (Release 2 spec section 4.2), and orders
-- are written with it. Section SS_SALES = 12 << 8 = 3072, area SS_SALES | 3 = 3075
-- (includes/access_levels.inc). Role 2 holds them in the demo dataset; appended only
-- when missing, so the role holds them whatever role 2 looks like.
UPDATE `0_security_roles`
SET `sections` = CONCAT(`sections`, ';3072')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('3072', REPLACE(`sections`, ';', ',')) = 0;

UPDATE `0_security_roles`
SET `areas` = CONCAT(`areas`, ';3075')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('3075', REPLACE(`areas`, ';', ',')) = 0;

-- SA_CUSTOMER: customers, branches and contacts are written with it (Release 2 spec
-- section 4.3). Area SS_SALES | 2 = 3074; appended only when missing, as above.
UPDATE `0_security_roles`
SET `areas` = CONCAT(`areas`, ';3074')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('3074', REPLACE(`areas`, ';', ',')) = 0;

-- A role that takes orders and nothing else: GraphQL access, the sales-order areas,
-- no SA_CUSTOMER and no setup areas. It proves the lookups need only SA_SALESORDER
-- (Release 2 spec §4.2). Sections must be listed too: FrontAccounting ignores an
-- area whose section the role lacks. 3072 = SS_SALES (12 << 8), 3073 = SA_SALESTRANSVIEW,
-- 3075 = SA_SALESORDER; 91136 / 91236 = SS_GRAPHQL / SA_GRAPHQL for extension 1.
INSERT INTO `0_security_roles` (`role`, `description`, `sections`, `areas`, `inactive`)
SELECT 'GraphQL Orders', 'GraphQL API access, sales orders only', '3072;91136', '3073;3075;91236', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_security_roles` r WHERE r.`role` = 'GraphQL Orders');

-- md5('password')
INSERT INTO `0_users` (`user_id`, `password`, `real_name`, `role_id`, `email`, `language`)
SELECT 'apiorders', '5f4dcc3b5aa765d61d8327deb882cf99', 'API Orders', r.`id`, 'apiorders@example.com', 'C'
FROM `0_security_roles` r
WHERE r.`role` = 'GraphQL Orders'
  AND NOT EXISTS (SELECT 1 FROM `0_users` u WHERE u.`user_id` = 'apiorders');
