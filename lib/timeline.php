<?php declare(strict_types=1);
/**
 * Email Case Workspace — the case timeline.
 *
 * The product's differentiator: several independent email threads, side by side,
 * on ONE shared chronological vertical axis.
 *
 * THE ALGORITHM IS DELIBERATELY BORING
 * ------------------------------------
 * One global event stream — every message from every visible linked thread,
 * ordered by effective time — and each event is rendered only in its own
 * thread's column. Every other column is empty at that row. That is the whole
 * trick, and its value is entirely in what it refuses to do: a message is never
 * moved up to close a gap, and columns are never packed independently. The
 * empty space IS the information — it shows that a supplier had said nothing
 * yet when we answered the customer.
 *
 * So there is no per-column layout pass anywhere in this file. One ordered list,
 * one row per message.
 *
 * EFFECTIVE TIME
 * --------------
 * received_at for incoming, sent_at for outgoing — materialized as
 * email_messages.occurred_at by migration 006. Sorting incoming mail by its
 * sent_at would place it on the axis before anyone here could have seen it, and
 * our reply could render above the message it answers.
 *
 * HIDDEN COLUMNS ARE EXCLUDED FROM THE STREAM
 * -------------------------------------------
 * Hiding a column removes its messages from the axis as well as from the view.
 * Keeping them would leave rows whose bubble has nowhere to go: visible gaps
 * pointing at nothing, which reads as data loss rather than as a hidden column.
 * The link is untouched — unhiding restores it.
 *
 * TIME IS STORED AND RETURNED IN UTC
 * ----------------------------------
 * Formatting happens in the browser, in the zone of the computer looking at it (like
 * Outlook when travelling, lib/timezone.php). The payload says that zone; the server only
 * uses it to decide where a new day starts between two messages.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/timezone.php';
require_once __DIR__ . '/body.php';
require_once __DIR__ . '/sanitize.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/cases.php';

/** Above this many messages a case stops loading in one go. */
const ECW_TIMELINE_MAX_EVENTS = 500;

/**
 * Gap thresholds, in seconds: how much vertical air a pause earns.
 *
 * Six buckets, not three — a coarser scale left "5 minutes" and "55 minutes"
 * with the same weight, which is what made the spacing dominate the page.
 * The CSS pixel heights that go with each bucket (case.css, .tl-gap.size-*)
 * are: none=0, xs=8, sm=14, md=20, lg=28, xl=36.
 */
const ECW_GAP_NONE = 300;      // under 5 min: no separator at all
const ECW_GAP_XS   = 1800;     // under 30 min
const ECW_GAP_SM   = 7200;     // under 2 hours
const ECW_GAP_MD   = 28800;    // under 8 hours
const ECW_GAP_LG   = 86400;    // under a day

function ecw_case_load(int $organizationId, int $caseId, bool $includeDeleted = false): ?array
{
    $st = ecw_db()->prepare(
        'SELECT c.*, u.name AS owner_name, o.timezone
           FROM `' . ecw_table('cases') . '` c
           LEFT JOIN `' . ecw_table('users') . '` u ON u.id = c.owner_user_id
           JOIN `' . ecw_table('organizations') . '` o ON o.id = c.organization_id
          WHERE c.id = ? AND c.organization_id = ?'
            . ($includeDeleted ? '' : ' AND c.deleted_at IS NULL')
    );
    $st->execute([$caseId, $organizationId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * The case summary header. Only what the step lists — no CRM fields.
 */
function ecw_case_summary(int $organizationId, int $caseId): ?array
{
    $case = ecw_case_load($organizationId, $caseId);
    if ($case === null) {
        return null;
    }

    $st = ecw_db()->prepare(
        'SELECT COUNT(DISTINCT ct.id) AS thread_count,
                MAX(m.occurred_at)    AS last_activity
           FROM `' . ecw_table('case_threads') . '` ct
           LEFT JOIN `' . ecw_table('email_messages') . '` m
                  ON m.email_thread_id = ct.email_thread_id AND m.provider_removed_at IS NULL
          WHERE ct.case_id = ?'
    );
    $st->execute([$caseId]);
    $stats = $st->fetch() ?: [];

    return [
        'id'            => (int)$case['id'],
        'number'        => $case['case_number'],
        'name'          => $case['name'],
        'status'        => $case['status'],
        'sub_status'    => $case['sub_status'] ?? null,   // the company's own status (lib/case_settings.php)
        'owner'         => $case['owner_name'],
        'owner_user_id' => $case['owner_user_id'] === null ? null : (int)$case['owner_user_id'],
        'handler_user_id' => isset($case['handler_user_id']) ? (int)$case['handler_user_id'] : null,
        'created_at'    => $case['created_at'],
        'closed_at'     => $case['closed_at'],
        'last_activity' => $stats['last_activity'] ?? null,
        'thread_count'  => (int)($stats['thread_count'] ?? 0),
        'timezone'      => ecw_tz_for_user(ecw_tz_viewer(), $organizationId),   // the viewer's zone, not the company's
        'created_by_user_id' => $case['created_by_user_id'] === null ? null : (int)$case['created_by_user_id'],
        'sharing'       => ecw_case_sharing($caseId),
        'due_at'        => $case['due_at'] ?? null,
        'tags'          => (static function () use ($caseId) { require_once __DIR__ . '/case_extras.php'; return ecw_case_tags($caseId); })(),
        // The reply clock: waiting for us since…, due by…, in business hours (lib/sla.php).
        'sla'           => (static function () use ($organizationId, $caseId) { require_once __DIR__ . '/sla.php'; return ecw_sla_for_cases($organizationId, [$caseId])[$caseId] ?? null; })(),
    ];
}

/**
 * The columns: one per linked thread, hidden ones included so the UI can offer
 * them back.
 */
function ecw_case_columns(int $caseId): array
{
    $st = ecw_db()->prepare(
        'SELECT ct.id AS link_id, ct.display_name, ct.sort_order, ct.is_hidden, ct.linked_at, ct.party_role,
                t.id AS thread_id, t.mailbox_id, t.normalized_subject, t.message_count,
                t.latest_message_at, t.latest_from_email, t.latest_from_name, t.unread_count
           FROM `' . ecw_table('case_threads') . '` ct
           JOIN `' . ecw_table('email_threads') . '` t ON t.id = ct.email_thread_id
          WHERE ct.case_id = ?
          ORDER BY ct.sort_order, ct.id'
    );
    $st->execute([$caseId]);
    $rows = $st->fetchAll();

    $columns = [];
    foreach ($rows as $index => $row) {
        $threadId = (int)$row['thread_id'];
        $columns[] = [
            'kind'           => 'email',
            'link_id'        => (int)$row['link_id'],
            'thread_id'      => $threadId,
            'index'          => $index,
            // A column identifies a conversation, never a sender.
            // Preserve custom labels; otherwise use the thread subject.
            'label'          => $row['display_name'] ?: ecw_thread_label($threadId, (string)$row['normalized_subject']),
            'renamed'        => $row['display_name'] !== null && $row['display_name'] !== '',
            'subject'        => $row['normalized_subject'],
            'participants'   => ecw_thread_participants($threadId, 20, true),
            'message_count'  => (int)$row['message_count'],
            'latest_at'      => $row['latest_message_at'],
            'is_hidden'      => (int)$row['is_hidden'] === 1,
            'sort_order'     => (int)$row['sort_order'],
            'mailbox_id'     => (int)$row['mailbox_id'],
            'unread_count'   => (int)$row['unread_count'],
            // Whose conversation: customer / supplier / internal (lib/next_actions.php).
            'party_role'     => $row['party_role'] ?? null,
        ];
    }

    return $columns;
}

/** Participants in one thread; case headers include the connected mailbox too. */
function ecw_thread_participants(int $threadId, int $limit = 3, bool $includeMailbox = false): array
{
    $st = ecw_db()->prepare(
        'SELECT p.email, MAX(p.name) AS name, COUNT(*) AS hits
           FROM `' . ecw_table('message_participants') . '` p
           JOIN `' . ecw_table('email_messages') . '` m ON m.id = p.email_message_id
           JOIN `' . ecw_table('email_threads') . '` t ON t.id = m.email_thread_id
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id
          WHERE m.email_thread_id = ? AND p.role IN ("from", "to")
            ' . ($includeMailbox ? '' : 'AND p.email <> LOWER(mb.email_address)') . '
          GROUP BY p.email
          ORDER BY hits DESC, p.email
          LIMIT ' . max(1, $limit)
    );
    $st->execute([$threadId]);
    return $st->fetchAll();
}

/**
 * A stable thread title when nobody has renamed the column.
 */
function ecw_thread_label(int $threadId, string $fallbackSubject): string
{
    return $fallbackSubject !== '' ? $fallbackSubject : 'Thread #' . $threadId;
}

/**
 * The timeline itself.
 *
 * @return array{summary: array, columns: array, events: array, truncated: bool}
 */
/**
 * @param int|string|null $onlyThreadId restrict the stream to one column (a thread id, or a module column's key)
 *
 * The narrow-screen view shows one thread at a time but must keep a truthful
 * time rail. Rather than filter events in the browser — which would mean a
 * second implementation of the gap algorithm, in another language, free to
 * drift from this one — the client asks for the timeline it wants and the
 * gaps are computed here, once, for whatever is actually being shown.
 *
 * MODULE COLUMNS
 * --------------
 * Modules may add columns (e.g. a chat with messages linked to the case) and
 * their rows (lib/modules.php ecw_timeline_module_columns / _events). They go
 * on the same axis by the same rule. Rows marked "group" that follow each
 * other in one column, within 10 minutes and with nothing between them, share
 * one row: a chat burst would otherwise push the emails apart.
 */
function ecw_case_timeline(int $organizationId, int $caseId, bool $allowRemoteImages = false, int|string|null $onlyThreadId = null, ?int $viewerUserId = null, bool $withQuoted = true): ?array
{
    $summary = ecw_case_summary($organizationId, $caseId);
    if ($summary === null) {
        return null;
    }

    require_once __DIR__ . '/modules.php';
    $columns = ecw_case_columns($caseId);
    $moduleColumns = ecw_timeline_module_columns($organizationId, $caseId, $viewerUserId);
    usort($moduleColumns, static fn(array $a, array $b): int => [(int)$a['sort_order'], (string)$a['thread_id']] <=> [(int)$b['sort_order'], (string)$b['thread_id']]);
    foreach ($moduleColumns as $i => $c) $moduleColumns[$i]['index'] = count($columns) + $i;
    $columns = array_merge($columns, $moduleColumns);
    // Unread is shown only where this viewer can see (and clear) the mailbox's read state.
    require_once __DIR__ . '/unread.php';
    $readable = array_flip($viewerUserId !== null ? ecw_unread_mailbox_ids($organizationId, $viewerUserId) : []);
    foreach ($columns as &$c) {
        if (!isset($readable[$c['mailbox_id']])) $c['unread_count'] = 0;
    }
    unset($c);
    $visible = array_values(array_filter(
        $columns,
        static fn(array $c): bool => !$c['is_hidden'] && ($onlyThreadId === null || (string)$c['thread_id'] === (string)$onlyThreadId)
    ));

    if ($visible === []) {
        return ['summary' => $summary, 'columns' => $columns, 'events' => [], 'truncated' => false];
    }

    $columnByThread = [];
    foreach ($visible as $position => $column) {
        $columnByThread[$column['thread_id']] = $position;
    }

    $threadIds = array_column(array_filter($visible, static fn(array $c): bool => $c['kind'] === 'email'), 'thread_id');
    $rows = [];
    if ($threadIds !== []) {
        $in = implode(',', array_fill(0, count($threadIds), '?'));
        // ONE query, ordered once. The column a message belongs to is a lookup, not
        // a second pass over the data.
        $st = ecw_db()->prepare(
            'SELECT m.id, m.email_thread_id, m.occurred_at, m.subject, m.direction,
                    m.from_email, m.from_name, m.to_json, m.cc_json, m.bcc_json,
                    m.body_html, m.body_text, m.quoted_html, m.quoted_text, m.body_fetch_state, m.attachments_state,
                    m.has_attachments, m.web_link, m.provider_removed_at,
                    m.in_reply_to_message_id, m.mailbox_id, m.is_read, m.internet_message_id
               FROM `' . ecw_table('email_messages') . "` m
              WHERE m.email_thread_id IN ({$in}) AND m.direction <> 'draft'
              ORDER BY m.occurred_at IS NULL, m.occurred_at ASC, m.id ASC
              LIMIT " . (ECW_TIMELINE_MAX_EVENTS + 1)
        );
        $st->execute($threadIds);
        $rows = ecw_timeline_one_copy($st->fetchAll());
    }

    $truncated = count($rows) > ECW_TIMELINE_MAX_EVENTS;
    if ($truncated) {
        $rows = array_slice($rows, 0, ECW_TIMELINE_MAX_EVENTS);
    }

    $replyContext = ecw_reply_contexts($rows);
    $localIds     = array_column($rows, 'id');
    $inTimeline   = array_flip(array_map('intval', $localIds));

    // Internal notes are not timeline rows any more: like comments in Word they
    // hang off each email (the 💬 button) and open in the notes panel
    // (case-panel.js / notes-ui.js). The stream below is emails only.
    $stream = [];
    foreach ($rows as $row) {
        $stream[] = ['t' => $row['occurred_at'] ?? '9999-12-31 23:59:59', 'kind' => 'email', 'row' => $row];
    }
    // Rows of module columns (chats linked to the case…), on the same axis.
    $moduleKeys = array_map('strval', array_column(array_filter($visible, static fn(array $c): bool => $c['kind'] !== 'email'), 'thread_id'));
    foreach (ecw_timeline_module_events($organizationId, $caseId, $moduleKeys, $viewerUserId) as $ev) {
        $stream[] = ['t' => (string)$ev['occurred_at'], 'kind' => 'module', 'event' => $ev];
    }
    // Stable: emails keep their query order; a note or module row in the same second follows the email.
    usort($stream, static fn(array $a, array $b): int => [$a['t'], $a['kind'] !== 'email'] <=> [$b['t'], $b['kind'] !== 'email']);

    $events    = [];
    $previous  = null;
    $viewTz    = ecw_tz_for_user($viewerUserId ?? ecw_tz_viewer(), $organizationId);   // where a new day starts
    foreach ($stream as $item) {
        if ($item['kind'] === 'module') {
            $ev = $item['event'];
            $key = (string)$ev['thread_id'];
            $last = $events ? $events[count($events) - 1] : null;
            // A burst in one column (nothing else between, within 10 minutes) shares one row.
            if (!empty($ev['group']) && $last !== null && ($last['type'] ?? '') === $ev['type'] && (string)$last['thread_id'] === $key
                && strtotime($item['t'] . ' UTC') - strtotime(($last['last_at'] ?? $last['occurred_at']) . ' UTC') <= 600) {
                $events[count($events) - 1]['items'][] = $ev['item'] ?? null;
                $events[count($events) - 1]['last_at'] = $item['t'];
                $previous = $item['t'];
                continue;
            }
            $events[] = [
                'type'        => (string)$ev['type'],
                'thread_id'   => $key,
                'column'      => $columnByThread[$key] ?? 0,
                'occurred_at' => $item['t'],
                'last_at'     => $item['t'],
                'gap'         => ecw_timeline_gap($previous, $item['t'], $viewTz),
                'items'       => [$ev['item'] ?? null],
            ];
            $previous = $item['t'];
            continue;
        }
        if ($item['kind'] === 'note') {
            $note = $item['note'];
            $events[] = [
                'type'        => 'note',
                'note_id'     => $note['id'],
                'thread_id'   => $note['thread_id'],
                'column'      => $note['thread_id'] !== null ? $columnByThread[$note['thread_id']] : null,
                'occurred_at' => $note['created_at'],
                'gap'         => ecw_timeline_gap($previous, $note['created_at'], $viewTz),
                'note'        => $note,
            ];
            $previous = $note['created_at'];
            continue;
        }
        $row = $item['row'];
        $messageId = (int)$row['id'];
        $when      = (string)$row['occurred_at'];
        $resolver  = static fn(string $cid): ?string => ecw_inline_attachment_url($messageId, $cid);

        $parts = ecw_message_display_parts($row);
        $context = $replyContext[$row['in_reply_to_message_id'] ?? ''] ?? null;
        if ($context !== null) {
            $context['in_timeline'] = isset($inTimeline[$context['id']]);
        }

        $events[] = [
            'type'       => 'email',
            'message_id' => $messageId,
            'thread_id'  => (int)$row['email_thread_id'],
            'column'     => $columnByThread[(int)$row['email_thread_id']] ?? 0,
            'occurred_at' => $when,
            'gap'        => ecw_timeline_gap($previous, $when, $viewTz),
            'bubble'     => [
                'from'            => ['email' => $row['from_email'], 'name' => $row['from_name']],
                'to'              => json_decode((string)($row['to_json'] ?? '[]'), true) ?: [],
                'cc'              => json_decode((string)($row['cc_json'] ?? '[]'), true) ?: [],
                // Bcc is only known on what we sent; shown there (a click on a name shows its address).
                'bcc'             => ($row['direction'] ?? '') === 'outgoing' ? (json_decode((string)($row['bcc_json'] ?? '[]'), true) ?: []) : [],
                'subject'         => $row['subject'],
                'direction'       => $row['direction'],
                'removed'         => $row['provider_removed_at'] !== null,
                'unread'          => (int)$row['is_read'] === 0 && $row['direction'] === 'incoming'
                                     && isset($readable[(int)$row['mailbox_id']]),
                'body_state'      => $row['body_fetch_state'],
                'needs_inline_images' => ecw_inline_images_missing($messageId, (string)$row['body_html'] . (string)$row['quoted_html']),
            'attachments_pending' => ($row['attachments_state'] ?? null) === 'pending',   // text here, files still coming (lib/sync/messages.php)
                'body_html'       => ecw_sanitize_email_html($parts['body_html'], $allowRemoteImages, $resolver),
                'signature_html'  => ecw_sanitize_email_html($parts['signature_html'], $allowRemoteImages, $resolver),
                // Null when left out: the bubble's "..." fetches it (api body).
                'quoted_html'     => $withQuoted ? ecw_sanitize_email_html($parts['quoted_html'], $allowRemoteImages, $resolver) : null,
                'has_quoted'      => $parts['has_quoted'],
                'has_attachments' => (bool)(int)$row['has_attachments'],
                'attachments'     => (int)$row['has_attachments'] === 1 ? ecw_message_attachments($messageId) : [],
                'web_link'        => $row['web_link'],
                'reply_to'        => $context,
            ],
        ];

        $previous = $when;
    }

    return [
        'summary'   => $summary,
        'columns'   => $columns,
        'events'    => $events,
        'truncated' => $truncated,
    ];
}

/**
 * One bubble per email in a column. The mailbox can hold several copies of one
 * email (same Message-ID): the Sent and Inbox copies of mail to oneself, a copy
 * left "removed" when Outlook moved or re-created it under a new id. Keeps the
 * copy still in the mailbox (outgoing first), in the rows' order.
 */
function ecw_timeline_one_copy(array $rows): array
{
    $best = [];
    foreach ($rows as $i => $row) {
        $mid = trim((string)($row['internet_message_id'] ?? ''));
        if ($mid === '') continue;
        $key = $row['email_thread_id'] . "\n" . strtolower($mid);
        $rank = [$row['provider_removed_at'] === null, ($row['direction'] ?? '') === 'outgoing'];
        if (!isset($best[$key]) || $rank > $best[$key][1]) $best[$key] = [$i, $rank];
    }
    $keep = array_flip(array_column($best, 0));
    return array_values(array_filter($rows, static function (array $row, int $i) use ($keep): bool {
        return trim((string)($row['internet_message_id'] ?? '')) === '' || isset($keep[$i]);
    }, ARRAY_FILTER_USE_BOTH));
}

/**
 * Resolve every "in reply to" for a page of messages in one query.
 *
 * The stored value is an RFC Message-ID, so the referenced message is found by
 * internet_message_id. It may not be local at all — a supplier replying to
 * something they were forwarded — and that is normal, not an error: the UI then
 * shows no reply reference rather than a dead link.
 *
 * @return array<string,array> keyed by RFC Message-ID
 */
function ecw_reply_contexts(array $rows): array
{
    $wanted = [];
    foreach ($rows as $row) {
        $ref = trim((string)($row['in_reply_to_message_id'] ?? ''));
        if ($ref !== '') {
            $wanted[$ref] = true;
        }
    }
    if ($wanted === []) {
        return [];
    }

    $ids = array_keys($wanted);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $threadIds = array_values(array_unique(array_filter(array_map(static fn($row) => (int)($row['email_thread_id'] ?? 0), $rows))));
    if (!$threadIds) return [];
    $threadList = implode(',', $threadIds);
    $referenceScope = ecw_private_mailboxes() ? " AND m.email_thread_id IN ({$threadList})"
        : " AND m.mailbox_id IN (SELECT mailbox_id FROM `" . ecw_table('email_threads') . "` WHERE id IN ({$threadList}))";
    $st = ecw_db()->prepare(
        'SELECT m.id, m.internet_message_id, m.from_email, m.from_name, m.occurred_at,
                m.subject, m.body_text, m.body_html
           FROM `' . ecw_table('email_messages') . "` m
          WHERE m.internet_message_id IN ({$in})" . $referenceScope
    );
    $st->execute($ids);

    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(string)$row['internet_message_id']] = [
            'id'          => (int)$row['id'],
            'from'        => $row['from_name'] ?: $row['from_email'],
            'occurred_at' => $row['occurred_at'],
            // A short plain-text quote, never markup: this is a reference, not
            // a second copy of the message.
            'snippet'     => ecw_preview_text((string)($row['body_text'] ?? ''), (string)($row['body_html'] ?? ''), 90),
            'in_timeline' => false,
        ];
    }

    return $out;
}

/**
 * The gap between two consecutive events.
 *
 * Proportional spacing is not an option: three days of silence would make the
 * page thousands of pixels tall and the reader would scroll past the very
 * finding the case is about. So spacing is bucketed — six sizes, none through
 * xl — while the TRUE elapsed time is always printed. Compressed distance,
 * exact number.
 *
 * @return array{seconds:int, label:?string, size:string, new_day:bool}|null
 */
function ecw_timeline_gap(?string $previous, string $current, string $tz = 'UTC'): ?array
{
    if ($previous === null || trim($previous) === '' || trim($current) === '') {
        return null;
    }

    $from = strtotime($previous . ' UTC');
    $to   = strtotime($current . ' UTC');
    if ($from === false || $to === false) {
        return null;
    }

    $seconds = max(0, $to - $from);
    // A new day where the viewer is: 23:30 and 00:30 in Athens are two days, even when UTC says one.
    $newDay  = $tz === 'UTC' ? substr($previous, 0, 10) !== substr($current, 0, 10)
        : ecw_tz_format($previous, $tz, 'Y-m-d') !== ecw_tz_format($current, $tz, 'Y-m-d');

    if ($seconds < ECW_GAP_NONE && !$newDay) {
        return ['seconds' => $seconds, 'label' => null, 'size' => 'none', 'new_day' => false];
    }

    return [
        'seconds' => $seconds,
        'label'   => ecw_format_elapsed($seconds),
        'size'    => ecw_gap_size($seconds),
        'new_day' => $newDay,
    ];
}

/**
 * Which of the five visible buckets a pause falls into. `none` (under five
 * minutes, same calendar day) is handled by the caller before this runs, so
 * every value that reaches here gets an actual separator row.
 */
function ecw_gap_size(int $seconds): string
{
    if ($seconds < ECW_GAP_XS) {
        return 'xs';
    }
    if ($seconds < ECW_GAP_SM) {
        return 'sm';
    }
    if ($seconds < ECW_GAP_MD) {
        return 'md';
    }
    if ($seconds < ECW_GAP_LG) {
        return 'lg';
    }
    return 'xl';
}

/** "+24 min", "+3h 14m", "+2 days 4h" — two units at most. */
function ecw_format_elapsed(int $seconds): string
{
    if ($seconds < 60) {
        return '+' . $seconds . ' sec';
    }
    if ($seconds < 3600) {
        return '+' . intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return '+' . $hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'm' : '');
    }

    $days  = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    return '+' . $days . ' day' . ($days === 1 ? '' : 's') . ($hours > 0 ? ' ' . $hours . 'h' : '');
}

// ------------------------------------------------------------ case actions

/**
 * Threads that could be added to a case: in this organization's mailboxes, and
 * not already linked to a case (the V1 one-thread-one-case rule, enforced by a
 * unique key — so offering a linked thread here would only produce an error).
 */
function ecw_case_linkable_threads(int $organizationId, string $query, int $limit = 25, ?int $userId = null): array
{
    $like   = '%' . trim($query) . '%';
    $params = [$organizationId];

    $sql = 'SELECT t.id, t.mailbox_id, t.normalized_subject, t.message_count, t.latest_message_at,
                   t.latest_from_name, t.latest_from_email, t.latest_preview, mb.email_address AS mailbox
              FROM `' . ecw_table('email_threads') . '` t
              JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id
             WHERE mb.organization_id = ?
               AND NOT EXISTS (SELECT 1 FROM `' . ecw_table('case_threads') . '` ct WHERE ct.email_thread_id = t.id)';

    if (ecw_private_mailboxes()) { require_once __DIR__ . '/shared_mailboxes.php'; $sql .= ' AND ' . ecw_mailbox_visible_sql('mb', $userId ?? 0, $params); }

    if (trim($query) !== '') {
        $sql .= ' AND (t.normalized_subject LIKE ? OR EXISTS (
                        SELECT 1 FROM `' . ecw_table('email_messages') . '` m
                          JOIN `' . ecw_table('message_participants') . '` p ON p.email_message_id = m.id
                         WHERE m.email_thread_id = t.id AND p.email LIKE ?))';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY t.latest_message_at DESC LIMIT ' . max(1, min($limit, 50));

    $st = ecw_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Rename a column, hide it, or show it again. The link itself is untouched. */
function ecw_case_thread_update(int $organizationId, int $caseId, int $threadId, array $changes): bool
{
    $st = ecw_db()->prepare(
        'SELECT ct.id FROM `' . ecw_table('case_threads') . '` ct
           JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
          WHERE ct.case_id = ? AND ct.email_thread_id = ? AND c.organization_id = ? AND c.deleted_at IS NULL'
    );
    $st->execute([$caseId, $threadId, $organizationId]);
    $linkId = $st->fetchColumn();
    if ($linkId === false) {
        return false;
    }

    $fields = [];
    $params = [];
    if (array_key_exists('display_name', $changes)) {
        $name = trim((string)$changes['display_name']);
        $fields[] = '`display_name` = ?';
        $params[] = $name === '' ? null : mb_substr($name, 0, 255);
    }
    if (array_key_exists('is_hidden', $changes)) {
        $fields[] = '`is_hidden` = ?';
        $params[] = !empty($changes['is_hidden']) ? 1 : 0;
    }
    if (array_key_exists('sort_order', $changes)) {
        $fields[] = '`sort_order` = ?';
        $params[] = (int)$changes['sort_order'];
    }
    if ($fields === []) {
        return true;
    }

    $params[] = (int)$linkId;
    ecw_db()->prepare('UPDATE `' . ecw_table('case_threads') . '` SET ' . implode(', ', $fields) . ' WHERE id = ?')
        ->execute($params);

    return true;
}

/** Rename the case, change status or owner. */
function ecw_case_update(int $organizationId, int $caseId, array $changes, ?int $actorUserId = null): bool
{
    $case = ecw_case_load($organizationId, $caseId);
    if ($case === null) {
        return false;
    }

    $assignments = ecw_local_features() ? ['owner_user_id', 'handler_user_id'] : ['owner_user_id'];
    foreach ($assignments as $field) {
        if (!isset($changes[$field])) continue;
        $member = ecw_db()->prepare('SELECT u.id FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id=u.id WHERE ou.organization_id=? AND u.id=? AND u.disabled_at IS NULL');
        $member->execute([$organizationId, (int)$changes[$field]]);
        if (!$member->fetchColumn()) return false;
    }
    $fields = [];
    $params = [];

    if (array_key_exists('name', $changes)) {
        $name = trim((string)$changes['name']);
        if ($name !== '' && $name !== $case['name']) {
            $fields[] = '`name` = ?';
            $params[] = mb_substr($name, 0, 255);
            ecw_case_log_event($caseId, 'CASE_RENAMED', $actorUserId, null, null,
                ['from' => $case['name'], 'to' => $name]);
        }
    }

    if (array_key_exists('status', $changes)) {
        // A group (open, waiting, closed) or one of the company's statuses (lib/case_settings.php),
        // which keeps its group in `status` and its own name in `sub_status`.
        require_once __DIR__ . '/case_settings.php';
        $resolved = ecw_case_status_resolve($organizationId, trim((string)$changes['status']));
        if ($resolved !== null && ($resolved[0] !== $case['status'] || $resolved[1] !== ($case['sub_status'] ?? null))) {
            [$status, $sub] = $resolved;
            $fields[] = '`status` = ?';
            $params[] = $status;
            $fields[] = '`sub_status` = ?';
            $params[] = $sub;
            $fields[] = '`status_changed_at` = ?';
            $params[] = ecw_now();
            if ($status !== $case['status']) {
                // closed_at is derived from the status rather than set by the caller,
                // so a case cannot be open with a closing date on it.
                $fields[] = '`closed_at` = ?';
                $params[] = $status === 'closed' ? ecw_now() : null;
                $closedNow = $status === 'closed';
            }
            $from = ecw_case_status_display($organizationId, (string)$case['status'], $case['sub_status'] ?? null);
            $to = ecw_case_status_display($organizationId, $status, $sub);
            ecw_case_log_event($caseId, 'CASE_STATUS_CHANGED', $actorUserId, null, null,
                ['from' => $case['status'], 'to' => $status] + ($from['label'] !== ucfirst((string)$case['status']) || $to['label'] !== ucfirst($status) ? ['from_label' => $from['label'], 'to_label' => $to['label']] : []));
        }
    }

    foreach ($assignments as $field) {
        if (!array_key_exists($field, $changes)) continue;
        $new = $changes[$field] === null || $changes[$field] === '' ? null : (int)$changes[$field];
        $fields[] = '`' . $field . '` = ?';
        $params[] = $new;
        if ($new !== ($case[$field] === null ? null : (int)$case[$field])) {
            ecw_case_log_event($caseId, 'CASE_ASSIGNED', $actorUserId, null, null, ['field' => $field, 'from' => $case[$field], 'to' => $new]);
        }
    }

    // Due date: 'YYYY-MM-DD', or empty to clear. A new date re-arms the reminder.
    if (array_key_exists('due_at', $changes)) {
        $due = trim((string)$changes['due_at']);
        $d = $due === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $due);
        if ($due !== '' && (!$d || $d->format('Y-m-d') !== $due)) return false;
        $due = $d ? $d->format('Y-m-d') : null;
        if ($due !== ($case['due_at'] ?? null)) {
            $fields[] = '`due_at` = ?';
            $params[] = $due;
            $fields[] = '`due_notified_at` = NULL';
            ecw_case_log_event($caseId, 'CASE_DUE_CHANGED', $actorUserId, null, null, ['from' => $case['due_at'] ?? null, 'to' => $due]);
        }
    }

    if ($fields === []) {
        return true;
    }

    $params[] = $caseId;
    $params[] = $organizationId;
    ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET ' . implode(', ', $fields)
        . ' WHERE id = ? AND organization_id = ?')->execute($params);
    if (!empty($closedNow)) {
        // Solutions memory: the AI writes down the problem and the solution (lib/case_memory.php).
        require_once __DIR__ . '/case_memory.php';
        ecw_memory_on_close($organizationId, $caseId);
    }

    return true;
}

/**
 * Rename a case. Trim, 1-120 chars, reject empty — the settings-menu
 * Rename action's own validation, stricter than ecw_case_update()'s
 * generic path (kept as-is so nothing already calling it changes shape).
 *
 * @return array{ok:bool,status?:int,error?:string,name?:string}
 */
function ecw_case_rename(int $organizationId, int $caseId, string $rawName, ?int $actorUserId = null): array
{
    $case = ecw_case_load($organizationId, $caseId);
    if ($case === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'case not found'];
    }

    $name   = trim($rawName);
    $length = mb_strlen($name);
    if ($length < 1 || $length > 120) {
        return ['ok' => false, 'status' => 422, 'error' => 'name must be 1 to 120 characters'];
    }

    if ($name !== $case['name']) {
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET `name` = ? WHERE id = ? AND organization_id = ?')
            ->execute([$name, $caseId, $organizationId]);
        ecw_case_log_event($caseId, 'CASE_RENAMED', $actorUserId, null, null, ['from' => $case['name'], 'to' => $name]);
    }

    return ['ok' => true, 'name' => $name];
}

/**
 * Soft delete: hides the case, nothing else. case_threads rows (and the
 * threads/emails they point at) are never touched, which is exactly what
 * makes ecw_case_restore() below a complete undo.
 */
function ecw_case_soft_delete(int $organizationId, int $caseId, ?int $actorUserId = null): bool
{
    $case = ecw_case_load($organizationId, $caseId);
    if ($case === null) {
        return false;
    }
    require_once __DIR__ . '/security.php';
    if (ecw_case_held($caseId)) {
        throw new InvalidArgumentException('This case is on legal hold and cannot be deleted. An admin can release the hold.');
    }

    ecw_db()->prepare(
        'UPDATE `' . ecw_table('cases') . '` SET `deleted_at` = ?, `deleted_by_user_id` = ? WHERE id = ? AND organization_id = ?'
    )->execute([ecw_now(), $actorUserId, $caseId, $organizationId]);

    ecw_case_log_event($caseId, 'CASE_DELETED', $actorUserId);
    require_once __DIR__ . '/categories.php';
    ecw_categories_mark_case($caseId);   // its categories come off in Outlook (and back on restore)
    return true;
}

/** Undo of ecw_case_soft_delete(). Threads were never unlinked, so clearing the two columns alone brings everything back. */
function ecw_case_restore(int $organizationId, int $caseId, ?int $actorUserId = null): bool
{
    $case = ecw_case_load($organizationId, $caseId, true);
    if ($case === null || $case['deleted_at'] === null) {
        return false;
    }

    ecw_db()->prepare(
        'UPDATE `' . ecw_table('cases') . '` SET `deleted_at` = NULL, `deleted_by_user_id` = NULL WHERE id = ? AND organization_id = ?'
    )->execute([$caseId, $organizationId]);

    ecw_case_log_event($caseId, 'CASE_RESTORED', $actorUserId);
    require_once __DIR__ . '/categories.php';
    ecw_categories_mark_case($caseId);
    return true;
}

/** "[CASE-00142] " in front of a subject, exactly once. */
function ecw_case_subject_tag(string $caseNumber, string $subject): string
{
    $tag = '[' . $caseNumber . ']';
    if (str_contains($subject, $tag)) {
        return $subject;
    }
    return trim($tag . ' ' . trim($subject));
}
