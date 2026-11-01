-- Telegram (lib/telegram.php, the Telegram tab): each person can connect
-- their own Telegram account (their phone number), like a second Telegram
-- Desktop. A background process (bin/daemon.php, the "telegram" container)
-- stays signed in, stores their chats here and sends what they write here.
-- Only that person sees their Telegram.
-- tg_accounts: one per person. status: new (code being requested), code
--   (waiting for the code), password (waiting for the 2-step password),
--   ready, logged_out, error. machine: the computer that holds the sign-in
--   (storage/telegram/sessions, never in backups), so a copy of the database
--   on another computer does not try to use it. api_hash and the login code /
--   password in jobs are stored encrypted.
-- tg_contacts: their Telegram contacts, to start a chat.
-- tg_chats: one per person they chat with (private chats only).
-- tg_messages: both directions; (chat_id, tg_message_id) is unique, so a
--   message seen twice (sent here, then reported back by Telegram) is stored
--   once. Files are kept under storage/telegram/media and storage/telegram/out.
-- tg_jobs: what the web asks the background process to do (send the code,
--   send a message, load a chat's history, mark read, sign out…).

CREATE TABLE IF NOT EXISTS `ecw_tg_accounts` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `status`             VARCHAR(16) NOT NULL DEFAULT 'new' COMMENT 'new, code, password, ready, logged_out, error',
  `phone`              VARCHAR(32) DEFAULT NULL,
  `tg_user_id`         BIGINT DEFAULT NULL,
  `name`               VARCHAR(190) DEFAULT NULL,
  `username`           VARCHAR(64) DEFAULT NULL,
  `api_id`             INT UNSIGNED DEFAULT NULL,
  `api_hash_enc`       TEXT DEFAULT NULL,
  `hint`               VARCHAR(255) DEFAULT NULL COMMENT 'where the code was sent, or the password hint',
  `last_error`         VARCHAR(255) DEFAULT NULL,
  `machine`            VARCHAR(40) DEFAULT NULL,
  `contacts_synced_at` DATETIME DEFAULT NULL,
  `dialogs_synced_at`  DATETIME DEFAULT NULL,
  `connected_at`       DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_tg_accounts_user` (`user_id`),
  KEY `idx_ecw_tg_accounts_org` (`organization_id`),
  CONSTRAINT `fk_ecw_tg_accounts_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_tg_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_tg_contacts` (
  `account_id`  BIGINT UNSIGNED NOT NULL,
  `peer_id`     BIGINT NOT NULL,
  `name`        VARCHAR(190) DEFAULT NULL,
  `username`    VARCHAR(64) DEFAULT NULL,
  `phone`       VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (`account_id`, `peer_id`),
  CONSTRAINT `fk_ecw_tg_contacts_account` FOREIGN KEY (`account_id`) REFERENCES `ecw_tg_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_tg_chats` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`         BIGINT UNSIGNED NOT NULL,
  `peer_id`            BIGINT NOT NULL COMMENT 'the other person''s Telegram user id',
  `name`               VARCHAR(190) DEFAULT NULL,
  `username`           VARCHAR(64) DEFAULT NULL COMMENT 'without the @',
  `phone`              VARCHAR(32) DEFAULT NULL,
  `case_id`            BIGINT UNSIGNED DEFAULT NULL,
  `last_message_at`    DATETIME DEFAULT NULL,
  `last_preview`       VARCHAR(255) DEFAULT NULL,
  `unread`             INT UNSIGNED NOT NULL DEFAULT 0,
  `read_outbox_max_id` BIGINT NOT NULL DEFAULT 0 COMMENT 'they have read our messages up to this id',
  `history_loaded`     TINYINT NOT NULL DEFAULT 0 COMMENT '0 not yet, 1 asked, 2 loaded',
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_tg_chats_peer` (`account_id`, `peer_id`),
  KEY `idx_ecw_tg_chats_recent` (`account_id`, `last_message_at`),
  KEY `idx_ecw_tg_chats_case` (`case_id`),
  CONSTRAINT `fk_ecw_tg_chats_account` FOREIGN KEY (`account_id`) REFERENCES `ecw_tg_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_tg_chats_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_tg_messages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id`          BIGINT UNSIGNED NOT NULL,
  `tg_message_id`    BIGINT DEFAULT NULL,
  `direction`        VARCHAR(8) NOT NULL COMMENT 'in, out',
  `kind`             VARCHAR(16) NOT NULL DEFAULT 'text' COMMENT 'text, image, document, audio, video, sticker, location, other',
  `body`             TEXT DEFAULT NULL,
  `file_path`        VARCHAR(255) DEFAULT NULL COMMENT 'under storage/telegram',
  `media_mime`       VARCHAR(120) DEFAULT NULL,
  `filename`         VARCHAR(255) DEFAULT NULL,
  `file_size`        BIGINT DEFAULT NULL,
  `status`           VARCHAR(12) DEFAULT NULL COMMENT 'out: sending, sent, read, failed. in: received',
  `error`            VARCHAR(255) DEFAULT NULL,
  `sent_by_user_id`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL on out: written in Telegram itself',
  `edited_at`        DATETIME DEFAULT NULL,
  `occurred_at`      DATETIME NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_tg_messages_msg` (`chat_id`, `tg_message_id`),
  KEY `idx_ecw_tg_messages_chat` (`chat_id`, `occurred_at`),
  CONSTRAINT `fk_ecw_tg_messages_chat` FOREIGN KEY (`chat_id`) REFERENCES `ecw_tg_chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_tg_jobs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`   BIGINT UNSIGNED NOT NULL,
  `type`         VARCHAR(16) NOT NULL COMMENT 'send_code, code, password, send, history, read, import, logout',
  `payload_enc`  TEXT DEFAULT NULL,
  `status`       VARCHAR(8) NOT NULL DEFAULT 'queued' COMMENT 'queued, running, done, failed',
  `result_json`  TEXT DEFAULT NULL,
  `error`        VARCHAR(255) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `done_at`      DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_tg_jobs_queue` (`account_id`, `status`, `id`),
  CONSTRAINT `fk_ecw_tg_jobs_account` FOREIGN KEY (`account_id`) REFERENCES `ecw_tg_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
