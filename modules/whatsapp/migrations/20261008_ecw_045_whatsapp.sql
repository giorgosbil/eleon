-- WhatsApp (lib/whatsapp.php, the WhatsApp tab, Settings → WhatsApp): a
-- workspace connects its WhatsApp Business number through Meta's Cloud API.
-- organizations.whatsapp_json: phone number id, business account id, the
--   access token and app secret (encrypted), the webhook verify token.
-- organizations.whatsapp_phone_number_id: copied out of the JSON so an
--   incoming webhook finds its workspace by the number it was sent to.
-- wa_chats: one per customer phone number, optionally linked to a case.
--   last_inbound_at decides the 24-hour window in which free text may be
--   sent; outside it WhatsApp only accepts an approved template.
-- wa_messages: both directions; wamid is WhatsApp's id, unique, so a
--   webhook delivered twice is stored once. status follows sent → delivered
--   → read (or failed, with the reason).

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_organizations' AND COLUMN_NAME = 'whatsapp_json') > 0, 'DO 1',
  'ALTER TABLE `ecw_organizations`
     ADD COLUMN `whatsapp_json` TEXT DEFAULT NULL,
     ADD COLUMN `whatsapp_phone_number_id` VARCHAR(40) DEFAULT NULL,
     ADD KEY `idx_ecw_organizations_wa_phone` (`whatsapp_phone_number_id`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

CREATE TABLE IF NOT EXISTS `ecw_wa_chats` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `wa_id`            VARCHAR(32) NOT NULL COMMENT 'the customer''s number, digits only, with country code',
  `name`             VARCHAR(190) DEFAULT NULL COMMENT 'their WhatsApp profile name',
  `case_id`          BIGINT UNSIGNED DEFAULT NULL,
  `last_message_at`  DATETIME DEFAULT NULL,
  `last_inbound_at`  DATETIME DEFAULT NULL,
  `last_preview`     VARCHAR(255) DEFAULT NULL,
  `unread`           INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_wa_chats_number` (`organization_id`, `wa_id`),
  KEY `idx_ecw_wa_chats_recent` (`organization_id`, `last_message_at`),
  KEY `idx_ecw_wa_chats_case` (`case_id`),
  CONSTRAINT `fk_ecw_wa_chats_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_wa_chats_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_wa_messages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id`          BIGINT UNSIGNED NOT NULL,
  `wamid`            VARCHAR(190) DEFAULT NULL,
  `direction`        VARCHAR(8) NOT NULL COMMENT 'in, out',
  `kind`             VARCHAR(16) NOT NULL DEFAULT 'text' COMMENT 'text, image, document, audio, video, sticker, location, template, reaction, other',
  `body`             TEXT DEFAULT NULL,
  `media_id`         VARCHAR(190) DEFAULT NULL,
  `media_mime`       VARCHAR(120) DEFAULT NULL,
  `filename`         VARCHAR(255) DEFAULT NULL,
  `status`           VARCHAR(12) DEFAULT NULL COMMENT 'out: sending, sent, delivered, read, failed. in: received',
  `error`            VARCHAR(255) DEFAULT NULL,
  `sent_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `occurred_at`      DATETIME NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_wa_messages_wamid` (`wamid`),
  KEY `idx_ecw_wa_messages_chat` (`chat_id`, `occurred_at`),
  CONSTRAINT `fk_ecw_wa_messages_chat` FOREIGN KEY (`chat_id`) REFERENCES `ecw_wa_chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
