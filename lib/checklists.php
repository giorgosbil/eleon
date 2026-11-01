<?php declare(strict_types=1);
/**
 * Checklists on cases, and case templates.
 *
 * A CHECKLIST is the steps of one case: text, who does it (optional), until
 * when (optional), done by whom and when. Everyone who can see the case can
 * tick it; the history records it. Assigning a step tells that person; on its
 * due day they are reminded once (ecw_checklist_due_notify, from the daemon).
 *
 * A TEMPLATE is a ready-made case for work that repeats ("New customer",
 * "Complaint", "Warranty claim"): steps with "+N days" and who does them
 * (Responsible / Handled by), tags, a due date in N days and a group. Picked
 * when a case is created, or applied to an existing case (steps already there
 * are not added twice). Templates are a Team / Business feature
 * ('case_templates'); checklists themselves are part of every case.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cases.php';

const ECW_CHECKLIST_MAX = 60;

// ------------------------------------------------------------------ checklist

/** The steps of a case, in order, with names. */
function ecw_checklist(int $caseId): array
{
    $st = ecw_db()->prepare('SELECT i.id, i.text, i.position, i.assignee_user_id, i.due_date, i.done_at, i.done_by_user_id,
                                    a.name AS assignee_name, d.name AS done_by_name
                               FROM `' . ecw_table('case_checklist') . '` i
                               LEFT JOIN `' . ecw_table('users') . '` a ON a.id = i.assignee_user_id
                               LEFT JOIN `' . ecw_table('users') . '` d ON d.id = i.done_by_user_id
                              WHERE i.case_id = ? ORDER BY i.position, i.id');
    $st->execute([$caseId]);
    return array_map(static fn($r) => [
        'id' => (int)$r['id'], 'text' => $r['text'], 'done' => $r['done_at'] !== null, 'done_at' => $r['done_at'],
        'done_by' => $r['done_by_name'], 'assignee_id' => $r['assignee_user_id'] !== null ? (int)$r['assignee_user_id'] : null,
        'assignee' => $r['assignee_name'], 'due_date' => $r['due_date'],
        'late' => $r['done_at'] === null && $r['due_date'] !== null && $r['due_date'] < gmdate('Y-m-d'),
    ], $st->fetchAll());
}

/** [case id => ['done' => n, 'total' => n]] for the case list. */
function ecw_checklist_progress(array $caseIds): array
{
    $caseIds = array_values(array_filter(array_map('intval', $caseIds)));
    if (!$caseIds) return [];
    try {
        $st = ecw_db()->prepare('SELECT case_id, COUNT(*) total, SUM(done_at IS NOT NULL) done FROM `' . ecw_table('case_checklist') . '`
                                  WHERE case_id IN (' . implode(',', array_fill(0, count($caseIds), '?')) . ') GROUP BY case_id');
        $st->execute($caseIds);
    } catch (Throwable $e) { return []; }   // before migration 040
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['case_id']] = ['done' => (int)$r['done'], 'total' => (int)$r['total']];
    return $out;
}

/** One step of a case of this organization, or null. */
function ecw_checklist_item(int $orgId, int $itemId): ?array
{
    $st = ecw_db()->prepare('SELECT i.*, c.organization_id, c.case_number, c.name AS case_name FROM `' . ecw_table('case_checklist') . '` i
                               JOIN `' . ecw_table('cases') . '` c ON c.id = i.case_id WHERE i.id = ? AND c.organization_id = ?');
    $st->execute([$itemId, $orgId]);
    return $st->fetch() ?: null;
}

/** A colleague who may be given a step: active member of the organization. */
function ecw_checklist_valid_person(int $orgId, ?int $userId): ?int
{
    if (!$userId) return null;
    $st = ecw_db()->prepare('SELECT u.id FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
                              WHERE u.id = ? AND ou.organization_id = ? AND u.disabled_at IS NULL');
    $st->execute([$userId, $orgId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function ecw_checklist_clean_text(string $text): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') throw new InvalidArgumentException('Write what the step is.');
    return mb_substr($text, 0, 255);
}

function ecw_checklist_clean_date(?string $date): ?string
{
    $date = trim((string)$date);
    if ($date === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new InvalidArgumentException('Choose a valid date.');
    return $date;
}

/** Add a step. @return int its id */
function ecw_checklist_add(int $orgId, int $caseId, int $actorId, string $text, ?int $assigneeId = null, ?string $dueDate = null, ?int $templateId = null): int
{
    $text = ecw_checklist_clean_text($text);
    $count = ecw_db()->prepare('SELECT COUNT(*), COALESCE(MAX(position), 0) FROM `' . ecw_table('case_checklist') . '` WHERE case_id = ?');
    $count->execute([$caseId]);
    [$n, $max] = $count->fetch(PDO::FETCH_NUM);
    if ((int)$n >= ECW_CHECKLIST_MAX) throw new InvalidArgumentException('A checklist has at most ' . ECW_CHECKLIST_MAX . ' steps.');
    $assigneeId = ecw_checklist_valid_person($orgId, $assigneeId);
    ecw_db()->prepare('INSERT INTO `' . ecw_table('case_checklist') . '` (case_id, position, text, assignee_user_id, due_date, template_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$caseId, (int)$max + 10, $text, $assigneeId, ecw_checklist_clean_date($dueDate), $templateId, $actorId]);
    $id = (int)ecw_db()->lastInsertId();
    if ($templateId === null) ecw_case_log_event($caseId, 'CHECKLIST_ADDED', $actorId, 'checklist', $id, ['text' => $text]);
    if ($assigneeId !== null) ecw_checklist_tell($orgId, $caseId, $assigneeId, $actorId, $text);
    return $id;
}

function ecw_checklist_tell(int $orgId, int $caseId, int $userId, int $actorId, string $text): void
{
    if ($userId === $actorId) return;
    require_once __DIR__ . '/notifications.php';
    $st = ecw_db()->prepare('SELECT case_number, name FROM `' . ecw_table('cases') . '` WHERE id = ?');
    $st->execute([$caseId]);
    $c = $st->fetch();
    ecw_notify($orgId, $userId, $actorId, 'assigned', 'A step for you in ' . ($c['case_number'] ?? '') . ': ' . $text, 'case.php?id=' . $caseId . '#checklist', $caseId);
}

/** Tick or untick. */
function ecw_checklist_toggle(int $orgId, int $itemId, int $actorId, bool $done): array
{
    $i = ecw_checklist_item($orgId, $itemId);
    if ($i === null) throw new InvalidArgumentException('Step not found.');
    if (($i['done_at'] !== null) === $done) return $i;
    ecw_db()->prepare('UPDATE `' . ecw_table('case_checklist') . '` SET done_at = ?, done_by_user_id = ? WHERE id = ?')
        ->execute([$done ? ecw_now() : null, $done ? $actorId : null, $itemId]);
    ecw_case_log_event((int)$i['case_id'], $done ? 'CHECKLIST_DONE' : 'CHECKLIST_UNDONE', $actorId, 'checklist', $itemId, ['text' => $i['text']]);
    return ecw_checklist_item($orgId, $itemId);
}

/** Change the text, the person or the date of a step. Only the fields given change. */
function ecw_checklist_update(int $orgId, int $itemId, int $actorId, array $in): void
{
    $i = ecw_checklist_item($orgId, $itemId);
    if ($i === null) throw new InvalidArgumentException('Step not found.');
    $set = []; $p = [];
    if (array_key_exists('text', $in)) { $set[] = 'text = ?'; $p[] = ecw_checklist_clean_text((string)$in['text']); }
    if (array_key_exists('due_date', $in)) { $set[] = 'due_date = ?, reminded_at = NULL'; $p[] = ecw_checklist_clean_date($in['due_date'] !== null ? (string)$in['due_date'] : null); }
    $newAssignee = null;
    if (array_key_exists('assignee_id', $in)) {
        $newAssignee = ecw_checklist_valid_person($orgId, $in['assignee_id'] !== null ? (int)$in['assignee_id'] : null);
        $set[] = 'assignee_user_id = ?, reminded_at = NULL'; $p[] = $newAssignee;
    }
    if (!$set) return;
    $p[] = $itemId;
    ecw_db()->prepare('UPDATE `' . ecw_table('case_checklist') . '` SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($p);
    if ($newAssignee !== null && $newAssignee !== (int)$i['assignee_user_id']) ecw_checklist_tell($orgId, (int)$i['case_id'], $newAssignee, $actorId, (string)($in['text'] ?? $i['text']));
}

function ecw_checklist_delete(int $orgId, int $itemId, int $actorId): void
{
    $i = ecw_checklist_item($orgId, $itemId);
    if ($i === null) return;
    ecw_db()->prepare('DELETE FROM `' . ecw_table('case_checklist') . '` WHERE id = ?')->execute([$itemId]);
    ecw_case_log_event((int)$i['case_id'], 'CHECKLIST_REMOVED', $actorId, 'checklist', $itemId, ['text' => $i['text']]);
}

/** New order: the ids of the case's steps, top to bottom. */
function ecw_checklist_reorder(int $orgId, int $caseId, array $ids): void
{
    $pos = 10;
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('case_checklist') . '` i JOIN `' . ecw_table('cases') . '` c ON c.id = i.case_id
                                SET i.position = ? WHERE i.id = ? AND i.case_id = ? AND c.organization_id = ?');
    foreach (array_slice(array_map('intval', $ids), 0, ECW_CHECKLIST_MAX) as $id) { $st->execute([$pos, $id, $caseId, $orgId]); $pos += 10; }
}

/** On a step's due day, its person is reminded once. Called every minute by the daemon. */
function ecw_checklist_due_notify(): int
{
    try {
        $st = ecw_db()->prepare('SELECT i.id, i.text, i.due_date, i.assignee_user_id, c.id AS case_id, c.organization_id, c.case_number, c.owner_user_id
                                   FROM `' . ecw_table('case_checklist') . '` i JOIN `' . ecw_table('cases') . "` c ON c.id = i.case_id
                                  WHERE i.done_at IS NULL AND i.due_date IS NOT NULL AND i.due_date <= ? AND i.reminded_at IS NULL
                                    AND c.deleted_at IS NULL AND c.status <> 'closed' LIMIT 50");
        $st->execute([gmdate('Y-m-d', time() + 14 * 3600)]);   // the latest today anywhere; each step waits for its company's day below
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return 0; }
    require_once __DIR__ . '/notifications.php';
    $n = 0;
    foreach ($rows as $r) {
        require_once __DIR__ . '/timezone.php';
        $today = ecw_tz_company_today((int)$r['organization_id']);
        if ($r['due_date'] > $today) continue;
        $who = (int)($r['assignee_user_id'] ?: $r['owner_user_id']);
        if ($who > 0) {
            $late = $r['due_date'] < $today;
            ecw_notify((int)$r['organization_id'], $who, null, 'reminder', $r['case_number'] . ': "' . $r['text'] . '" is ' . ($late ? 'overdue (was due ' . $r['due_date'] . ')' : 'due today'),
                'case.php?id=' . (int)$r['case_id'] . '#checklist', (int)$r['case_id']);
            $n++;
        }
        ecw_db()->prepare('UPDATE `' . ecw_table('case_checklist') . '` SET reminded_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$r['id']]);
    }
    return $n;
}

// ------------------------------------------------------------------ templates

/** @return array<int,array> the organization's templates (archived ones only with $all) */
function ecw_case_templates(int $orgId, bool $all = false): array
{
    try {
        $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('case_templates') . '` WHERE organization_id = ?' . ($all ? '' : ' AND archived_at IS NULL') . ' ORDER BY name');
        $st->execute([$orgId]);
    } catch (Throwable $e) { return []; }
    return array_map('ecw_case_template_row', $st->fetchAll());
}

function ecw_case_template(int $orgId, int $id): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('case_templates') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $orgId]);
    $r = $st->fetch();
    return $r ? ecw_case_template_row($r) : null;
}

function ecw_case_template_row(array $r): array
{
    return ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'name_prefix' => $r['name_prefix'],
        'steps' => json_decode((string)$r['steps_json'], true) ?: [], 'tags' => json_decode((string)$r['tags_json'], true) ?: [],
        'due_days' => $r['due_days'] !== null ? (int)$r['due_days'] : null, 'group_id' => $r['group_id'] !== null ? (int)$r['group_id'] : null,
        'archived' => $r['archived_at'] !== null];
}

/**
 * Create or change a template. $in: name, description, name_prefix, due_days,
 * group_id, tags (array or comma text), steps [{text, days, who}].
 * @return int the template id
 */
function ecw_case_template_save(int $orgId, int $actorId, array $in, ?int $id = null): int
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Give the template a name.');
    $steps = [];
    foreach (array_slice((array)($in['steps'] ?? []), 0, ECW_CHECKLIST_MAX) as $s) {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)($s['text'] ?? '')));
        if ($text === '') continue;
        $days = isset($s['days']) && $s['days'] !== '' && $s['days'] !== null ? max(0, min(365, (int)$s['days'])) : null;
        $who = in_array($s['who'] ?? '', ['owner', 'handler'], true) ? (string)$s['who'] : null;
        $steps[] = ['text' => mb_substr($text, 0, 255), 'days' => $days, 'who' => $who];
    }
    $tags = is_array($in['tags'] ?? null) ? $in['tags'] : preg_split('/\s*,\s*/', (string)($in['tags'] ?? ''));
    $tags = array_values(array_unique(array_filter(array_map(static fn($t) => mb_strtolower(mb_substr(trim((string)$t), 0, 40)), (array)$tags))));
    $groupId = (int)($in['group_id'] ?? 0) ?: null;
    if ($groupId !== null) {
        $g = ecw_db()->prepare('SELECT id FROM `' . ecw_table('case_groups') . '` WHERE id = ? AND organization_id = ?');
        $g->execute([$groupId, $orgId]);
        if (!$g->fetchColumn()) $groupId = null;
    }
    $dueDays = isset($in['due_days']) && $in['due_days'] !== '' && $in['due_days'] !== null ? max(0, min(365, (int)$in['due_days'])) : null;
    $vals = [mb_substr($name, 0, 120), mb_substr(trim((string)($in['description'] ?? '')), 0, 500) ?: null, json_encode($steps, JSON_UNESCAPED_UNICODE),
        json_encode(array_slice($tags, 0, 10), JSON_UNESCAPED_UNICODE), $dueDays, $groupId, mb_substr(trim((string)($in['name_prefix'] ?? '')), 0, 60) ?: null];
    if ($id !== null) {
        if (ecw_case_template($orgId, $id) === null) throw new InvalidArgumentException('Template not found.');
        ecw_db()->prepare('UPDATE `' . ecw_table('case_templates') . '` SET name = ?, description = ?, steps_json = ?, tags_json = ?, due_days = ?, group_id = ?, name_prefix = ? WHERE id = ? AND organization_id = ?')
            ->execute(array_merge($vals, [$id, $orgId]));
        return $id;
    }
    ecw_db()->prepare('INSERT INTO `' . ecw_table('case_templates') . '` (name, description, steps_json, tags_json, due_days, group_id, name_prefix, organization_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array_merge($vals, [$orgId, $actorId]));
    return (int)ecw_db()->lastInsertId();
}

function ecw_case_template_archive(int $orgId, int $id, bool $archived = true): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('case_templates') . '` SET archived_at = ' . ($archived ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id = ? AND organization_id = ?')->execute([$id, $orgId]);
}

/**
 * Apply a template to a case: its steps (dated from today, given to the
 * case's Responsible / Handled by), its tags (added), the due date and the
 * group (only when the case has none). Steps already on the case are skipped.
 * @return array{steps:int, tags:int, due:?string, group:bool}
 */
function ecw_case_apply_template(int $orgId, int $caseId, int $templateId, int $actorId): array
{
    $t = ecw_case_template($orgId, $templateId);
    if ($t === null || $t['archived']) throw new InvalidArgumentException('Template not found.');
    $st = ecw_db()->prepare('SELECT id, owner_user_id, handler_user_id, due_at, group_id FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
    $st->execute([$caseId, $orgId]);
    $case = $st->fetch();
    if (!$case) throw new InvalidArgumentException('Case not found.');
    $have = array_map(static fn($i) => mb_strtolower($i['text']), ecw_checklist($caseId));
    $out = ['steps' => 0, 'tags' => 0, 'due' => null, 'group' => false];
    foreach ($t['steps'] as $s) {
        if (in_array(mb_strtolower($s['text']), $have, true)) continue;
        $who = $s['who'] === 'owner' ? $case['owner_user_id'] : ($s['who'] === 'handler' ? $case['handler_user_id'] : null);
        $due = $s['days'] !== null ? gmdate('Y-m-d', time() + (int)$s['days'] * 86400) : null;
        ecw_checklist_add($orgId, $caseId, $actorId, $s['text'], $who !== null ? (int)$who : null, $due, $templateId);
        $out['steps']++;
    }
    if ($t['tags']) {
        require_once __DIR__ . '/case_extras.php';
        $before = ecw_case_tags($caseId);
        $after = ecw_case_tags_save($caseId, array_merge($before, $t['tags']), $actorId);
        $out['tags'] = count($after) - count($before);
    }
    if ($t['due_days'] !== null && empty($case['due_at'])) {
        $due = gmdate('Y-m-d', time() + $t['due_days'] * 86400);
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET due_at = ?, due_notified_at = NULL WHERE id = ?')->execute([$due, $caseId]);
        ecw_case_log_event($caseId, 'CASE_DUE_CHANGED', $actorId, null, null, ['from' => null, 'to' => $due]);
        $out['due'] = $due;
    }
    if ($t['group_id'] !== null && empty($case['group_id'])) {
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET group_id = ? WHERE id = ?')->execute([$t['group_id'], $caseId]);
        $out['group'] = true;
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET template_id = COALESCE(template_id, ?) WHERE id = ?')->execute([$templateId, $caseId]);
    ecw_case_log_event($caseId, 'CASE_TEMPLATE_APPLIED', $actorId, 'template', $templateId, ['name' => $t['name'], 'steps' => $out['steps']]);
    return $out;
}

/** The case name with the template's prefix in front ("Complaint · Damaged pallet"). */
function ecw_case_template_name(array $t, string $name): string
{
    $name = trim($name);
    $prefix = trim((string)($t['name_prefix'] ?? ''));
    if ($prefix !== '' && !str_starts_with($name, $prefix)) $name = $prefix . ' ' . $name;
    return mb_substr($name !== '' ? $name : (string)$t['name'], 0, 120);
}

/** Create a case from a template (the template's name prefix goes in front). @return int the case id */
function ecw_case_create_from_template(int $orgId, int $actorId, string $name, int $templateId): int
{
    $t = ecw_case_template($orgId, $templateId);
    if ($t === null || $t['archived']) throw new InvalidArgumentException('Template not found.');
    $caseId = ecw_case_create($orgId, ecw_case_template_name($t, $name), $actorId);
    ecw_case_apply_template($orgId, $caseId, $templateId, $actorId);
    return $caseId;
}

/** A few ready-made templates an admin can start from (Settings → Case templates). */
const ECW_TEMPLATE_STARTERS = [
    'New customer' => ['name_prefix' => 'New customer · ', 'tags' => ['new customer'], 'due_days' => 14, 'steps' => [
        ['text' => 'Check company details and VAT number', 'days' => 1, 'who' => 'owner'],
        ['text' => 'Send price list and terms', 'days' => 2, 'who' => 'owner'],
        ['text' => 'Create the customer in the accounting system', 'days' => 3, 'who' => 'handler'],
        ['text' => 'Agree the first order and delivery address', 'days' => 7, 'who' => 'owner'],
        ['text' => 'Welcome call after the first delivery', 'days' => 14, 'who' => 'owner']]],
    'Complaint' => ['name_prefix' => 'Complaint · ', 'tags' => ['complaint'], 'due_days' => 7, 'steps' => [
        ['text' => 'Confirm receipt to the customer (same day)', 'days' => 0, 'who' => 'owner'],
        ['text' => 'Collect photos, order number and batch / serial number', 'days' => 1, 'who' => 'handler'],
        ['text' => 'Find the cause with production / the supplier', 'days' => 3, 'who' => 'handler'],
        ['text' => 'Propose a solution (replace, repair, credit)', 'days' => 5, 'who' => 'owner'],
        ['text' => 'Close with the customer and note what we change', 'days' => 7, 'who' => 'owner']]],
    'Warranty claim' => ['name_prefix' => 'Warranty · ', 'tags' => ['warranty'], 'due_days' => 21, 'steps' => [
        ['text' => 'Check the purchase date and warranty terms', 'days' => 1, 'who' => 'handler'],
        ['text' => 'Ask for the product to be returned (RMA number)', 'days' => 2, 'who' => 'handler'],
        ['text' => 'Inspect the returned product', 'days' => 10, 'who' => 'handler'],
        ['text' => 'Decide: repair, replace or refuse (with reason)', 'days' => 14, 'who' => 'owner'],
        ['text' => 'Send back / credit and inform the customer', 'days' => 21, 'who' => 'owner']]],
    'Support request' => ['name_prefix' => 'Support · ', 'tags' => ['support'], 'due_days' => 3, 'steps' => [
        ['text' => 'Confirm we received it and when we will answer', 'days' => 0, 'who' => 'owner'],
        ['text' => 'Reproduce or understand the problem; ask for what is missing', 'days' => 1, 'who' => 'handler'],
        ['text' => 'Solve it, or agree the next step with the customer', 'days' => 3, 'who' => 'handler'],
        ['text' => 'Check with the customer that it is solved', 'days' => 5, 'who' => 'owner']]],
];
