-- View profiles: what each person sees (Simple, Standard, Everything, Custom),
-- and the organization's default for people who never chose one.
-- The license key and the trial start live in ecw_app_settings (migration 021).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_users' AND COLUMN_NAME='view_profile') > 0, 'DO 1', 'ALTER TABLE `ecw_users` ADD COLUMN `view_profile` VARCHAR(16) DEFAULT NULL COMMENT ''simple, standard, everything, custom. NULL = organization default'', ADD COLUMN `view_features_json` TEXT DEFAULT NULL COMMENT ''Custom profile: feature => shown''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='default_view_profile') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `default_view_profile` VARCHAR(16) DEFAULT NULL COMMENT ''profile for people who never chose one. NULL = everything''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
