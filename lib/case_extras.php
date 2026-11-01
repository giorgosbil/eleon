<?php declare(strict_types=1);
/**
 * Cases: tags, merge, due-date reminders, auto-assign, history.
 *
 *  - Tags: short labels on a case ("quote", "urgent", "warranty"), filterable
 *    in the case list.
 *  - Merge: one case into another. Conversations, notes, reminders, Teams
 *    chats, people and tags move to the target; the source is soft-deleted
 *    with a pointer to the target, so its old link still leads somewhere.
 *  - Due date: Responsible and Handled by get a bell notification on the day
 *    (once; changing the date re-arms it).
 *  - Auto-assign: a group can hand new cases from routing rules to the member
 *    with the fewest open cases.
 *  - History: case_events in words (who changed what, when).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/modules.php';

const ECW_CASE_MAX_TAGS = 10;

/** @return string[] */
function ecw_case_tags(int $caseId): array
{
    $st = ecw_db()->prepare('SELECT tag FROM `' . ecw_table('case_tags') . '` WHERE case_id = ? ORDER BY tag');
    $st->execute([$caseId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** @param string[] $tags @return string[] the saved tags */
function ecw_case_tags_save(int $caseId, array $tags, ?int $actorUserId): array
{
    $clean = [];
    foreach ($tags as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} _\-]/u', '', (string)$t) ?? '') ?? '');
        if ($t === '') continue;
        $t = mb_strtolower(mb_substr($t, 0, 40));
        $clean[$t] = true;
    }
    $clean = array_slice(array_keys($clean), 0, ECW_CASE_MAX_TAGS);
    $before = ecw_case_tags($caseId);
    if ($before === $clean || (count($before) === count($clean) && !array_diff($before, $clean))) return $before;
    $pdo = ecw_db();
    $pdo->prepare('DELETE FROM `' . ecw_table('case_tags') . '` WHERE case_id = ?')->execute([$caseId]);
    $ins = $pdo->prepare('INSERT INTO `' . ecw_table('case_tags') . '` (case_id, tag) VALUES (?, ?)');
    foreach ($clean as $t) $ins->execute([$caseId, $t]);
    ecw_case_log_event($caseId, 'CASE_TAGS_CHANGED', $actorUserId, null, null, ['from' => $before, 'to' => $clean]);
    require_once __DIR__ . '/categories.php';
    ecw_categories_mark_case($caseId);   // the tags show as Outlook categories
    sort($clean);
    return $clean;
}

/** Every tag in use in the organisation, for suggestions. */
function ecw_org_tags(int $organizationId): array
{
    $st = ecw_db()->prepare('SELECT t.tag, COUNT(*) n FROM `' . ecw_table('case_tags') . '` t JOIN `' . ecw_table('cases') . '` c ON c.id = t.case_id
                              WHERE c.organization_id = ? AND c.deleted_at IS NULL GROUP BY t.tag ORDER BY n DESC, t.tag LIMIT 100');
    $st->execute([$organizationId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Merge $sourceId into $targetId. The caller has checked the user can see both.
 * @return int the target id
 */
function ecw_case_merge(int $organizationId, int $sourceId, int $targetId, ?int $actorUserId): int
{
    if ($sourceId === $targetId) throw new InvalidArgumentException('Choose a different case to merge into.');
    $pdo = ecw_db();
    $load = $pdo->prepare('SELECT id, case_number, name, deleted_at FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ?');
    $load->execute([$sourceId, $organizationId]); $src = $load->fetch();
    $load->execute([$targetId, $organizationId]); $dst = $load->fetch();
    if (!$src || !$dst || $src['deleted_at'] !== null || $dst['deleted_at'] !== null) throw new InvalidArgumentException('Case not found.');
    require_once __DIR__ . '/security.php';
    if (ecw_case_held($sourceId)) throw new InvalidArgumentException($src['case_number'] . ' is on legal hold, so it cannot be merged away. Merge the other case into it instead.');

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM `' . ecw_table('case_threads') . '` WHERE case_id = ' . $targetId)->fetchColumn();
        $pdo->prepare('UPDATE `' . ecw_table('case_threads') . '` SET case_id = ?, sort_order = sort_order + ? WHERE case_id = ?')->execute([$targetId, $order, $sourceId]);
        foreach (['case_notes', 'followups', 'case_teams_chats', 'drafts'] as $t) {
            $pdo->prepare('UPDATE `' . ecw_table($t) . '` SET case_id = ? WHERE case_id = ?')->execute([$targetId, $sourceId]);
        }
        // What modules keep per case (chats linked to it…) moves too, in the same transaction.
        require_once __DIR__ . '/modules.php';
        foreach (ecw_hook_functions('case.merged') as $fn) $fn($sourceId, $targetId);
        $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('case_members') . '` (case_id, user_id) SELECT ?, user_id FROM `' . ecw_table('case_members') . '` WHERE case_id = ?')->execute([$targetId, $sourceId]);
        $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('case_tags') . '` (case_id, tag) SELECT ?, tag FROM `' . ecw_table('case_tags') . '` WHERE case_id = ?')->execute([$targetId, $sourceId]);
        $pdo->prepare('UPDATE `' . ecw_table('cases') . '` SET merged_into_case_id = ?, deleted_at = ?, deleted_by_user_id = ? WHERE id = ?')->execute([$targetId, ecw_now(), $actorUserId, $sourceId]);
        ecw_case_log_event($sourceId, 'CASE_MERGED_INTO', $actorUserId, 'case', $targetId, ['case_number' => $dst['case_number'], 'name' => $dst['name']]);
        ecw_case_log_event($targetId, 'CASE_MERGED_FROM', $actorUserId, 'case', $sourceId, ['case_number' => $src['case_number'], 'name' => $src['name']]);
        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns) $pdo->rollBack();
        throw $e;
    }
    require_once __DIR__ . '/categories.php';
    ecw_categories_mark_case($targetId);   // the merged emails now carry the target's case number
    return $targetId;
}

/** The group member with the fewest open cases (ties: least recently given one), or null. */
function ecw_case_auto_owner(int $groupId): ?int
{
    $st = ecw_db()->prepare('SELECT auto_assign FROM `' . ecw_table('case_groups') . '` WHERE id = ?');
    $st->execute([$groupId]);
    if ((int)$st->fetchColumn() !== 1) return null;
    $st = ecw_db()->prepare(
        'SELECT m.user_id,
                (SELECT COUNT(*) FROM `' . ecw_table('cases') . "` c WHERE c.owner_user_id = m.user_id AND c.status <> 'closed' AND c.deleted_at IS NULL) AS open_cases,
                (SELECT MAX(c2.created_at) FROM `" . ecw_table('cases') . '` c2 WHERE c2.owner_user_id = m.user_id) AS last_given
           FROM `' . ecw_table('case_group_members') . '` m
           JOIN `' . ecw_table('users') . '` u ON u.id = m.user_id AND u.disabled_at IS NULL
          WHERE m.group_id = ?
          ORDER BY open_cases ASC, last_given IS NULL DESC, last_given ASC LIMIT 1'
    );
    $st->execute([$groupId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** Bell notification on the due day (once per date). Called every minute by the daemon. */
function ecw_cases_due_notify(): int
{
    require_once __DIR__ . '/license.php';
    if (!ecw_package_has('case_extras')) return 0;   // due dates are not in this installation's package
    require_once __DIR__ . '/notifications.php';
    $st = ecw_db()->prepare('SELECT id, organization_id, case_number, name, due_at, owner_user_id, handler_user_id FROM `' . ecw_table('cases') . "`
                              WHERE due_at IS NOT NULL AND due_at <= ? AND due_notified_at IS NULL AND status <> 'closed' AND deleted_at IS NULL LIMIT 50");
    // The due day at each company (lib/timezone.php): asked up to the latest today anywhere, then each waits for its own.
    require_once __DIR__ . '/timezone.php';
    $st->execute([gmdate('Y-m-d', time() + 14 * 3600)]);
    $n = 0;
    foreach ($st->fetchAll() as $c) {
        $today = ecw_tz_company_today((int)$c['organization_id']);
        if ($c['due_at'] > $today) continue;
        $late = $c['due_at'] < $today;
        $text = 'Case ' . $c['case_number'] . ' “' . $c['name'] . '” is ' . ($late ? 'overdue (was due ' . $c['due_at'] . ')' : 'due today');
        $people = array_unique(array_filter([(int)$c['owner_user_id'], (int)($c['handler_user_id'] ?? 0)]));
        foreach ($people as $uid) ecw_notify((int)$c['organization_id'], $uid, null, 'due', $text, 'case.php?id=' . (int)$c['id'], (int)$c['id']);
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET due_notified_at = ? WHERE id = ?')->execute([ecw_now(), (int)$c['id']]);
        $n++;
    }
    return $n;
}

/** The case's history, newest first, with who did it. */
function ecw_case_history(int $caseId, int $limit = 200): array
{
    $st = ecw_db()->prepare('SELECT e.event_type, e.entity_type, e.entity_id, e.metadata_json, e.occurred_at, u.name, u.email
                               FROM `' . ecw_table('case_events') . '` e LEFT JOIN `' . ecw_table('users') . '` u ON u.id = e.user_id
                              WHERE e.case_id = ? ORDER BY e.occurred_at DESC, e.id DESC LIMIT ' . max(1, $limit));
    $st->execute([$caseId]);
    $names = [];
    $nameOf = static function (?int $id) use (&$names): string {
        if (!$id) return 'nobody';
        if (!isset($names[$id])) { $s = ecw_db()->prepare('SELECT name, email FROM `' . ecw_table('users') . '` WHERE id = ?'); $s->execute([$id]); $u = $s->fetch(); $names[$id] = $u ? ((string)$u['name'] ?: (string)$u['email']) : 'a former member'; }
        return $names[$id];
    };
    return array_map(static function (array $r) use ($nameOf): array {
        $m = json_decode((string)($r['metadata_json'] ?? ''), true) ?: [];
        $text = match ($r['event_type']) {
            'CASE_CREATED' => 'created the case',
            'CASE_RENAMED' => 'renamed it from “' . ($m['from'] ?? '') . '” to “' . ($m['to'] ?? '') . '”',
            'CASE_STATUS_CHANGED' => 'changed the status from ' . ($m['from_label'] ?? $m['from'] ?? '?') . ' to ' . ($m['to_label'] ?? $m['to'] ?? '?'),
            'CASE_ASSIGNED' => 'made ' . $nameOf(isset($m['to']) ? (int)$m['to'] : null) . ' ' . (($m['field'] ?? '') === 'handler_user_id' ? 'Handled by' : 'Responsible'),
            'CASE_DUE_CHANGED' => ($m['to'] ?? null) ? 'set the due date to ' . $m['to'] : 'removed the due date',
            'CASE_TAGS_CHANGED' => ($m['to'] ?? []) ? 'set the tags to ' . implode(', ', (array)$m['to']) : 'removed all tags',
            'CASE_SHARING_CHANGED' => 'changed the group / who can see it',
            'CHECKLIST_ADDED' => 'added the step “' . ($m['text'] ?? '') . '”',
            'CHECKLIST_DONE' => 'ticked “' . ($m['text'] ?? '') . '”',
            'CHECKLIST_UNDONE' => 'unticked “' . ($m['text'] ?? '') . '”',
            'CHECKLIST_REMOVED' => 'removed the step “' . ($m['text'] ?? '') . '”',
            'CASE_TEMPLATE_APPLIED' => 'used the template “' . ($m['name'] ?? '') . '”' . (!empty($m['steps']) ? ' (' . (int)$m['steps'] . ' steps)' : ''),
            'CASE_NEXT_CHANGED' => isset($m['undo']) ? 'undid the AI’s next step'
                : (($m['by'] ?? '') === 'ai' ? 'AI: ' : '') . implode('; ', array_filter([
                    array_key_exists('next_action', $m) ? ($m['next_action'] ? 'next step “' . $m['next_action'] . '”' : 'cleared the next step') : null,
                    array_key_exists('waiting_on', $m) ? ($m['waiting_on'] ? 'waiting on ' . strtolower((string)(['us' => 'Us', 'customer' => 'Customer', 'supplier' => 'Supplier', 'internal' => 'Colleague'][$m['waiting_on']] ?? $m['waiting_on'])) : 'waiting on nobody') : null,
                    array_key_exists('review_on', $m) ? ($m['review_on'] ? 'look again on ' . $m['review_on'] : 'no look-again date') : null,
                    array_key_exists('next_user_id', $m) ? 'next step for ' . $nameOf(isset($m['next_user_id']) ? (int)$m['next_user_id'] : null) : null,
                ])) . (($m['by'] ?? '') === 'rule' ? ' (after an email)' : ''),
            'CASE_REF_ADDED' => (($m['by'] ?? '') === 'ai' ? 'AI: ' : '') . 'added the reference ' . ($m['value'] ?? ''),
            'CASE_REF_REMOVED' => 'removed the reference ' . ($m['value'] ?? ''),
            'CASE_THREAD_ROLE' => ($m['role'] ?? null) ? 'marked a conversation as ' . $m['role'] : 'unmarked a conversation',
            'THREAD_AUTO_LINKED' => 'added a conversation by itself: it mentions ' . ($m['reason'] ?? 'this case'),
            'THREAD_LINKED' => 'added a conversation',
            'THREAD_UNLINKED' => 'removed a conversation',
            'NOTE_ADDED' => 'added a note',
            'TEAMS_CHAT_LINKED' => 'linked a Teams chat' . (isset($m['title']) ? ' (' . $m['title'] . ')' : ''),
            'TEAMS_CHAT_UNLINKED' => 'unlinked a Teams chat',
            'CASE_SOLUTION_EDITED' => 'wrote the problem and solution summary',
            'CASE_SIMILAR_CONFIRMED' => 'confirmed it is the same issue as ' . ($m['case_number'] ?? 'an earlier case'),
            'CASE_SIMILAR_REJECTED' => 'said it is not the same issue as ' . ($m['case_number'] ?? 'an earlier case'),
            'CASE_REOPENED' => 'reopened it (a reply came in)',
            'CASE_DELETED' => 'deleted the case',
            'CASE_RESTORED' => 'restored the case',
            'CASE_MERGED_INTO' => 'merged it into ' . ($m['case_number'] ?? 'another case'),
            'CASE_MERGED_FROM' => 'merged ' . ($m['case_number'] ?? 'another case') . ' “' . ($m['name'] ?? '') . '” into this case',
            // Events of optional modules (lib/modules.php) name themselves.
            default => ecw_hook_first('case.event_label', (string)$r['event_type'], $m) ?? strtolower(str_replace('_', ' ', (string)$r['event_type'])),
        };
        return ['at' => $r['occurred_at'], 'who' => $r['name'] ?: ($r['email'] ?: 'The app'), 'text' => $text, 'type' => $r['event_type'],
                'link_case' => in_array($r['event_type'], ['CASE_MERGED_INTO', 'CASE_MERGED_FROM'], true) ? (int)$r['entity_id'] : null];
    }, $st->fetchAll());
}
