-- Scheduled emails go once, whichever copy of the app sends them (lib/compose.php
-- ecw_draft_sent_elsewhere). A copy of the database on a second PC (a backup put on a laptop)
-- sent the same scheduled emails a second time: each copy thought they were still waiting.
--   send_key: set when the email is scheduled, carried by the email itself in Outlook (a hidden
--             property). Before sending or handing it to Outlook, the app asks Outlook whether
--             an email with this key is already sent or held, and then does not send it again.
--   outlook_hold_tried_at: the last try to hand a scheduled email to Outlook. A try that failed
--             (no connection, an antivirus in between) is made again every few minutes, so it
--             does not stay with this PC alone (ecw_drafts_outlook_followup).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_drafts' AND COLUMN_NAME='send_key') > 0, 'DO 1',
  'ALTER TABLE `ecw_drafts`
     ADD COLUMN `send_key` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT ''the email in Outlook carries it: sent once, from any copy of the app'',
     ADD COLUMN `outlook_hold_tried_at` DATETIME DEFAULT NULL COMMENT ''last try to hand the scheduled email to Outlook''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
