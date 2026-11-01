-- Chats in cases (modules/chatlink), part 3: a chat column can follow its chat
-- (every new message is added to the case), and the old "Teams" tab of the
-- case page (whole Teams chats linked to a case, core tables case_teams_*)
-- becomes chat columns that follow.
-- chat_link_columns.follow / follow_user_id / follow_from: added since
--   follow_from, read with follow_user_id's access (their Teams sign-in, their
--   Telegram); synced_at / sync_error: the last refresh and why it failed.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_chat_link_columns' AND COLUMN_NAME = 'follow') > 0, 'DO 1',
  'ALTER TABLE `ecw_chat_link_columns`
     ADD COLUMN `follow`          TINYINT NOT NULL DEFAULT 0,
     ADD COLUMN `follow_user_id`  BIGINT UNSIGNED DEFAULT NULL,
     ADD COLUMN `follow_from`     DATETIME DEFAULT NULL,
     ADD COLUMN `synced_at`       DATETIME DEFAULT NULL,
     ADD COLUMN `sync_error`      VARCHAR(255) DEFAULT NULL,
     ADD KEY `idx_ecw_chat_link_columns_follow` (`follow`, `synced_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;

-- The old Teams tab's chats: a column each, following the chat with the access of whoever linked it.
INSERT IGNORE INTO `ecw_chat_link_columns` (case_id, source, chat_ref, follow, follow_user_id, follow_from, synced_at, sync_error)
  SELECT t.case_id, 'teams', t.chat_id, IF(t.linked_by_user_id IS NULL, 0, 1), t.linked_by_user_id, '2000-01-01 00:00:00', t.synced_at, t.sync_error
    FROM `ecw_case_teams_chats` t;

-- …and their saved messages. "Ours" when written by one of the workspace's Microsoft accounts.
INSERT IGNORE INTO `ecw_chat_links`
    (organization_id, case_id, source, chat_ref, chat_title, message_ref, owner_user_id, sender, direction, body_text, body_html, filename, sent_at, linked_by_user_id, linked_at)
  SELECT c.organization_id, t.case_id, 'teams', t.chat_id, t.title, m.provider_message_id, NULL, LEFT(COALESCE(m.from_name, ''), 190),
         IF(EXISTS (SELECT 1 FROM `ecw_mailboxes` mb WHERE mb.organization_id = c.organization_id AND mb.provider_account_id = m.from_user_id AND m.from_user_id <> ''), 'out', 'in'),
         TRIM(REGEXP_REPLACE(REGEXP_REPLACE(COALESCE(m.body_html, ''), '<br[^>]*>|</p>|</div>', '\n'), '<[^>]+>', '')),
         m.body_html, NULL, m.created_at, t.linked_by_user_id, t.linked_at
    FROM `ecw_case_teams_messages` m
    JOIN `ecw_case_teams_chats` t ON t.id = m.link_id
    JOIN `ecw_cases` c ON c.id = t.case_id;
