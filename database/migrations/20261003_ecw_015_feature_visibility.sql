-- Which optional features normal (non-admin) members may use. NULL = all on.
-- Admins always see everything. See lib/features.php.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='features_json') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `features_json` JSON DEFAULT NULL COMMENT ''feature => visible to non-admin members''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
