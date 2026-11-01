-- Chats in cases (modules/chatlink): chat messages (Teams, WhatsApp,
-- Telegram) linked to a case.
-- chat_links: a copy of each linked message. A copy, because the case is
--   shared with people who cannot read the chat (a Telegram chat is one
--   person's, a Teams chat its members'); linking is the decision to share
--   those messages with the case. Unlinking deletes the copy.
--   source: teams, whatsapp, telegram. chat_ref: the chat (a Teams chat id,
--   or the module's chat row id). message_ref: the message in that chat.
--   owner_user_id: whose chat it is when it is private (Telegram), so only
--   they get "open in Telegram".
-- chat_link_suggestions: what the AI suggested for a chat, once per newest
--   message (basis), so reopening the chat does not ask the AI again; state
--   dismissed keeps it from coming back.

CREATE TABLE IF NOT EXISTS `ecw_chat_links` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`    BIGINT UNSIGNED NOT NULL,
  `case_id`            BIGINT UNSIGNED NOT NULL,
  `source`             VARCHAR(16) NOT NULL COMMENT 'teams, whatsapp, telegram',
  `chat_ref`           VARCHAR(255) NOT NULL,
  `chat_title`         VARCHAR(255) DEFAULT NULL,
  `message_ref`        VARCHAR(255) NOT NULL,
  `owner_user_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'a private chat''s owner (Telegram)',
  `sender`             VARCHAR(190) DEFAULT NULL,
  `direction`          VARCHAR(8) NOT NULL DEFAULT 'in' COMMENT 'in, out',
  `body_text`          TEXT DEFAULT NULL,
  `body_html`          MEDIUMTEXT DEFAULT NULL COMMENT 'sanitized (Teams)',
  `filename`           VARCHAR(255) DEFAULT NULL,
  `sent_at`            DATETIME NOT NULL,
  `linked_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `linked_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_chat_links_msg` (`case_id`, `source`, `chat_ref`(150), `message_ref`(100)),
  KEY `idx_ecw_chat_links_chat` (`organization_id`, `source`, `chat_ref`(150)),
  KEY `idx_ecw_chat_links_case` (`case_id`, `sent_at`),
  CONSTRAINT `fk_ecw_chat_links_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_chat_links_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_chat_link_suggestions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `source`           VARCHAR(16) NOT NULL,
  `chat_ref`         VARCHAR(255) NOT NULL,
  `basis`            VARCHAR(255) NOT NULL COMMENT 'the newest message it read',
  `case_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL: nothing to suggest',
  `message_refs`     TEXT DEFAULT NULL COMMENT 'JSON list',
  `reason`           VARCHAR(255) DEFAULT NULL,
  `confidence`       TINYINT UNSIGNED DEFAULT NULL,
  `state`            VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open, dismissed, used',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_chat_link_sugg` (`user_id`, `source`, `chat_ref`(150), `basis`(100)),
  CONSTRAINT `fk_ecw_chat_link_sugg_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_chat_link_sugg_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_chat_link_sugg_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
