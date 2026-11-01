-- Unread notes are decided by note id, not by time: two notes in the same
-- second must not hide each other. (Fresh installs get the column from 024.)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_note_reads' AND COLUMN_NAME='last_note_id') > 0, 'DO 1', 'ALTER TABLE `ecw_note_reads` ADD COLUMN `last_note_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''newest note of the conversation this person has seen''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
