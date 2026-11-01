-- One-time correction for migration 048: emails whose attachments were already
-- listed before attachments_state existed were marked 'pending' when a sync
-- passed over them again. They are complete: mark them 'done' so their
-- attachments are not fetched again and the sync spinner can stop.

UPDATE `ecw_email_messages` m
   SET m.attachments_state = 'done'
 WHERE m.attachments_state = 'pending'
   AND EXISTS (SELECT 1 FROM `ecw_email_attachments` a WHERE a.email_message_id = m.id);
