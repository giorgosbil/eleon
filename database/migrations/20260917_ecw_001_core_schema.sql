-- Step 01 — Email Case Workspace core schema.
--
-- Implements docs/steps/01-data-model.md in full: 13 tables, the
-- cases -> case_threads -> email_threads chain, and every index the step
-- listed. Read this header before changing any table below.
--
--
-- WHY EVERY TABLE IS PREFIXED ecw_
-- ----------------------------------------------------------------------
-- The step names its tables `users`, `cases`, `organizations`, ... but this
-- schema lands in registerdatabase_dev, which already holds 169 tables of
-- the Zetisis platform. `users` is a direct collision: it exists, its PK is
-- `user_id`, its password column is `pwd`, and it carries 24 platform
-- columns that have nothing to do with this product. The step's `users`
-- (id / name / email / password_hash) is a different table with the same
-- name, so one of them has to move.
--
-- A dedicated database would have kept the step's names verbatim and was
-- the first choice, but the MySQL account this app runs as (loopuser) holds
-- GRANT USAGE ON *.* plus ALL PRIVILEGES ON registerdatabase_dev only --
-- it cannot CREATE DATABASE. A separate schema therefore needs an infra
-- change (a new DB plus a new grant) that is not this step's job.
--
-- So: one prefix, applied to all 13 tables, not just the colliding one.
-- Prefixing only `users` would leave `cases`, `mailboxes`, `email_messages`
-- and friends sitting unlabelled among the platform's tables, and the next
-- person could not tell which subsystem owns them. This follows the
-- precedent already set in this database by biz_outreach_* -- a self-
-- contained subsystem that namespaces everything it owns.
--
-- The mapping is 1:1 and mechanical (spec name -> ecw_<spec name>), and
-- every physical name is resolved through lib/schema.php, so moving this
-- schema into its own database later is a one-file change plus a rename.
--
--
-- COLLATION: WHY PROVIDER IDS ARE utf8mb4_bin
-- ----------------------------------------------------------------------
-- Microsoft Graph ids (message id, folder id, conversationId) are opaque
-- base64-ish strings where case is significant: AAkALgAA... and aakalgaa...
-- are different messages. Under the table default (utf8mb4_unicode_ci) they
-- compare equal, which would make the sync upsert key collapse two distinct
-- messages into one row. Every provider-issued identifier column is
-- therefore declared utf8mb4_bin, matching biz_outreach_mailbox_messages,
-- which learned this the same way. RFC 5322 Message-IDs get the same
-- treatment for the same reason.
--
--
-- TIME: EVERYTHING IS UTC
-- ----------------------------------------------------------------------
-- The MySQL server runs UTC (NOW() = UTC_TIMESTAMP()) while PHP runs
-- Europe/Berlin, so any value produced by date() and compared in SQL is
-- silently two hours off. All DATETIME columns here store UTC, and
-- application writers must use gmdate()/DateTimeImmutable in UTC.
-- DATETIME, not TIMESTAMP: TIMESTAMP re-converts through the session time
-- zone and dies in 2038. organizations.timezone is a display preference
-- (IANA name) and never changes what is stored.
--
--
-- FOREIGN KEYS
-- ----------------------------------------------------------------------
-- Real FKs, not logical ones. This is a new self-contained subsystem with
-- no legacy rows to reconcile, so the database can enforce its own
-- hierarchy. CASCADE flows down ownership (organization -> mailbox ->
-- folder/thread -> message -> attachment, case -> case_thread/case_event),
-- because a message without its mailbox is unreadable garbage. References
-- to people (owner, creator, linker) are ON DELETE SET NULL: deleting a
-- user must not delete the case they happened to open, and the audit row
-- stays, minus the actor.
--
--
-- THE THREE COLUMNS THIS FILE ADDS BEYOND THE STEP'S LISTS
-- ----------------------------------------------------------------------
-- Each one exists because a requirement in the product spec cannot be met
-- without it. Nothing else was invented.
--
-- 1. email_messages.occurred_at (STORED generated column)
--    Spec section 8 puts every linked thread on ONE shared chronological
--    axis, and section 10 requires newest-first fetches served by an index.
--    A message's real time is received_at for incoming and sent_at for
--    outgoing, so ordering needs COALESCE(sent_at, received_at) -- and a
--    COALESCE in ORDER BY cannot use either index, turning every timeline
--    and every folder page into a filesort. Materialising it as a STORED
--    generated column gives one indexable sort key that cannot drift from
--    its inputs, because MySQL recomputes it on every write. Rows where all
--    inputs are NULL (an unsent local draft) sort last under DESC and are
--    excluded from the case timeline.
--
-- 2. email_messages.body_fetch_state
--    Spec section 10: do not fetch large bodies during the initial sync.
--    Without a state column, "body_html IS NULL" cannot distinguish "not
--    fetched yet" from "genuinely empty body" from "fetch failed", and the
--    backfill job either re-fetches forever or skips real gaps. Mirrors
--    biz_outreach_mailbox_messages.body_state, which exists for this reason.
--
-- 3. case_threads.is_hidden
--    Spec section 8: "A user can hide/show thread columns without removing
--    them from the Case." Hiding is per-link state, and the only place it
--    can live is the link row.
--
-- email_addresses.normalized_email is a fourth column not in the step's
-- list, but the step itself asks for UNIQUE(organization_id +
-- normalized_email) -- the constraint needs the column it names.
--
--
-- WHY internet_message_id IS INDEXED BUT NOT UNIQUE
-- ----------------------------------------------------------------------
-- Tempting, and biz_outreach_mailbox_messages does make (mailbox_id,
-- rfc_message_id) unique -- but it is wrong here. When a user emails
-- themselves, or is CC'd on their own send, Exchange stores two distinct
-- messages in the same mailbox (the Sent copy and the Inbox copy) carrying
-- the SAME internetMessageId. A unique key rejects the second one and the
-- mailbox silently loses mail. The sync upsert key is therefore
-- (mailbox_id, provider_message_id), and internet_message_id is a plain
-- lookup index used for threading and for re-identifying a message whose
-- Graph id changed.
--
-- That id change is not hypothetical: Graph reissues a message id when the
-- message moves folders or when a draft is sent. Rows are reconciled by
-- internet_message_id when a known provider id disappears -- see the sync
-- step for the procedure.
--
--
-- SELECT DISCIPLINE
-- ----------------------------------------------------------------------
-- email_messages carries four LONGTEXT body columns. Any list view
-- (mailbox list, thread list, case timeline header) MUST enumerate the
-- columns it needs. SELECT * on this table pulls whole message bodies over
-- the wire for every row on the page and defeats spec section 10 on its own.
--
--
-- IDEMPOTENT: every statement is IF NOT EXISTS, so re-running is a no-op.


CREATE TABLE IF NOT EXISTS `ecw_organizations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255) NOT NULL,
  `timezone`   VARCHAR(64)  NOT NULL DEFAULT 'UTC' COMMENT 'IANA name, display only. Stored times are always UTC',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(255) NOT NULL,
  `email`         VARCHAR(320) NOT NULL COMMENT 'RFC 5321 maximum length',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash() output. Never a plaintext or reversible value',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='This product has its own users. NOT the platform `users` table';


CREATE TABLE IF NOT EXISTS `ecw_organization_users` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `role`            VARCHAR(32) NOT NULL DEFAULT 'user' COMMENT 'admin, user, read_only. VARCHAR not ENUM so roles grow without a migration',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_org_users_pair` (`organization_id`, `user_id`),
  KEY `idx_ecw_org_users_user` (`user_id`),
  CONSTRAINT `fk_ecw_org_users_org`  FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_org_users_user` FOREIGN KEY (`user_id`)         REFERENCES `ecw_users` (`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_mailboxes` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`         BIGINT UNSIGNED NOT NULL,
  `connected_by_user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `provider`                VARCHAR(32)  NOT NULL DEFAULT 'microsoft',
  `provider_account_id`     VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Graph user id / oid. Case-sensitive',
  `email_address`           VARCHAR(320) NOT NULL,
  `display_name`            VARCHAR(255) DEFAULT NULL,
  `access_token_encrypted`  MEDIUMTEXT DEFAULT NULL COMMENT 'NEVER store a plaintext token',
  `refresh_token_encrypted` MEDIUMTEXT DEFAULT NULL COMMENT 'NEVER store a plaintext token',
  `token_expires_at`        DATETIME DEFAULT NULL COMMENT 'UTC. Access token expiry, refreshed ahead of it',
  `sync_status`             VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending, initial, active, error, disconnected',
  `last_sync_at`            DATETIME DEFAULT NULL,
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_mailboxes_provider_account` (`organization_id`, `provider`, `provider_account_id`),
  UNIQUE KEY `uq_ecw_mailboxes_address` (`organization_id`, `provider`, `email_address`) COMMENT 'one connection per mailbox per org. Reconnect updates the row, never duplicates the sync',
  KEY `idx_ecw_mailboxes_sync` (`sync_status`, `last_sync_at`),
  CONSTRAINT `fk_ecw_mailboxes_org`  FOREIGN KEY (`organization_id`)      REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_mailboxes_user` FOREIGN KEY (`connected_by_user_id`) REFERENCES `ecw_users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_mail_folders` (
  `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`                BIGINT UNSIGNED NOT NULL,
  `provider_folder_id`        VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `parent_provider_folder_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'NULL at the root. Tree is rebuilt from provider ids, not local ids',
  `name`                      VARCHAR(255) NOT NULL,
  `folder_type`               VARCHAR(32)  NOT NULL DEFAULT 'custom' COMMENT 'inbox, sent, drafts, deleted, archive, custom',
  `is_system`                 TINYINT(1)   NOT NULL DEFAULT 0,
  `is_selected_for_sync`      TINYINT(1)   NOT NULL DEFAULT 0,
  `delta_link_encrypted`      MEDIUMTEXT DEFAULT NULL COMMENT 'Graph deltaLink. Encrypted: it embeds a mailbox-scoped token',
  `last_sync_at`              DATETIME DEFAULT NULL,
  `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_mail_folders_provider` (`mailbox_id`, `provider_folder_id`),
  KEY `idx_ecw_mail_folders_selected` (`mailbox_id`, `is_selected_for_sync`),
  KEY `idx_ecw_mail_folders_parent` (`mailbox_id`, `parent_provider_folder_id`(255)),
  KEY `idx_ecw_mail_folders_type` (`mailbox_id`, `folder_type`),
  CONSTRAINT `fk_ecw_mail_folders_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `ecw_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_email_addresses` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `email`            VARCHAR(320) NOT NULL COMMENT 'as last seen on the wire, original case preserved',
  `normalized_email` VARCHAR(320) NOT NULL COMMENT 'trimmed and lowercased. The identity key',
  `display_name`     VARCHAR(255) DEFAULT NULL COMMENT 'last seen display name. Advisory only',
  `domain`           VARCHAR(255) NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_email_addresses_identity` (`organization_id`, `normalized_email`),
  KEY `idx_ecw_email_addresses_domain` (`organization_id`, `domain`),
  CONSTRAINT `fk_ecw_email_addresses_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Identity cache written automatically from traffic. NOT a CRM. No owner, no lifecycle, no manual entry';


CREATE TABLE IF NOT EXISTS `ecw_email_threads` (
  `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`               BIGINT UNSIGNED NOT NULL,
  `provider_conversation_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'NULLable on purpose: a thread may be assembled from References headers alone',
  `normalized_subject`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'subject with Re:/Fw:/Aw: prefixes and whitespace stripped',
  `latest_message_at`        DATETIME DEFAULT NULL COMMENT 'denormalised from the newest member message. Drives newest-first thread lists',
  `message_count`            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'denormalised counter maintained by sync',
  `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_email_threads_conversation` (`mailbox_id`, `provider_conversation_id`),
  KEY `idx_ecw_email_threads_latest` (`mailbox_id`, `latest_message_at` DESC),
  KEY `idx_ecw_email_threads_subject` (`mailbox_id`, `normalized_subject`(191)),
  CONSTRAINT `fk_ecw_email_threads_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `ecw_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_email_messages` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`             BIGINT UNSIGNED NOT NULL,
  `email_thread_id`        BIGINT UNSIGNED NOT NULL,
  `mail_folder_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL when the containing folder is not synced',

  `provider_message_id`    VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Graph id. MUTABLE: reissued on move and on draft send',
  `internet_message_id`    VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'RFC 5322 Message-ID. Stable across moves. Indexed, deliberately NOT unique',
  `in_reply_to_message_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `references_json`        JSON DEFAULT NULL COMMENT 'References header parsed into an ordered array of Message-IDs',

  `subject`                VARCHAR(512) DEFAULT NULL,

  `from_email`             VARCHAR(320) DEFAULT NULL,
  `from_name`              VARCHAR(255) DEFAULT NULL,

  `to_json`                JSON DEFAULT NULL COMMENT 'array of {email, name}',
  `cc_json`                JSON DEFAULT NULL,
  `bcc_json`               JSON DEFAULT NULL COMMENT 'only ever populated on messages we sent',

  `body_html`              LONGTEXT DEFAULT NULL COMMENT 'body with quoted history removed',
  `body_text`              LONGTEXT DEFAULT NULL,
  `quoted_html`            LONGTEXT DEFAULT NULL COMMENT 'the split-off quoted history, collapsed behind ... in the UI',
  `quoted_text`            LONGTEXT DEFAULT NULL,
  `body_fetch_state`       VARCHAR(32) NOT NULL DEFAULT 'not_fetched' COMMENT 'not_fetched, metadata_only, fetched, fetch_failed. Distinguishes an unfetched body from an empty one',

  `direction`              ENUM('incoming','outgoing','draft') NOT NULL,
  `is_read`                TINYINT(1) NOT NULL DEFAULT 0,
  `has_attachments`        TINYINT(1) NOT NULL DEFAULT 0,

  `sent_at`                DATETIME DEFAULT NULL COMMENT 'UTC, provider sentDateTime',
  `received_at`            DATETIME DEFAULT NULL COMMENT 'UTC, provider receivedDateTime',
  `occurred_at`            DATETIME GENERATED ALWAYS AS (COALESCE(`sent_at`, `received_at`, `provider_created_at`)) STORED
                           COMMENT 'single chronological axis for the case timeline and newest-first paging. Cannot drift: recomputed on every write',

  `provider_created_at`    DATETIME DEFAULT NULL,
  `provider_modified_at`   DATETIME DEFAULT NULL,

  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_email_messages_provider` (`mailbox_id`, `provider_message_id`) COMMENT 'the sync upsert key',
  KEY `idx_ecw_email_messages_mailbox_received` (`mailbox_id`, `received_at`),
  KEY `idx_ecw_email_messages_mailbox_sent` (`mailbox_id`, `sent_at`),
  KEY `idx_ecw_email_messages_thread_received` (`email_thread_id`, `received_at`),
  KEY `idx_ecw_email_messages_internet_id` (`internet_message_id`),
  KEY `idx_ecw_email_messages_in_reply_to` (`in_reply_to_message_id`),
  KEY `idx_ecw_email_messages_from` (`from_email`),
  KEY `idx_ecw_email_messages_subject` (`subject`(191)),
  KEY `idx_ecw_email_messages_mailbox_occurred` (`mailbox_id`, `occurred_at` DESC),
  KEY `idx_ecw_email_messages_thread_occurred` (`email_thread_id`, `occurred_at`),
  KEY `idx_ecw_email_messages_folder_occurred` (`mail_folder_id`, `occurred_at` DESC),
  KEY `idx_ecw_email_messages_unread` (`mailbox_id`, `is_read`, `occurred_at` DESC),
  KEY `idx_ecw_email_messages_body_state` (`body_fetch_state`, `id`) COMMENT 'drives the body backfill job',
  CONSTRAINT `fk_ecw_email_messages_mailbox` FOREIGN KEY (`mailbox_id`)      REFERENCES `ecw_mailboxes` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_email_messages_thread`  FOREIGN KEY (`email_thread_id`) REFERENCES `ecw_email_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_email_messages_folder`  FOREIGN KEY (`mail_folder_id`)  REFERENCES `ecw_mail_folders` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_email_attachments` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_message_id`        BIGINT UNSIGNED NOT NULL,
  `provider_attachment_id`  VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `filename`                VARCHAR(512) DEFAULT NULL,
  `mime_type`               VARCHAR(255) DEFAULT NULL,
  `size_bytes`              BIGINT UNSIGNED DEFAULT NULL,
  `is_inline`               TINYINT(1) NOT NULL DEFAULT 0,
  `content_id`              VARCHAR(255) DEFAULT NULL COMMENT 'cid: reference used by inline images in body_html',
  `local_path`              VARCHAR(1024) DEFAULT NULL COMMENT 'NULL until actually downloaded',
  `provider_download_state` VARCHAR(32) NOT NULL DEFAULT 'not_downloaded' COMMENT 'not_downloaded, downloading, downloaded, skipped_too_large, failed',
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_email_attachments_provider` (`email_message_id`, `provider_attachment_id`),
  KEY `idx_ecw_email_attachments_inline` (`email_message_id`, `is_inline`),
  KEY `idx_ecw_email_attachments_cid` (`content_id`),
  KEY `idx_ecw_email_attachments_state` (`provider_download_state`, `id`) COMMENT 'drives on-demand download. Initial sync stores metadata only',
  CONSTRAINT `fk_ecw_email_attachments_message` FOREIGN KEY (`email_message_id`) REFERENCES `ecw_email_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_cases` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`     BIGINT UNSIGNED NOT NULL,
  `case_number`         VARCHAR(32)  NOT NULL COMMENT 'display form, e.g. CASE-00142. Unique per org',
  `name`                VARCHAR(255) NOT NULL,
  `status`              VARCHAR(32)  NOT NULL DEFAULT 'open' COMMENT 'open, waiting, closed. VARCHAR not ENUM: statuses become configurable later',
  `owner_user_id`       BIGINT UNSIGNED DEFAULT NULL,
  `created_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `closed_at`           DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_cases_number` (`organization_id`, `case_number`) COMMENT 'also the concurrency guard: number allocation races resolve as a duplicate-key retry',
  KEY `idx_ecw_cases_status` (`organization_id`, `status`, `updated_at` DESC),
  KEY `idx_ecw_cases_name` (`organization_id`, `name`(191)),
  KEY `idx_ecw_cases_owner` (`owner_user_id`),
  CONSTRAINT `fk_ecw_cases_org`     FOREIGN KEY (`organization_id`)    REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_cases_owner`   FOREIGN KEY (`owner_user_id`)      REFERENCES `ecw_users` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_ecw_cases_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `ecw_users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ecw_case_threads` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`            BIGINT UNSIGNED NOT NULL,
  `email_thread_id`    BIGINT UNSIGNED NOT NULL,
  `display_name`       VARCHAR(255) DEFAULT NULL COMMENT 'column header in the case timeline, e.g. Customer, Hexagon',
  `sort_order`         INT NOT NULL DEFAULT 0 COMMENT 'left-to-right column order',
  `is_hidden`          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'column hidden in the timeline. Still linked to the case',
  `linked_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `linked_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_case_threads_thread` (`email_thread_id`) COMMENT 'V1 rule made structural: a thread belongs to at most one case. Drop this key, do not add a workaround, if that rule is ever revisited',
  KEY `idx_ecw_case_threads_case_order` (`case_id`, `sort_order`, `id`),
  CONSTRAINT `fk_ecw_case_threads_case`   FOREIGN KEY (`case_id`)           REFERENCES `ecw_cases` (`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_threads_thread` FOREIGN KEY (`email_thread_id`)   REFERENCES `ecw_email_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_threads_user`   FOREIGN KEY (`linked_by_user_id`) REFERENCES `ecw_users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='The join that makes unlimited thread columns possible. Never put thread ids on ecw_cases';


CREATE TABLE IF NOT EXISTS `ecw_case_events` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for system-generated events, or a deleted actor',
  `event_type`    VARCHAR(64) NOT NULL COMMENT 'CASE_CREATED, THREAD_LINKED, THREAD_UNLINKED, CASE_RENAMED, CASE_STATUS_CHANGED, EMAIL_SENT, EMAIL_RECEIVED. Free text so the vocabulary grows without a migration',
  `entity_type`   VARCHAR(64) DEFAULT NULL COMMENT 'polymorphic target, e.g. email_thread, email_message. No FK by design',
  `entity_id`     BIGINT UNSIGNED DEFAULT NULL,
  `metadata_json` JSON DEFAULT NULL COMMENT 'small sidecar. Never message bodies, never full provider payloads',
  `occurred_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC. When it happened, not when it was recorded',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_case_events_case_time` (`case_id`, `occurred_at`),
  KEY `idx_ecw_case_events_type_time` (`event_type`, `occurred_at`),
  KEY `idx_ecw_case_events_entity` (`entity_type`, `entity_id`),
  CONSTRAINT `fk_ecw_case_events_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_events_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only. No UPDATE, no DELETE from application code';


CREATE TABLE IF NOT EXISTS `ecw_mailbox_sync_state` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailbox_id`       BIGINT UNSIGNED NOT NULL,
  `mail_folder_id`   BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for mailbox-wide sync types such as folder-tree discovery',
  `sync_type`        VARCHAR(32) NOT NULL COMMENT 'folders, messages, bodies, attachments',
  `cursor_encrypted` MEDIUMTEXT DEFAULT NULL COMMENT 'provider cursor. Encrypted, same reason as delta_link_encrypted',
  `last_success_at`  DATETIME DEFAULT NULL,
  `last_error`       TEXT DEFAULT NULL COMMENT 'last failure message, truncated by the writer. No tokens, no PII',
  `retry_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_sync_state_scope` (`mailbox_id`, (IFNULL(`mail_folder_id`, 0)), `sync_type`)
    COMMENT 'functional key part, not a stored generated column: MySQL rejects ON DELETE CASCADE on a column a generated column derives from (error 1215). IFNULL keeps the mailbox-wide rows, where mail_folder_id IS NULL, under the same unique constraint as folder-scoped ones',
  KEY `idx_ecw_sync_state_folder` (`mail_folder_id`),
  KEY `idx_ecw_sync_state_health` (`retry_count`, `last_success_at`),
  CONSTRAINT `fk_ecw_sync_state_mailbox` FOREIGN KEY (`mailbox_id`)     REFERENCES `ecw_mailboxes` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_sync_state_folder`  FOREIGN KEY (`mail_folder_id`) REFERENCES `ecw_mail_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Run health and error tracking. ecw_mail_folders.delta_link_encrypted stays the authority for the per-folder delta cursor in V1';
