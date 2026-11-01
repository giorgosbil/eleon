-- Case groups' rules for new incoming email (Settings → Case groups, lib/group_rules.php):
--   match_words: words that start a case at once, without AI ("βαθμονόμηση");
--   match_ai: in the person's own words, which emails are for the group: the AI reads each
--             new email against it (lib/agents.php triage);
--   match_mailbox_id: only emails arriving in this account;
--   match_action: 'off', 'suggest' (a card "Start a case in this group") or 'auto' (the case
--             is started in the group).
-- case_group_feedback: what the AI learns from: "yes, for this group" (a suggestion accepted)
-- and "not for this group" (a suggestion dismissed, a case it started undone).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_case_groups' AND COLUMN_NAME='match_action') > 0, 'DO 1',
  'ALTER TABLE `ecw_case_groups`
     ADD COLUMN `match_words` TEXT DEFAULT NULL COMMENT ''words that start a case without AI, one per line'',
     ADD COLUMN `match_ai` TEXT DEFAULT NULL COMMENT ''which emails are for the group, for the AI'',
     ADD COLUMN `match_mailbox_id` INT UNSIGNED DEFAULT NULL COMMENT ''only emails arriving in this account'',
     ADD COLUMN `match_action` VARCHAR(8) NOT NULL DEFAULT ''off'' COMMENT ''off, suggest, auto''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

CREATE TABLE IF NOT EXISTS `ecw_case_group_feedback` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `verdict` VARCHAR(4) NOT NULL COMMENT 'yes or no',
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `summary` VARCHAR(300) DEFAULT NULL,
  `message_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `group_recent` (`group_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
