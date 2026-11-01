-- Response-time targets (SLA): how fast an open case's unanswered customer
-- email must get a reply, counted in business hours. One setting for the
-- workspace, optionally a different target per case group.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_organizations' AND COLUMN_NAME='sla_json') > 0, 'DO 1', 'ALTER TABLE `ecw_organizations` ADD COLUMN `sla_json` TEXT DEFAULT NULL COMMENT ''{enabled, reply_hours, days, start, end}''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_case_groups' AND COLUMN_NAME='sla_hours') > 0, 'DO 1', 'ALTER TABLE `ecw_case_groups` ADD COLUMN `sla_hours` DECIMAL(5,1) DEFAULT NULL COMMENT ''reply target for this group, business hours. NULL = the workspace target''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
