-- One account at a time (lib/account.php). Every page (Mail, Cases, Contacts,
-- Calendar, Teams, Reports, personal settings) shows the account chosen at the
-- top. A case belongs to the account it was opened in (cases.mailbox_id): the
-- mailbox of its first linked conversation, or the account chosen when it was
-- created by hand. A case with a conversation in another account shows there too.
--
-- Mail history comes in two steps (lib/sync/messages.php): the last two years
-- of every folder first, then the older emails in the background.
-- mailbox_sync_state.recent_done_at marks a folder whose two years are in.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_cases' AND COLUMN_NAME = 'mailbox_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases`
     ADD COLUMN `mailbox_id` BIGINT UNSIGNED DEFAULT NULL COMMENT "the account the case belongs to",
     ADD KEY `idx_ecw_cases_mailbox` (`organization_id`, `mailbox_id`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

UPDATE `ecw_cases` c
   SET c.mailbox_id = (SELECT t.mailbox_id FROM `ecw_case_threads` ct JOIN `ecw_email_threads` t ON t.id = ct.email_thread_id
                        WHERE ct.case_id = c.id ORDER BY ct.id LIMIT 1)
 WHERE c.mailbox_id IS NULL;

UPDATE `ecw_cases` c
   SET c.mailbox_id = (SELECT MIN(mb.id) FROM `ecw_mailboxes` mb
                        WHERE mb.organization_id = c.organization_id AND mb.connected_by_user_id = c.created_by_user_id)
 WHERE c.mailbox_id IS NULL;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_mailbox_sync_state' AND COLUMN_NAME = 'recent_done_at') > 0, 'DO 1',
  'ALTER TABLE `ecw_mailbox_sync_state`
     ADD COLUMN `recent_done_at` DATETIME DEFAULT NULL COMMENT "the folder walk reached two years back, older mail waits for the other folders"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_templates' AND COLUMN_NAME = 'mailbox_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_templates`
     ADD COLUMN `mailbox_id` BIGINT UNSIGNED DEFAULT NULL COMMENT "a personal template of one account, NULL for every account"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_rules' AND COLUMN_NAME = 'mailbox_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_rules`
     ADD COLUMN `mailbox_id` BIGINT UNSIGNED DEFAULT NULL COMMENT "a personal rule of one account, NULL for every account"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
