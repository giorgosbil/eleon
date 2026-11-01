-- Error reporting (lib/errors.php): every crash on the server and every
-- script error in someone's browser, grouped by kind (fingerprint), with how
-- often and when. Shown in Settings → System health; admins are told by email
-- the first time a kind appears, and again when a fixed one comes back.
CREATE TABLE IF NOT EXISTS `ecw_error_reports` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fingerprint`  CHAR(40) NOT NULL,
  `source`       VARCHAR(12) NOT NULL COMMENT 'server, daemon, browser',
  `kind`         VARCHAR(120) NOT NULL,
  `message`      VARCHAR(500) NOT NULL,
  `location`     VARCHAR(255) DEFAULT NULL COMMENT 'file:line, or the page for a browser error',
  `detail`       TEXT DEFAULT NULL COMMENT 'stack trace, secrets removed',
  `count`        INT UNSIGNED NOT NULL DEFAULT 1,
  `first_at`     DATETIME NOT NULL,
  `last_at`      DATETIME NOT NULL,
  `last_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `last_url`     VARCHAR(255) DEFAULT NULL,
  `notified_at`  DATETIME DEFAULT NULL,
  `resolved_at`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_error_reports_fp` (`fingerprint`),
  KEY `idx_ecw_error_reports_open` (`resolved_at`, `last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
