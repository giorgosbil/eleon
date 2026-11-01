-- Chats in cases (modules/chatlink): a linked chat is a column of the case's
-- timeline, next to the email threads (lib/timeline.php, module columns).
-- chat_link_columns: one per chat with messages in a case: its name when
--   renamed, hidden, order, whose conversation (customer / supplier / internal).
--   Made when a chat's first message is linked; gone when its last one is.
-- chat_links.skipped_before: how many messages of the real chat lie between
--   this linked message and the previous linked one, shown as
--   "··· 7 messages not in this case". Stored for Teams only (read live from
--   Microsoft); WhatsApp and Telegram are counted when shown.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_chat_links' AND COLUMN_NAME = 'skipped_before') > 0, 'DO 1',
  'ALTER TABLE `ecw_chat_links` ADD COLUMN `skipped_before` INT UNSIGNED DEFAULT NULL');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

CREATE TABLE IF NOT EXISTS `ecw_chat_link_columns` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`       BIGINT UNSIGNED NOT NULL,
  `source`        VARCHAR(16) NOT NULL,
  `chat_ref`      VARCHAR(255) NOT NULL,
  `display_name`  VARCHAR(255) DEFAULT NULL,
  `is_hidden`     TINYINT NOT NULL DEFAULT 0,
  `sort_order`    INT NOT NULL DEFAULT 1000,
  `party_role`    VARCHAR(16) DEFAULT NULL COMMENT 'customer, supplier, internal',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_chat_link_columns` (`case_id`, `source`, `chat_ref`(150)),
  CONSTRAINT `fk_ecw_chat_link_columns_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chats linked before columns existed get theirs.
INSERT IGNORE INTO `ecw_chat_link_columns` (case_id, source, chat_ref)
  SELECT DISTINCT case_id, source, chat_ref FROM `ecw_chat_links`;
