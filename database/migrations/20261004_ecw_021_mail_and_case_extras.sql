-- Undo send / send later, drafts in Outlook, meeting invitations, blocked
-- senders, case tags / due dates / merge / auto-assign, phone push.

-- Drafts: scheduled sending (also used for the 10-second Undo window) and the
-- matching draft in the mailbox's own Drafts folder.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_drafts' AND COLUMN_NAME='send_at') > 0, 'DO 1', 'ALTER TABLE `ecw_drafts` ADD COLUMN `send_at` DATETIME DEFAULT NULL COMMENT ''UTC. Set = queued to send at that time'', ADD COLUMN `send_state` VARCHAR(16) DEFAULT NULL COMMENT ''scheduled, sending, failed'', ADD COLUMN `send_error` VARCHAR(500) DEFAULT NULL, ADD COLUMN `provider_draft_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT ''the same draft in Outlook / Gmail'', ADD COLUMN `provider_push_pending` TINYINT(1) NOT NULL DEFAULT 0, ADD KEY `idx_ecw_drafts_send` (`send_state`, `send_at`), ADD KEY `idx_ecw_drafts_push` (`mailbox_id`, `provider_push_pending`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- Meeting invitations: Graph's message subtype (eventMessageRequest, ...).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_messages' AND COLUMN_NAME='meeting_kind') > 0, 'DO 1', 'ALTER TABLE `ecw_email_messages` ADD COLUMN `meeting_kind` VARCHAR(40) DEFAULT NULL COMMENT ''eventMessageRequest, eventMessageResponse, ... NULL = an ordinary email''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- Blocked senders: new mail from them is moved to Junk while the app runs.
CREATE TABLE IF NOT EXISTS `ecw_blocked_senders` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`         BIGINT UNSIGNED NOT NULL,
  `pattern`            VARCHAR(320) NOT NULL COMMENT 'an address, or @domain',
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_blocked_senders` (`mailbox_id`, `pattern`),
  CONSTRAINT `fk_ecw_blocked_senders_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `ecw_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cases: due date, and where a merged case went.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_cases' AND COLUMN_NAME='due_at') > 0, 'DO 1', 'ALTER TABLE `ecw_cases` ADD COLUMN `due_at` DATE DEFAULT NULL, ADD COLUMN `due_notified_at` DATETIME DEFAULT NULL, ADD COLUMN `merged_into_case_id` BIGINT UNSIGNED DEFAULT NULL, ADD KEY `idx_ecw_cases_due` (`organization_id`, `due_at`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

CREATE TABLE IF NOT EXISTS `ecw_case_tags` (
  `case_id` BIGINT UNSIGNED NOT NULL,
  `tag`     VARCHAR(40) NOT NULL,
  PRIMARY KEY (`case_id`, `tag`),
  KEY `idx_ecw_case_tags_tag` (`tag`),
  CONSTRAINT `fk_ecw_case_tags_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups: hand new cases from routing rules to the member with the fewest open cases.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_case_groups' AND COLUMN_NAME='auto_assign') > 0, 'DO 1', 'ALTER TABLE `ecw_case_groups` ADD COLUMN `auto_assign` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- Phone / browser push (Web Push).
CREATE TABLE IF NOT EXISTS `ecw_push_subscriptions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `endpoint`      TEXT NOT NULL,
  `endpoint_hash` CHAR(64) NOT NULL,
  `p256dh`        VARCHAR(200) NOT NULL,
  `auth`          VARCHAR(100) NOT NULL,
  `user_agent`    VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_push_endpoint` (`endpoint_hash`),
  KEY `idx_ecw_push_user` (`user_id`),
  CONSTRAINT `fk_ecw_push_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_mailboxes' AND COLUMN_NAME='push_watermark_id') > 0, 'DO 1', 'ALTER TABLE `ecw_mailboxes` ADD COLUMN `push_watermark_id` BIGINT UNSIGNED DEFAULT NULL COMMENT ''newest message already announced by push''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- Small app-wide values (the Web Push key pair), so backups carry them.
CREATE TABLE IF NOT EXISTS `ecw_app_settings` (
  `name`       VARCHAR(64) NOT NULL,
  `value`      TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
