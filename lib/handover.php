<?php declare(strict_types=1);
/**
 * When someone leaves, or is away:
 *
 * HANDOVER (admins, Settings → Users): everything a person is responsible for
 * moves to a colleague in one step — open cases (Responsible / Handled by),
 * open reminders, conversations they claimed in shared mailboxes, their case
 * and group memberships — and, if chosen, their own mailbox becomes a shared
 * mailbox the colleague can read, so nothing in it is lost.
 *
 * HOLIDAY COVER (Account, or an admin for someone): from–until dates and a
 * colleague. While away, the cover sees their cases (lib/access.php) and gets
 * a copy of their notifications (lib/notifications.php). Nothing is moved; it
 * ends by itself.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** The colleague covering for $userId today, or null. @return array{cover_id:int, name:string, until:string}|null */
function ecw_cover_for(int $userId): ?array
{
    static $cache = [];
    require_once __DIR__ . '/timezone.php';
    $today = ecw_tz_today(ecw_tz_for_user($userId));   // the days the person entered, where they are
    $key = $userId . '|' . $today;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = ecw_db()->prepare('SELECT u.name, u.email, u.cover_user_id, u.away_until FROM `' . ecw_table('users') . '` u
                                   JOIN `' . ecw_table('users') . '` c ON c.id = u.cover_user_id AND c.disabled_at IS NULL
                                  WHERE u.id = ? AND u.away_from <= ? AND u.away_until >= ?');
        $st->execute([$userId, $today, $today]);
        $r = $st->fetch();
    } catch (Throwable $e) { $r = false; }   // before migration 039
    return $cache[$key] = $r ? ['cover_id' => (int)$r['cover_user_id'], 'name' => (string)($r['name'] ?: $r['email']), 'until' => (string)$r['away_until']] : null;
}

/** @return array{from:?string, until:?string, cover_id:?int, cover_name:?string, active:bool} */
function ecw_away_get(int $userId): array
{
    try {
        $st = ecw_db()->prepare('SELECT u.away_from, u.away_until, u.cover_user_id, c.name AS cover_name FROM `' . ecw_table('users') . '` u
                                   LEFT JOIN `' . ecw_table('users') . '` c ON c.id = u.cover_user_id WHERE u.id = ?');
        $st->execute([$userId]);
        $r = $st->fetch() ?: [];
    } catch (Throwable $e) { $r = []; }
    require_once __DIR__ . '/timezone.php';
    $today = ecw_tz_today(ecw_tz_for_user($userId));
    $from = $r['away_from'] ?? null; $until = $r['away_until'] ?? null;
    return ['from' => $from, 'until' => $until, 'cover_id' => isset($r['cover_user_id']) ? (int)$r['cover_user_id'] : null, 'cover_name' => $r['cover_name'] ?? null,
        'active' => $from !== null && $until !== null && $from <= $today && $until >= $today];
}

/**
 * Set someone's holiday cover. The person themselves, or an admin.
 * @return string|null error
 */
function ecw_away_set(int $orgId, int $actorId, int $userId, string $from, string $until, int $coverId): ?string
{
    if ($actorId !== $userId && ecw_user_role($actorId, $orgId) !== 'admin') return 'Only an admin can set cover for someone else.';
    if (ecw_user_role($userId, $orgId) === null) return 'Person not found.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < $from) return 'Choose a first and a last day (the last on or after the first).';
    require_once __DIR__ . '/timezone.php';
    if ($until < ecw_tz_today(ecw_tz_for_user($userId, $orgId))) return 'The last day is already past.';
    if ((strtotime($until) - strtotime($from)) / 86400 > 366) return 'Cover can last up to a year.';
    if ($coverId === $userId) return 'Choose a colleague to cover for you.';
    $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . "` ou ON ou.user_id = u.id
                              WHERE u.id = ? AND ou.organization_id = ? AND u.disabled_at IS NULL AND ou.role <> 'read_only'");
    $st->execute([$coverId, $orgId]);
    if (!$st->fetchColumn()) return 'Choose an active colleague (not read only) to cover.';
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET away_from = ?, away_until = ?, cover_user_id = ? WHERE id = ?')->execute([$from, $until, $coverId, $userId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('user.away', 'user', $userId, ['from' => $from, 'until' => $until, 'cover' => $coverId], $orgId, $actorId);
    require_once __DIR__ . '/notifications.php';
    $name = ecw_db()->prepare('SELECT name FROM `' . ecw_table('users') . '` WHERE id = ?');
    $name->execute([$userId]);
    ecw_notify($orgId, $coverId, $actorId === $userId ? $userId : $actorId, 'assigned',
        'For ' . ((string)$name->fetchColumn() ?: 'a colleague') . ': you cover their cases from ' . $from . ' to ' . $until . '. You see their cases and get their alerts.', 'cases.php');
    return null;
}

function ecw_away_clear(int $orgId, int $actorId, int $userId): void
{
    if ($actorId !== $userId && ecw_user_role($actorId, $orgId) !== 'admin') return;
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET away_from = NULL, away_until = NULL, cover_user_id = NULL WHERE id = ?')->execute([$userId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('user.away', 'user', $userId, ['cleared' => true], $orgId, $actorId);
}

/** What a handover would move (for the confirmation screen). */
function ecw_handover_preview(int $orgId, int $fromId): array
{
    $q = static function (string $sql, array $p): int { $st = ecw_db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
    return [
        'cases' => $q('SELECT COUNT(*) FROM `' . ecw_table('cases') . "` WHERE organization_id = ? AND deleted_at IS NULL AND status <> 'closed' AND (owner_user_id = ? OR handler_user_id = ?)", [$orgId, $fromId, $fromId]),
        'reminders' => $q('SELECT COUNT(*) FROM `' . ecw_table('followups') . '` WHERE organization_id = ? AND user_id = ? AND done_at IS NULL', [$orgId, $fromId]),
        'claimed' => $q('SELECT COUNT(*) FROM `' . ecw_table('email_threads') . '` t JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id WHERE mb.organization_id = ? AND t.claimed_by_user_id = ?', [$orgId, $fromId]),
        'mailboxes' => $q('SELECT COUNT(*) FROM `' . ecw_table('mailboxes') . "` WHERE organization_id = ? AND connected_by_user_id = ? AND sync_status <> 'disconnected'", [$orgId, $fromId]),
    ];
}

/**
 * Hand everything of $fromId over to $toId (admins).
 * $shareMailboxes: their own mailboxes become shared mailboxes $toId reads.
 * @return array counts of what moved
 */
function ecw_handover(int $orgId, int $actorId, int $fromId, int $toId, bool $shareMailboxes): array
{
    if (ecw_user_role($actorId, $orgId) !== 'admin') throw new InvalidArgumentException('Only an admin can hand over someone\'s work.');
    if ($fromId === $toId) throw new InvalidArgumentException('Choose a different person to hand over to.');
    if (ecw_user_role($fromId, $orgId) === null) throw new InvalidArgumentException('Person not found.');
    $role = ecw_user_role($toId, $orgId);
    $st = ecw_db()->prepare('SELECT disabled_at FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$toId]);
    if ($role === null || $role === 'read_only' || $st->fetchColumn() !== null) throw new InvalidArgumentException('Hand over to an active colleague who can edit (not read only).');

    require_once __DIR__ . '/cases.php';
    require_once __DIR__ . '/shared_mailboxes.php';
    $pdo = ecw_db();
    $out = ['cases' => 0, 'reminders' => 0, 'claimed' => 0, 'memberships' => 0, 'mailboxes' => 0];
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $cases = $pdo->prepare('SELECT id, owner_user_id, handler_user_id FROM `' . ecw_table('cases') . "` WHERE organization_id = ? AND deleted_at IS NULL AND status <> 'closed' AND (owner_user_id = ? OR handler_user_id = ?)");
        $cases->execute([$orgId, $fromId, $fromId]);
        foreach ($cases->fetchAll() as $c) {
            foreach (['owner_user_id', 'handler_user_id'] as $field) {
                if ((int)$c[$field] !== $fromId) continue;
                $pdo->prepare('UPDATE `' . ecw_table('cases') . "` SET `{$field}` = ? WHERE id = ?")->execute([$toId, (int)$c['id']]);
                ecw_case_log_event((int)$c['id'], 'CASE_ASSIGNED', $actorId, null, null, ['field' => $field, 'from' => $fromId, 'to' => $toId, 'reason' => 'handover']);
            }
            $out['cases']++;
        }
        $st = $pdo->prepare('UPDATE `' . ecw_table('followups') . '` SET user_id = ? WHERE organization_id = ? AND user_id = ? AND done_at IS NULL');
        $st->execute([$toId, $orgId, $fromId]);
        $out['reminders'] = $st->rowCount();
        $st = $pdo->prepare('UPDATE `' . ecw_table('email_threads') . '` t JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id SET t.claimed_by_user_id = ?, t.claimed_at = UTC_TIMESTAMP() WHERE mb.organization_id = ? AND t.claimed_by_user_id = ?');
        $st->execute([$toId, $orgId, $fromId]);
        $out['claimed'] = $st->rowCount();
        $st = $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('case_members') . '` (case_id, user_id) SELECT cm.case_id, ? FROM `' . ecw_table('case_members') . '` cm JOIN `' . ecw_table('cases') . '` c ON c.id = cm.case_id WHERE c.organization_id = ? AND cm.user_id = ?');
        $st->execute([$toId, $orgId, $fromId]);
        $out['memberships'] += $st->rowCount();
        $st = $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('case_group_members') . '` (group_id, user_id) SELECT gm.group_id, ? FROM `' . ecw_table('case_group_members') . '` gm JOIN `' . ecw_table('case_groups') . '` g ON g.id = gm.group_id WHERE g.organization_id = ? AND gm.user_id = ?');
        $st->execute([$toId, $orgId, $fromId]);
        $out['memberships'] += $st->rowCount();
        // Shared mailboxes of the person: the colleague is added where a member list exists.
        $st = $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('mailbox_members') . '` (mailbox_id, user_id) SELECT mm.mailbox_id, ? FROM `' . ecw_table('mailbox_members') . '` mm JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = mm.mailbox_id WHERE mb.organization_id = ? AND mm.user_id = ?');
        $st->execute([$toId, $orgId, $fromId]);
        $pdo->prepare('UPDATE `' . ecw_table('ai_suggestions') . "` SET state = 'expired', decided_at = UTC_TIMESTAMP() WHERE organization_id = ? AND user_id = ? AND state = 'open'")->execute([$orgId, $fromId]);
        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns) $pdo->rollBack();
        throw $e;
    }
    if ($shareMailboxes) {
        $mbs = $pdo->prepare('SELECT id FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ? AND connected_by_user_id = ? AND is_shared = 0');
        $mbs->execute([$orgId, $fromId]);
        foreach ($mbs->fetchAll(PDO::FETCH_COLUMN) as $mbId) {
            if (ecw_mailbox_share_save($orgId, $actorId, (int)$mbId, true, [$toId]) === null) $out['mailboxes']++;
        }
    }
    require_once __DIR__ . '/audit.php';
    ecw_audit('user.handover', 'user', $fromId, $out + ['to' => $toId], $orgId, $actorId);
    require_once __DIR__ . '/notifications.php';
    $name = $pdo->prepare('SELECT name FROM `' . ecw_table('users') . '` WHERE id = ?');
    $name->execute([$fromId]);
    ecw_notify($orgId, $toId, $actorId, 'assigned', 'You took over the work of ' . ((string)$name->fetchColumn() ?: 'a colleague') . ': '
        . $out['cases'] . ' open case' . ($out['cases'] === 1 ? '' : 's') . ', ' . $out['reminders'] . ' reminder' . ($out['reminders'] === 1 ? '' : 's')
        . ($out['mailboxes'] ? ', and their mailbox' : '') . '.', 'cases.php');
    return $out;
}
