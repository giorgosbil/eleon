-- Support agent (modules/support_agent): where the support agent's API is and its
-- token (encrypted), per workspace. organizations.support_agent_json:
--   url, token_enc, last_ok_at, last_error.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_organizations' AND COLUMN_NAME = 'support_agent_json') = 0,
  'ALTER TABLE `ecw_organizations` ADD COLUMN `support_agent_json` TEXT DEFAULT NULL',
  'SELECT 1');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
