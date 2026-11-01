-- Task 07 — case settings: rename and delete.
--
-- Hard deletes are banned in this project. Deleting a case removes the
-- grouping only — case_threads rows, and the emails they point at, are
-- never touched, so restoring a case brings everything back exactly as it
-- was. Two nullable columns are enough to model that: deleted_at is the
-- soft-delete flag every case query now filters on, deleted_by_user_id is
-- the audit "who", matching the ON DELETE SET NULL pattern every other
-- actor column on this table already uses (owner_user_id, created_by_user_id).
--
-- idx_ecw_cases_deleted is a genuine addition (not covered by the existing
-- (organization_id, status, updated_at) index), because every remaining
-- case query now filters deleted_at first, ahead of status.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_cases' AND COLUMN_NAME = 'deleted_at') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases`
     ADD COLUMN `deleted_at` DATETIME DEFAULT NULL COMMENT "soft delete only. NULL = active. Never a hard DELETE",
     ADD COLUMN `deleted_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
     ADD KEY `idx_ecw_cases_deleted` (`organization_id`, `deleted_at`),
     ADD CONSTRAINT `fk_ecw_cases_deleted_by` FOREIGN KEY (`deleted_by_user_id`) REFERENCES `ecw_users` (`id`) ON DELETE SET NULL');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
