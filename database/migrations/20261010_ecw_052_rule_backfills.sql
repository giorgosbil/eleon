-- Automation rules on earlier emails (Settings → Rules → Run on earlier emails,
-- lib/rule_backfill.php). One row per run: the emails it chose when started
-- (one per conversation), how far the background worker got, and what it did.
CREATE TABLE IF NOT EXISTS `ecw_rule_backfills` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `rule_id`         BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT 'who started it',
  `date_from`       DATE NOT NULL,
  `date_to`         DATE NOT NULL,
  `close_cases`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cases it opens are closed (history only)',
  `items_json`      MEDIUMTEXT NOT NULL COMMENT '[[thread_id, message_id], ...] in date order',
  `total`           INT UNSIGNED NOT NULL DEFAULT 0,
  `done`            INT UNSIGNED NOT NULL DEFAULT 0,
  `acted`           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'conversations where an action did something',
  `failed`          INT UNSIGNED NOT NULL DEFAULT 0,
  `status`          VARCHAR(12) NOT NULL DEFAULT 'running' COMMENT 'running, done, cancelled',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`     DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_rule_backfills_status` (`status`, `id`),
  KEY `idx_ecw_rule_backfills_rule` (`rule_id`, `id`),
  CONSTRAINT `fk_ecw_rule_backfills_rule` FOREIGN KEY (`rule_id`) REFERENCES `ecw_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
