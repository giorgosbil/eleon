-- Step 04A — authentication.
--
-- One column. The step says not to introduce a user lifecycle system, and this
-- is not one: a single nullable timestamp, read in exactly one place (the login
-- check) and written by exactly one (the admin users page).
--
-- It is here rather than deferred because the same step gives admins the power
-- to CREATE users. Creating accounts without any way to revoke one means the
-- only remedy for a mistaken or departed user is changing their password out
-- from under them, or a DELETE that cascades their case links away.
--
-- A timestamp rather than a boolean, so the audit question "since when" has an
-- answer without a second column.
--
-- Note on numbering: 007 belongs to the parallel step 05 rate-limiting work
-- already present in this directory, so this step takes 008.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecw_users' AND COLUMN_NAME = 'disabled_at') > 0, 'DO 1',
  'ALTER TABLE `ecw_users`
     ADD COLUMN `disabled_at` DATETIME DEFAULT NULL COMMENT "set = account cannot sign in. Checked at login only",
     ADD KEY `idx_ecw_users_disabled` (`disabled_at`)');
PREPARE ecw_ddl FROM @ddl;
EXECUTE ecw_ddl;
DEALLOCATE PREPARE ecw_ddl;
