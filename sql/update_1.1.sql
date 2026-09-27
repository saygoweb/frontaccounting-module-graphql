CREATE TABLE IF NOT EXISTS `0_graphql_machine_token` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `jti` char(32) NOT NULL,
  `login` varchar(60) NOT NULL,
  `label` varchar(255) NOT NULL DEFAULT '',
  `issued_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `jti` (`jti`),
  KEY `login` (`login`)
) ENGINE=InnoDB;
