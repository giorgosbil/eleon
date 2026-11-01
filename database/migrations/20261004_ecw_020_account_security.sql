-- Two-factor sign-in (authenticator app codes) and password reset links.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_users' AND COLUMN_NAME='totp_secret_encrypted') > 0, 'DO 1', 'ALTER TABLE `ecw_users` ADD COLUMN `totp_secret_encrypted` TEXT DEFAULT NULL COMMENT ''authenticator secret, encrypted. NULL = two-factor off'', ADD COLUMN `totp_enabled_at` DATETIME DEFAULT NULL, ADD COLUMN `totp_last_step` BIGINT DEFAULT NULL COMMENT ''last accepted 30-second step: a code works once'', ADD COLUMN `totp_recovery_json` TEXT DEFAULT NULL COMMENT ''hashes of unused recovery codes''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

CREATE TABLE IF NOT EXISTS `ecw_password_resets` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `token_hash`         CHAR(64) NOT NULL COMMENT 'sha256 of the link token, the token itself is never stored',
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'admin who made the link, NULL = requested by the user',
  `expires_at`         DATETIME NOT NULL,
  `used_at`            DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_password_resets_token` (`token_hash`),
  KEY `idx_ecw_password_resets_user` (`user_id`),
  CONSTRAINT `fk_ecw_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
