-- Step 05 — rate limiting for auth and send endpoints.
--
-- Fixed-window counter, one row per (scope, subject, window_start). The
-- window boundary is computed by the application as
-- floor(unix_time / window_seconds) * window_seconds, so this table carries
-- no knowledge of any particular window length — oauth_start, oauth_callback
-- and send can each pick their own without a schema change.
--
-- The increment is a single INSERT ... ON DUPLICATE KEY UPDATE against the
-- UNIQUE key below, so two concurrent requests from the same subject in the
-- same window cannot both read a stale count and both slip through.
CREATE TABLE IF NOT EXISTS `ecw_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`        VARCHAR(64)  NOT NULL,
  `subject`      VARCHAR(191) NOT NULL,
  `window_start` DATETIME     NOT NULL,
  `count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_rate_limits_window` (`scope`, `subject`, `window_start`),
  KEY `idx_ecw_rate_limits_window_start` (`window_start`) COMMENT 'drives the old-window cleanup job, not built yet'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
