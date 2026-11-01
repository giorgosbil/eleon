-- Two-factor sign-in: "Trust this computer for 30 days". The browser keeps a
-- random token in a cookie, only its sha256 is stored here. Turning two-factor
-- off or on again, a new password, or "Forget trusted computers" removes them.
CREATE TABLE IF NOT EXISTS `ecw_trusted_devices` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64) NOT NULL,
  `label`        VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'browser and system, from the user agent',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_trusted_devices_token` (`token_hash`),
  KEY `idx_ecw_trusted_devices_user` (`user_id`),
  CONSTRAINT `fk_ecw_trusted_devices_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
