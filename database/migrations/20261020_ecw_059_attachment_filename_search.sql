-- Search by file name (lib/search.php): only real files are looked at (not the pictures inside
-- emails, nine in ten attachments), through this index alone. It holds the whole file name, so
-- the lookup never reads the attachment rows with their extracted text (which do not stay in
-- memory on a large mailbox): about 0.7 s less per searched word on 270,000 attachments.
-- An earlier version of this index held only the first 191 characters: it is replaced.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_attachments' AND INDEX_NAME='idx_ecw_attachments_files' AND SUB_PART IS NOT NULL) > 0,
  'ALTER TABLE `ecw_email_attachments` DROP KEY `idx_ecw_attachments_files`', 'DO 1');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_attachments' AND INDEX_NAME='idx_ecw_attachments_files') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_attachments` ADD KEY `idx_ecw_attachments_files` (`is_inline`, `filename`, `email_message_id`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
