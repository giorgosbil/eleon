-- Case groups (Support, Sales, ...), per-case visibility and members, and
-- users' Outlook photos.
--
-- ecw_cases.visibility: 'organization' (every member of the workspace, the
-- behaviour before this migration and still the default) or 'members' (only
-- the creator, Responsible, Handled by, people added to the case, and members
-- of the case's group). Existing cases keep 'organization'.

CREATE TABLE IF NOT EXISTS `ecw_case_groups` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`    BIGINT UNSIGNED NOT NULL,
  `name`               VARCHAR(80) NOT NULL,
  `color`              VARCHAR(7) DEFAULT NULL COMMENT '#rrggbb label color',
  `created_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_case_groups_name` (`organization_id`, `name`),
  CONSTRAINT `fk_ecw_case_groups_org`  FOREIGN KEY (`organization_id`)    REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_groups_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `ecw_users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_group_members` (
  `group_id` BIGINT UNSIGNED NOT NULL,
  `user_id`  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`group_id`, `user_id`),
  KEY `idx_ecw_case_group_members_user` (`user_id`),
  CONSTRAINT `fk_ecw_cgm_group` FOREIGN KEY (`group_id`) REFERENCES `ecw_case_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_cgm_user`  FOREIGN KEY (`user_id`)  REFERENCES `ecw_users` (`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_members` (
  `case_id`          BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `added_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `added_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`, `user_id`),
  KEY `idx_ecw_case_members_user` (`user_id`),
  CONSTRAINT `fk_ecw_case_members_case`  FOREIGN KEY (`case_id`)          REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_members_user`  FOREIGN KEY (`user_id`)          REFERENCES `ecw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_members_adder` FOREIGN KEY (`added_by_user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_cases' AND COLUMN_NAME='visibility') > 0, 'DO 1', 'ALTER TABLE `ecw_cases` ADD COLUMN `visibility` VARCHAR(16) NOT NULL DEFAULT ''organization'', ADD COLUMN `group_id` BIGINT UNSIGNED DEFAULT NULL, ADD KEY `idx_ecw_cases_group` (`group_id`), ADD CONSTRAINT `fk_ecw_cases_group` FOREIGN KEY (`group_id`) REFERENCES `ecw_case_groups` (`id`) ON DELETE SET NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_users' AND COLUMN_NAME='avatar_hash') > 0, 'DO 1', 'ALTER TABLE `ecw_users` ADD COLUMN `avatar_hash` CHAR(16) DEFAULT NULL COMMENT ''NULL = no Outlook photo'', ADD COLUMN `avatar_checked_at` DATETIME DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
