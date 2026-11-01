<?php declare(strict_types=1);
/**
 * Email Case Workspace — mail rules.
 *
 * Two kinds share one condition language:
 *   color  personal (user_id set): tint matching threads in that user's list,
 *          e.g. "my address is in To -> blue". Evaluated when the list is drawn,
 *          so a new rule applies to old mail immediately.
 *   route  organization-wide (admins): act on fresh incoming mail that is not in
 *          a case yet -> add to a case, or open a new case in a group with a
 *          Responsible. Evaluated once, at ingest (lib/automation.php).
 *
 * Conditions: {"match":"all"|"any","items":[{"field","op","value"}]}
 *   field: from | to | cc | to_cc | any | subject
 *   op:    contains | is | domain | me      ("me": one of my addresses / my name)
 */

require_once __DIR__ . '/db.php';

const ECW_RULE_FIELDS = ['from' => 'From', 'to' => 'To', 'cc' => 'Cc', 'to_cc' => 'To or Cc', 'any' => 'Anyone on it', 'subject' => 'Subject',
                         'body' => 'Message text', 'attachment' => 'Attachments', 'mailbox' => 'Mailbox', 'folder' => 'Folder'];
const ECW_RULE_OPS    = ['contains' => 'contains', 'not_contains' => 'does not contain', 'is' => 'is exactly', 'domain' => 'is from the domain',
                         'me' => 'includes me', 'exists' => 'has any'];
/** Which operators make sense for which field (the editor offers only these). */
const ECW_RULE_FIELD_OPS = [
    'people'     => ['contains', 'not_contains', 'is', 'domain', 'me'],
    'subject'    => ['contains', 'not_contains', 'is'],
    'body'       => ['contains', 'not_contains'],
    'attachment' => ['exists'],
    'mailbox'    => ['is'],
    'folder'     => ['is'],   // the email is in that folder (an Outlook rule may have put it there)
];
/**
 * What an automation rule can do (kind "route", admins). A rule may do several.
 * Case actions only touch mail that is not in a case yet; the rest act on the
 * conversation in its mailbox and reach Outlook / Gmail like a person would.
 */
const ECW_RULE_ACTIONS = [
    'create_case' => 'Open a new case',
    'add_to_case' => 'Add to an existing case',
    'set_owner'   => 'Set Responsible on its case',
    'tag'         => 'Tag its case',
    'move'        => 'Move to a folder',
    'flag'        => 'Flag it',
    'mark_read'   => 'Mark as read',
    'snooze'      => 'Snooze it',
    'notify'      => 'Notify someone',
    'auto_reply'  => 'Reply automatically with a template',
    'webhook'     => 'Send to the webhook (Integrations)',
];
const ECW_RULE_MAX_ACTIONS = 8;

function ecw_rule_field_group(string $field): string
{
    return in_array($field, ['subject', 'body', 'attachment', 'mailbox', 'folder'], true) ? $field : 'people';
}
const ECW_RULE_COLORS = ['#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#64748b'];

/** Validate and normalize a conditions payload from the browser. */
function ecw_rule_conditions(array $input): array
{
    $items = [];
    foreach (array_slice((array)($input['items'] ?? []), 0, 10) as $c) {
        $field = (string)($c['field'] ?? '');
        $op    = (string)($c['op'] ?? '');
        $value = mb_strimwidth(trim((string)($c['value'] ?? '')), 0, 200, '');
        if (!isset(ECW_RULE_FIELDS[$field]) || !isset(ECW_RULE_OPS[$op])) {
            continue;
        }
        if (!in_array($op, ECW_RULE_FIELD_OPS[ecw_rule_field_group($field)], true)) {
            continue;
        }
        if (!in_array($op, ['me', 'exists'], true) && $value === '') {
            continue;
        }
        if ($op === 'domain') {
            $value = ltrim(strtolower($value), '@');
        }
        if (($field === 'folder' || $field === 'mailbox') && !ctype_digit($value)) {
            continue;   // a folder / mailbox id
        }
        $items[] = ['field' => $field, 'op' => $op, 'value' => $value];
    }
    if ($items === []) {
        throw new InvalidArgumentException('Add at least one complete condition.');
    }
    return ['match' => ($input['match'] ?? 'all') === 'any' ? 'any' : 'all', 'items' => $items];
}

/**
 * @param array $msg  ['from'=>['email','name'], 'to'=>[[email,name]..], 'cc'=>[..], 'subject'=>string]
 * @param array $me   ['emails'=>string[], 'name'=>string]
 */
function ecw_rule_matches(array $conditions, array $msg, array $me): bool
{
    $results = [];
    foreach ($conditions['items'] ?? [] as $c) {
        $results[] = ecw_rule_condition_matches($c, $msg, $me);
    }
    if ($results === []) {
        return false;
    }
    return ($conditions['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
}

function ecw_rule_condition_matches(array $c, array $msg, array $me): bool
{
    $value = mb_strtolower((string)($c['value'] ?? ''));
    if ($c['field'] === 'subject' || $c['field'] === 'body') {
        $text = mb_strtolower((string)($msg[$c['field']] ?? ''));
        return match ($c['op']) {
            'is'           => $text === $value,
            'not_contains' => $value !== '' && !str_contains($text, $value),
            default        => $value !== '' && str_contains($text, $value),
        };
    }
    if ($c['field'] === 'attachment') {
        return !empty($msg['has_attachments']);
    }
    if ($c['field'] === 'mailbox') {
        return (string)($msg['mailbox_id'] ?? '') === (string)($c['value'] ?? '');
    }
    if ($c['field'] === 'folder') {
        return (string)($msg['folder_id'] ?? '') === (string)($c['value'] ?? '');
    }
    if ($c['op'] === 'not_contains') {
        // Nobody in that field matches.
        return !ecw_rule_condition_matches(['op' => 'contains'] + $c, $msg, $me);
    }

    $people = match ($c['field']) {
        'from'  => [$msg['from'] ?? []],
        'to'    => $msg['to'] ?? [],
        'cc'    => $msg['cc'] ?? [],
        'to_cc' => array_merge($msg['to'] ?? [], $msg['cc'] ?? []),
        default => array_merge([$msg['from'] ?? []], $msg['to'] ?? [], $msg['cc'] ?? []),
    };
    $myEmails = array_map('mb_strtolower', $me['emails'] ?? []);
    $myName   = mb_strtolower(trim((string)($me['name'] ?? '')));
    // A whole address ("support@inquality.gr") is that address only, not one ending in it
    // ("sales.support@inquality.gr"). A part ("support", "@inquality.gr", a name) is found anywhere.
    $wholeAddress = (bool)preg_match('/^[^@\s]+@[^@\s]+$/u', $value);

    foreach ($people as $p) {
        $email = mb_strtolower((string)($p['email'] ?? ''));
        $name  = mb_strtolower((string)($p['name'] ?? ''));
        $hit = match ($c['op']) {
            'me'       => ($email !== '' && in_array($email, $myEmails, true)) || ($myName !== '' && $name !== '' && str_contains($name, $myName)),
            'is'       => $email === $value,
            'domain'   => $value !== '' && str_ends_with($email, '@' . $value),
            default    => $value !== '' && ($wholeAddress ? $email === $value : (str_contains($email, $value) || str_contains($name, $value))),
        };
        if ($hit) {
            return true;
        }
    }
    return false;
}

/** The folder an email is in now (for the "Folder is" condition). */
function ecw_rule_message_folder(int $messageId): ?int
{
    $st = ecw_db()->prepare('SELECT mail_folder_id FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
    $st->execute([$messageId]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int)$v;
}

function ecw_rules_list(int $orgId, string $kind, ?int $userId): array
{
    $sql = 'SELECT * FROM `' . ecw_table('rules') . '` WHERE organization_id = ? AND kind = ?';
    $params = [$orgId, $kind];
    if ($kind === 'color') {
        // Colour rules are personal, per account (lib/account.php; NULL = every account).
        require_once __DIR__ . '/account.php';
        $sql .= ' AND user_id = ? AND (mailbox_id IS NULL OR mailbox_id <=> ?)';
        $params[] = (int)$userId;
        $params[] = ecw_active_account_id($orgId, (int)$userId);
    } else {
        $sql .= ' AND user_id IS NULL';
    }
    $st = ecw_db()->prepare($sql . ' ORDER BY sort_order, id');
    $st->execute($params);
    $rows = $st->fetchAll();
    // How often each automation rule ran, and when last (Settings → Rules shows it).
    $runs = [];
    if ($kind === 'route' && $rows !== []) {
        try {
            $ids = implode(',', array_map(static fn($r) => (int)$r['id'], $rows));
            foreach (ecw_db()->query('SELECT rule_id, COUNT(*) n, MAX(created_at) last_at FROM `' . ecw_table('rule_runs') . "` WHERE rule_id IN ({$ids}) GROUP BY rule_id") as $x) {
                $runs[(int)$x['rule_id']] = ['count' => (int)$x['n'], 'last_at' => $x['last_at']];
            }
        } catch (Throwable $e) { /* before migration 027 */ }
    }
    // Rules running on earlier emails right now (lib/rule_backfill.php): the page shows their progress.
    $earlier = [];
    if ($kind === 'route' && $rows !== []) {
        try {
            foreach (ecw_db()->query("SELECT DISTINCT rule_id FROM `" . ecw_table('rule_backfills') . "` WHERE status = 'running'") as $x) $earlier[(int)$x['rule_id']] = true;
        } catch (Throwable $e) { /* before migration 052 */ }
    }
    return array_map(static function (array $r) use ($kind, $runs, $earlier): array {
        $actions = json_decode((string)$r['actions_json'], true) ?: [];
        return [
            'id' => (int)$r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'enabled' => (bool)(int)$r['enabled'],
            'conditions' => json_decode((string)$r['conditions_json'], true) ?: [],
            'actions' => $kind === 'route' ? ecw_rule_route_actions($actions) : $actions,
            'sort_order' => (int)$r['sort_order'],
            'runs' => $runs[(int)$r['id']] ?? ['count' => 0, 'last_at' => null],
            'earlier_running' => isset($earlier[(int)$r['id']]),
        ];
    }, $rows);
}

/**
 * Create/update a rule. Colour rules belong to the caller; routing rules are
 * admin-only and their targets (case, group, user) must be in the organization.
 */
function ecw_rule_save(int $orgId, int $userId, bool $isAdmin, array $data): int
{
    $kind = ($data['kind'] ?? '') === 'route' ? 'route' : 'color';
    if ($kind === 'route' && !$isAdmin) {
        throw new InvalidArgumentException('Only admins can change routing rules.');
    }
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Give the rule a name.');
    }
    $conditions = ecw_rule_conditions((array)($data['conditions'] ?? []));
    $actions = ecw_rule_actions($orgId, $kind, (array)($data['actions'] ?? []));

    $id = (int)($data['id'] ?? 0);
    $owner = $kind === 'color' ? $userId : null;
    if ($id > 0) {
        $st = ecw_db()->prepare('UPDATE `' . ecw_table('rules') . '` SET name = ?, conditions_json = ?, actions_json = ?, enabled = ?
                                  WHERE id = ? AND organization_id = ? AND kind = ? AND ' . ($owner === null ? 'user_id IS NULL' : 'user_id = ' . (int)$owner));
        $st->execute([$name, json_encode($conditions), json_encode($actions), empty($data['disabled']) ? 1 : 0, $id, $orgId, $kind]);
        if ($st->rowCount() === 0) {
            $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('rules') . '` WHERE id = ? AND organization_id = ? AND kind = ? AND ' . ($owner === null ? 'user_id IS NULL' : 'user_id = ' . (int)$owner));
            $chk->execute([$id, $orgId, $kind]);
            if (!$chk->fetchColumn()) {
                throw new InvalidArgumentException('Rule not found.');
            }
        }
        return $id;
    }
    $order = ecw_db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `' . ecw_table('rules') . '` WHERE organization_id = ? AND kind = ?');
    $order->execute([$orgId, $kind]);
    require_once __DIR__ . '/account.php';
    $account = $kind === 'color' ? ecw_active_account_id($orgId, $userId) : null;
    ecw_db()->prepare('INSERT INTO `' . ecw_table('rules') . '` (organization_id, user_id, kind, name, conditions_json, actions_json, sort_order, enabled, created_by, created_at, mailbox_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $owner, $kind, $name, json_encode($conditions), json_encode($actions), (int)$order->fetchColumn(), empty($data['disabled']) ? 1 : 0, $userId, ecw_now(), $account]);
    return (int)ecw_db()->lastInsertId();
}

function ecw_rule_actions(int $orgId, string $kind, array $a): array
{
    if ($kind === 'color') {
        $color = strtolower((string)($a['color'] ?? ''));
        if (!preg_match('/^#[0-9a-f]{6}$/D', $color)) {
            throw new InvalidArgumentException('Pick a colour.');
        }
        return ['color' => $color];
    }
    // Automation rules: a list of actions, plus "stop here" and "first email only".
    $items = isset($a['list']) && is_array($a['list']) ? $a['list'] : [$a];   // old rules had one action
    $list = [];
    foreach (array_slice($items, 0, ECW_RULE_MAX_ACTIONS) as $item) {
        if (is_array($item) && ($item['type'] ?? '') !== '') $list[] = ecw_rule_action($orgId, $item);
    }
    if ($list === []) throw new InvalidArgumentException('Choose what the rule does.');
    $caseActs = count(array_filter($list, static fn($x) => in_array($x['type'], ['create_case', 'add_to_case'], true)));
    if ($caseActs > 1) throw new InvalidArgumentException('A rule can open a case or add to one — not both.');
    foreach (array_count_values(array_column($list, 'type')) as $t => $n) {
        if ($n > 1 && !in_array($t, ['notify', 'tag'], true)) {
            throw new InvalidArgumentException('Each kind of action only once per rule (notify and tag may repeat).');
        }
    }
    return ['list' => $list, 'stop' => !array_key_exists('stop', $a) || !empty($a['stop']), 'first_only' => !empty($a['first_only'])];
}

/** The actions of a saved automation rule, old (one action) or new (a list). */
function ecw_rule_route_actions(array $actions): array
{
    if (isset($actions['list']) && is_array($actions['list'])) {
        return ['list' => $actions['list'], 'stop' => !array_key_exists('stop', $actions) || !empty($actions['stop']), 'first_only' => !empty($actions['first_only'])];
    }
    return ['list' => isset($actions['type']) ? [$actions] : [], 'stop' => true, 'first_only' => false];
}

/** Validate one automation action. */
function ecw_rule_action(int $orgId, array $a): array
{
    $type = (string)($a['type'] ?? '');
    $inOrg = static function (int $userId) use ($orgId): bool {
        $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('organization_users') . '` WHERE organization_id = ? AND user_id = ?');
        $st->execute([$orgId, $userId]);
        return (bool)$st->fetchColumn();
    };
    switch ($type) {
        case 'set_owner':
        case 'notify':
            if (empty($a['user_id']) || !$inOrg((int)$a['user_id'])) throw new InvalidArgumentException('Choose a person in this workspace.');
            return ['type' => $type, 'user_id' => (int)$a['user_id']];
        case 'tag':
            $tag = trim(mb_strtolower((string)($a['tag'] ?? '')));
            $tag = (string)preg_replace('/[^\p{L}\p{N} _\-]/u', '', $tag);
            if ($tag === '' || mb_strlen($tag) > 40) throw new InvalidArgumentException('Write the tag (up to 40 letters).');
            return ['type' => 'tag', 'tag' => $tag];
        case 'move':
            $st = ecw_db()->prepare('SELECT f.id, f.mailbox_id FROM `' . ecw_table('mail_folders') . '` f JOIN `' . ecw_table('mailboxes') . "` m ON m.id = f.mailbox_id
                                      WHERE f.id = ? AND m.organization_id = ? AND f.folder_type NOT IN ('sent', 'drafts')");
            $st->execute([(int)($a['folder_id'] ?? 0), $orgId]);
            $f = $st->fetch();
            if (!$f) throw new InvalidArgumentException('Choose the folder to move it to.');
            return ['type' => 'move', 'folder_id' => (int)$f['id'], 'mailbox_id' => (int)$f['mailbox_id']];
        case 'flag':
        case 'mark_read':
        case 'webhook':
            return ['type' => $type];
        case 'snooze':
            $hours = (int)($a['hours'] ?? 0);
            if ($hours < 1 || $hours > 24 * 30) throw new InvalidArgumentException('Snooze for 1 hour to 30 days.');
            return ['type' => 'snooze', 'hours' => $hours];
        case 'auto_reply':
            $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('templates') . '` WHERE id = ? AND organization_id = ? AND user_id IS NULL');
            $st->execute([(int)($a['template_id'] ?? 0), $orgId]);
            if (!$st->fetchColumn()) throw new InvalidArgumentException('Choose a shared reply template for the automatic reply.');
            return ['type' => 'auto_reply', 'template_id' => (int)$a['template_id']];
    }
    if ($type === 'add_to_case') {
        $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $st->execute([(int)($a['case_id'] ?? 0), $orgId]);
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('Choose the case to add mail to.');
        }
        return ['type' => 'add_to_case', 'case_id' => (int)$a['case_id']];
    }
    if ($type === 'create_case') {
        $out = ['type' => 'create_case', 'group_id' => null, 'owner_user_id' => null];
        if (!empty($a['group_id'])) {
            $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('case_groups') . '` WHERE id = ? AND organization_id = ?');
            $st->execute([(int)$a['group_id'], $orgId]);
            if (!$st->fetchColumn()) {
                throw new InvalidArgumentException('Group not found.');
            }
            $out['group_id'] = (int)$a['group_id'];
        }
        if (!empty($a['owner_user_id'])) {
            $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('organization_users') . '` WHERE organization_id = ? AND user_id = ?');
            $st->execute([$orgId, (int)$a['owner_user_id']]);
            if (!$st->fetchColumn()) {
                throw new InvalidArgumentException('That person is not in this workspace.');
            }
            $out['owner_user_id'] = (int)$a['owner_user_id'];
        }
        return $out;
    }
    throw new InvalidArgumentException('Choose what the rule does.');
}

function ecw_rule_delete(int $orgId, int $userId, bool $isAdmin, int $id): bool
{
    $st = ecw_db()->prepare('SELECT kind, user_id FROM `' . ecw_table('rules') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $orgId]);
    $r = $st->fetch();
    if ($r === false || ($r['kind'] === 'color' ? (int)$r['user_id'] !== $userId : !$isAdmin)) {
        return false;
    }
    ecw_db()->prepare('DELETE FROM `' . ecw_table('rules') . '` WHERE id = ?')->execute([$id]);
    return true;
}

/** Who "me" is for a user: their name and every mailbox address they connected. */
function ecw_rule_me(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $st = ecw_db()->prepare('SELECT name, email FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch() ?: ['name' => '', 'email' => ''];
    $st = ecw_db()->prepare('SELECT email_address FROM `' . ecw_table('mailboxes') . '` WHERE connected_by_user_id = ?');
    $st->execute([$userId]);
    $emails = array_filter(array_merge([(string)$u['email']], $st->fetchAll(PDO::FETCH_COLUMN)));
    return $cache[$userId] = ['emails' => array_values(array_unique($emails)), 'name' => (string)$u['name']];
}

/**
 * Colours for a page of thread-list rows, from the viewer's colour rules,
 * tested against each thread's newest received message (by date, not by id:
 * a sync can store an older email after a newer one), or its newest message
 * when nothing was received. Our own reply must not take a "to me" colour
 * away. One query for the page.
 *
 * $shown: thread id => the received email its row shows (Inbox: the newest one in the
 * Inbox). That email is judged, so "To contains support@" colours only rows whose email has
 * support@ in To, not one where it is in Cc while an earlier email had it in To.
 *
 * @param int[] $threadIds
 * @param array<int,int> $shown
 * @return array<int,string> thread id => colour
 */
function ecw_rule_colors_for_threads(int $orgId, int $userId, array $threadIds, array $shown = []): array
{
    $rules = array_values(array_filter(ecw_rules_list($orgId, 'color', $userId), static fn(array $r): bool => $r['enabled']));
    if ($rules === [] || $threadIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $threadIds));
    $msgs = ecw_db()->query(
        'SELECT email_thread_id, subject, from_email, from_name, to_json, cc_json FROM (
           SELECT m.*, ROW_NUMBER() OVER (PARTITION BY m.email_thread_id
                    ORDER BY m.direction = \'incoming\' DESC, m.occurred_at DESC, m.id DESC) AS pick
             FROM `' . ecw_table('email_messages') . "` m
            WHERE m.email_thread_id IN ({$in}) AND m.provider_removed_at IS NULL AND m.direction <> 'draft') l
          WHERE pick = 1"
    )->fetchAll();
    $shownIds = array_values(array_filter(array_map('intval', $shown)));
    if ($shownIds !== []) {
        $byThread = [];
        foreach ($msgs as $m) $byThread[(int)$m['email_thread_id']] = $m;
        foreach (ecw_db()->query('SELECT id, email_thread_id, subject, from_email, from_name, to_json, cc_json FROM `' . ecw_table('email_messages') . '`
                                   WHERE id IN (' . implode(',', $shownIds) . ')') as $m) {
            if ((int)($shown[(int)$m['email_thread_id']] ?? 0) === (int)$m['id']) $byThread[(int)$m['email_thread_id']] = $m;
        }
        $msgs = array_values($byThread);
    }
    $me = ecw_rule_me($userId);
    $out = [];
    foreach ($msgs as $m) {
        $msg = [
            'from' => ['email' => $m['from_email'], 'name' => $m['from_name']],
            'to' => json_decode((string)$m['to_json'], true) ?: [],
            'cc' => json_decode((string)$m['cc_json'], true) ?: [],
            'subject' => $m['subject'],
        ];
        foreach ($rules as $rule) {
            if (ecw_rule_matches($rule['conditions'], $msg, $me)) {
                $out[(int)$m['email_thread_id']] = $rule['actions']['color'];
                break;   // first matching rule wins, in the order shown in Settings
            }
        }
    }
    return $out;
}
