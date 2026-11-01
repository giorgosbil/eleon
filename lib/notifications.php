<?php declare(strict_types=1);
/**
 * Email Case Workspace — in-app notifications (the bell in the navigation).
 *
 * Written by mentions, reminders, auto-reopen and assignment. Read only by the
 * recipient. Links are paths relative to the web root, never absolute URLs, so
 * a notification can never point somewhere outside the application.
 *
 * The bell shows the workspace one is working in (a person in several companies sees the
 * others' unread count, not their items: their links open in that workspace only); some
 * kinds are important (ECW_NOTIFY_IMPORTANT: a red badge, their own tab); a case one can no
 * longer open keeps its notification but loses the link. Quiet hours (Settings ->
 * Notifications): no push to the phone or computer then, the bell still collects them.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/avatars.php';

/** Kinds that need the person (someone asked, gave them work, a date came, something failed). */
const ECW_NOTIFY_IMPORTANT = ['mention', 'assigned', 'reminder', 'due', 'sla', 'send_failed', 'reopened', 'chat'];

/** Whether it is this person's quiet time now (Settings -> Notifications), in their time zone. */
function ecw_notify_quiet_now(int $userId, ?DateTimeImmutable $now = null): bool
{
    $st = ecw_db()->prepare('SELECT u.preferences_json, ou.organization_id FROM `' . ecw_table('users') . '` u
                               LEFT JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE u.id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return false;
    $prefs = json_decode((string)$row['preferences_json'], true) ?: [];
    $a = (array)($prefs['appearance'] ?? []);
    if (empty($a['quiet'])) return false;
    // Where the person is (the computer they use, else where they were last seen), else the company's zone.
    require_once __DIR__ . '/timezone.php';
    try { $tz = new DateTimeZone(ecw_tz_for_user($userId, $row['organization_id'] !== null ? (int)$row['organization_id'] : null)); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $t = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
    if (!empty($a['quiet_weekend']) && (int)$t->format('N') >= 6) return true;
    $min = static fn(string $hm): int => (int)substr($hm, 0, 2) * 60 + (int)substr($hm, 3, 2);
    $from = $min((string)($a['quiet_from'] ?? '19:00'));
    $to = $min((string)($a['quiet_to'] ?? '08:00'));
    $m = (int)$t->format('G') * 60 + (int)$t->format('i');
    if ($from === $to) return false;
    return $from < $to ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to);   // 19:00 to 08:00 runs past midnight
}

function ecw_notify(int $orgId, int $userId, ?int $actorUserId, string $type, string $text, ?string $link = null, ?int $caseId = null, ?int $threadId = null): void
{
    if ($actorUserId !== null && $actorUserId === $userId) {
        return;   // nobody needs to be told about their own action
    }
    if ($link !== null && (str_contains($link, '//') || str_starts_with($link, '/') || preg_match('/^[a-z]+:/i', $link))) {
        $link = null;
    }
    ecw_db()->prepare(
        'INSERT INTO `' . ecw_table('notifications') . '`
            (organization_id, user_id, actor_user_id, type, case_id, thread_id, text, link, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$orgId, $userId, $actorUserId, $type, $caseId, $threadId, mb_strimwidth($text, 0, 500, '…'), $link, ecw_now()]);
    // Holiday cover (lib/handover.php): while they are away, their cover hears it too.
    if (!str_starts_with($text, 'For ')) {
        require_once __DIR__ . '/handover.php';
        $cover = ecw_cover_for($userId);
        if ($cover !== null && $cover['cover_id'] !== $actorUserId) {
            ecw_notify($orgId, $cover['cover_id'], $actorUserId, $type, 'For ' . $cover['name'] . ' (away): ' . $text, $link, $caseId, $threadId);
        }
    }
    // Also to their phone / computer when they turned push on (lib/push.php), outside their
    // quiet hours. Never blocks the action.
    try {
        if (!ecw_notify_quiet_now($userId)) {
            require_once __DIR__ . '/push.php';
            ecw_push_to_user($userId, 'Case Workspace', $text, (string)($link ?? ''), $type . '-' . ($caseId ?? $threadId ?? '0'));
        }
    } catch (Throwable $e) {
        ecw_log('push for notification failed: ' . $e->getMessage());
    }
}

/** Unread notifications, in one workspace ($orgId) or in all of the person's (null). */
function ecw_notifications_unread(int $userId, ?int $orgId = null): int
{
    $st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('notifications') . '` WHERE user_id = ? AND read_at IS NULL' . ($orgId !== null ? ' AND organization_id = ?' : ''));
    $st->execute($orgId !== null ? [$userId, $orgId] : [$userId]);
    return (int)$st->fetchColumn();
}

/** Unread and important, in one workspace: the badge turns red for these. */
function ecw_notifications_unread_important(int $userId, int $orgId): int
{
    $in = implode(',', array_fill(0, count(ECW_NOTIFY_IMPORTANT), '?'));
    $st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('notifications') . '` WHERE user_id = ? AND organization_id = ? AND read_at IS NULL AND type IN (' . $in . ')');
    $st->execute(array_merge([$userId, $orgId], ECW_NOTIFY_IMPORTANT));
    return (int)$st->fetchColumn();
}

/**
 * The person's notifications, newest first: of one workspace ($orgId), or all (null). A
 * notification about a case they can no longer open keeps its text but loses its link.
 */
function ecw_notifications_list(int $userId, int $limit = 30, ?int $orgId = null): array
{
    $st = ecw_db()->prepare(
        'SELECT n.id, n.organization_id, n.type, n.text, n.link, n.read_at, n.created_at, n.case_id,
                u.id AS actor_id, u.name AS actor_name, u.email AS actor_email, u.avatar_hash AS actor_avatar
           FROM `' . ecw_table('notifications') . '` n
           LEFT JOIN `' . ecw_table('users') . '` u ON u.id = n.actor_user_id
          WHERE n.user_id = ?' . ($orgId !== null ? ' AND n.organization_id = ?' : '') . '
          ORDER BY n.created_at DESC, n.id DESC
          LIMIT ' . max(1, min(100, $limit))
    );
    $st->execute($orgId !== null ? [$userId, $orgId] : [$userId]);
    $rows = $st->fetchAll();
    if ($rows !== []) require_once __DIR__ . '/access.php';
    $open = [];
    $out = [];
    foreach ($rows as $r) {
        $link = $r['link'];
        $caseId = $r['case_id'] !== null ? (int)$r['case_id'] : null;
        if ($caseId !== null && $link !== null) {
            $key = $r['organization_id'] . ':' . $caseId;
            $open[$key] ??= ecw_case_visible((int)$r['organization_id'], $userId, $caseId);
            if (!$open[$key]) $link = null;
        }
        $out[] = [
            'id'         => (int)$r['id'],
            'type'       => $r['type'],
            'text'       => $r['text'],
            'link'       => $link,
            'case_id'    => $caseId,
            'important'  => in_array($r['type'], ECW_NOTIFY_IMPORTANT, true),
            'read'       => $r['read_at'] !== null,
            'created_at' => $r['created_at'],
            'actor'      => $r['actor_id'] === null ? null : [
                'id' => (int)$r['actor_id'], 'name' => $r['actor_name'], 'email' => $r['actor_email'],
                'avatar_url' => ecw_avatar_url((int)$r['actor_id'], $r['actor_avatar']),
            ],
        ];
    }
    return $out;
}

/** Mark one ($id) or all (null) of the user's notifications read; "all" in one workspace when $orgId is given. */
function ecw_notifications_mark_read(int $userId, ?int $id = null, ?int $orgId = null): void
{
    $sql = 'UPDATE `' . ecw_table('notifications') . '` SET read_at = ? WHERE user_id = ? AND read_at IS NULL';
    $params = [ecw_now(), $userId];
    if ($id === null && $orgId !== null) {
        $sql .= ' AND organization_id = ?';
        $params[] = $orgId;
    }
    if ($id !== null) {
        $sql .= ' AND id = ?';
        $params[] = $id;
    }
    ecw_db()->prepare($sql)->execute($params);
}
