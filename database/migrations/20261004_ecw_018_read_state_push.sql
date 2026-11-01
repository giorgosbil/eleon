-- Read/unread changed in the workspace is pushed to Outlook / Gmail by the sync
-- daemon (job type read_state). While a change waits to be pushed, a delta from
-- the provider must not overwrite it with the old value.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_messages' AND COLUMN_NAME='read_push_pending') > 0, 'DO 1', 'ALTER TABLE `ecw_email_messages` ADD COLUMN `read_push_pending` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = is_read changed here, not yet sent to the provider'', ADD KEY `idx_ecw_email_messages_read_push` (`mailbox_id`, `read_push_pending`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
