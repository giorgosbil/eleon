-- Interface language per person: en, el. NULL = follow the browser's language.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_users' AND COLUMN_NAME='language') > 0, 'DO 1', 'ALTER TABLE `ecw_users` ADD COLUMN `language` VARCHAR(5) DEFAULT NULL COMMENT ''en, el. NULL = the browser language''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
