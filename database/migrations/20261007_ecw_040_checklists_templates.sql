-- Checklists on cases, and case templates (Settings → Case templates).
-- case_checklist: the steps of one case (who, until when, done by whom).
-- case_templates: a ready-made case — checklist steps (with "+N days" and
--   who does them), tags, due date and group — picked when a case is created
--   or applied to an existing case.
CREATE TABLE IF NOT EXISTS `ecw_case_checklist` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`          BIGINT UNSIGNED NOT NULL,
  `position`         INT NOT NULL DEFAULT 0,
  `text`             VARCHAR(255) NOT NULL,
  `assignee_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `due_date`         DATE DEFAULT NULL,
  `done_at`          DATETIME DEFAULT NULL,
  `done_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `template_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'the template it came from',
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reminded_at`      DATETIME DEFAULT NULL COMMENT 'the assignee was told it is due',
  PRIMARY KEY (`id`),
  KEY `idx_ecw_checklist_case` (`case_id`, `position`),
  KEY `idx_ecw_checklist_due` (`done_at`, `due_date`),
  CONSTRAINT `fk_ecw_checklist_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_templates` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `name`             VARCHAR(120) NOT NULL,
  `description`      VARCHAR(500) DEFAULT NULL,
  `steps_json`       TEXT DEFAULT NULL COMMENT '[{text, days, who: owner|handler|null}]',
  `tags_json`        TEXT DEFAULT NULL,
  `due_days`         INT DEFAULT NULL,
  `group_id`         BIGINT UNSIGNED DEFAULT NULL,
  `name_prefix`      VARCHAR(60) DEFAULT NULL COMMENT 'e.g. "Complaint · " in front of the case name',
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `archived_at`      DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_case_templates_org` (`organization_id`, `archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_cases' AND COLUMN_NAME='template_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases` ADD COLUMN `template_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
