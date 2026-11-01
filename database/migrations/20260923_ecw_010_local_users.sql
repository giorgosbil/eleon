-- Invitations are email-bound bearer links; raw tokens are never stored.
CREATE TABLE IF NOT EXISTS `ecw_user_invitations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `email` VARCHAR(320) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `created_by_user_id` BIGINT UNSIGNED NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY (`token_hash`),
  KEY `idx_ecw_invitation_org` (`organization_id`,`email`),
  FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by_user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_cases' AND COLUMN_NAME='handler_user_id') > 0, 'DO 1', 'ALTER TABLE `ecw_cases` ADD COLUMN `handler_user_id` BIGINT UNSIGNED DEFAULT NULL, ADD KEY `idx_ecw_case_handler` (`handler_user_id`), ADD CONSTRAINT `fk_ecw_case_handler` FOREIGN KEY (`handler_user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
