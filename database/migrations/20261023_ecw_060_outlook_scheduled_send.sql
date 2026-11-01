-- Send later through Outlook itself (lib/compose.php ecw_draft_schedule_in_outlook): Microsoft
-- holds the email with its send time (PR_DEFERRED_SEND_TIME) and sends it then, even when this
-- PC is off. The draft remembers which message Microsoft holds, to cancel or change it.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_drafts' AND COLUMN_NAME='outlook_internet_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_drafts`
     ADD COLUMN `outlook_internet_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT ''Message-ID of the copy Outlook holds to send at send_at'',
     ADD COLUMN `outlook_held_id` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT ''its Graph id when last seen (Outbox / Drafts)'',
     ADD COLUMN `outlook_schedule_dirty` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''changed in the app since: the held copy must be replaced''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
