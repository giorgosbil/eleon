-- Shared drafts: the author can let colleagues who see the conversation (or the
-- case) open the draft and edit it, one at a time: whoever has it open holds a
-- short edit lock (renewed every few seconds), the others read along.
-- version counts saves, so a stale save from a second window is refused.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_drafts' AND COLUMN_NAME='shared_at') > 0, 'DO 1', 'ALTER TABLE `ecw_drafts` ADD COLUMN `shared_at` DATETIME DEFAULT NULL COMMENT ''set = colleagues who see the conversation or case may open and edit it'', ADD COLUMN `shared_by_user_id` BIGINT UNSIGNED DEFAULT NULL, ADD COLUMN `editing_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT ''who holds the edit lock'', ADD COLUMN `editing_at` DATETIME DEFAULT NULL COMMENT ''lock heartbeat. Older than 40 s = free'', ADD COLUMN `last_edited_by_user_id` BIGINT UNSIGNED DEFAULT NULL, ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 0, ADD KEY `idx_ecw_drafts_shared` (`shared_at`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
