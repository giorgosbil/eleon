<?php declare(strict_types=1);
/**
 * Email Case Workspace — the queries behind the mail UI.
 *
 * Every function here reads the local database. None of them calls a provider.
 * Opening the mailbox must not touch Graph, and the only way to guarantee that
 * is for the read path to have no provider in it at all.
 *
 * PAGINATION IS KEYSET, NOT OFFSET
 * --------------------------------
 * LIMIT 50 OFFSET 12000 makes MySQL walk and discard 12,000 rows, so scrolling
 * gets slower the further down you go — on a 20,000-message mailbox the bottom
 * of the list is where it hurts most. Paging on (latest_message_at, id) instead
 * seeks straight into idx_ecw_email_threads_latest and costs the same at row
 * 12,000 as at row 1. It is also correct while mail arrives: an offset shifts
 * every row down by one and the user sees a duplicate, while a keyset cursor
 * keeps pointing at the same place.
 *
 * The tuple must include the id. Two threads can share a latest_message_at to
 * the second, and a cursor on the timestamp alone either skips one or repeats
 * it forever.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/body.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/sanitize.php';
require_once __DIR__ . '/threading.php';

const ECW_THREAD_PAGE_SIZE = 50;
/** A search for common words reads this many conversations at a time, at most this many times per page. */
const ECW_SEARCH_WALK_BATCH = 500;
const ECW_SEARCH_WALK_ROUNDS = 8;

/** Navigation: the folder tree plus unread counts, for one mailbox. */
function ecw_folder_tree(int $mailboxId): array
{
    $st = ecw_db()->prepare(
        'SELECT f.id, f.provider_folder_id, f.parent_provider_folder_id, f.name, f.folder_type,
                f.is_system, f.is_selected_for_sync, f.is_case_intake,
                (SELECT COUNT(*) FROM `' . ecw_table('email_messages') . "` m
                  WHERE m.mail_folder_id = f.id AND m.is_read = 0
                    AND m.direction = 'incoming' AND m.provider_removed_at IS NULL) AS unread_count
           FROM `" . ecw_table('mail_folders') . '` f
          WHERE f.mailbox_id = ? AND f.folder_type <> "system"
          ORDER BY f.is_system DESC, FIELD(f.folder_type, "inbox","sent","drafts","archive","junk","deleted"), f.name'
    );
    $st->execute([$mailboxId]);
    return $st->fetchAll();
}

/**
 * The thread list.
 *
 * @param array $opts folder_id|folder_type|query|cursor|limit
 * @return array{threads: array, next_cursor: ?string}
 */
function ecw_thread_list(int $mailboxId, array $opts = []): array
{
    $pdo     = ecw_db();
    $threads = ecw_table('email_threads');
    $limit   = max(1, min((int)($opts['limit'] ?? ECW_THREAD_PAGE_SIZE), 100));

    $where  = ['t.mailbox_id = ?'];
    $params = [$mailboxId];

    // Sent Items lists each conversation by YOUR newest sent email in it: who it
    // went to, when, what it said (as Outlook does). By the conversation's newest
    // email, a reply received today made mail sent days ago look sent today.
    $sentFolders = ecw_sent_folder_ids($mailboxId, $opts);

    // Folder scoping is an EXISTS over messages rather than a column on the
    // thread: one conversation legitimately spans Inbox and Sent, and it must
    // appear in both.
    if (!empty($opts['folder_id'])) {
        $where[]  = 'EXISTS (SELECT /*+ QB_NAME(fold) */ 1 FROM `' . ecw_table('email_messages') . '` fm
                              WHERE fm.email_thread_id = t.id AND fm.mail_folder_id = ?
                                AND fm.provider_removed_at IS NULL)';
        $params[] = (int)$opts['folder_id'];
    } elseif (!empty($opts['folder_type'])) {
        $where[]  = 'EXISTS (SELECT 1 FROM `' . ecw_table('email_messages') . '` fm
                              JOIN `' . ecw_table('mail_folders') . '` ff ON ff.id = fm.mail_folder_id
                              WHERE fm.email_thread_id = t.id AND ff.folder_type = ?
                                AND fm.provider_removed_at IS NULL)';
        $params[] = (string)$opts['folder_type'];
    }

    $filters = ecw_search_parse((string)($opts['query'] ?? ''));
    $search = null;
    if (!ecw_search_is_empty($filters)) {
        $conditions = ecw_search_conditions($filters, ecw_search_common_terms($filters));
        if ($conditions['where'] !== []) {
            $search = $conditions;
        }
    }

    // Snoozed threads leave the list until their time (or a new reply) and are
    // listed on their own under "Snoozed".
    if (!empty($opts['snoozed'])) {
        $where[]  = 't.snoozed_until > ?';
        $params[] = ecw_now();
    } elseif (empty($opts['include_snoozed'])) {
        $where[]  = '(t.snoozed_until IS NULL OR t.snoozed_until <= ?)';
        $params[] = ecw_now();
    }

    // Shared mailboxes: "Mine" / "Unassigned" / "Others'" (who claimed the conversation).
    $claim = (string)($opts['claim'] ?? '');
    if ($claim === 'mine' && !empty($opts['user_id'])) { $where[] = 't.claimed_by_user_id = ?'; $params[] = (int)$opts['user_id']; }
    elseif ($claim === 'unclaimed') { $where[] = 't.claimed_by_user_id IS NULL'; }
    elseif ($claim === 'others' && !empty($opts['user_id'])) { $where[] = 't.claimed_by_user_id IS NOT NULL AND t.claimed_by_user_id <> ?'; $params[] = (int)$opts['user_id']; }
    $where[] = 't.latest_message_at IS NOT NULL';

    $cursor = ecw_decode_cursor((string)($opts['cursor'] ?? ''));
    $listColumns = 't.id, t.normalized_subject, t.latest_message_at, t.message_count, t.latest_message_id,
                       t.latest_from_email, t.latest_from_name, t.latest_preview, t.latest_direction,
                       t.unread_count, t.has_attachments, t.snoozed_until, t.flagged_count,
                       t.claimed_by_user_id, cu.name AS claimed_by_name,
                       c.id AS case_id, c.case_number, c.name AS case_name, c.status AS case_status';
    $listJoins = '
                  LEFT JOIN `' . ecw_table('users') . '` cu ON cu.id = t.claimed_by_user_id
                  LEFT JOIN `' . ecw_table('case_threads') . '` ct ON ct.email_thread_id = t.id
                  LEFT JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id AND c.deleted_at IS NULL';
    $page = static function (array $where, array $params, ?array $cursor, int $take, bool $walk, string $columns = '') use ($pdo, $threads, $listColumns, $listJoins): array {
        if ($cursor !== null) {
            $where[] = '(t.latest_message_at < ? OR (t.latest_message_at = ? AND t.id < ?))';
            array_push($params, $cursor['ts'], $cursor['ts'], $cursor['id']);
        }
        $sql = 'SELECT ' . ($walk ? '/*+ NO_SEMIJOIN(@fold) */ ' : '') . ($columns !== '' ? $columns : $listColumns) . '
                  FROM `' . $threads . '` t' . ($walk ? ' FORCE INDEX (idx_ecw_email_threads_latest)' : '') . ($columns !== '' ? '' : $listJoins) . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY t.latest_message_at DESC, t.id DESC
                 LIMIT ' . $take;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    };
    // The matching conversations as a set; $within limits it to a batch.
    // Conditions about the conversation (is:unlinked, case:) are tested on it
    // directly, without reading its emails.
    $searchWhere = static function (array $within = []) use ($search, $mailboxId): array {
        $sql = $search['thread_where'];
        $p = $search['thread_params'];
        if ($search['message_where'] !== []) {
            array_unshift($sql, 't.id IN (SELECT m.email_thread_id FROM `' . ecw_table('email_messages') . '` m
                           WHERE m.mailbox_id = ? AND m.provider_removed_at IS NULL'
                           . ($within !== [] ? ' AND m.email_thread_id IN (' . implode(',', array_map('intval', $within)) . ')' : '') . '
                             AND ' . implode(' AND ', $search['message_where']) . ')');
            $p = array_merge([$mailboxId], $search['message_params'], $p);
        }
        return ['(' . implode(' AND ', $sql) . ')', $p];
    };

    // Top results (like Outlook): on the first page of a word search, the conversations whose
    // name holds every word typed come first (the list app.js draws them above the dates).
    // Accent and case do not matter (the column's collation): "εκπαιδευση" finds "Εκπαίδευση".
    $top = [];
    $words = $search !== null ? array_merge($filters['keywords'], $filters['phrases']) : [];
    if ($words !== [] && $cursor === null && empty($opts['no_top'])) {
        $topWhere = $where; $topParams = $params;
        foreach (array_slice($words, 0, 6) as $w) {
            $topWhere[] = "t.normalized_subject LIKE ? ESCAPE '='";
            $topParams[] = ecw_search_literal_pattern($w);
        }
        // The rest of the search still applies (from:, dates, attachments…).
        if ($search['thread_where'] !== [] || count($search['message_where']) > count($words)) {
            [$sw, $sp] = $searchWhere();
            $topWhere[] = $sw;
            $topParams = array_merge($topParams, $sp);
        }
        $top = array_map('ecw_thread_list_row', $page($topWhere, $topParams, null, 4, false));
    }

    // One extra row tells us whether another page exists, without a COUNT(*)
    // over the whole result set.
    $nextCursor = null;
    if ($sentFolders !== [] && ($search === null || !$search['walk'])) {
        // Sent Items: newest first by the newest SENT email of each conversation
        // (idx_ecw_email_messages_folder_occurred). The cursor walks that date.
        $sentWhere = $where; $sentParams = $params;
        if ($search !== null) {
            [$sw, $sp] = $searchWhere();
            $sentWhere[] = $sw;
            $sentParams = array_merge($sentParams, $sp);
        }
        if ($cursor !== null) {
            $sentWhere[] = '(x.folder_at < ? OR (x.folder_at = ? AND t.id < ?))';
            array_push($sentParams, $cursor['ts'], $cursor['ts'], $cursor['id']);
        }
        $st = $pdo->prepare('SELECT ' . str_replace('t.latest_message_at,', 'x.folder_at AS latest_message_at,', $listColumns) . '
                  FROM (SELECT email_thread_id, MAX(occurred_at) AS folder_at FROM `' . ecw_table('email_messages') . '`
                         WHERE mail_folder_id IN (' . implode(',', $sentFolders) . ') AND provider_removed_at IS NULL
                         GROUP BY email_thread_id) x
                  JOIN `' . $threads . '` t ON t.id = x.email_thread_id' . $listJoins . '
                 WHERE ' . implode(' AND ', $sentWhere) . '
                 ORDER BY x.folder_at DESC, t.id DESC
                 LIMIT ' . ($limit + 1));
        $st->execute($sentParams);
        $rows = $st->fetchAll();
    } elseif (!empty($opts['folder_id']) && $search === null && empty($opts['snoozed'])) {
        // A folder (Inbox, Archive…): each conversation by its newest email IN THIS FOLDER,
        // like Sent Items above. By the conversation's newest email, your reply sent today
        // made mail received weeks ago look received today, at the top of the Inbox.
        // Walks the folder's newest emails (idx_ecw_email_messages_folder_occurred) a
        // window at a time: grouping the whole folder takes seconds on a big Inbox.
        $folderId = (int)$opts['folder_id'];
        $messagesT = ecw_table('email_messages');
        $window = 3000;
        $scan = 'SELECT email_thread_id, occurred_at FROM `' . $messagesT . '`
                  WHERE mail_folder_id = ? AND provider_removed_at IS NULL AND occurred_at IS NOT NULL'
                . ($cursor !== null ? ' AND occurred_at <= ?' : '') . ' ORDER BY occurred_at DESC LIMIT ' . $window;
        $scanParams = $cursor !== null ? [$folderId, $cursor['ts']] : [$folderId];
        $folderWhere = $where; $folderParams = $params;
        if ($cursor !== null) {
            // Listed on an earlier page: it has a newer email in this folder than the cursor.
            $folderWhere[] = '(x.folder_at < ? OR (x.folder_at = ? AND t.id < ?)) AND NOT EXISTS (SELECT 1 FROM `' . $messagesT . '` nm
                               WHERE nm.email_thread_id = t.id AND nm.mail_folder_id = ? AND nm.provider_removed_at IS NULL AND nm.occurred_at > ?)';
            array_push($folderParams, $cursor['ts'], $cursor['ts'], $cursor['id'], $folderId, $cursor['ts']);
        }
        $st = $pdo->prepare('SELECT ' . str_replace('t.latest_message_at,', 'x.folder_at AS latest_message_at,', $listColumns) . '
                  FROM (SELECT email_thread_id, MAX(occurred_at) AS folder_at FROM (' . $scan . ') s GROUP BY email_thread_id) x
                  JOIN `' . $threads . '` t ON t.id = x.email_thread_id' . $listJoins . '
                 WHERE ' . implode(' AND ', $folderWhere) . '
                 ORDER BY x.folder_at DESC, t.id DESC
                 LIMIT ' . ($limit + 1));
        $st->execute(array_merge($scanParams, $folderParams));
        $rows = $st->fetchAll();
        if (count($rows) <= $limit) {
            // A short page: more of the folder lies past the window (snoozed or claimed ones
            // were left out of it). The list asks for the next page itself.
            $edge = $pdo->prepare('SELECT occurred_at FROM `' . $messagesT . '` WHERE mail_folder_id = ? AND provider_removed_at IS NULL AND occurred_at IS NOT NULL'
                . ($cursor !== null ? ' AND occurred_at <= ?' : '') . ' ORDER BY occurred_at DESC LIMIT 1 OFFSET ' . ($window - 1));
            $edge->execute($scanParams);
            $oldest = $edge->fetchColumn();
            if ($oldest !== false) {
                $sameSecond = array_filter($rows, static fn($r) => (string)$r['latest_message_at'] === (string)$oldest);
                $nextCursor = ecw_encode_cursor((string)$oldest, $sameSecond !== [] ? min(array_map(static fn($r) => (int)$r['id'], $sameSecond)) : PHP_INT_MAX);
            }
        }
        $folderRows = [$folderId];
    } elseif ($search !== null && $search['walk']) {
        // Every word is common (or one full text cannot index): read the
        // conversations newest-first in batches and check each batch, until a
        // page is full. A page can come back short with a cursor when the
        // matches are far apart; the list then asks for the next one itself.
        $rows = [];
        $from = $cursor;
        for ($round = 0; $round < ECW_SEARCH_WALK_ROUNDS; $round++) {
            $batch = $page($where, $params, $from, ECW_SEARCH_WALK_BATCH, !empty($opts['folder_id']), 't.id, t.latest_message_at');
            if ($batch === []) break;
            [$sw, $sp] = $searchWhere(array_column($batch, 'id'));
            $rows = array_merge($rows, $page(array_merge($where, [$sw]), array_merge($params, $sp), $from, $limit + 1 - count($rows), false));
            $lastSeen = end($batch);
            $from = ['ts' => (string)$lastSeen['latest_message_at'], 'id' => (int)$lastSeen['id']];
            if (count($rows) > $limit || count($batch) < ECW_SEARCH_WALK_BATCH) break;
        }
        if (count($rows) <= $limit && isset($batch) && count($batch) === ECW_SEARCH_WALK_BATCH) {
            $nextCursor = ecw_encode_cursor($from['ts'], $from['id']);   // more to look through
        }
    } else {
        // A plain folder: walk conversations newest-first and stop at 51,
        // asking "is it in this folder?" of each (idx_ecw_messages_thread_folder).
        // Left to itself MySQL gathered the whole folder first and sorted it:
        // 1.3 s on a 60,000-email mailbox instead of a few ms. A search keeps
        // the planner's choice: its matches are few, so collecting them first
        // is right.
        if ($search !== null) {
            [$sw, $sp] = $searchWhere();
            $where[] = $sw;
            $params = array_merge($params, $sp);
        }
        $rows = $page($where, $params, $cursor, $limit + 1, !empty($opts['folder_id']) && ($search === null || $search['message_where'] === []));
    }

    if (count($rows) > $limit) {
        $last       = $rows[$limit - 1];
        $nextCursor = ecw_encode_cursor((string)$last['latest_message_at'], (int)$last['id']);
        $rows       = array_slice($rows, 0, $limit);
    }
    $list = array_map('ecw_thread_list_row', $rows);
    // Top results go through the same steps (Sent Items view, colours, folder), then apart again.
    $topIds = array_column($top, 'id');
    $list = array_merge($top, array_values(array_filter($list, static fn($r) => !in_array($r['id'], $topIds, true))));
    if ($sentFolders !== [] && $list !== []) {
        $list = ecw_sent_list_rows($list, $sentFolders);
    } elseif (isset($folderRows) && $list !== []) {
        $list = ecw_folder_list_rows($list, $folderRows);
    }
    // The viewer's personal colour rules (Settings -> Rules) — only while that feature is theirs.
    if (!empty($opts['user_id']) && !empty($opts['organization_id']) && $list !== [] && empty($opts['no_colors'])) {
        require_once __DIR__ . '/rules.php';
        // Judged on the email the row shows (a received one), so the colour matches what is read there.
        $shown = array_filter(array_column($list, 'shown_message_id', 'id'));
        $colors = ecw_rule_colors_for_threads((int)$opts['organization_id'], (int)$opts['user_id'], array_column($list, 'id'), $shown);
        foreach ($list as &$row) {
            $row['color'] = $colors[$row['id']] ?? null;
        }
        unset($row);
    }
    // A search over every folder: where each conversation is (its newest email's folder), as Outlook shows.
    if ($search !== null && empty($opts['folder_id']) && empty($opts['folder_type']) && $list !== []) {
        $ids = implode(',', array_map('intval', array_column($list, 'id')));
        $where = [];
        foreach ($pdo->query('SELECT m.email_thread_id, f.name, f.folder_type FROM `' . ecw_table('email_messages') . '` m
                                JOIN `' . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id
                               WHERE m.email_thread_id IN ({$ids}) AND m.provider_removed_at IS NULL AND m.direction <> 'draft'
                               ORDER BY m.occurred_at DESC, m.id DESC")->fetchAll() as $f) {
            $tid = (int)$f['email_thread_id'];
            // Our own reply sits in Sent Items: the folder the conversation was filed in says more.
            if (!isset($where[$tid]) || ($where[$tid]['type'] === 'sent' && $f['folder_type'] !== 'sent')) $where[$tid] = ['name' => (string)$f['name'], 'type' => (string)$f['folder_type']];
        }
        foreach ($list as &$row) $row['folder'] = $where[(int)$row['id']] ?? null;
        unset($row);
    }
    $topRows = array_slice($list, 0, count($top));
    $list = array_slice($list, count($top));

    return [
        'top'         => $topRows,
        'threads'     => $list,
        'next_cursor' => $nextCursor,
        'query'       => $filters['raw'],
    ];
}

/** The mailbox's Sent folder(s) when the list asked for Sent Items, else []. */
function ecw_sent_folder_ids(int $mailboxId, array $opts): array
{
    if (!empty($opts['snoozed'])) return [];
    $folders = ecw_table('mail_folders');
    if (!empty($opts['folder_id'])) {
        $st = ecw_db()->prepare('SELECT id FROM `' . $folders . "` WHERE id = ? AND mailbox_id = ? AND folder_type = 'sent'");
        $st->execute([(int)$opts['folder_id'], $mailboxId]);
    } elseif (($opts['folder_type'] ?? '') === 'sent') {
        $st = ecw_db()->prepare('SELECT id FROM `' . $folders . "` WHERE mailbox_id = ? AND folder_type = 'sent'");
        $st->execute([$mailboxId]);
    } else {
        return [];
    }
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Sent Items rows: the newest sent email of each conversation says who it went
 * to ("To: …"), when and what, and the row is not bold for replies received.
 */
function ecw_sent_list_rows(array $list, array $sentFolders): array
{
    $messages = ecw_table('email_messages');
    $ids = implode(',', array_map('intval', array_column($list, 'id')));
    $in = implode(',', array_map('intval', $sentFolders));
    $newest = ecw_db()->query('SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY email_thread_id ORDER BY occurred_at DESC, id DESC) AS n
                                 FROM `' . $messages . '` WHERE email_thread_id IN (' . $ids . ') AND mail_folder_id IN (' . $in . ')
                                  AND provider_removed_at IS NULL) x WHERE n = 1')->fetchAll(PDO::FETCH_COLUMN);
    if ($newest === []) return $list;
    $sent = [];
    foreach (ecw_db()->query('SELECT email_thread_id, occurred_at, to_json, cc_json, body_text, body_html FROM `' . $messages . '`
                               WHERE id IN (' . implode(',', array_map('intval', $newest)) . ')') as $m) {
        $sent[(int)$m['email_thread_id']] = $m;
    }
    foreach ($list as &$row) {
        $m = $sent[$row['id']] ?? null;
        if ($m === null) continue;
        $people = array_merge(json_decode((string)$m['to_json'], true) ?: [], json_decode((string)$m['cc_json'], true) ?: []);
        $names = [];
        foreach ($people as $p) {
            $n = trim((string)($p['name'] ?? '')) ?: trim((string)($p['email'] ?? ''));
            if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
        }
        $row['from_name'] = 'To: ' . ($names === [] ? '(no recipients)' : implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) : ''));
        $row['from_email'] = (string)($people[0]['email'] ?? '');
        $row['latest_at'] = $m['occurred_at'];
        $row['preview'] = ecw_preview_text((string)$m['body_text'], (string)$m['body_html']);
        $row['direction'] = 'outgoing';
        $row['shown_message_id'] = null;   // our own email: the conversation's newest received one decides
        $row['unread'] = false;
    }
    unset($row);
    return $list;
}

/**
 * Folder rows (Inbox, Archive…): the newest email of each conversation in the folder says
 * who wrote, when and what. Not your reply in Sent Items: that is not in this folder.
 */
function ecw_folder_list_rows(array $list, array $folders): array
{
    $messages = ecw_table('email_messages');
    $ids = implode(',', array_map('intval', array_column($list, 'id')));
    $in = implode(',', array_map('intval', $folders));
    $newest = ecw_db()->query('SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY email_thread_id ORDER BY occurred_at DESC, id DESC) AS n
                                 FROM `' . $messages . '` WHERE email_thread_id IN (' . $ids . ') AND mail_folder_id IN (' . $in . ')
                                  AND provider_removed_at IS NULL) x WHERE n = 1')->fetchAll(PDO::FETCH_COLUMN);
    if ($newest === []) return $list;
    $byThread = [];
    foreach (ecw_db()->query('SELECT id, email_thread_id, occurred_at, from_email, from_name, direction, body_text, body_html FROM `' . $messages . '`
                               WHERE id IN (' . implode(',', array_map('intval', $newest)) . ')') as $m) {
        $byThread[(int)$m['email_thread_id']] = $m;
    }
    foreach ($list as &$row) {
        $m = $byThread[$row['id']] ?? null;
        if ($m === null) continue;
        $row['from_name'] = $m['from_name'] ?: $m['from_email'];
        $row['from_email'] = $m['from_email'];
        $row['latest_at'] = $m['occurred_at'];
        $row['preview'] = ecw_preview_text((string)$m['body_text'], (string)$m['body_html']);
        $row['direction'] = $m['direction'];
        $row['shown_message_id'] = $m['direction'] === 'incoming' ? (int)$m['id'] : null;
    }
    unset($row);
    return $list;
}

function ecw_thread_list_row(array $r): array
{
    return [
        'id'              => (int)$r['id'],
        'subject'         => ($r['normalized_subject'] ?? '') !== '' ? $r['normalized_subject'] : '(no subject)',
        'preview'         => $r['latest_preview'],
        'from_name'       => $r['latest_from_name'] ?: $r['latest_from_email'],
        'from_email'      => $r['latest_from_email'],
        'direction'       => $r['latest_direction'],
        'latest_at'       => $r['latest_message_at'],
        // The email whose sender and text the row shows, when it is one received (colour rules).
        'shown_message_id' => ($r['latest_direction'] ?? '') === 'incoming' && !empty($r['latest_message_id']) ? (int)$r['latest_message_id'] : null,
        'message_count'   => (int)$r['message_count'],
        'unread'          => (int)$r['unread_count'] > 0,
        'unread_count'    => (int)$r['unread_count'],
        'has_attachments' => (bool)(int)$r['has_attachments'],
        'flagged'         => (int)($r['flagged_count'] ?? 0) > 0,
        'snoozed_until'   => $r['snoozed_until'] ?? null,
        'claimed'         => empty($r['claimed_by_user_id']) ? null : ['id' => (int)$r['claimed_by_user_id'], 'name' => (string)($r['claimed_by_name'] ?? '')],
        'case'            => $r['case_id'] === null ? null : [
            'id'     => (int)$r['case_id'],
            'number' => $r['case_number'],
            'name'   => $r['case_name'],
            'status' => $r['case_status'],
        ],
    ];
}

/**
 * One thread, with its messages.
 *
 * @param string $order 'asc' (oldest first, the recommended default) or 'desc'
 */
/**
 * Pictures in an email (cid:) that are not stored yet, so the provider must be
 * asked for them. Inline pictures ARE attachments (is_inline = 1): an email
 * whose files are stored needs nothing, however many pictures it shows.
 */
function ecw_inline_images_missing(int $messageId, string $html): bool
{
    if (stripos($html, 'cid:') === false) return false;
    $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_attachments') . '` WHERE email_message_id = ? LIMIT 1');
    $st->execute([$messageId]);
    return $st->fetchColumn() === false;
}

/**
 * $opts: 'quoted' => false leaves each email's quoted history out (the "..."
 * fetches it when clicked): in a long conversation every reply repeats all the
 * earlier ones, megabytes the reader rarely opens. 'message_id' => one email only.
 */
function ecw_thread_detail(int $mailboxId, int $threadId, string $order = 'asc', bool $allowRemoteImages = false, array $opts = []): ?array
{
    $withQuoted = $opts['quoted'] ?? true;
    $onlyMessage = isset($opts['message_id']) ? (int)$opts['message_id'] : null;
    $pdo = ecw_db();

    $st = $pdo->prepare('SELECT * FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
    $st->execute([$threadId, $mailboxId]);
    $thread = $st->fetch();
    if ($thread === false) {
        return null;
    }

    $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
    $st = $pdo->prepare(
        'SELECT m.id, m.provider_message_id, m.internet_message_id, m.mail_folder_id,
                m.subject, m.from_email, m.from_name, m.to_json, m.cc_json, m.bcc_json,
                m.direction, m.is_read, m.is_flagged, m.meeting_kind, m.has_attachments, m.occurred_at, m.sent_at, m.received_at,
                m.body_html, m.body_text, m.quoted_html, m.quoted_text, m.body_fetch_state, m.attachments_state,
                m.web_link, m.provider_removed_at, f.name AS folder_name, f.folder_type
           FROM `' . ecw_table('email_messages') . '` m
           LEFT JOIN `' . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id
          WHERE m.email_thread_id = ?" . ($onlyMessage !== null ? ' AND m.id = ?' : '') . "
          ORDER BY m.occurred_at {$direction}, m.id {$direction}"
    );
    $st->execute($onlyMessage !== null ? [$threadId, $onlyMessage] : [$threadId]);
    $messages = $st->fetchAll();

    $out = [];
    foreach ($messages as $m) {
        $messageId = (int)$m['id'];
        $resolver  = static fn(string $cid): ?string => ecw_inline_attachment_url($messageId, $cid);

        $parts = ecw_message_display_parts($m);
        $out[] = [
            'id'              => $messageId,
            'subject'         => $m['subject'],
            'from'            => ['email' => $m['from_email'], 'name' => $m['from_name']],
            'to'              => json_decode((string)($m['to_json'] ?? '[]'), true) ?: [],
            'cc'              => json_decode((string)($m['cc_json'] ?? '[]'), true) ?: [],
            'bcc'             => json_decode((string)($m['bcc_json'] ?? '[]'), true) ?: [],
            'direction'       => $m['direction'],
            'is_read'         => (bool)(int)$m['is_read'],
            'is_flagged'      => (bool)(int)($m['is_flagged'] ?? 0),
            'meeting_kind'    => $m['meeting_kind'] ?? null,
            'occurred_at'     => $m['occurred_at'],
            'folder'          => $m['folder_name'],
            'folder_type'     => $m['folder_type'],
            'web_link'        => $m['web_link'],
            'removed'         => $m['provider_removed_at'] !== null,
            'body_state'      => $m['body_fetch_state'],
            'needs_inline_images' => ecw_inline_images_missing($messageId, (string)$m['body_html'] . (string)$m['quoted_html']),
            'attachments_pending' => ($m['attachments_state'] ?? null) === 'pending',   // text here, files still coming (lib/sync/messages.php)
            'body_html'       => ecw_sanitize_email_html($parts['body_html'], $allowRemoteImages, $resolver),
            'signature_html' => ecw_sanitize_email_html($parts['signature_html'], $allowRemoteImages, $resolver),
            // Quoted history stays collapsed until the reader asks ("..."). Left
            // out (null) unless asked for: the "..." then fetches it (api body).
            'quoted_html'     => $withQuoted ? ecw_sanitize_email_html($parts['quoted_html'], $allowRemoteImages, $resolver) : null,
            'has_quoted'      => $parts['has_quoted'],
            'has_attachments' => (bool)(int)$m['has_attachments'],
            'attachments'     => ecw_message_attachments($messageId),
        ];
    }

    $st = $pdo->prepare(
        'SELECT c.id, c.case_number, c.name, c.status, ct.display_name
           FROM `' . ecw_table('case_threads') . '` ct
           JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id AND c.deleted_at IS NULL
          WHERE ct.email_thread_id = ?'
    );
    $st->execute([$threadId]);
    $case = $st->fetch();

    return [
        'id'       => (int)$thread['id'],
        'subject'  => $thread['normalized_subject'] !== '' ? $thread['normalized_subject'] : '(no subject)',
        'messages' => $out,
        'case'     => $case === false ? null : [
            'id'     => (int)$case['id'],
            'number' => $case['case_number'],
            'name'   => $case['name'],
            'status' => $case['status'],
        ],
    ];
}

function ecw_message_attachments(int $messageId): array
{
    $st = ecw_db()->prepare(
        'SELECT id, filename, mime_type, size_bytes, is_inline, content_id, provider_download_state
           FROM `' . ecw_table('email_attachments') . '`
          WHERE email_message_id = ? AND is_inline = 0
          ORDER BY id'
    );
    $st->execute([$messageId]);
    return array_map(static fn(array $a): array => [
        'id'        => (int)$a['id'],
        'filename'  => $a['filename'],
        'mime_type' => $a['mime_type'],
        'size'      => (int)$a['size_bytes'],
        'state'     => $a['provider_download_state'],
    ], $st->fetchAll());
}

/**
 * Task 04's in-app preview: mime types the browser can render directly
 * (native img/iframe), decided from the file's real bytes, never from the
 * filename or the mime type the email claimed — a renamed .html can't get
 * itself rendered inline by wearing a .pdf extension. See the attachment
 * streaming function's ?inline=1 branch in web/api/index.php, the only
 * caller.
 */
function ecw_inline_preview_allowed(string $detectedMime): bool
{
    return in_array($detectedMime, [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ], true);
}

/** URL for an inline image, if we have that cid on this message. */
function ecw_inline_attachment_url(int $messageId, string $contentId): ?string
{
    $st = ecw_db()->prepare(
        'SELECT id FROM `' . ecw_table('email_attachments') . '`
          WHERE email_message_id = ? AND (content_id = ? OR content_id = ?) LIMIT 1'
    );
    $st->execute([$messageId, $contentId, '<' . trim($contentId, '<>') . '>']);
    $id = $st->fetchColumn();
    return $id === false ? null : 'api/index.php?action=attachment&id=' . (int)$id;
}

/**
 * Mark a thread read/unread, then refresh the cached counters.
 *
 * Read marks every message; unread marks only the latest incoming one, the way
 * Outlook does. Changed rows are flagged and pushed to the provider by the
 * daemon's read_state job, so the mailbox in Outlook / Gmail agrees.
 */
function ecw_thread_mark_read(int $mailboxId, int $threadId, bool $isRead): int
{
    $table = ecw_table('email_messages');
    if ($isRead) {
        $st = ecw_db()->prepare(
            "UPDATE `{$table}` SET is_read = 1, read_push_pending = 1
              WHERE email_thread_id = ? AND mailbox_id = ? AND is_read = 0"
        );
        $st->execute([$threadId, $mailboxId]);
    } else {
        $st = ecw_db()->prepare(
            "UPDATE `{$table}` SET is_read = 0, read_push_pending = 1
              WHERE email_thread_id = ? AND mailbox_id = ? AND direction = 'incoming' AND provider_removed_at IS NULL
              ORDER BY occurred_at DESC, id DESC LIMIT 1"
        );
        $st->execute([$threadId, $mailboxId]);
    }
    $changed = $st->rowCount();

    if ($changed > 0) {
        ecw_thread_refresh_counters($threadId);
        require_once __DIR__ . '/sync/queue.php';
        try { ecw_queue_enqueue($mailboxId, null, 'read_state', 'user'); } catch (Throwable $e) { ecw_log('read_state enqueue failed: ' . $e->getMessage()); }
    }
    return $changed;
}

/**
 * The read_state job: send pending read/unread changes to the provider.
 * A message that fails stays pending and is retried by the next job.
 */
function ecw_push_read_state(int $mailboxId, int $limit = 200): array
{
    $table = ecw_table('email_messages');
    $st = ecw_db()->prepare(
        "SELECT id, provider_message_id, is_read FROM `{$table}`
          WHERE mailbox_id = ? AND read_push_pending = 1 AND provider_removed_at IS NULL
          ORDER BY id LIMIT " . max(1, $limit)
    );
    $st->execute([$mailboxId]);
    $rows = $st->fetchAll();
    if ($rows === []) {
        return ecw_push_flags($mailboxId, null, $limit);
    }

    require_once __DIR__ . '/mailbox.php';
    $provider = ecw_provider_for_mailbox($mailboxId);
    $done = ecw_db()->prepare("UPDATE `{$table}` SET read_push_pending = 0 WHERE id = ? AND is_read = ?");
    $pushed = $failed = 0;
    foreach ($rows as $row) {
        try {
            $provider->markRead((string)$row['provider_message_id'], (int)$row['is_read'] === 1);
            // Only clear when the value sent is still the current one.
            $done->execute([(int)$row['id'], (int)$row['is_read']]);
            $pushed++;
        } catch (Throwable $e) {
            $failed++;
            ecw_log('read_state push failed for message ' . $row['id'] . ': ' . $e->getMessage());
            if (str_contains($e->getMessage(), '404')) {
                $done->execute([(int)$row['id'], (int)$row['is_read']]);   // gone at the provider: nothing to sync
            }
        }
    }
    // Clear messages the provider already removed.
    ecw_db()->prepare("UPDATE `{$table}` SET read_push_pending = 0 WHERE mailbox_id = ? AND read_push_pending = 1 AND provider_removed_at IS NOT NULL")
        ->execute([$mailboxId]);
    $flags = ecw_push_flags($mailboxId, $provider, $limit);
    return ['pushed' => $pushed + $flags['pushed'], 'failed' => $failed + $flags['failed']];
}

/** The flag half of the read_state job (Outlook flag / Gmail star). */
function ecw_push_flags(int $mailboxId, ?MailProvider $provider = null, int $limit = 200): array
{
    $table = ecw_table('email_messages');
    $st = ecw_db()->prepare(
        "SELECT id, provider_message_id, is_flagged FROM `{$table}`
          WHERE mailbox_id = ? AND flag_push_pending = 1 AND provider_removed_at IS NULL ORDER BY id LIMIT " . max(1, $limit)
    );
    $st->execute([$mailboxId]);
    $rows = $st->fetchAll();
    if ($rows === []) return ['pushed' => 0, 'failed' => 0];
    require_once __DIR__ . '/mailbox.php';
    $provider ??= ecw_provider_for_mailbox($mailboxId);
    $done = ecw_db()->prepare("UPDATE `{$table}` SET flag_push_pending = 0 WHERE id = ? AND is_flagged = ?");
    $pushed = $failed = 0;
    foreach ($rows as $row) {
        try { $provider->setFlag((string)$row['provider_message_id'], (int)$row['is_flagged'] === 1); $done->execute([(int)$row['id'], (int)$row['is_flagged']]); $pushed++; }
        catch (Throwable $e) {
            $failed++;
            ecw_log('flag push failed for message ' . $row['id'] . ': ' . $e->getMessage());
            if (str_contains($e->getMessage(), '404')) $done->execute([(int)$row['id'], (int)$row['is_flagged']]);
        }
    }
    return ['pushed' => $pushed, 'failed' => $failed];
}

function ecw_encode_cursor(string $timestamp, int $id): string
{
    return base64_encode($timestamp . '|' . $id);
}

/** @return array{ts:string,id:int}|null */
function ecw_decode_cursor(string $cursor): ?array
{
    if ($cursor === '') {
        return null;
    }
    $raw = base64_decode($cursor, true);
    if ($raw === false || !str_contains($raw, '|')) {
        return null;
    }
    [$ts, $id] = explode('|', $raw, 2);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts) || !ctype_digit($id)) {
        return null;
    }
    return ['ts' => $ts, 'id' => (int)$id];
}
