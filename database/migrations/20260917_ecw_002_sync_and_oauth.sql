-- Step 02 — OAuth, synchronization and webhook infrastructure.
--
-- Three new tables plus additive columns on step 01's tables. Nothing in
-- step 01 is redefined or dropped.
--
--
-- IDEMPOTENT ALTERs
-- ----------------------------------------------------------------------
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS (that is MariaDB). The runner
-- records applied files so nothing re-runs in the normal case, but a
-- migration that fails halfway must be safe to re-run after the fix --
-- exactly what happened to step 01. Each ALTER is therefore wrapped in the
-- standard information_schema + PREPARE idiom. The no-op branch is `DO 1`,
-- not `SELECT 1`: a branch returning a result set leaves an unbuffered
-- query open on the connection and the next statement dies with error 2014.
--
--
-- WHY A LEASE, NOT A FLAG
-- ----------------------------------------------------------------------
-- run_lease_id / run_lease_at on ecw_mailbox_sync_state give one row-level
-- lease per (mailbox, folder, sync_type). A lease is claimed only against a
-- row with no lease or a lease whose heartbeat is older than the TTL, and
-- MySQL serializes that UPDATE, so of two concurrent runs exactly one wins.
-- This is what makes the webhook path safe: a burst of notifications for one
-- folder costs one failed UPDATE per loser and never reaches Graph. A crashed
-- run needs no recovery -- it stops heartbeating, the lease ages out, the next
-- run resumes from the last durable cursor. Copied deliberately from
-- bussiness/outreach/mailbox_sync.php, which arrived at the same design.
--
--
-- WHY DEAD LETTERS
-- ----------------------------------------------------------------------
-- The cursor may only advance once every message on a page has resolved to
-- EITHER a durable upsert OR a durable dead-letter row. Without somewhere to
-- record "this one message is unprocessable", a single poison message forces
-- a choice between blocking the folder forever and silently skipping mail.
-- Neither is acceptable under "never silently stop syncing".
--
--
-- WHY @removed IS A SOFT DELETE
-- ----------------------------------------------------------------------
-- Graph delta reports deletions and moves-out as {"id":..., "@removed":...}.
-- Hard-deleting the local row would tear messages out of a case timeline
-- retroactively -- the conversation genuinely happened, and an audit trail
-- that edits itself when someone cleans their mailbox is worthless. Rows are
-- stamped provider_removed_at instead: mail lists filter them out, the case
-- timeline keeps them and marks them.
--
--
-- WHY client_state IS STORED AS A HASH
-- ----------------------------------------------------------------------
-- Graph echoes the clientState we set back in every notification, and that
-- echo is what proves the notification came from our subscription. Validation
-- only ever needs to compare, so the secret itself is never stored: the column
-- holds sha256(clientState) and the webhook hashes what it receives. A leaked
-- database row therefore cannot be used to forge a notification.


CREATE TABLE IF NOT EXISTS `ecw_graph_subscriptions` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`           BIGINT UNSIGNED NOT NULL,
  `provider`             VARCHAR(32) NOT NULL DEFAULT 'microsoft',
  `subscription_id`      VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'provider-issued id',
  `resource`             VARCHAR(512) NOT NULL COMMENT 'e.g. /users/{id}/mailFolders/{folderId}/messages',
  `change_type`          VARCHAR(64)  NOT NULL DEFAULT 'created,updated,deleted',
  `notification_url`     VARCHAR(512) NOT NULL,
  `client_state_hash`    CHAR(64) NOT NULL COMMENT 'sha256 of the clientState secret. The secret itself is never stored',
  `expires_at`           DATETIME NOT NULL COMMENT 'UTC. Graph caps message subscriptions well under 3 days',
  `status`               VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active, expired, failed, deleted',
  `last_renewed_at`      DATETIME DEFAULT NULL,
  `last_notification_at` DATETIME DEFAULT NULL COMMENT 'silence here is the signal that notifications are being missed',
  `last_error`           TEXT DEFAULT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_graph_subscriptions_provider_id` (`provider`, `subscription_id`),
  KEY `idx_ecw_graph_subscriptions_expiry` (`status`, `expires_at`) COMMENT 'drives the renewal sweep',
  KEY `idx_ecw_graph_subscriptions_mailbox` (`mailbox_id`, `status`),
  CONSTRAINT `fk_ecw_graph_subscriptions_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `ecw_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_sync_queue` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`     BIGINT UNSIGNED NOT NULL,
  `mail_folder_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for mailbox-wide jobs such as folder discovery',
  `job_type`       VARCHAR(32) NOT NULL COMMENT 'folders, delta, backfill, bodies',
  `reason`         VARCHAR(64) NOT NULL DEFAULT 'cron' COMMENT 'webhook, cron, manual, initial. Provenance for debugging only',
  `status`         VARCHAR(16) NOT NULL DEFAULT 'queued' COMMENT 'queued, running, done, failed',
  `attempts`       INT UNSIGNED NOT NULL DEFAULT 0,
  `available_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC. Backoff pushes this forward',
  `locked_by`      VARCHAR(64) DEFAULT NULL,
  `locked_at`      DATETIME DEFAULT NULL,
  `last_error`     TEXT DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_sync_queue_claim` (`status`, `available_at`, `id`) COMMENT 'the worker claim scan',
  KEY `idx_ecw_sync_queue_scope` (`mailbox_id`, `mail_folder_id`, `job_type`, `status`) COMMENT 'used to coalesce an already-queued job',
  CONSTRAINT `fk_ecw_sync_queue_mailbox` FOREIGN KEY (`mailbox_id`)     REFERENCES `ecw_mailboxes` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_sync_queue_folder`  FOREIGN KEY (`mail_folder_id`) REFERENCES `ecw_mail_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Deliberately not uniquely keyed. Coalescing is best-effort (enqueue skips an existing queued row) because the per-folder lease, not the queue, is what makes duplicate work a no-op';


CREATE TABLE IF NOT EXISTS `ecw_message_dead_letters` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`          BIGINT UNSIGNED NOT NULL,
  `mail_folder_id`      BIGINT UNSIGNED DEFAULT NULL,
  `provider_message_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `internet_message_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `error_class`         VARCHAR(64)  NOT NULL DEFAULT 'unknown',
  `error_message`       TEXT DEFAULT NULL COMMENT 'no tokens, no bodies, no PII beyond addresses already stored',
  `payload_excerpt`     MEDIUMTEXT DEFAULT NULL COMMENT 'bounded excerpt of the provider payload, for diagnosis',
  `retry_count`         INT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_dead_letters_message` (`mailbox_id`, `provider_message_id`),
  KEY `idx_ecw_dead_letters_recent` (`mailbox_id`, `last_seen_at`),
  CONSTRAINT `fk_ecw_dead_letters_mailbox` FOREIGN KEY (`mailbox_id`)     REFERENCES `ecw_mailboxes` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_dead_letters_folder`  FOREIGN KEY (`mail_folder_id`) REFERENCES `ecw_mail_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A message lands here only so the folder cursor can advance past it. Never a silent skip';


-- ---------------------------------------------------------------- ALTERs

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_mailbox_sync_state' AND COLUMN_NAME = 'run_lease_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_mailbox_sync_state`
     ADD COLUMN `run_lease_id` VARCHAR(64) DEFAULT NULL COMMENT "holder of the current lease. NULL when free",
     ADD COLUMN `run_lease_at` DATETIME DEFAULT NULL COMMENT "UTC heartbeat. A lease older than the TTL is stealable",
     ADD KEY `idx_ecw_sync_state_lease` (`run_lease_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_mailboxes' AND COLUMN_NAME = 'status_message') > 0, 'DO 1',
  'ALTER TABLE `ecw_mailboxes`
     ADD COLUMN `status_message` VARCHAR(255) DEFAULT NULL COMMENT "human-readable reason for the current sync_status. Shown in Settings, never a secret",
     ADD COLUMN `status_changed_at` DATETIME DEFAULT NULL,
     ADD COLUMN `provider_tenant_id` VARCHAR(128) DEFAULT NULL,
     ADD COLUMN `granted_scopes` TEXT DEFAULT NULL COMMENT "scopes the token was actually issued with, for diagnosing a permission gap",
     ADD COLUMN `initial_sync_completed_at` DATETIME DEFAULT NULL COMMENT "newest-first import reached the point where the UI is usable",
     ADD COLUMN `history_synced_through` DATETIME DEFAULT NULL COMMENT "backfill has reached this far back. NULL = not started",
     ADD COLUMN `historical_sync_complete` TINYINT(1) NOT NULL DEFAULT 0 COMMENT "the whole mailbox history is local"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_mail_folders' AND COLUMN_NAME = 'is_case_intake') > 0, 'DO 1',
  'ALTER TABLE `ecw_mail_folders`
     ADD COLUMN `is_case_intake` TINYINT(1) NOT NULL DEFAULT 0 COMMENT "the configurable Create Case folder. Moving mail here drafts a case",
     ADD KEY `idx_ecw_mail_folders_intake` (`mailbox_id`, `is_case_intake`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages' AND COLUMN_NAME = 'provider_removed_at') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages`
     ADD COLUMN `provider_removed_at` DATETIME DEFAULT NULL COMMENT "delta reported @removed. Soft delete: mail lists hide it, the case timeline keeps it",
     ADD COLUMN `web_link` VARCHAR(1024) DEFAULT NULL COMMENT "provider deep link. Backs Open the original full message",
     ADD KEY `idx_ecw_email_messages_live` (`mailbox_id`, `provider_removed_at`, `occurred_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
