-- Text read from attachments (PDF, Word, Excel, PowerPoint, text) by the
-- background worker (bin/worker.php, lib/attachment_text.php), so search and
-- the AI can look inside files, for example invoice amounts in PDFs.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_attachments' AND COLUMN_NAME='text_content') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_attachments` ADD COLUMN `text_content` MEDIUMTEXT DEFAULT NULL, ADD COLUMN `text_state` VARCHAR(12) DEFAULT NULL COMMENT ''done, empty, failed, skipped, too_big'', ADD COLUMN `text_extracted_at` DATETIME DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_attachments' AND INDEX_NAME='idx_ecw_attachments_text_state') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_attachments` ADD KEY `idx_ecw_attachments_text_state` (`text_state`, `is_inline`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_email_attachments' AND INDEX_NAME='ft_ecw_attachments_text') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_attachments` ADD FULLTEXT KEY `ft_ecw_attachments_text` (`filename`, `text_content`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
