-- Other AI apps reading this workspace through its MCP server (web/mcp.php):
-- Microsoft 365 Copilot signs people in with Microsoft Entra SSO (settings in
-- organizations.copilot_json), any other MCP client with a personal access
-- token. Only the SHA-256 of a token is stored. The token is shown once.
CREATE TABLE IF NOT EXISTS `ecw_ai_tokens` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `name`             VARCHAR(80) NOT NULL,
  `token_hash`       CHAR(64) NOT NULL,
  `token_hint`       VARCHAR(16) NOT NULL COMMENT 'first characters, to tell tokens apart',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`     DATETIME DEFAULT NULL,
  `revoked_at`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_ai_tokens_hash` (`token_hash`),
  KEY `idx_ecw_ai_tokens_user` (`user_id`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='copilot_json') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `copilot_json` TEXT DEFAULT NULL COMMENT ''{enabled, tenant_id, client_id, audiences, reference_id}''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
