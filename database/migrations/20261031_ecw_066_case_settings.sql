-- Case settings (Settings → Case settings, lib/case_settings.php):
--   organizations.case_settings_json: the company's own statuses (each belongs to Open, Waiting
--             or Closed), conversation roles, overdue timers, the case number format, tag colours.
--   cases.sub_status: the company's own status of a case ("Waiting for parts"); cases.status
--             keeps its group (open, waiting, closed), which everything else goes by.
--   cases.status_changed_at: when the status last changed ("in Waiting for more than X days").
-- saved_filters: filters of the Cases list saved under a name, a person's own or shared.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='case_settings_json') > 0, 'DO 1',
  'ALTER TABLE `ecw_organizations`
     ADD COLUMN `case_settings_json` TEXT DEFAULT NULL COMMENT ''statuses, roles, overdue timers, case numbers, tag colours''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_cases' AND COLUMN_NAME='sub_status') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases`
     ADD COLUMN `sub_status` VARCHAR(32) DEFAULT NULL COMMENT ''the own status of the company, status keeps its group'',
     ADD COLUMN `status_changed_at` DATETIME DEFAULT NULL COMMENT ''when the status last changed''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- Cases from before: their last change is the best guess of when the status changed.
UPDATE `ecw_cases` SET `status_changed_at` = COALESCE(`closed_at`, `updated_at`, `created_at`) WHERE `status_changed_at` IS NULL;

CREATE TABLE IF NOT EXISTS `ecw_saved_filters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'who saved it',
  `name` VARCHAR(80) NOT NULL,
  `query` VARCHAR(2000) NOT NULL COMMENT 'the Cases list address after ?',
  `shared` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: every colleague sees it',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_saved_filters_org` (`organization_id`, `shared`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
