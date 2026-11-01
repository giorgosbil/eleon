-- Snooze reaches the mailbox: a snoozed conversation is moved to a "Snoozed"
-- folder (Gmail: label) in Outlook / Gmail too, and moved back when it wakes.
-- snooze_moves_json keeps where each email was, for the way back.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_threads' AND COLUMN_NAME='snooze_moves_json') > 0, 'DO 1', 'ALTER TABLE `ecw_email_threads` ADD COLUMN `snooze_moves_json` TEXT DEFAULT NULL COMMENT ''emails moved to the Snoozed folder, and where they came from''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_mailboxes' AND COLUMN_NAME='snooze_in_mailbox') > 0, 'DO 1', 'ALTER TABLE `ecw_mailboxes` ADD COLUMN `snooze_in_mailbox` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = snoozing moves mail to a Snoozed folder in Outlook / Gmail''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
