-- How many emails Microsoft says each folder holds (totalItemCount), kept at
-- every folder scan. Settings → Mailbox → Folders to sync shows "1,234 of
-- 1,240" per folder and a tick when the folder is complete, so a person can
-- see that every folder synchronized correctly.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_mail_folders' AND COLUMN_NAME = 'total_count') > 0, 'DO 1',
  'ALTER TABLE `ecw_mail_folders`
     ADD COLUMN `total_count` INT UNSIGNED DEFAULT NULL COMMENT "emails in the folder according to the provider, at the last folder scan",
     ADD COLUMN `total_checked_at` DATETIME DEFAULT NULL');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
