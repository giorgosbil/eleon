-- AI review of a case (the review icon next to Next step, lib/case_review.php).
-- The last review of each case is kept, with what it was based on (newest email
-- and note), so opening it again costs nothing until something new arrives.
CREATE TABLE IF NOT EXISTS `ecw_case_reviews` (
  `case_id`     BIGINT UNSIGNED NOT NULL,
  `based_on`    VARCHAR(80) NOT NULL COMMENT 'newest email, note and next step it saw',
  `review_json` MEDIUMTEXT NOT NULL,
  `model`       VARCHAR(80) DEFAULT NULL,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT 'who asked for it',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`),
  CONSTRAINT `fk_ecw_case_reviews_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
