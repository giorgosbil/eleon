-- Case groups are Company (everyone sees the group and its cases) or Personal (only their owner:
-- hidden from everyone else, and so are its cases, except people placed on a case). Stored in
-- default_sharing: 'company', 'personal', or NULL = a company group from before (its cases keep
-- the sharing each one has). The earlier choices become one of the two, and the cases already in
-- a personal group are hidden from others now (the choice was only applied to cases added later).
UPDATE `ecw_case_groups` SET default_sharing = 'company' WHERE default_sharing = 'everyone';
UPDATE `ecw_case_groups` SET default_sharing = 'personal' WHERE default_sharing IN ('group', 'case_people');
UPDATE `ecw_cases` c JOIN `ecw_case_groups` g ON g.id = c.group_id SET c.visibility = 'members' WHERE g.default_sharing = 'personal' AND c.visibility <> 'members';
