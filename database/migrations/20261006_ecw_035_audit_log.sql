-- Audit log: who did what, when, from where. Written by lib/audit.php,
-- read in Settings, Audit log (admins, Business package). Never holds email
-- content, passwords or tokens: only the action, its target and short details.
CREATE TABLE IF NOT EXISTS `ecw_audit_log` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id`  BIGINT UNSIGNED DEFAULT NULL,
  `user_id`          BIGINT UNSIGNED DEFAULT NULL,
  `action`           VARCHAR(60) NOT NULL COMMENT 'e.g. login.ok, case.delete, attachment.download',
  `target_type`      VARCHAR(30) DEFAULT NULL,
  `target_id`        BIGINT UNSIGNED DEFAULT NULL,
  `detail`           VARCHAR(1000) DEFAULT NULL COMMENT 'short JSON, no content',
  `via`              VARCHAR(20) NOT NULL DEFAULT 'web' COMMENT 'web, api, copilot, token, worker, cli',
  `ip`               VARCHAR(45) DEFAULT NULL,
  `user_agent`       VARCHAR(200) DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecw_audit_org_time` (`organization_id`, `created_at`),
  KEY `idx_ecw_audit_target` (`target_type`, `target_id`),
  KEY `idx_ecw_audit_user_time` (`user_id`, `created_at`),
  KEY `idx_ecw_audit_action` (`organization_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
