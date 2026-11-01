-- AI connections (lib/ai.php, Settings → Email assistant): each AI service a
-- workspace connects (the server's Claude, its own Claude key, OpenAI, Gemini,
-- a local Ollama…) is counted here per month: requests, tokens, failures, and
-- the last error, so the settings page shows what each one costs and System
-- health can say when the main one keeps failing and the backup is answering.
-- The connections themselves live in organizations.ai_json.
CREATE TABLE IF NOT EXISTS `ecw_ai_usage` (
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `connection_id`   VARCHAR(40) NOT NULL,
  `month`           CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `requests`        INT UNSIGNED NOT NULL DEFAULT 0,
  `input_tokens`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `failures`        INT UNSIGNED NOT NULL DEFAULT 0,
  `fallbacks`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'answered as the backup',
  `last_error`      VARCHAR(255) DEFAULT NULL,
  `last_error_at`   DATETIME DEFAULT NULL,
  `last_used_at`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`organization_id`, `connection_id`, `month`),
  CONSTRAINT `fk_ecw_ai_usage_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
