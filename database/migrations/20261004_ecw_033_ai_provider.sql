-- AI provider per workspace: the built-in Claude key from the server, or an
-- OpenAI-compatible endpoint the administrator chooses (Ollama, LiteLLM, a
-- company gateway). The endpoint key is stored encrypted (lib/crypto.php).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='ai_json') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `ai_json` TEXT DEFAULT NULL COMMENT ''{provider, base_url, model, key_enc, daily_requests}''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
