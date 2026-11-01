<?php declare(strict_types=1);
require_once __DIR__.'/db.php';
/** A shared case grants read access to that thread only, never to the rest of its mailbox. */
function ecw_access_mailbox(int $orgId,int $userId,int $mailboxId,?int $sharedThreadId=null):?array
{
    $st=ecw_db()->prepare('SELECT * FROM `'.ecw_table('mailboxes').'` WHERE id=? AND organization_id=?');
    $st->execute([$mailboxId,$orgId]);$mailbox=$st->fetch();
    if(!$mailbox)return null;
    if(!ecw_private_mailboxes() || (int)$mailbox['connected_by_user_id']===$userId)return $mailbox;
    // A shared mailbox (info@, sales@): its members, or everyone when it lists none (lib/shared_mailboxes.php).
    require_once __DIR__.'/shared_mailboxes.php';
    if(ecw_mailbox_shared_with($mailbox,$userId))return $mailbox;
    if(!$sharedThreadId)return null;
    // Linked to a case this user can see, so a members-only case shares its threads with its members only.
    $params=[$sharedThreadId,$mailboxId,$orgId];
    $st=ecw_db()->prepare('SELECT t.id FROM `'.ecw_table('email_threads').'` t JOIN `'.ecw_table('case_threads').'` ct ON ct.email_thread_id=t.id JOIN `'.ecw_table('cases').'` c ON c.id=ct.case_id WHERE t.id=? AND t.mailbox_id=? AND c.organization_id=? AND c.deleted_at IS NULL AND '.ecw_case_visible_sql('c',$userId,$params));
    $st->execute($params);return $st->fetchColumn()?$mailbox:null;
}
/**
 * SQL predicate: can $userId see the case aliased $alias? Appends its params.
 *
 * 'organization' cases are visible to the whole workspace (the original
 * behaviour). 'members' cases only to the creator, Responsible, Handled by,
 * people added to the case, and members of the case's group. Callers still
 * scope by organization_id themselves; this only ever narrows.
 */
function ecw_case_visible_sql(string $alias, int $userId, array &$params): string
{
    array_push($params, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    return "({$alias}.visibility = 'organization'"
        . " OR {$alias}.created_by_user_id = ? OR {$alias}.owner_user_id = ? OR {$alias}.handler_user_id = ?"
        . ' OR EXISTS (SELECT 1 FROM `' . ecw_table('case_members') . "` vcm WHERE vcm.case_id = {$alias}.id AND vcm.user_id = ?)"
        // The group's members (a personal group has none but its owner).
        . ' OR EXISTS (SELECT 1 FROM `' . ecw_table('case_group_members') . '` vgm JOIN `' . ecw_table('case_groups') . "` vg ON vg.id = vgm.group_id
                       WHERE vgm.group_id = {$alias}.group_id AND vgm.user_id = ? AND (vg.default_sharing IS NULL OR vg.default_sharing <> 'personal'))"
        // A personal or members group's owner sees its cases.
        . ' OR EXISTS (SELECT 1 FROM `' . ecw_table('case_groups') . "` vpg WHERE vpg.id = {$alias}.group_id AND vpg.default_sharing IN ('personal', 'members') AND vpg.created_by_user_id = ?)"
        // Holiday cover (lib/handover.php): the cases of someone away, while they are away.
        . ' OR EXISTS (SELECT 1 FROM `' . ecw_table('users') . "` vaw WHERE vaw.cover_user_id = ? AND vaw.id IN ({$alias}.owner_user_id, {$alias}.handler_user_id)"
        . ' AND vaw.away_from <= ' . ecw_access_today($userId) . ' AND vaw.away_until >= ' . ecw_access_today($userId) . '))';
}

/** Can $userId see case $caseId (in $orgId, not deleted)? */
function ecw_case_visible(int $orgId, int $userId, int $caseId, bool $includeDeleted = false): bool
{
    $params = [$caseId, $orgId];
    $sql = 'SELECT 1 FROM `' . ecw_table('cases') . '` c WHERE c.id = ? AND c.organization_id = ?'
        . ($includeDeleted ? '' : ' AND c.deleted_at IS NULL') . ' AND '
        . ecw_case_visible_sql('c', $userId, $params);
    $st = ecw_db()->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function ecw_access_message(int $orgId,int $userId,int $messageId):bool
{
    $st=ecw_db()->prepare('SELECT mailbox_id,email_thread_id FROM `'.ecw_table('email_messages').'` WHERE id=?');$st->execute([$messageId]);$row=$st->fetch();
    return $row && ecw_access_mailbox($orgId,$userId,(int)$row['mailbox_id'],(int)$row['email_thread_id'])!==null;
}

/** Today where this person is, quoted for SQL (holiday cover runs on the days they entered). */
function ecw_access_today(int $userId): string
{
    require_once __DIR__ . '/timezone.php';
    return "'" . ecw_tz_today(ecw_tz_for_user($userId)) . "'";
}
