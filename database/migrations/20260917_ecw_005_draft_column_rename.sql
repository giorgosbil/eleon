-- Step 03 — rename ecw_drafts.in_reply_to_message_id.
--
-- The column holds a LOCAL ecw_email_messages.id. The column of the same name
-- on ecw_email_messages holds an RFC 5322 Message-ID string. Two columns, one
-- name, two completely different value spaces, one join apart from each other
-- — the schema conformance test tripped over it immediately, which is a fair
-- preview of what it would do to a person.
--
-- Renamed while the table is still empty and has three callers.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_drafts' AND COLUMN_NAME = 'in_reply_to_message_id') > 0,
  'ALTER TABLE `ecw_drafts` CHANGE COLUMN `in_reply_to_message_id` `reply_to_local_message_id` BIGINT UNSIGNED DEFAULT NULL COMMENT "local ecw_email_messages.id this draft answers. NOT an RFC Message-ID"',
  'DO 1');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
