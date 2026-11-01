-- Speed at large volumes (tools/perf/bench.php, 60,000-email mailbox).
-- idx_ecw_messages_folder_unread: the unread number next to every folder is a
--   COUNT that used to walk every message of the folder; now it reads only the
--   unread ones.
-- idx_ecw_messages_thread_folder: "is this conversation in this folder?" is
--   asked for each conversation while the list walks newest-first; one probe.
-- idx_ecw_messages_unread_threads: is:unread answered from the index alone
--   (which conversations have an unread incoming email), no email rows read.
-- ft_ecw_participants_people: a plain search word is also looked for in the
--   senders' and recipients' names and addresses; LIKE '%word%' read all of them.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages' AND INDEX_NAME = 'idx_ecw_messages_folder_unread') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages` ADD KEY `idx_ecw_messages_folder_unread` (`mail_folder_id`, `is_read`, `direction`, `provider_removed_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages' AND INDEX_NAME = 'idx_ecw_messages_thread_folder') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages` ADD KEY `idx_ecw_messages_thread_folder` (`email_thread_id`, `mail_folder_id`, `provider_removed_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_message_participants' AND INDEX_NAME = 'ft_ecw_participants_people') > 0, 'DO 1',
  'ALTER TABLE `ecw_message_participants` ADD FULLTEXT KEY `ft_ecw_participants_people` (`name`, `email`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages' AND INDEX_NAME = 'idx_ecw_messages_unread_threads') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages` ADD KEY `idx_ecw_messages_unread_threads` (`mailbox_id`, `is_read`, `direction`, `provider_removed_at`, `email_thread_id`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;