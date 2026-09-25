CREATE TABLE IF NOT EXISTS `0_graphql_refresh_token` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` smallint(6) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `issued_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `replaced_by` int(11) unsigned DEFAULT NULL,
  `client` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB;
