-- Step 04 — case timeline.
--
--
-- occurred_at BECOMES DIRECTION-AWARE
-- ----------------------------------------------------------------------
-- Step 01 defined occurred_at as COALESCE(sent_at, received_at, ...). The
-- timeline step defines the effective timestamp differently, and it is right:
--
--     incoming mail -> received_at
--     outgoing mail -> sent_at
--
-- The difference is not cosmetic. Both columns are populated on an incoming
-- message: sent_at is when the far end pressed send, received_at is when it
-- reached this mailbox. Delivery lag between them runs from seconds to hours
-- (greylisting, queued relays, a supplier's mail server retrying overnight).
--
-- Ordering incoming mail by sent_at therefore places it on the timeline at a
-- moment when nobody here could possibly have seen it — and the whole point of
-- the case timeline is "what did we know, and when". A reply of ours can end up
-- rendered ABOVE the message it answers, which reads as an impossibility and
-- destroys trust in the column exactly where that trust matters most.
--
-- MODIFY, not drop-and-add: MySQL recomputes every existing row and the indexes
-- (idx_..._mailbox_occurred, _thread_occurred, _folder_occurred, _live) survive
-- the rebuild. Verified on 8.0.46 before writing this.
--
-- This also improves the mail list from step 03, which sorts on the same
-- column: a mailbox should order by when mail ARRIVED.
--
--
-- DRAFTS GAIN A CASE
-- ----------------------------------------------------------------------
-- "Start new email conversation" from inside a case has to remember which case
-- it came from: the thread does not exist until the mail is sent and the sent
-- copy syncs back, so the link cannot be made at compose time. The draft
-- carries the case id across that gap, and the send path links the resulting
-- thread once there is a thread to link.
--
-- ON DELETE SET NULL: deleting a case must not delete someone's unsent draft.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_email_messages'
                  AND COLUMN_NAME = 'occurred_at'
                  AND GENERATION_EXPRESSION LIKE '%incoming%') > 0, 'DO 1',
  'ALTER TABLE `ecw_email_messages`
     MODIFY COLUMN `occurred_at` DATETIME GENERATED ALWAYS AS (
        CASE WHEN `direction` = "incoming"
             THEN COALESCE(`received_at`, `sent_at`, `provider_created_at`)
             ELSE COALESCE(`sent_at`, `received_at`, `provider_created_at`) END
     ) STORED COMMENT "effective time: received_at for incoming, sent_at for outgoing. One chronological axis for the case timeline"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_drafts' AND COLUMN_NAME = 'case_id') > 0, 'DO 1',
  'ALTER TABLE `ecw_drafts`
     ADD COLUMN `case_id` BIGINT UNSIGNED DEFAULT NULL COMMENT "link the resulting thread to this case once it is sent",
     ADD KEY `idx_ecw_drafts_case` (`case_id`),
     ADD CONSTRAINT `fk_ecw_drafts_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE SET NULL');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
