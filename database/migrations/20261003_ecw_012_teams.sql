-- Microsoft Teams: per-user opt-in chat access and per-organization channel
-- notifications.
--
-- Teams is opt-in per mailbox (the user's own Microsoft connection). The mail
-- token refresh keeps asking only for mail scopes, so an account that never
-- enabled Teams, or whose tenant refuses the Teams consent, keeps syncing mail
-- exactly as before. The Teams access token is fetched with the same refresh
-- token but a Teams-only scope, and cached in its own columns.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_mailboxes' AND COLUMN_NAME='teams_enabled') > 0, 'DO 1', 'ALTER TABLE `ecw_mailboxes` ADD COLUMN `teams_enabled` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `teams_token_encrypted` MEDIUMTEXT DEFAULT NULL, ADD COLUMN `teams_token_expires_at` DATETIME DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='teams_webhook_encrypted') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `teams_webhook_encrypted` TEXT DEFAULT NULL COMMENT ''Teams Workflows webhook URL for case notifications. Encrypted: the URL is a bearer credential'', ADD COLUMN `teams_notify_json` JSON DEFAULT NULL COMMENT ''which case events are posted''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
