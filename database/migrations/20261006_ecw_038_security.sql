-- Security (Settings → Security):
-- users.sso_subject: the Microsoft account a person signs in with ("tenant:object id").
-- organizations.security_json: Microsoft sign-in, retention and backup options.
-- cases.legal_hold_*: a held case cannot be deleted, its emails cannot be
-- deleted for good, and retention never removes anything from it.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_users' AND COLUMN_NAME='sso_subject') > 0, 'DO 1',
  'ALTER TABLE `ecw_users` ADD COLUMN `sso_subject` VARCHAR(120) DEFAULT NULL COMMENT ''Microsoft sign-in: tenant id:object id'', ADD UNIQUE KEY `uq_ecw_users_sso` (`sso_subject`)');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='security_json') > 0, 'DO 1',
  'ALTER TABLE `ecw_organizations` ADD COLUMN `security_json` TEXT DEFAULT NULL COMMENT ''Microsoft sign-in, retention, backup encryption''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_cases' AND COLUMN_NAME='legal_hold_at') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases` ADD COLUMN `legal_hold_at` DATETIME DEFAULT NULL, ADD COLUMN `legal_hold_by_user_id` BIGINT UNSIGNED DEFAULT NULL, ADD COLUMN `legal_hold_note` VARCHAR(255) DEFAULT NULL');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
