-- Case number and case tags as Outlook categories (Gmail: labels "Case/…") on
-- every email of a case. app_categories_json remembers what the app put there,
-- so categories people set themselves in Outlook are never touched.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_messages' AND COLUMN_NAME='categories_push_pending') > 0, 'DO 1', 'ALTER TABLE `ecw_email_messages` ADD COLUMN `app_categories_json` TEXT DEFAULT NULL COMMENT ''categories the app set on this email in the mailbox'', ADD COLUMN `categories_push_pending` TINYINT(1) NOT NULL DEFAULT 0, ADD KEY `idx_ecw_messages_categories_push` (`mailbox_id`, `categories_push_pending`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

-- Everything already in a case gets its categories once.
UPDATE `ecw_email_messages` m JOIN `ecw_case_threads` ct ON ct.email_thread_id = m.email_thread_id
   SET m.categories_push_pending = 1
 WHERE m.provider_removed_at IS NULL AND m.direction <> 'draft';
