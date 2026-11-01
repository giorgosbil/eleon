-- Running the app as a team, for years:
-- mailboxes.is_shared / shared_address: a mailbox the team works from together
--   (info@, sales@). shared_address: a Microsoft 365 shared mailbox reached
--   through the connecting person's own sign-in ("Full Access" in Exchange).
-- mailbox_members: who sees a shared mailbox (nobody listed = everyone).
-- email_threads.claimed_by_user_id: who is handling a conversation.
-- users.away_*: holiday cover — while away, their cases and alerts go to the cover too.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_mailboxes' AND COLUMN_NAME='is_shared') > 0, 'DO 1',
  'ALTER TABLE `ecw_mailboxes` ADD COLUMN `is_shared` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `shared_address` VARCHAR(320) DEFAULT NULL COMMENT ''Microsoft shared mailbox reached through the connecting user''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

CREATE TABLE IF NOT EXISTS `ecw_mailbox_members` (
  `mailbox_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mailbox_id`, `user_id`),
  KEY `idx_ecw_mailbox_members_user` (`user_id`),
  CONSTRAINT `fk_ecw_mailbox_members_mb` FOREIGN KEY (`mailbox_id`) REFERENCES `ecw_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_threads' AND COLUMN_NAME='claimed_by_user_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_threads` ADD COLUMN `claimed_by_user_id` BIGINT UNSIGNED DEFAULT NULL, ADD COLUMN `claimed_at` DATETIME DEFAULT NULL, ADD KEY `idx_ecw_threads_claim` (`mailbox_id`, `claimed_by_user_id`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_users' AND COLUMN_NAME='away_until') > 0, 'DO 1',
  'ALTER TABLE `ecw_users` ADD COLUMN `away_from` DATE DEFAULT NULL, ADD COLUMN `away_until` DATE DEFAULT NULL, ADD COLUMN `cover_user_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
