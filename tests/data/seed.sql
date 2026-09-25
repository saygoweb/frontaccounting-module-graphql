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

-- SA_SALESTYPES for the SalesType pilot (Task 11): section SS_SALES_C = 11 << 8 =
-- 2816, area SS_SALES_C | 1 = 2817. Role 2 may already hold them; appended only
-- when missing, so the role holds them whatever role 2 looks like.
UPDATE `0_security_roles`
SET `sections` = CONCAT(`sections`, ';2816')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('2816', REPLACE(`sections`, ';', ',')) = 0;

UPDATE `0_security_roles`
SET `areas` = CONCAT(`areas`, ';2817')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('2817', REPLACE(`areas`, ';', ',')) = 0;
