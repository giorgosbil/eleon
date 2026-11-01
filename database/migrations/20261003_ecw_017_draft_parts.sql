-- Reopening a draft must restore the composer exactly: the answer, the chosen
-- signature and the quoted history are kept apart (each sanitized), so they
-- are not merged into one body and duplicated on the next save.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_drafts' AND COLUMN_NAME='parts_json') > 0, 'DO 1', 'ALTER TABLE `ecw_drafts` ADD COLUMN `parts_json` JSON DEFAULT NULL COMMENT ''{answer, signature, quote, quote_changed}, each sanitized''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
