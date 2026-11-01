-- What happens next on a case (lib/next_actions.php).
-- cases.next_*: the next action, who does it, who it waits on (us, the
--   customer, the supplier/manufacturer, a colleague) and since when, and the
--   day to look at it again. next_by says who set it last (user, ai, rule);
--   next_prev_json keeps what the AI replaced, for its one-click undo.
-- case_threads.party_role: whose conversation it is (customer / supplier /
--   internal), so the rule "we wrote to the supplier: waiting on the supplier"
--   knows which side an email went to.
-- case_refs: the case's reference numbers (customer order, supplier ticket or
--   RMA, serial, claim, part, invoice), searchable from the case list.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_cases' AND COLUMN_NAME = 'next_action') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases`
     ADD COLUMN `next_action` VARCHAR(255) DEFAULT NULL,
     ADD COLUMN `next_user_id` BIGINT UNSIGNED DEFAULT NULL,
     ADD COLUMN `waiting_on` VARCHAR(16) DEFAULT NULL COMMENT "us, customer, supplier, internal",
     ADD COLUMN `waiting_since` DATETIME DEFAULT NULL,
     ADD COLUMN `review_on` DATE DEFAULT NULL,
     ADD COLUMN `review_notified_at` DATETIME DEFAULT NULL,
     ADD COLUMN `next_by` VARCHAR(8) DEFAULT NULL COMMENT "user, ai, rule",
     ADD COLUMN `next_at` DATETIME DEFAULT NULL,
     ADD COLUMN `next_source_message_id` BIGINT UNSIGNED DEFAULT NULL,
     ADD COLUMN `next_prev_json` TEXT DEFAULT NULL,
     ADD COLUMN `next_ai_basis` VARCHAR(64) DEFAULT NULL,
     ADD KEY `idx_ecw_cases_waiting` (`organization_id`, `waiting_on`),
     ADD KEY `idx_ecw_cases_review` (`review_on`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_case_threads' AND COLUMN_NAME = 'party_role') > 0, 'DO 1',
  'ALTER TABLE `ecw_case_threads` ADD COLUMN `party_role` VARCHAR(16) DEFAULT NULL COMMENT "customer, supplier, internal"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

CREATE TABLE IF NOT EXISTS `ecw_case_refs` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`           BIGINT UNSIGNED NOT NULL,
  `kind`              VARCHAR(24) NOT NULL,
  `value`             VARCHAR(120) NOT NULL,
  `value_norm`        VARCHAR(120) NOT NULL COMMENT 'lower case, no spaces or dashes: what search and duplicates compare',
  `source`            VARCHAR(8) NOT NULL DEFAULT 'user' COMMENT 'user or ai',
  `source_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_case_refs` (`case_id`, `value_norm`),
  KEY `idx_ecw_case_refs_value` (`value_norm`),
  CONSTRAINT `fk_ecw_case_refs_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
