-- Deals: the sales and purchasing documents of a case (lib/deals.php), found in its
-- emails by smart matching and, with the AI assistant, read by the AI:
-- RFQ → offer (with its revisions) → PO → order confirmation → delivery → invoice,
-- for what we sell (side "sell": a customer's RFQ, our offer, their PO) and what we
-- buy (side "buy": our RFQ, a supplier's offer, our PO). One row per document found
-- in one email; a person's correction (locked) is never overwritten.
CREATE TABLE IF NOT EXISTS `ecw_deal_docs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `case_id`         BIGINT UNSIGNED NOT NULL,
  `thread_id`       BIGINT UNSIGNED DEFAULT NULL,
  `message_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'the email it is in (Go to email)',
  `attachment_id`   BIGINT UNSIGNED DEFAULT NULL COMMENT 'the file it is, when it is one (offer PDF, PO)',
  `side`            VARCHAR(4)  NOT NULL COMMENT 'sell | buy',
  `stage`           VARCHAR(12) NOT NULL COMMENT 'rfq | offer | po | confirm | delivery | invoice',
  `ref`             VARCHAR(120) DEFAULT NULL COMMENT 'its number as written: Q-2026-014, PO 4500123',
  `ref_norm`        VARCHAR(120) DEFAULT NULL,
  `revision`        SMALLINT UNSIGNED DEFAULT NULL COMMENT 'as written (Rev 2), otherwise counted by date',
  `amount`          DECIMAL(14,2) DEFAULT NULL,
  `currency`        CHAR(3) DEFAULT NULL,
  `doc_date`        DATETIME DEFAULT NULL COMMENT 'when the email was sent or received',
  `valid_until`     DATE DEFAULT NULL,
  `delivery_date`   DATE DEFAULT NULL,
  `tracking`        VARCHAR(120) DEFAULT NULL,
  `summary`         VARCHAR(600) DEFAULT NULL,
  `items_json`      TEXT DEFAULT NULL COMMENT '[{"desc","qty","unit","price"}]',
  `source`          VARCHAR(5)  NOT NULL DEFAULT 'rules' COMMENT 'rules | ai | user',
  `confidence`      TINYINT UNSIGNED NOT NULL DEFAULT 50,
  `locked`          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'a person corrected it: kept as they left it',
  `dismissed`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '"Not this": hidden, and not found again',
  `edited_by`       BIGINT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ecw_deal_docs_msg` (`case_id`, `message_id`, `stage`),
  KEY `idx_ecw_deal_docs_case` (`case_id`, `side`, `stage`),
  KEY `idx_ecw_deal_docs_ref` (`organization_id`, `ref_norm`),
  CONSTRAINT `fk_ecw_deal_docs_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the matching last saw per case (newest email, files read), so it only runs again
-- when something new arrived; and when the AI last read it.
CREATE TABLE IF NOT EXISTS `ecw_deal_state` (
  `case_id`     BIGINT UNSIGNED NOT NULL,
  `rules_basis` VARCHAR(80) DEFAULT NULL,
  `ai_basis`    VARCHAR(80) DEFAULT NULL,
  `ai_at`       DATETIME DEFAULT NULL,
  `ai_note`     VARCHAR(600) DEFAULT NULL COMMENT 'the AI''s one-line reading of the deal',
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`),
  CONSTRAINT `fk_ecw_deal_state_case` FOREIGN KEY (`case_id`) REFERENCES `ecw_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
