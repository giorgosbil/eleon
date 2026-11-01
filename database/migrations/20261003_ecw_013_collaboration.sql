-- Collaboration features: case notes + @mentions, notifications, follow-ups
-- (reminders, snooze), reply templates, mail rules (personal colours and
-- organization routing), "who is replying" presence, Teams chats linked to
-- cases, and outgoing webhooks.

CREATE TABLE IF NOT EXISTS `ecw_case_notes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `body`       TEXT NOT NULL COMMENT 'plain text, mentions stored as @[Name](user:ID), rendered escaped',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_case_notes_case` (`case_id`, `created_at`),
  CONSTRAINT `fk_ecw_case_notes_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_notes_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_notifications` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL COMMENT 'recipient',
  `actor_user_id`   BIGINT UNSIGNED DEFAULT NULL,
  `type`            VARCHAR(32) NOT NULL COMMENT 'mention, reminder, reopened, assigned',
  `case_id`         BIGINT UNSIGNED DEFAULT NULL,
  `thread_id`       BIGINT UNSIGNED DEFAULT NULL,
  `text`            VARCHAR(500) NOT NULL,
  `link`            VARCHAR(500) DEFAULT NULL COMMENT 'path relative to the web root',
  `read_at`         DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_notifications_user` (`user_id`, `read_at`, `created_at`),
  CONSTRAINT `fk_ecw_notifications_org`   FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_notifications_user`  FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_notifications_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ecw_notifications_case`  FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_followups` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `case_id`         BIGINT UNSIGNED DEFAULT NULL,
  `thread_id`       BIGINT UNSIGNED DEFAULT NULL,
  `due_at`          DATETIME NOT NULL,
  `note`            VARCHAR(255) DEFAULT NULL,
  `if_no_reply`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cancel silently if the customer answers first',
  `created_at`      DATETIME NOT NULL,
  `done_at`         DATETIME DEFAULT NULL,
  `outcome`         VARCHAR(16) DEFAULT NULL COMMENT 'notified, replied, cancelled',
  PRIMARY KEY (`id`),
  KEY `idx_ecw_followups_due` (`done_at`, `due_at`),
  KEY `idx_ecw_followups_case` (`case_id`),
  KEY `idx_ecw_followups_thread` (`thread_id`),
  CONSTRAINT `fk_ecw_followups_org`    FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_followups_user`   FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_followups_case`   FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_followups_thread` FOREIGN KEY (`thread_id`) REFERENCES `ecw_email_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_templates` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = shared with the organization',
  `name`            VARCHAR(120) NOT NULL,
  `subject`         VARCHAR(255) DEFAULT NULL,
  `body_html`       MEDIUMTEXT NOT NULL COMMENT 'sanitized on save',
  `created_by`      BIGINT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL,
  `updated_at`      DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_templates_org` (`organization_id`, `user_id`),
  CONSTRAINT `fk_ecw_templates_org`  FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_templates_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_templates_by`   FOREIGN KEY (`created_by`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_rules` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT 'set for personal colour rules, NULL for organization routing rules',
  `kind`            VARCHAR(16) NOT NULL COMMENT 'color or route',
  `name`            VARCHAR(120) NOT NULL,
  `conditions_json` JSON NOT NULL,
  `actions_json`    JSON NOT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`      BIGINT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_rules_org` (`organization_id`, `kind`, `enabled`),
  CONSTRAINT `fk_ecw_rules_org`  FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_rules_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_rules_by`   FOREIGN KEY (`created_by`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_presence` (
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `thread_id`  BIGINT UNSIGNED NOT NULL,
  `activity`   VARCHAR(16) NOT NULL DEFAULT 'replying',
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `thread_id`),
  KEY `idx_ecw_presence_thread` (`thread_id`, `updated_at`),
  CONSTRAINT `fk_ecw_presence_user`   FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_presence_thread` FOREIGN KEY (`thread_id`) REFERENCES `ecw_email_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_teams_chats` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`           BIGINT UNSIGNED NOT NULL,
  `chat_id`           VARCHAR(255) NOT NULL,
  `title`             VARCHAR(255) NOT NULL,
  `web_url`           VARCHAR(1000) DEFAULT NULL,
  `linked_by_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'whose Teams token keeps the copy fresh',
  `linked_at`         DATETIME NOT NULL,
  `synced_at`         DATETIME DEFAULT NULL,
  `sync_error`        VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_case_teams_chat` (`case_id`, `chat_id`),
  CONSTRAINT `fk_ecw_ctc_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_ctc_user` FOREIGN KEY (`linked_by_user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_teams_messages` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id`             BIGINT UNSIGNED NOT NULL,
  `provider_message_id` VARCHAR(64) NOT NULL,
  `from_name`           VARCHAR(255) DEFAULT NULL,
  `from_user_id`        VARCHAR(64) DEFAULT NULL,
  `body_html`           MEDIUMTEXT COMMENT 'sanitized when stored',
  `created_at`          DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_ctm_message` (`link_id`, `provider_message_id`),
  KEY `idx_ecw_ctm_time` (`link_id`, `created_at`),
  CONSTRAINT `fk_ecw_ctm_link` FOREIGN KEY (`link_id`) REFERENCES `ecw_case_teams_chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_threads' AND COLUMN_NAME='snoozed_until') > 0, 'DO 1', 'ALTER TABLE `ecw_email_threads` ADD COLUMN `snoozed_until` DATETIME DEFAULT NULL, ADD KEY `idx_ecw_threads_snoozed` (`mailbox_id`, `snoozed_until`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='webhook_url_encrypted') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `webhook_url_encrypted` TEXT DEFAULT NULL, ADD COLUMN `webhook_secret_encrypted` TEXT DEFAULT NULL, ADD COLUMN `webhook_events_json` JSON DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
