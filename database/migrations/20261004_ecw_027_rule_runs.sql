-- Automation rules: one row each time a rule acted on an email (what it did).
-- Shown as "ran 12 times, last 2 h ago", and it stops an automatic reply from
-- going to the same conversation twice.
CREATE TABLE IF NOT EXISTS `ecw_rule_runs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`     BIGINT UNSIGNED NOT NULL,
  `thread_id`   BIGINT UNSIGNED DEFAULT NULL,
  `message_id`  BIGINT UNSIGNED DEFAULT NULL,
  `did_json`    TEXT DEFAULT NULL COMMENT 'the actions that ran, and any that failed',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_rule_runs_rule` (`rule_id`, `created_at`),
  KEY `idx_ecw_rule_runs_thread` (`thread_id`, `rule_id`),
  CONSTRAINT `fk_ecw_rule_runs_rule` FOREIGN KEY (`rule_id`) REFERENCES `ecw_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_rule_runs_thread` FOREIGN KEY (`thread_id`) REFERENCES `ecw_email_threads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
