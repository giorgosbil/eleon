<?php declare(strict_types=1);
/**
 * Email Case Workspace — case creation and thread linking.
 *
 * Step 02 needs exactly this much of the case layer: the "Create Case" mail
 * folder has to be able to draft a case and link a thread to it. The case UI,
 * status workflow and timeline belong to their own steps.
 *
 * CASE NUMBER ALLOCATION
 * ----------------------
 * MAX+1 inside a transaction, retried on duplicate key. There is no counter
 * table because the unique key (organization_id, case_number) already makes a
 * collision impossible to persist — two racing allocators produce one winner
 * and one 1062, and the loser simply tries again with the new maximum. A
 * counter row would add a hot lock for a table that gets a handful of inserts
 * a day.
 *
 * The numeric part is parsed back out of the stored display form rather than
 * kept in its own column: one source of truth, and CASE-00142 sorts and reads
 * the same everywhere.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/timezone.php';   // days at the company (due filters)
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/avatars.php';

const ECW_CASE_NUMBER_PREFIX = 'CASE-';
const ECW_CASE_NUMBER_PAD    = 5;

function ecw_case_next_number(int $organizationId): string
{
    // The company's format (Settings → Case settings, lib/case_settings.php): CASE-{N} by default.
    // With the year in it ({YYYY}), the running number starts again each year.
    require_once __DIR__ . '/case_settings.php';
    [$prefix, $pad] = ecw_case_number_parts($organizationId);
    $st = ecw_db()->prepare(
        'SELECT MAX(CAST(SUBSTRING(case_number, ?) AS UNSIGNED))
           FROM `' . ecw_table('cases') . '`
          WHERE organization_id = ? AND case_number LIKE ? AND SUBSTRING(case_number, ?) REGEXP \'^[0-9]+$\''
    );
    $like = addcslashes($prefix, '%_\\') . '%';
    $st->execute([strlen($prefix) + 1, $organizationId, $like, strlen($prefix) + 1]);
    $max = (int)($st->fetchColumn() ?: 0);

    return $prefix . str_pad((string)($max + 1), $pad, '0', STR_PAD_LEFT);
}

/** @return int the new case id */
function ecw_case_create(int $organizationId, string $name, ?int $userId = null, string $status = 'open', ?int $mailboxId = null): int
{
    $pdo = ecw_db();
    // The account it belongs to (lib/account.php): given, or the creator's chosen account
    // for a case made by hand. A case opened from an email takes that email's mailbox when linked.
    if ($mailboxId === null && $userId !== null && PHP_SAPI !== 'cli') {
        require_once __DIR__ . '/account.php';
        $mailboxId = ecw_active_account_id($organizationId, $userId);
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $number = ecw_case_next_number($organizationId);
        try {
            // A due date ahead when the company wants one (Settings → Case settings).
            $due = ecw_case_default_due($organizationId);
            $st = $pdo->prepare(
                'INSERT INTO `' . ecw_table('cases') . '`
                    (organization_id, case_number, name, status, owner_user_id, created_by_user_id, mailbox_id, due_at, status_changed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $st->execute([$organizationId, $number, $name, $status, $userId, $userId, $mailboxId, $due]);
            $caseId = (int)$pdo->lastInsertId();

            ecw_case_log_event($caseId, 'CASE_CREATED', $userId, null, null, ['case_number' => $number]);
            return $caseId;
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
            // Another allocator took this number between our MAX and our INSERT.
            // Recompute and try again — that is the whole concurrency story.
            ecw_log("case number {$number} taken, retrying allocation");
        }
    }

    throw new RuntimeException("ecw: could not allocate a case number for organization {$organizationId}");
}

/**
 * Link a thread to a case. Returns the case_threads id.
 *
 * A thread already linked to THIS case is a no-op; one linked to a DIFFERENT
 * case throws, because the unique key enforces the V1 rule and silently
 * re-pointing someone's thread would be worse than failing.
 */
function ecw_case_link_thread(int $caseId, int $threadId, ?string $displayName = null, ?int $userId = null): int
{
    $pdo = ecw_db();

    $st = $pdo->prepare('SELECT id, case_id FROM `' . ecw_table('case_threads') . '` WHERE email_thread_id = ?');
    $st->execute([$threadId]);
    $existing = $st->fetch();

    if ($existing !== false) {
        if ((int)$existing['case_id'] === $caseId) {
            return (int)$existing['id'];
        }
        // A deleted case keeps its conversations (so Restore brings them back), but
        // it must not hold them hostage: linking elsewhere takes the conversation out,
        // unless that case is on legal hold.
        $old = $pdo->prepare('SELECT deleted_at, legal_hold_at FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $old->execute([(int)$existing['case_id']]);
        $oldCase = $old->fetch();
        if ($oldCase && $oldCase['deleted_at'] !== null && $oldCase['legal_hold_at'] === null) {
            $pdo->prepare('DELETE FROM `' . ecw_table('case_threads') . '` WHERE id = ?')->execute([(int)$existing['id']]);
            ecw_case_log_event((int)$existing['case_id'], 'THREAD_UNLINKED', $userId, 'email_thread', $threadId, ['moved_to_case' => $caseId]);
            $existing = false;
        }
    }
    if ($existing !== false) {
        throw new RuntimeException(
            "ecw: thread {$threadId} is already linked to case {$existing['case_id']}; unlink it first"
        );
    }

    $order = (int)$pdo->query(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM `' . ecw_table('case_threads') . '` WHERE case_id = ' . $caseId
    )->fetchColumn();

    $st = $pdo->prepare(
        'INSERT INTO `' . ecw_table('case_threads') . '`
            (case_id, email_thread_id, display_name, sort_order, linked_by_user_id, linked_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$caseId, $threadId, $displayName, $order, $userId, ecw_now()]);
    $linkId = (int)$pdo->lastInsertId();
    // A case without an account takes the account of its first conversation.
    $pdo->prepare('UPDATE `' . ecw_table('cases') . '` c SET c.mailbox_id = (SELECT t.mailbox_id FROM `' . ecw_table('email_threads') . '` t WHERE t.id = ?)
                    WHERE c.id = ? AND c.mailbox_id IS NULL')->execute([$threadId, $caseId]);

    ecw_case_log_event($caseId, 'THREAD_LINKED', $userId, 'email_thread', $threadId, ['display_name' => $displayName]);
    // Its emails get the case number (and tags) as Outlook categories.
    require_once __DIR__ . '/categories.php';
    ecw_categories_mark_threads([$threadId]);
    return $linkId;
}

function ecw_case_log_event(
    int $caseId,
    string $eventType,
    ?int $userId = null,
    ?string $entityType = null,
    ?int $entityId = null,
    array $metadata = []
): void {
    ecw_db()->prepare(
        'INSERT INTO `' . ecw_table('case_events') . '`
            (case_id, user_id, event_type, entity_type, entity_id, metadata_json, occurred_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $caseId,
        $userId,
        $eventType,
        $entityType,
        $entityId,
        $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ecw_now(),
    ]);
    // The events that matter for compliance also go to the audit log.
    $audit = ['CASE_CREATED' => 'case.create', 'CASE_DELETED' => 'case.delete', 'CASE_RESTORED' => 'case.restore',
        'CASE_SHARING_CHANGED' => 'case.sharing', 'CASE_MERGED_INTO' => 'case.merge'][$eventType] ?? null;
    if ($audit !== null) {
        require_once __DIR__ . '/audit.php';
        $st = ecw_db()->prepare('SELECT organization_id, case_number FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $st->execute([$caseId]);
        $c = $st->fetch() ?: [];
        ecw_audit($audit, 'case', $caseId, ['case' => $c['case_number'] ?? null] + array_intersect_key($metadata, array_flip(['visibility', 'member_count', 'into', 'target_case'])),
            isset($c['organization_id']) ? (int)$c['organization_id'] : null, $userId, $userId === null ? 'worker' : '');
    }
}

/**
 * The case a thread belongs to, or null.
 *
 * Deliberately NOT filtered on deleted_at: lib/ingest.php's case-intake-folder
 * check uses this to decide whether a thread already belongs to a case before
 * drafting a new one. The uq_ecw_case_threads_thread key means a thread can
 * only ever point at one case row regardless of its deleted state, so
 * excluding deleted cases here would make that guard see the thread as
 * unclaimed and try to create a second case for it — which
 * ecw_case_link_thread() would then reject with "already linked to case X",
 * a RuntimeException the intake path does not catch.
 */
function ecw_case_for_thread(int $threadId): ?array
{
    $st = ecw_db()->prepare(
        'SELECT c.* FROM `' . ecw_table('cases') . '` c
           JOIN `' . ecw_table('case_threads') . '` ct ON ct.case_id = c.id
          WHERE ct.email_thread_id = ?'
    );
    $st->execute([$threadId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * The case that already holds another mailbox's copy of this conversation, or null. One email
 * sent to two colleagues is two conversations, one in each mailbox (same Message-ID): once
 * one copy is in a case, automation (references, rules, case groups, the assistant) must not
 * link the other copy too, or the case shows the conversation twice, nor start a second case.
 */
function ecw_case_for_copy(int $threadId): ?array
{
    $m = ecw_table('email_messages');
    $st = ecw_db()->prepare(
        'SELECT c.* FROM `' . $m . '` mine
           JOIN `' . $m . '` other ON other.internet_message_id = mine.internet_message_id AND other.email_thread_id <> mine.email_thread_id
           JOIN `' . ecw_table('case_threads') . '` ct ON ct.email_thread_id = other.email_thread_id
           JOIN `' . ecw_table('cases') . "` c ON c.id = ct.case_id AND c.deleted_at IS NULL
          WHERE mine.email_thread_id = ? AND mine.internet_message_id IS NOT NULL AND mine.direction <> 'draft'
          LIMIT 1"
    );
    $st->execute([$threadId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * Task 06 — filters for the case list (web/cases.php and the case_list API
 * action share every one of these; there is exactly one implementation of
 * the filtering SQL).
 */

const ECW_CASE_LIST_PAGE_SIZE = 50;

/** Whitelist: URL value => {column in the derived case-list rowset, default direction}. */
const ECW_CASE_SORTS = [
    'last_activity' => ['column' => 'last_activity', 'dir' => 'DESC'],
    'created'        => ['column' => 'created_at',    'dir' => 'DESC'],
    'name'           => ['column' => 'name',           'dir' => 'ASC'],
    'threads'        => ['column' => 'thread_count',  'dir' => 'DESC'],
    // The column menus of the case table (assets/caseList.js).
    'number'         => ['column' => 'case_number',   'dir' => 'DESC'],
    'status'         => ['column' => 'status',        'dir' => 'ASC'],
    'group'          => ['column' => 'group_name',    'dir' => 'ASC'],
    'owner'          => ['column' => 'owner_name',    'dir' => 'ASC'],
    'handler'        => ['column' => 'handler_name',  'dir' => 'ASC'],
    'due'            => ['column' => 'due_at',        'dir' => 'ASC'],
    'next'           => ['column' => 'next_action',   'dir' => 'ASC'],
];

/**
 * Escape LIKE metacharacters so a literal % or _ typed by a user cannot
 * widen what their own search matches. MySQL's default LIKE escape
 * character is backslash, so no ESCAPE clause is needed at the call site.
 */
function ecw_like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

/**
 * Parse the case list's filter state out of $_GET. One function so the
 * server-rendered page and the JSON action can never read the query string
 * two different ways.
 */
const ECW_CASE_VIEWS = ['mine', 'unassigned', 'overdue', 'week', 'supplier', 'review', 'nonext'];

/** SQL for a quick view, or null. Quick views are work to do: closed cases are never in them. */
function ecw_case_view_sql(string $view, ?int $userId, array &$params, int $orgId = 0): ?string
{
    switch ($view) {
        case 'mine':
            if (!$userId) return null;
            array_push($params, $userId, $userId);
            return "(c.owner_user_id = ? OR c.handler_user_id = ?) AND c.status <> 'closed'";
        case 'unassigned':
            return "(c.owner_user_id IS NULL AND c.handler_user_id IS NULL AND c.status <> 'closed')";
        case 'overdue':
            // Due date passed, or in Waiting too long (Settings → Case settings).
            if ($orgId > 0) { require_once __DIR__ . '/case_settings.php'; return ecw_case_overdue_sql($orgId); }
            return "(c.due_at IS NOT NULL AND c.due_at < UTC_DATE() AND c.status <> 'closed')";
        case 'week':
            // Days at the company (lib/timezone.php), not UTC days.
            require_once __DIR__ . '/timezone.php';
            $today = ecw_tz_sql_today($orgId);
            return "(c.due_at IS NOT NULL AND c.due_at >= {$today} AND c.due_at < {$today} + INTERVAL 7 DAY AND c.status <> 'closed')";
        // Next steps (lib/next_actions.php).
        case 'supplier':
            return "(c.waiting_on = 'supplier' AND c.status <> 'closed')";
        case 'review':
            require_once __DIR__ . '/timezone.php';
            return "(c.review_on IS NOT NULL AND c.review_on <= " . ecw_tz_sql_today($orgId) . " AND c.status <> 'closed')";
        case 'nonext':
            return "((c.next_action IS NULL OR c.next_action = '') AND c.status <> 'closed')";
    }
    return null;
}

/** How many cases each quick view has for this person (not closed, visible to them). */
function ecw_case_view_counts(int $orgId, int $userId, ?int $accountId = null): array
{
    require_once __DIR__ . '/account.php';
    $out = [];
    foreach (array_merge(['all', 'open'], ECW_CASE_VIEWS, ['waiting']) as $view) {
        $params = [$orgId];
        $sql = 'SELECT COUNT(*) FROM `' . ecw_table('cases') . '` c WHERE c.organization_id = ? AND c.deleted_at IS NULL AND ' . ecw_case_visible_sql('c', $userId, $params)
             . ' AND ' . ecw_case_account_sql('c', $accountId, $params, $userId);
        if ($view === 'waiting' || $view === 'open') $sql .= " AND c.status = '{$view}'";
        elseif ($view !== 'all' && ($v = ecw_case_view_sql($view, $userId, $params, $orgId)) !== null) $sql .= ' AND ' . $v;
        $st = ecw_db()->prepare($sql);
        $st->execute($params);
        $out[$view] = (int)$st->fetchColumn();
    }
    return $out;
}

/**
 * The two kinds of case group (Settings → Case groups), in case_groups.default_sharing:
 *   company  = everyone in the company sees the group and its cases;
 *   members  = only its members (and its owner, always one of them) see the group and its cases;
 *   personal = only its owner (created_by_user_id): hidden from everyone else, and so are its
 *              cases. People placed on a case (Responsible, handler, added people) always see it.
 * NULL = a company group from before: its cases keep the sharing each one has.
 */
const ECW_GROUP_SCOPES = ['company', 'members', 'personal'];

/** SQL: may $userId see group $alias (a personal group: only its owner)? */
function ecw_case_group_visible_sql(string $alias, int $userId, array &$params): string
{
    array_push($params, $userId, $userId);
    return "({$alias}.default_sharing IS NULL OR {$alias}.default_sharing = 'company' OR {$alias}.created_by_user_id = ?"
        . " OR ({$alias}.default_sharing = 'members' AND EXISTS (SELECT 1 FROM `" . ecw_table('case_group_members') . "` vgm2 WHERE vgm2.group_id = {$alias}.id AND vgm2.user_id = ?)))";
}

/**
 * A case entered a group: it is seen as the group says. Company: by everyone; personal: only by
 * the group's owner and the people on the case. A group from before (NULL) changes nothing.
 * @return bool changed
 */
function ecw_case_apply_group_sharing(int $caseId, ?int $groupId, ?int $actorId = null): bool
{
    if ($groupId === null) return false;
    $st = ecw_db()->prepare('SELECT g.default_sharing, c.visibility FROM `' . ecw_table('case_groups') . '` g JOIN `' . ecw_table('cases') . '` c ON c.id = ? WHERE g.id = ?');
    $st->execute([$caseId, $groupId]);
    $r = $st->fetch();
    if (!$r || !in_array($r['default_sharing'], ECW_GROUP_SCOPES, true)) return false;
    $want = $r['default_sharing'] === 'company' ? 'organization' : 'members';
    if ($r['visibility'] === $want) return false;
    ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET visibility = ? WHERE id = ?')->execute([$want, $caseId]);
    ecw_case_log_event($caseId, 'CASE_SHARING_CHANGED', $actorId, null, null, ['visibility' => $want, 'by_group' => $groupId]);
    return true;
}

/**
 * Move a case to another group (or none). A group that decides who sees its cases
 * (ecw_case_apply_group_sharing) sets the case's sharing; otherwise it stays as it is.
 * A group that hands out its cases gives this one a Responsible when it has none.
 */
function ecw_case_set_group(int $orgId, int $caseId, ?int $groupId, int $actorId): bool
{
    $st = ecw_db()->prepare('SELECT id, group_id, owner_user_id FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
    $st->execute([$caseId, $orgId]);
    $case = $st->fetch();
    if (!$case) return false;
    if ($groupId !== null) {
        $gp = [$groupId, $orgId];
        $g = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('case_groups') . '` g WHERE g.id = ? AND g.organization_id = ? AND ' . ecw_case_group_visible_sql('g', $actorId, $gp));
        $g->execute($gp);
        if (!$g->fetchColumn()) throw new InvalidArgumentException('Group not found.');   // someone else's personal group too
    }
    if ((int)$case['group_id'] === (int)$groupId) return true;
    ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET group_id = ? WHERE id = ?')->execute([$groupId, $caseId]);
    ecw_case_log_event($caseId, 'CASE_SHARING_CHANGED', $actorId, null, null, ['group_id' => $groupId, 'only' => 'group']);
    ecw_case_apply_group_sharing($caseId, $groupId, $actorId);
    if ($groupId !== null && $case['owner_user_id'] === null) {
        require_once __DIR__ . '/case_extras.php';
        $auto = ecw_case_auto_owner($groupId);
        if ($auto !== null) {
            ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET owner_user_id = ? WHERE id = ?')->execute([$auto, $caseId]);
            ecw_case_log_event($caseId, 'CASE_ASSIGNED', $actorId, null, null, ['field' => 'owner_user_id', 'from' => null, 'to' => $auto, 'reason' => 'group']);
        }
    }
    return true;
}

function ecw_case_list_opts_from_get(): array
{
    $mailboxIds = [];
    foreach ((array)($_GET['mailbox_ids'] ?? []) as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $mailboxIds[] = $id;
        }
    }
    // Several groups at once (the tabs toggle); an old link's single group_id still works.
    $groupIds = array_values(array_unique(array_filter(array_map('intval', array_merge((array)($_GET['group_ids'] ?? []), [$_GET['group_id'] ?? 0])), static fn(int $id): bool => $id > 0)));

    return [
        'status'          => (string)($_GET['status'] ?? 'open'),
        'q'               => trim((string)($_GET['q'] ?? '')),
        'mailbox_ids'     => $mailboxIds,
        'participant'     => trim((string)($_GET['participant'] ?? '')),
        'date_preset'     => (string)($_GET['date_preset'] ?? ''),
        'date_from'       => (string)($_GET['date_from'] ?? ''),
        'date_to'         => (string)($_GET['date_to'] ?? ''),
        'has_attachments' => (string)($_GET['has_attachments'] ?? ''),
        // Filters panel: a person (-1 = nobody), waiting on, created / due ranges, unread, an attachment name ("*.step").
        'owner'           => (int)($_GET['owner'] ?? 0),
        'handler'         => (int)($_GET['handler'] ?? 0),
        'waiting'         => (string)($_GET['waiting'] ?? ''),
        'created_from'    => (string)($_GET['created_from'] ?? ''),
        'created_to'      => (string)($_GET['created_to'] ?? ''),
        'due'             => (string)($_GET['due'] ?? ''),
        'due_from'        => (string)($_GET['due_from'] ?? ''),
        'due_to'          => (string)($_GET['due_to'] ?? ''),
        'unread'          => (string)($_GET['unread'] ?? ''),
        'file'            => trim((string)($_GET['file'] ?? '')),
        'group_ids'       => $groupIds,
        'group_id'        => count($groupIds) === 1 ? $groupIds[0] : 0,   // the one group chosen (the new-case dialog starts in it)
        'tag'             => mb_strtolower(trim((string)($_GET['tag'] ?? ''))),
        // Quick views above the list: My cases, Unassigned, Overdue, Due this week.
        'view'            => in_array($_GET['view'] ?? '', ECW_CASE_VIEWS, true) ? (string)$_GET['view'] : '',
        'sort'            => (string)($_GET['sort'] ?? 'last_activity'),
        'dir'             => (string)($_GET['dir'] ?? ''),
        'page'            => (int)($_GET['page'] ?? 1),
    ];
}

/** Mailboxes selectable in the case list's Mailbox filter. */
function ecw_case_list_mailboxes(int $organizationId): array
{
    $st = ecw_db()->prepare(
        'SELECT id, email_address, display_name FROM `' . ecw_table('mailboxes') . '`
          WHERE organization_id = ? ORDER BY email_address'
    );
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/**
 * `date_preset` -> a UTC [from, to] bound on last-activity, or [null, null]
 * for "any time". `custom` reads date_from/date_to (YYYY-MM-DD); an
 * unparsable custom bound is dropped rather than guessed at.
 *
 * @return array{0: ?string, 1: ?string}
 */
function ecw_case_list_date_range(array $opts): array
{
    $preset = (string)($opts['date_preset'] ?? '');
    $now    = ecw_now();

    switch ($preset) {
        case 'today':   // since midnight where the viewer is
            return [ecw_tz_to_utc(ecw_tz_today(ecw_tz_for_user(null)) . ' 00:00:00', ecw_tz_for_user(null)) ?? gmdate('Y-m-d 00:00:00'), $now];
        case '7d':
            return [gmdate('Y-m-d H:i:s', time() - 7 * 86400), $now];
        case '30d':
            return [gmdate('Y-m-d H:i:s', time() - 30 * 86400), $now];
        case 'custom':
            return [
                ecw_case_list_parse_date((string)($opts['date_from'] ?? ''), false),
                ecw_case_list_parse_date((string)($opts['date_to'] ?? ''), true),
            ];
        default:
            return [null, null];
    }
}

/** Accepts YYYY-MM-DD only — the <input type=date> the filter panel sends. */
function ecw_case_list_parse_date(string $value, bool $endOfDay): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    // The day as the viewer lives it (lib/timezone.php), in UTC.
    return ecw_tz_to_utc($value . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), ecw_tz_for_user(null));
}

/**
 * The filtered, sorted, paginated case list.
 *
 * Every predicate is scoped to `c.organization_id = ?` first, exactly like
 * every other case query in this file — no filter here ever widens that.
 *
 * Filtering happens inside a derived table so that a range on the computed
 * `last_activity` column (a correlated MAX(), not a real column — see
 * web/cases.php's original query) is a plain outer WHERE instead of a
 * GROUP-less HAVING, which reads the same either way but this is the
 * clearer of the two.
 *
 * @return array{cases: array, total: int, page: int, per_page: int}
 */
function ecw_case_list(int $organizationId, array $opts = []): array
{
    $casesT   = ecw_table('cases');
    $threadsT = ecw_table('case_threads');
    $emailT   = ecw_table('email_threads');
    $msgT     = ecw_table('email_messages');
    $partT    = ecw_table('message_participants');
    $usersT   = ecw_table('users');

    $where  = ['c.organization_id = ?', 'c.deleted_at IS NULL'];
    $params = [$organizationId];

    // Members-only cases are listed only to the people inside them.
    if (isset($opts['user_id'])) {
        $where[] = ecw_case_visible_sql('c', (int)$opts['user_id'], $params);
    }

    // One account at a time (lib/account.php).
    if (!empty($opts['account_id'])) {
        require_once __DIR__ . '/account.php';
        $where[] = ecw_case_account_sql('c', (int)$opts['account_id'], $params, isset($opts['user_id']) ? (int)$opts['user_id'] : null);
    }

    // One group or several (a case in any of them).
    $groupIds = array_values(array_unique(array_filter(array_map('intval', array_merge((array)($opts['group_ids'] ?? []), [$opts['group_id'] ?? 0])), static fn(int $id): bool => $id > 0)));
    if ($groupIds !== []) {
        $where[]  = 'c.group_id IN (' . implode(',', array_fill(0, count($groupIds), '?')) . ')';
        array_push($params, ...$groupIds);
    }

    $tag = (string)($opts['tag'] ?? '');
    if ($tag !== '') {
        $where[]  = 'EXISTS (SELECT 1 FROM `' . ecw_table('case_tags') . '` tg WHERE tg.case_id = c.id AND tg.tag = ?)';
        $params[] = $tag;
    }

    if (($viewSql = ecw_case_view_sql((string)($opts['view'] ?? ''), isset($opts['user_id']) ? (int)$opts['user_id'] : null, $params, $organizationId)) !== null) {
        $where[] = $viewSql;
    }

    $status = (string)($opts['status'] ?? 'open');
    require_once __DIR__ . '/case_settings.php';
    if (in_array($status, ECW_CASE_STATUSES, true)) {
        $where[]  = 'c.status = ?';
        $params[] = $status;
    } elseif ($status !== 'all' && ($resolved = ecw_case_status_resolve($organizationId, $status)) !== null) {
        // One of the company's own statuses (Settings → Case settings).
        $where[]  = 'c.sub_status = ?';
        $params[] = $resolved[1];
    }

    $q = trim((string)($opts['q'] ?? ''));
    if ($q !== '') {
        $like     = '%' . ecw_like_escape($q) . '%';
        // A reference number (order, ticket, serial…) finds its case too, typed with or without spaces and dashes.
        require_once __DIR__ . '/next_actions.php';
        $norm     = ecw_ref_norm($q);
        $where[]  = '(c.case_number LIKE ? OR c.name LIKE ? OR c.next_action LIKE ? OR EXISTS (
                        SELECT 1 FROM `' . $threadsT . '` qct
                          JOIN `' . $emailT . '` qt ON qt.id = qct.email_thread_id
                         WHERE qct.case_id = c.id AND qt.normalized_subject LIKE ?)
                      OR EXISTS (SELECT 1 FROM `' . ecw_table('case_tags') . '` qtg WHERE qtg.case_id = c.id AND qtg.tag LIKE ?)'
                   . ($norm !== '' ? ' OR c.id IN (SELECT qr.case_id FROM `' . ecw_table('case_refs') . '` qr WHERE qr.value_norm LIKE ?)' : '') . ')';
        array_push($params, $like, $like, $like, $like, $like);
        if ($norm !== '') $params[] = '%' . ecw_like_escape($norm) . '%';
    }

    $mailboxIds = array_values(array_unique(array_map('intval', (array)($opts['mailbox_ids'] ?? []))));
    $mailboxIds = array_values(array_filter($mailboxIds, static fn(int $id): bool => $id > 0));
    if ($mailboxIds !== []) {
        $placeholders = implode(',', array_fill(0, count($mailboxIds), '?'));
        $where[]      = 'EXISTS (SELECT 1 FROM `' . $threadsT . '` mct
                                   JOIN `' . $emailT . '` mt ON mt.id = mct.email_thread_id
                                  WHERE mct.case_id = c.id AND mt.mailbox_id IN (' . $placeholders . '))';
        array_push($params, ...$mailboxIds);
    }

    $participant = trim((string)($opts['participant'] ?? ''));
    if ($participant !== '') {
        // The cases this person wrote or was written to in, worked out once
        // (not per case): see ecw_search_people_where().
        require_once __DIR__ . '/search.php';
        [$who, $whoParams] = ecw_search_people_where($participant);
        $where[]  = 'c.id IN (SELECT pct.case_id FROM `' . $threadsT . '` pct
                               JOIN `' . $msgT . '` pm ON pm.email_thread_id = pct.email_thread_id
                              WHERE pm.id IN (SELECT p.email_message_id FROM `' . $partT . '` p WHERE ' . $who . '))';
        $params   = array_merge($params, $whoParams);
    }

    $hasAttachments = (string)($opts['has_attachments'] ?? '');
    if ($hasAttachments === 'yes') {
        $where[] = 'EXISTS (SELECT 1 FROM `' . $threadsT . '` act
                              JOIN `' . $msgT . '` am ON am.email_thread_id = act.email_thread_id
                             WHERE act.case_id = c.id AND am.has_attachments = 1)';
    } elseif ($hasAttachments === 'no') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM `' . $threadsT . '` act
                                  JOIN `' . $msgT . '` am ON am.email_thread_id = act.email_thread_id
                                 WHERE act.case_id = c.id AND am.has_attachments = 1)';
    }

    // A specific person, or -1: nobody.
    foreach (['owner' => 'c.owner_user_id', 'handler' => 'c.handler_user_id'] as $key => $col) {
        $who = (int)($opts[$key] ?? 0);
        if ($who === -1) $where[] = $col . ' IS NULL';
        elseif ($who > 0) { $where[] = $col . ' = ?'; $params[] = $who; }
    }

    $waiting = (string)($opts['waiting'] ?? '');
    if ($waiting === 'none') $where[] = "(c.waiting_on IS NULL OR c.waiting_on = '')";
    elseif (in_array($waiting, ['us', 'customer', 'supplier', 'internal'], true)) { $where[] = 'c.waiting_on = ?'; $params[] = $waiting; }

    // Opened between (the viewer's days).
    $cFrom = ecw_case_list_parse_date((string)($opts['created_from'] ?? ''), false);
    $cTo   = ecw_case_list_parse_date((string)($opts['created_to'] ?? ''), true);
    if ($cFrom !== null) { $where[] = 'c.created_at >= ?'; $params[] = $cFrom; }
    if ($cTo !== null)   { $where[] = 'c.created_at <= ?'; $params[] = $cTo; }

    // Due: overdue / today / this week / none, or a range of days.
    switch ((string)($opts['due'] ?? '')) {
        case 'overdue': $where[] = ecw_case_overdue_sql($organizationId); break;
        case 'today':   $where[] = 'c.due_at = ' . ecw_tz_sql_today($organizationId); break;
        case 'week':    $where[] = 'c.due_at BETWEEN ' . ecw_tz_sql_today($organizationId) . ' AND ' . ecw_tz_sql_today($organizationId) . ' + INTERVAL 7 DAY'; break;
        case 'none':    $where[] = 'c.due_at IS NULL'; break;
        case 'set':     $where[] = 'c.due_at IS NOT NULL'; break;
    }
    foreach (['due_from' => '>=', 'due_to' => '<='] as $key => $op) {
        $d = (string)($opts[$key] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $where[] = 'c.due_at ' . $op . ' ?'; $params[] = $d; }
    }

    // An attachment by name in any of the case's emails: "offer", "*.step", "drawing*.pdf".
    $file = (string)($opts['file'] ?? '');
    if ($file !== '') {
        require_once __DIR__ . '/search.php';
        $where[] = 'EXISTS (SELECT 1 FROM `' . $threadsT . '` fct
                              JOIN `' . $msgT . '` fm ON fm.email_thread_id = fct.email_thread_id
                              JOIN `' . ecw_table('email_attachments') . "` fa ON fa.email_message_id = fm.id
                             WHERE fct.case_id = c.id AND fa.is_inline = 0 AND fa.filename LIKE ? ESCAPE '=')";
        $params[] = ecw_search_filename_pattern($file);
    }

    [$dateFrom, $dateTo] = ecw_case_list_date_range($opts);
    $outerWhere  = [];
    $outerParams = [];
    if (($opts['unread'] ?? '') === 'yes') $outerWhere[] = 'x.unread_count > 0';
    if ($dateFrom !== null) {
        $outerWhere[]  = 'x.last_activity >= ?';
        $outerParams[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $outerWhere[]  = 'x.last_activity <= ?';
        $outerParams[] = $dateTo;
    }
    $outerSql = $outerWhere !== [] ? ' WHERE ' . implode(' AND ', $outerWhere) : '';

    $sortKey = (string)($opts['sort'] ?? 'last_activity');
    if (!isset(ECW_CASE_SORTS[$sortKey])) {
        $sortKey = 'last_activity';
    }
    $sortDef    = ECW_CASE_SORTS[$sortKey];
    $dirOpt     = strtolower((string)($opts['dir'] ?? ''));
    $dir        = in_array($dirOpt, ['asc', 'desc'], true) ? strtoupper($dirOpt) : $sortDef['dir'];
    $sortColumn = $sortDef['column']; // whitelisted above; never the raw $sortKey/$dirOpt string

    $page    = max(1, (int)($opts['page'] ?? 1));
    $perPage = ECW_CASE_LIST_PAGE_SIZE;
    $offset  = ($page - 1) * $perPage;

    // Unread mail in the case, counted only in mailboxes whose read state this user sees.
    require_once __DIR__ . '/unread.php';
    $unreadSql = isset($opts['user_id'])
        ? ecw_unread_mailbox_sql('ut.mailbox_id', ecw_unread_mailbox_ids($organizationId, (int)$opts['user_id'])) : '1 = 1';

    $inner = 'SELECT c.id, c.case_number, c.name, c.status, c.sub_status, c.status_changed_at, c.created_at, c.updated_at, c.due_at,
                     (SELECT GROUP_CONCAT(tg2.tag ORDER BY tg2.tag SEPARATOR \'|\') FROM `' . ecw_table('case_tags') . '` tg2 WHERE tg2.case_id = c.id) AS tags,
                     c.visibility, g.name AS group_name, g.color AS group_color,
                     u.name AS owner_name, c.owner_user_id, c.handler_user_id, c.group_id, hu.name AS handler_name,
                     c.next_action, c.next_user_id, nu.name AS next_user_name, c.waiting_on, c.review_on, c.next_by,
                     (SELECT COALESCE(SUM(ut.unread_count), 0) FROM `' . $threadsT . '` uct
                        JOIN `' . $emailT . '` ut ON ut.id = uct.email_thread_id
                       WHERE uct.case_id = c.id AND ' . $unreadSql . ') AS unread_count,
                     (SELECT COUNT(*) FROM `' . $threadsT . '` ct WHERE ct.case_id = c.id) AS thread_count,
                     (SELECT MAX(m.occurred_at)
                        FROM `' . $threadsT . '` ct2
                        JOIN `' . $msgT . '` m ON m.email_thread_id = ct2.email_thread_id
                       WHERE ct2.case_id = c.id) AS last_activity
                FROM `' . $casesT . '` c
                LEFT JOIN `' . $usersT . '` u ON u.id = c.owner_user_id
                LEFT JOIN `' . $usersT . '` hu ON hu.id = c.handler_user_id
                LEFT JOIN `' . $usersT . '` nu ON nu.id = c.next_user_id
                LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
               WHERE ' . implode(' AND ', $where);

    $countSt = ecw_db()->prepare('SELECT COUNT(*) FROM (' . $inner . ') x' . $outerSql);
    $countSt->execute(array_merge($params, $outerParams));
    $total = (int)$countSt->fetchColumn();

    $dataSt = ecw_db()->prepare(
        'SELECT * FROM (' . $inner . ') x' . $outerSql . '
          ORDER BY (x.`' . $sortColumn . '` IS NULL' . (in_array($sortColumn, ['name', 'status', 'group_name', 'owner_name', 'handler_name', 'next_action', 'case_number'], true) ? ' OR x.`' . $sortColumn . '` = \'\'' : '') . '), x.`' . $sortColumn . '` ' . $dir . ', x.id ' . $dir . '
          LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $dataSt->execute(array_merge($params, $outerParams));
    $raw = $dataSt->fetchAll();
    $rows = array_map('ecw_case_list_row', $raw);
    // The company's status names and colours, and "in Waiting too long" (Settings → Case settings).
    $waitDays = (int)ecw_case_settings($organizationId)['waiting_overdue_days'];
    foreach ($rows as $i => &$row) {
        $d = ecw_case_status_display($organizationId, (string)$raw[$i]['status'], $raw[$i]['sub_status'] ?? null);
        $row['sub_status'] = $raw[$i]['sub_status'] ?? null;
        $row['status_label'] = $d['label'];
        $row['status_color'] = $d['color'];
        $since = $raw[$i]['status_changed_at'] ?? $raw[$i]['updated_at'] ?? null;
        $row['waiting_overdue'] = $waitDays > 0 && $raw[$i]['status'] === 'waiting' && $since !== null && strtotime($since . ' UTC') < time() - $waitDays * 86400;
    }
    unset($row);
    // The reply clock (lib/sla.php) of the open ones on this page.
    require_once __DIR__ . '/sla.php';
    $clocks = ecw_sla_for_cases($organizationId, array_column($rows, 'id'));
    foreach ($rows as &$row) $row['sla'] = $clocks[$row['id']] ?? null;
    unset($row);
    // Checklist progress (lib/checklists.php): "3/5" next to the name.
    require_once __DIR__ . '/checklists.php';
    require_once __DIR__ . '/features.php';
    $progress = (PHP_SAPI === 'cli' || ecw_feature_allowed('checklists')) ? ecw_checklist_progress(array_column($rows, 'id')) : [];
    foreach ($rows as &$row) $row['checklist'] = $progress[$row['id']] ?? null;
    unset($row);

    return [
        'cases'    => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ];
}

function ecw_case_list_row(array $r): array
{
    return [
        'id'            => (int)$r['id'],
        'case_number'   => $r['case_number'],
        'name'          => $r['name'],
        'status'        => $r['status'],
        'owner_name'    => $r['owner_name'],
        'owner_user_id' => isset($r['owner_user_id']) ? ($r['owner_user_id'] !== null ? (int)$r['owner_user_id'] : null) : null,
        'handler_name'  => $r['handler_name'] ?? null,
        'handler_user_id' => isset($r['handler_user_id']) ? (int)$r['handler_user_id'] : null,
        'group_id'      => isset($r['group_id']) ? (int)$r['group_id'] : null,
        'visibility'    => $r['visibility'] ?? 'organization',
        'group_name'    => $r['group_name'] ?? null,
        'group_color'   => $r['group_color'] ?? null,
        'thread_count'  => (int)$r['thread_count'],
        'unread_count'  => (int)($r['unread_count'] ?? 0),
        'due_at'        => $r['due_at'] ?? null,
        // What happens next (lib/next_actions.php).
        'next'          => [
            'action'    => $r['next_action'] ?? null,
            'user_id'   => isset($r['next_user_id']) ? (int)$r['next_user_id'] : null,
            'user_name' => $r['next_user_name'] ?? null,
            'waiting_on' => $r['waiting_on'] ?? null,
            'review_on' => $r['review_on'] ?? null,
            'by'        => $r['next_by'] ?? null,
        ],
        'tags'          => ($r['tags'] ?? '') !== '' && $r['tags'] !== null ? explode('|', (string)$r['tags']) : [],
        'last_activity' => $r['last_activity'],
        'created_at'    => $r['created_at'],
        'updated_at'    => $r['updated_at'],
    ];
}

// ------------------------------------------------- people, groups, sharing

/** Active members of the organization, with their Outlook photo URL (or null). */
function ecw_org_users(int $organizationId): array
{
    $st = ecw_db()->prepare(
        'SELECT u.id, u.name, u.email, u.avatar_hash FROM `' . ecw_table('users') . '` u
           JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
          WHERE ou.organization_id = ? AND u.disabled_at IS NULL ORDER BY u.name'
    );
    $st->execute([$organizationId]);
    return array_map('ecw_person_row', $st->fetchAll());
}

function ecw_person_row(array $u): array
{
    return [
        'id'         => (int)$u['id'],
        'name'       => (string)$u['name'],
        'email'      => (string)$u['email'],
        'avatar_url' => ecw_avatar_url((int)$u['id'], $u['avatar_hash'] ?? null),
    ];
}

/** @param int[] $userIds @return int[] those that are active members of the org */
function ecw_org_member_ids(int $organizationId, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if ($userIds === []) {
        return [];
    }
    $st = ecw_db()->prepare(
        'SELECT u.id FROM `' . ecw_table('users') . '` u
           JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
          WHERE ou.organization_id = ? AND u.disabled_at IS NULL
            AND u.id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'
    );
    $st->execute(array_merge([$organizationId], $userIds));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Case groups with their members, owner and kind. $viewerId given: without other people's
 * personal groups (they are hidden from everyone but their owner).
 */
function ecw_case_groups(int $organizationId, ?int $viewerId = null): array
{
    $params = [$organizationId];
    $only = $viewerId !== null ? ' AND ' . ecw_case_group_visible_sql('g', $viewerId, $params) : '';
    $st = ecw_db()->prepare(
        'SELECT g.id, g.name, g.color, g.created_by_user_id, g.auto_assign, g.default_sharing, g.match_words, g.match_ai, g.match_mailbox_id, g.match_action, u.name AS owner_name FROM `' . ecw_table('case_groups') . '` g
           LEFT JOIN `' . ecw_table('users') . '` u ON u.id = g.created_by_user_id
          WHERE g.organization_id = ?' . $only . ' ORDER BY (g.default_sharing = \'personal\') DESC, g.name'
    );
    $st->execute($params);
    $groups = [];
    foreach ($st->fetchAll() as $g) {
        $groups[(int)$g['id']] = [
            'id' => (int)$g['id'], 'name' => $g['name'], 'color' => $g['color'], 'auto_assign' => (int)($g['auto_assign'] ?? 0) === 1,
            'scope' => in_array($g['default_sharing'] ?? null, ['members', 'personal'], true) ? $g['default_sharing'] : 'company',
            'owner_name' => $g['owner_name'] ?? null,
            'created_by_user_id' => $g['created_by_user_id'] === null ? null : (int)$g['created_by_user_id'],
            'members' => [],
            // Its rules for incoming email (lib/group_rules.php).
            'rules' => ['ai' => (string)($g['match_ai'] ?? ''), 'words' => (string)($g['match_words'] ?? ''),
                'mailbox_id' => $g['match_mailbox_id'] === null ? null : (int)$g['match_mailbox_id'],
                'action' => in_array($g['match_action'] ?? 'off', ['suggest', 'auto'], true) ? $g['match_action'] : 'off'],
        ];
    }
    if ($groups !== []) {
        $st = ecw_db()->prepare(
            'SELECT gm.group_id, u.id, u.name, u.email, u.avatar_hash
               FROM `' . ecw_table('case_group_members') . '` gm
               JOIN `' . ecw_table('users') . '` u ON u.id = gm.user_id
              WHERE gm.group_id IN (' . implode(',', array_keys($groups)) . ') AND u.disabled_at IS NULL
              ORDER BY u.name'
        );
        $st->execute();
        foreach ($st->fetchAll() as $row) {
            $groups[(int)$row['group_id']]['members'][] = ecw_person_row($row);
        }
    }
    return array_values($groups);
}

/**
 * Create (no id) or update a group. Anyone in the organization may create
 * one; only its creator or an admin may change or delete it.
 *
 * @return int the group id
 */
function ecw_case_group_save(int $organizationId, int $actorUserId, bool $actorIsAdmin, array $data): int
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        throw new InvalidArgumentException('Enter a group name (up to 80 characters).');
    }
    $color = (string)($data['color'] ?? '');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/D', $color) ? strtolower($color) : null;
    $memberIds = ecw_org_member_ids($organizationId, (array)($data['member_ids'] ?? []));
    $groupId = (int)($data['id'] ?? 0);
    // Company or personal. A group from before saved without a choice stays as it was (NULL).
    $scope = in_array($data['scope'] ?? null, ECW_GROUP_SCOPES, true) ? (string)$data['scope'] : null;
    // The people list belongs to "Members": a company group is everyone's, a personal one its owner's.
    if ($scope === 'personal' || $scope === 'company') { $memberIds = []; $data['auto_assign'] = false; }
    if ($scope === 'members' && !in_array($actorUserId, $memberIds, true) && $groupId === 0) $memberIds[] = $actorUserId;   // its owner is one of them
    require_once __DIR__ . '/group_rules.php';
    // Saved without its rules (an older screen, a script): the rules it has are kept.
    if (!isset($data['rules']) && $groupId > 0) {
        $cur = ecw_db()->prepare('SELECT match_ai AS ai, match_words AS words, match_mailbox_id AS mailbox_id, match_action AS action FROM `' . ecw_table('case_groups') . '` WHERE id = ?');
        $cur->execute([$groupId]);
        $data['rules'] = $cur->fetch() ?: [];
    }
    $rules = ecw_group_rules_clean($organizationId, (array)($data['rules'] ?? []));
    $before = null;

    $pdo = ecw_db();
    $owns = !$pdo->inTransaction();
    if ($owns) { $pdo->beginTransaction(); }
    try {
        if ($groupId > 0) {
            $st = $pdo->prepare('SELECT created_by_user_id FROM `' . ecw_table('case_groups') . '` WHERE id = ? AND organization_id = ? FOR UPDATE');
            $st->execute([$groupId, $organizationId]);
            $owner = $st->fetch();
            if ($owner === false) {
                throw new InvalidArgumentException('Group not found.');
            }
            $cur = $pdo->prepare('SELECT default_sharing FROM `' . ecw_table('case_groups') . '` WHERE id = ?');
            $cur->execute([$groupId]);
            $before = $cur->fetchColumn() ?: null;
            // A group hidden from this person (someone's personal one, members they are not among):
            // they cannot change it, not even an admin.
            $vp = [$groupId];
            $vis = $pdo->prepare('SELECT 1 FROM `' . ecw_table('case_groups') . '` g WHERE g.id = ? AND ' . ecw_case_group_visible_sql('g', $actorUserId, $vp));
            $vis->execute($vp);
            if (!$vis->fetchColumn()) {
                throw new InvalidArgumentException('Group not found.');
            }
            if (!$actorIsAdmin && (int)$owner['created_by_user_id'] !== $actorUserId) {
                throw new InvalidArgumentException('Only the person who created this group or an admin can change it.');
            }
            if ($scope === null) $scope = $before;
            $pdo->prepare('UPDATE `' . ecw_table('case_groups') . '` SET name = ?, color = ?, auto_assign = ?, default_sharing = ?,
                             match_ai = ?, match_words = ?, match_mailbox_id = ?, match_action = ? WHERE id = ?')
                ->execute([$name, $color, !empty($data['auto_assign']) ? 1 : 0, $scope, $rules['ai'], $rules['words'], $rules['mailbox_id'], $rules['action'], $groupId]);
            $pdo->prepare('DELETE FROM `' . ecw_table('case_group_members') . '` WHERE group_id = ?')->execute([$groupId]);
        } else {
            $pdo->prepare('INSERT INTO `' . ecw_table('case_groups') . '` (organization_id, name, color, created_by_user_id, auto_assign, default_sharing,
                             match_ai, match_words, match_mailbox_id, match_action) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$organizationId, $name, $color, $actorUserId, !empty($data['auto_assign']) ? 1 : 0, $scope ?? 'company',
                    $rules['ai'], $rules['words'], $rules['mailbox_id'], $rules['action']]);
            $groupId = (int)$pdo->lastInsertId();
        }
        $add = $pdo->prepare('INSERT INTO `' . ecw_table('case_group_members') . '` (group_id, user_id) VALUES (?, ?)');
        foreach ($memberIds as $id) {
            $add->execute([$groupId, $id]);
        }
        // Made personal, or company again: the cases already in the group follow now (before,
        // only cases added later did, and the others stayed visible to everyone).
        if ($scope !== $before && ($before !== null || in_array($scope, ['members', 'personal'], true))) {
            if (in_array($scope, ECW_GROUP_SCOPES, true)) {
                $cs = $pdo->prepare('SELECT id FROM `' . ecw_table('cases') . '` WHERE group_id = ? AND deleted_at IS NULL');
                $cs->execute([$groupId]);
                foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $cid) ecw_case_apply_group_sharing((int)$cid, $groupId, $actorUserId);
            }
        }
        if ($owns) { $pdo->commit(); }
    } catch (PDOException $e) {
        if ($owns) { $pdo->rollBack(); }
        if (str_contains($e->getMessage(), '1062')) {
            throw new InvalidArgumentException('A group with that name already exists.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($owns) { $pdo->rollBack(); }
        throw $e;
    }
    return $groupId;
}

/** Delete a group. Its cases stay; they just lose the group (FK SET NULL). */
function ecw_case_group_delete(int $organizationId, int $actorUserId, bool $actorIsAdmin, int $groupId): void
{
    $st = ecw_db()->prepare('SELECT created_by_user_id, default_sharing FROM `' . ecw_table('case_groups') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$groupId, $organizationId]);
    $owner = $st->fetch();
    // A group hidden from this person does not exist for them, admins included.
    $vp = [$groupId];
    $vis = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('case_groups') . '` g WHERE g.id = ? AND ' . ecw_case_group_visible_sql('g', $actorUserId, $vp));
    $vis->execute($vp);
    if ($owner === false || !$vis->fetchColumn()) {
        throw new InvalidArgumentException('Group not found.');
    }
    if (!$actorIsAdmin && (int)$owner['created_by_user_id'] !== $actorUserId) {
        throw new InvalidArgumentException('Only the person who created this group or an admin can delete it.');
    }
    ecw_db()->prepare('DELETE FROM `' . ecw_table('case_groups') . '` WHERE id = ?')->execute([$groupId]);
}

/** Visibility, group and added people of one case, for the case page. */
function ecw_case_sharing(int $caseId): array
{
    $st = ecw_db()->prepare(
        'SELECT c.visibility, g.id AS group_id, g.name AS group_name, g.color AS group_color
           FROM `' . ecw_table('cases') . '` c
           LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
          WHERE c.id = ?'
    );
    $st->execute([$caseId]);
    $c = $st->fetch() ?: [];

    $st = ecw_db()->prepare(
        'SELECT u.id, u.name, u.email, u.avatar_hash FROM `' . ecw_table('case_members') . '` cm
           JOIN `' . ecw_table('users') . '` u ON u.id = cm.user_id
          WHERE cm.case_id = ? AND u.disabled_at IS NULL ORDER BY u.name'
    );
    $st->execute([$caseId]);

    return [
        'visibility' => (string)($c['visibility'] ?? 'organization'),
        'group'      => empty($c['group_id']) ? null
            : ['id' => (int)$c['group_id'], 'name' => $c['group_name'], 'color' => $c['group_color']],
        'members'    => array_map('ecw_person_row', $st->fetchAll()),
    ];
}

/**
 * Change who can see a case. The caller has already checked that the actor can
 * see it. Switching to members-only keeps the actor inside: someone who is not
 * creator / Responsible / Handled by is added as a member rather than locking
 * themselves out of the case they are editing.
 */
function ecw_case_sharing_save(int $organizationId, int $caseId, int $actorUserId, array $data): array
{
    $visibility = (string)($data['visibility'] ?? 'organization');
    if (!in_array($visibility, ECW_CASE_VISIBILITIES, true)) {
        throw new InvalidArgumentException('Unknown visibility.');
    }
    $groupId = (int)($data['group_id'] ?? 0) ?: null;
    if ($groupId !== null) {
        $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('case_groups') . '` WHERE id = ? AND organization_id = ?');
        $st->execute([$groupId, $organizationId]);
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('Group not found.');
        }
    }
    $memberIds = ecw_org_member_ids($organizationId, (array)($data['member_ids'] ?? []));

    $pdo = ecw_db();
    $owns = !$pdo->inTransaction();
    if ($owns) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare('UPDATE `' . ecw_table('cases') . '` SET visibility = ?, group_id = ? WHERE id = ? AND organization_id = ?')
            ->execute([$visibility, $groupId, $caseId, $organizationId]);
        $pdo->prepare('DELETE FROM `' . ecw_table('case_members') . '` WHERE case_id = ?')->execute([$caseId]);
        $add = $pdo->prepare('INSERT INTO `' . ecw_table('case_members') . '` (case_id, user_id, added_by_user_id, added_at) VALUES (?, ?, ?, ?)');
        foreach ($memberIds as $id) {
            $add->execute([$caseId, $id, $actorUserId, ecw_now()]);
        }
        if ($visibility === 'members' && !ecw_case_visible($organizationId, $actorUserId, $caseId)) {
            $add->execute([$caseId, $actorUserId, $actorUserId, ecw_now()]);
        }
        ecw_case_log_event($caseId, 'CASE_SHARING_CHANGED', $actorUserId, null, null, [
            'visibility' => $visibility, 'group_id' => $groupId, 'member_count' => count($memberIds),
        ]);
        if ($owns) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($owns) { $pdo->rollBack(); }
        throw $e;
    }
    return ecw_case_sharing($caseId);
}

/**
 * "Which case does this email belong to?" — open cases the user can see that
 * share people with this thread, most shared people first. No AI: it is
 * instant, free and explainable ("2 people in common").
 *
 * @param string[] $ownAddresses the viewer's own mailbox addresses, never counted as a match
 */
function ecw_case_suggest(int $orgId, int $userId, int $threadId, array $ownAddresses, int $limit = 5): array
{
    $P = ecw_table('message_participants');
    $M = ecw_table('email_messages');
    $st = ecw_db()->prepare("SELECT DISTINCT LOWER(p.email) FROM `{$P}` p JOIN `{$M}` m ON m.id = p.email_message_id WHERE m.email_thread_id = ?");
    $st->execute([$threadId]);
    $own = array_map('strtolower', $ownAddresses);
    $emails = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), static fn($e) => $e && !in_array($e, $own, true)));
    if ($emails === []) {
        return [];
    }
    $emails = array_slice($emails, 0, 50);
    $params = $emails;
    $in = implode(',', array_fill(0, count($emails), '?'));
    $params[] = $orgId;
    $params[] = $threadId;
    $visible = ecw_case_visible_sql('c', $userId, $params);
    $st = ecw_db()->prepare(
        "SELECT c.id, c.case_number, c.name, c.status, COUNT(DISTINCT LOWER(p.email)) AS shared
           FROM `" . ecw_table('cases') . "` c
           JOIN `" . ecw_table('case_threads') . "` ct ON ct.case_id = c.id
           JOIN `{$M}` m ON m.email_thread_id = ct.email_thread_id
           JOIN `{$P}` p ON p.email_message_id = m.id AND LOWER(p.email) IN ({$in})
          WHERE c.organization_id = ? AND c.deleted_at IS NULL AND c.status <> 'closed'
            AND ct.email_thread_id <> ? AND {$visible}
          GROUP BY c.id, c.case_number, c.name, c.status, c.updated_at
          ORDER BY shared DESC, c.updated_at DESC
          LIMIT " . max(1, min(10, $limit))
    );
    $st->execute($params);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'case_number' => $r['case_number'], 'name' => $r['name'],
        'status' => $r['status'], 'shared' => (int)$r['shared']], $st->fetchAll());
}
