-- Step 03 — inbox, search and composer.
--
-- Three new tables, one full-text index, one preferences column. Nothing from
-- steps 01/02 is redefined.
--
--
-- WHY A PARTICIPANT TABLE
-- ----------------------------------------------------------------------
-- Step 01 stored recipients as JSON (to_json / cc_json / bcc_json) and this
-- file's own step notes flagged the consequence: `to:` and `cc:` search had no
-- index path and meant a full scan of every message body in the mailbox.
--
-- MySQL 8.0.17+ can index a JSON array directly (a multi-valued index over
-- `$[*].email`), and that was the cheaper-looking fix. It was not taken:
--   - a multi-valued index covers ONE column, so to/cc/bcc/from need four of
--     them, and none of them can answer "was this address involved at all",
--     which is what both `participant:` search and the product spec's
--     "search cases by participant email" actually ask;
--   - multi-valued indexes cannot be used in a JOIN the optimizer can reorder,
--     and every one of these queries is already joining messages to threads;
--   - a row per participant also carries the display name, so recipient
--     autocomplete reads one indexed table instead of unpacking JSON.
--
-- The JSON columns stay as the record of what the provider sent. This table is
-- a derived index over them, written by ingest, and rebuildable at any time
-- from the JSON — which is exactly what the backfill at the bottom does.
--
--
-- WHY FULL TEXT ON (subject, body_text) AND NOT quoted_text
-- ----------------------------------------------------------------------
-- Every reply in a thread quotes the whole thread, so indexing quoted_text
-- makes one phrase match every message that ever repeated it, and a search for
-- "RTune" returns the entire conversation instead of the message that said it.
-- Keeping quoted history out of the index is what makes the result list useful.
--
-- body_html is excluded too: markup tokens pollute a full-text index (a search
-- for "style" or "div" matches everything).
--
--
-- WHY DRAFTS ARE THEIR OWN TABLE
-- ----------------------------------------------------------------------
-- A composer draft has no provider_message_id until it is sent, and
-- ecw_email_messages.provider_message_id is NOT NULL because it is the sync
-- upsert key. Relaxing that to hold local-only rows would put a nullable hole
-- in the one column the whole sync pipeline identifies messages by. Drafts
-- therefore live beside the mailbox, not inside it, and become ordinary
-- messages only when the provider gives them an id.


CREATE TABLE IF NOT EXISTS `ecw_message_participants` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_message_id` BIGINT UNSIGNED NOT NULL,
  `role`             VARCHAR(8) NOT NULL COMMENT 'from, to, cc, bcc',
  `email`            VARCHAR(320) NOT NULL COMMENT 'normalized: trimmed, lowercased',
  `name`             VARCHAR(255) DEFAULT NULL COMMENT 'display name as seen on this message',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_participants_row` (`email_message_id`, `role`, `email`),
  KEY `idx_ecw_participants_email_role` (`email`, `role`) COMMENT 'from: / to: / cc: search',
  KEY `idx_ecw_participants_email` (`email`) COMMENT 'was this address involved at all',
  CONSTRAINT `fk_ecw_participants_message` FOREIGN KEY (`email_message_id`)
      REFERENCES `ecw_email_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Derived index over the message JSON recipient columns. Rebuildable, never authoritative';


CREATE TABLE IF NOT EXISTS `ecw_drafts` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`        BIGINT UNSIGNED NOT NULL,
  `mailbox_id`             BIGINT UNSIGNED NOT NULL,
  `user_id`                BIGINT UNSIGNED DEFAULT NULL,
  `mode`                   VARCHAR(16) NOT NULL DEFAULT 'new' COMMENT 'new, reply, reply_all, forward',
  `in_reply_to_message_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'local message id this answers',
  `subject`                VARCHAR(512) DEFAULT NULL,
  `body_html`              LONGTEXT DEFAULT NULL COMMENT 'sanitized before it is stored',
  `to_json`                JSON DEFAULT NULL,
  `cc_json`                JSON DEFAULT NULL,
  `bcc_json`               JSON DEFAULT NULL,
  `attachments_json`       JSON DEFAULT NULL COMMENT 'uploaded files awaiting send',
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_drafts_mine` (`mailbox_id`, `user_id`, `updated_at`),
  KEY `idx_ecw_drafts_reply_to` (`in_reply_to_message_id`),
  CONSTRAINT `fk_ecw_drafts_org`     FOREIGN KEY (`organization_id`)        REFERENCES `ecw_organizations` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_drafts_mailbox` FOREIGN KEY (`mailbox_id`)            REFERENCES `ecw_mailboxes` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_drafts_user`    FOREIGN KEY (`user_id`)               REFERENCES `ecw_users` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_ecw_drafts_replyto` FOREIGN KEY (`in_reply_to_message_id`) REFERENCES `ecw_email_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_signatures` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `mailbox_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = this user default, across mailboxes',
  `display_name`    VARCHAR(255) DEFAULT NULL,
  `title`           VARCHAR(255) DEFAULT NULL,
  `company`         VARCHAR(255) DEFAULT NULL,
  `phone`           VARCHAR(64)  DEFAULT NULL,
  `signature_html`  LONGTEXT DEFAULT NULL COMMENT 'sanitized on save. Never rendered raw',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_signatures_scope` (`user_id`, (IFNULL(`mailbox_id`, 0)))
    COMMENT 'functional key part, for the same reason as ecw_mailbox_sync_state: a stored generated column would block ON DELETE CASCADE on mailbox_id',
  KEY `idx_ecw_signatures_mailbox` (`mailbox_id`),
  CONSTRAINT `fk_ecw_signatures_org`     FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_signatures_user`    FOREIGN KEY (`user_id`)         REFERENCES `ecw_users` (`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_signatures_mailbox` FOREIGN KEY (`mailbox_id`)      REFERENCES `ecw_mailboxes` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------- ALTERs

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages' AND INDEX_NAME = 'ft_ecw_email_messages_content') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages` ADD FULLTEXT KEY `ft_ecw_email_messages_content` (`subject`, `body_text`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_users' AND COLUMN_NAME = 'preferences_json') > 0, 'DO 1',
  'ALTER TABLE `ecw_users` ADD COLUMN `preferences_json` JSON DEFAULT NULL COMMENT "UI preferences, e.g. thread_order. Never security-relevant"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

-- Rebuild the participant index from the JSON the provider gave us. Safe to
-- re-run: the unique key makes it idempotent. This is also the repair
-- procedure if the table is ever suspected of drifting.
INSERT IGNORE INTO `ecw_message_participants` (`email_message_id`, `role`, `email`, `name`)
SELECT m.id, 'from', LOWER(TRIM(m.from_email)), m.from_name
  FROM `ecw_email_messages` m
 WHERE m.from_email IS NOT NULL AND m.from_email <> '';

-- (removed for MariaDB: JSON_TABLE backfill, no-op on a fresh database)
