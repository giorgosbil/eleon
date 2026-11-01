-- Notes on email conversations, optionally about one email.
--
-- A note now belongs to EITHER
--   a conversation (thread_id; message_id = the email it is about, optional) — it
--   follows the thread: visible wherever the thread is, including every case the
--   thread is linked to, now or later; OR
--   a case as a whole (case_id, thread_id NULL) — shown across the case timeline.
ALTER TABLE `ecw_case_notes` MODIFY `case_id` BIGINT UNSIGNED DEFAULT NULL;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_case_notes' AND COLUMN_NAME='thread_id') > 0, 'DO 1', 'ALTER TABLE `ecw_case_notes` ADD COLUMN `thread_id` BIGINT UNSIGNED DEFAULT NULL AFTER `case_id`, ADD COLUMN `message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `thread_id`, ADD KEY `idx_ecw_case_notes_thread` (`thread_id`, `created_at`), ADD CONSTRAINT `fk_ecw_case_notes_thread` FOREIGN KEY (`thread_id`) REFERENCES `ecw_email_threads` (`id`) ON DELETE CASCADE, ADD CONSTRAINT `fk_ecw_case_notes_message` FOREIGN KEY (`message_id`) REFERENCES `ecw_email_messages` (`id`) ON DELETE SET NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
