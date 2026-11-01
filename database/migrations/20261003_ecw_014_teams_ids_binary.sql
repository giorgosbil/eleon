-- Provider ids are case-sensitive (see migration 001, "provider ids"): Teams
-- message and chat ids must compare byte for byte, like every other provider id.
ALTER TABLE `ecw_case_teams_messages`
  MODIFY `provider_message_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE `ecw_case_teams_chats`
  MODIFY `chat_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
