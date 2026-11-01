-- Step 03 — cached latest-thread metadata for the message list.
--
-- WHY THESE COLUMNS EXIST
-- ----------------------------------------------------------------------
-- The step asks for "cached latest-thread metadata" and forbids rendering
-- 20,000 rows. Without a cache, one screen of the thread list needs, per row:
-- the newest message (to show who and when), a slice of its body (the preview),
-- a COUNT of unread messages, and an EXISTS for attachments. That is four
-- correlated subqueries per row, and the preview one reads a LONGTEXT column
-- off-page for every row on the page.
--
-- These columns move that work to write time, where it happens once per
-- arriving message instead of once per page view. ecw_thread_refresh_counters()
-- is the single writer, and it already ran on every ingest — it now fills these
-- too, so there is no new place for the cache to go stale.
--
-- latest_preview is VARCHAR(255) rather than a slice of body_text taken at read
-- time, so the list query never touches a body column at all.
--
-- Everything here is derived. Truncate these columns and re-run
-- ecw_thread_refresh_counters() for every thread and the cache is rebuilt --
-- which is what bin/rebuild_thread_cache.php does.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_threads' AND COLUMN_NAME = 'latest_preview') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_threads`
     ADD COLUMN `latest_message_id` BIGINT UNSIGNED DEFAULT NULL COMMENT "newest non-removed message in this thread",
     ADD COLUMN `latest_from_email` VARCHAR(320) DEFAULT NULL,
     ADD COLUMN `latest_from_name`  VARCHAR(255) DEFAULT NULL,
     ADD COLUMN `latest_preview`    VARCHAR(255) DEFAULT NULL COMMENT "plain-text snippet of the newest message, quoted history excluded",
     ADD COLUMN `latest_direction`  VARCHAR(16)  DEFAULT NULL,
     ADD COLUMN `unread_count`      INT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN `has_attachments`   TINYINT(1) NOT NULL DEFAULT 0,
     ADD KEY `idx_ecw_threads_unread` (`mailbox_id`, `unread_count`, `latest_message_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
