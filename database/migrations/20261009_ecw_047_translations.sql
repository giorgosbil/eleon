-- Translations of emails (lib/translate.php, the "Translate" button in Mail and
-- Cases). One row per email and target language: the same email is translated
-- by the AI once, then shown from here.

CREATE TABLE IF NOT EXISTS `ecw_message_translations` (
  `email_message_id` BIGINT UNSIGNED NOT NULL,
  `lang`             VARCHAR(8) NOT NULL,
  `source_lang`      VARCHAR(40) DEFAULT NULL COMMENT 'what the AI said the email was written in',
  `text`             MEDIUMTEXT NOT NULL,
  `model`            VARCHAR(120) DEFAULT NULL,
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email_message_id`, `lang`),
  CONSTRAINT `fk_ecw_message_translations_msg` FOREIGN KEY (`email_message_id`) REFERENCES `ecw_email_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
