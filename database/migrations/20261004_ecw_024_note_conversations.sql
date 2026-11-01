-- Notes as conversations, like comments in Word: a first note (parent NULL)
-- and its replies (parent_note_id = the first note). An email can have several
-- such conversations. A conversation can be marked resolved and reopened.
-- Existing notes become one-note conversations, nothing is moved.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_case_notes' AND COLUMN_NAME='parent_note_id') > 0, 'DO 1', 'ALTER TABLE `ecw_case_notes` ADD COLUMN `parent_note_id` BIGINT UNSIGNED DEFAULT NULL COMMENT ''the first note of the conversation. NULL = this is the first note'', ADD COLUMN `resolved_at` DATETIME DEFAULT NULL COMMENT ''first notes only'', ADD COLUMN `resolved_by_user_id` BIGINT UNSIGNED DEFAULT NULL, ADD KEY `idx_ecw_case_notes_parent` (`parent_note_id`), ADD KEY `idx_ecw_case_notes_message` (`message_id`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- When each person last looked at each conversation (for the unread dot).
CREATE TABLE IF NOT EXISTS `ecw_note_reads` (
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `root_note_id` BIGINT UNSIGNED NOT NULL,
  `seen_at`      DATETIME NOT NULL,
  `last_note_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'newest note of the conversation this person has seen',
  PRIMARY KEY (`user_id`, `root_note_id`),
  KEY `idx_ecw_note_reads_note` (`root_note_id`),
  CONSTRAINT `fk_ecw_note_reads_user` FOREIGN KEY (`user_id`) REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_note_reads_note` FOREIGN KEY (`root_note_id`) REFERENCES `ecw_case_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
