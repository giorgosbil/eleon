-- Smooth sync (lib/sync/messages.php): the import and delta pages now bring each
-- email's text with the list, so conversations open complete. Attachments
-- (the file list and pictures inside the text) still need one extra call per
-- email: attachments_state = 'pending' marks the ones still to fetch; the
-- background job takes them newest first and sets 'done' (or 'failed').
-- NULL: nothing to fetch (no attachments), or fetched with the full message.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages' AND COLUMN_NAME = 'attachments_state') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages`
     ADD COLUMN `attachments_state` VARCHAR(8) DEFAULT NULL COMMENT "pending, done, failed",
     ADD KEY `idx_ecw_email_messages_att_state` (`mailbox_id`, `attachments_state`, `occurred_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
