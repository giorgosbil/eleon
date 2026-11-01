-- Contacts and companies. People are the addresses already seen on mail
-- (ecw_email_addresses), companies their email domains. These columns and the
-- companies table hold what colleagues add by hand: a proper name, phone,
-- job title and notes.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_addresses' AND COLUMN_NAME='edited_name') > 0, 'DO 1', 'ALTER TABLE `ecw_email_addresses` ADD COLUMN `edited_name` VARCHAR(255) DEFAULT NULL COMMENT ''name set by a colleague, wins over display_name'', ADD COLUMN `phone` VARCHAR(60) DEFAULT NULL, ADD COLUMN `job_title` VARCHAR(120) DEFAULT NULL, ADD COLUMN `notes` TEXT DEFAULT NULL, ADD COLUMN `edited_by_user_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

CREATE TABLE IF NOT EXISTS `ecw_companies` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`   BIGINT UNSIGNED NOT NULL,
  `domain`            VARCHAR(255) NOT NULL,
  `name`              VARCHAR(255) DEFAULT NULL,
  `phone`             VARCHAR(60) DEFAULT NULL,
  `website`           VARCHAR(255) DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `edited_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_companies_domain` (`organization_id`, `domain`),
  CONSTRAINT `fk_ecw_companies_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
