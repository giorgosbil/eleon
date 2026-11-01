-- Solutions memory (lib/case_memory.php, the Solutions memory agent).
-- case_resolutions: when a case is closed the AI reads it and writes what the
--   problem was and how it was solved, a few keywords, and whether it was
--   solved at all (a spam or duplicate case teaches nothing). People can
--   correct it. state: pending (waiting for the AI), done, failed.
-- case_similar: "this might be the same issue as case N", found for open
--   cases by searching the closed cases' summaries and checked by the AI.
--   The team answers Yes or No, and a No is remembered so the pair is never
--   suggested again.

CREATE TABLE IF NOT EXISTS `ecw_case_resolutions` (
  `case_id`            BIGINT UNSIGNED NOT NULL,
  `organization_id`    BIGINT UNSIGNED NOT NULL,
  `state`              VARCHAR(8) NOT NULL DEFAULT 'pending' COMMENT 'pending, done, failed',
  `problem`            TEXT DEFAULT NULL,
  `solution`           TEXT DEFAULT NULL,
  `keywords`           VARCHAR(500) DEFAULT NULL,
  `solved`             TINYINT(1) NOT NULL DEFAULT 1,
  `written_by`         VARCHAR(8) NOT NULL DEFAULT 'ai' COMMENT 'ai, user',
  `edited_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `model`              VARCHAR(120) DEFAULT NULL,
  `error`              VARCHAR(255) DEFAULT NULL,
  `attempts`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`),
  KEY `idx_ecw_case_resolutions_todo` (`state`, `organization_id`),
  FULLTEXT KEY `ft_ecw_case_resolutions` (`problem`, `solution`, `keywords`),
  CONSTRAINT `fk_ecw_case_resolutions_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_resolutions_org` FOREIGN KEY (`organization_id`) REFERENCES `ecw_organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecw_case_similar` (
  `case_id`             BIGINT UNSIGNED NOT NULL COMMENT 'the open case',
  `similar_case_id`     BIGINT UNSIGNED NOT NULL COMMENT 'the closed case it looks like',
  `organization_id`     BIGINT UNSIGNED NOT NULL,
  `confidence`          DECIMAL(3,2) NOT NULL DEFAULT 0.50,
  `reason`              VARCHAR(255) DEFAULT NULL,
  `state`               VARCHAR(8) NOT NULL DEFAULT 'open' COMMENT 'open, yes, no, ai_no (the AI judged it different: never shown)',
  `decided_by_user_id`  BIGINT UNSIGNED DEFAULT NULL,
  `decided_at`          DATETIME DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`, `similar_case_id`),
  KEY `idx_ecw_case_similar_back` (`similar_case_id`),
  CONSTRAINT `fk_ecw_case_similar_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ecw_case_similar_other` FOREIGN KEY (`similar_case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_cases' AND COLUMN_NAME = 'similar_basis') > 0, 'DO 1',
  'ALTER TABLE `ecw_cases` ADD COLUMN `similar_basis` BIGINT UNSIGNED DEFAULT NULL COMMENT "newest email id the similar-case search last looked at"');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
