-- The AI agents (lib/agents.php, run by bin/worker.php): they read new mail
-- and cases in the background and leave suggestions people accept or dismiss.
-- agent_runs: which email / case an agent already handled (each only once).
-- ai_suggestions: what they suggest, with one-click actions and the evidence.
-- case_ai_summaries: the living summary of each case.
CREATE TABLE IF NOT EXISTS `ecw_agent_runs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `agent`            VARCHAR(30) NOT NULL,
  `subject_type`     VARCHAR(20) NOT NULL COMMENT 'message, case, user, thread',
  `subject_id`       BIGINT UNSIGNED NOT NULL,
  `state`            VARCHAR(12) NOT NULL DEFAULT 'done' COMMENT 'done, skipped, failed',
  `detail`           VARCHAR(500) DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_agent_runs` (`agent`, `subject_type`, `subject_id`),
  KEY `idx_ecw_agent_runs_org` (`organization_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_ai_suggestions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL COMMENT 'who sees it',
  `agent`            VARCHAR(30) NOT NULL,
  `kind`             VARCHAR(30) NOT NULL,
  `thread_id`        BIGINT UNSIGNED DEFAULT NULL,
  `case_id`          BIGINT UNSIGNED DEFAULT NULL,
  `message_id`       BIGINT UNSIGNED DEFAULT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `body`             TEXT DEFAULT NULL,
  `facts_json`       TEXT DEFAULT NULL COMMENT 'label/value pairs the agent found',
  `actions_json`     TEXT DEFAULT NULL COMMENT 'the one-click actions offered',
  `confidence`       TINYINT UNSIGNED NOT NULL DEFAULT 50,
  `state`            VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open, accepted, dismissed, expired',
  `result`           VARCHAR(255) DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`       DATETIME DEFAULT NULL,
  `expires_at`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_sugg_user` (`user_id`, `state`, `created_at`),
  KEY `idx_ecw_sugg_thread` (`thread_id`, `state`),
  KEY `idx_ecw_sugg_case` (`case_id`, `state`),
  KEY `idx_ecw_sugg_org_kind` (`organization_id`, `kind`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_ai_summaries` (
  `case_id`          BIGINT UNSIGNED NOT NULL,
  `summary`          TEXT NOT NULL,
  `based_on`         VARCHAR(80) DEFAULT NULL COMMENT 'newest email and note it saw',
  `model`            VARCHAR(80) DEFAULT NULL,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='agents_json') > 0, 'DO 1',
  'ALTER TABLE `ecw_organizations` ADD COLUMN `agents_json` TEXT DEFAULT NULL COMMENT ''which AI agents run, and their options''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
