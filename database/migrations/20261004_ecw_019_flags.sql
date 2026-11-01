-- Flags (Outlook "flag" / Gmail "star"), kept in step with the provider like
-- read/unread: set here, pushed by the read_state job, and a provider update
-- does not overwrite a change that is still waiting to be pushed.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_messages' AND COLUMN_NAME='is_flagged') > 0, 'DO 1', 'ALTER TABLE `ecw_email_messages` ADD COLUMN `is_flagged` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `flag_push_pending` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- The thread list shows a flag without opening messages (like unread_count).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_threads' AND COLUMN_NAME='flagged_count') > 0, 'DO 1', 'ALTER TABLE `ecw_email_threads` ADD COLUMN `flagged_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
