-- Case groups decide who sees their cases (Settings → Case groups, lib/cases.php
-- ecw_case_apply_group_sharing): NULL = as each case is set (as before), 'everyone' = the whole
-- company, 'group' = the group's members (and the people on the case), 'case_people' = only the
-- people on the case (Responsible, handler, creator, added people): group members do not see it.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ecw_case_groups' AND COLUMN_NAME='default_sharing') > 0, 'DO 1',
  'ALTER TABLE `ecw_case_groups` ADD COLUMN `default_sharing` VARCHAR(16) DEFAULT NULL COMMENT ''who sees cases that enter the group: everyone, group, case_people, or NULL = as each case is set''');
PREPARE ecw_stmt FROM @ddl;
EXECUTE ecw_stmt;
DEALLOCATE PREPARE ecw_stmt;
