<?php declare(strict_types=1);
/**
 * Email Case Workspace — internal case notes with @mentions.
 *
 * Notes are internal: they never leave the application and are never sent to
 * a customer. They are visible to whoever can see the case.
 *
 * Bodies are stored as plain text. A mention is the token @[Name](user:ID),
 * inserted by the note editor's autocomplete; on render everything is escaped
 * first and only then are mention tokens and links turned into markup, so a
 * note can never inject HTML.
 *
 * Mentioning someone on a members-only case adds them to the case: the point of
 * a mention is to bring that person in, and a notification linking to a case
 * they cannot open would be useless.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/notifications.php';

const ECW_NOTE_MAX_CHARS = 10000;

/** @return int[] mentioned user ids, in order, de-duplicated */
function ecw_note_mentions(string $body): array
{
    preg_match_all('/@\[[^\]\n]{1,120}\]\(user:(\d{1,18})\)/u', $body, $m);
    return array_values(array_unique(array_map('intval', $m[1] ?? [])));
}

/** Escaped HTML for display. */
function ecw_note_render(string $body): string
{
    $html = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    // Mentions: the escaped form of @[Name](user:ID).
    $html = (string)preg_replace('/@\[([^\]\n]{1,120})\]\(user:(\d{1,18})\)/u', '<span class="mention" data-user="$2">@$1</span>', $html);
    // Bare http(s) links. The text is already escaped, so &amp; etc. are safe inside href.
    $html = (string)preg_replace('~\bhttps?://[^\s<]+~i', '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>', $html);
    return nl2br($html);
}

/** Plain text for notifications and webhooks: mentions become @Name. */
function ecw_note_plain(string $body): string
{
    return (string)preg_replace('/@\[([^\]\n]{1,120})\]\(user:\d{1,18}\)/u', '@$1', $body);
}


// ------------------------------------------------------------------ reading

/**
 * Shared SELECT: note rows with author and, for a note about one email, that email.
 * Replies whose first note was deleted are left out with it.
 */
function ecw_notes_select(string $where, array $params, int $viewerUserId): array
{
    $st = ecw_db()->prepare(
        'SELECT n.id, n.case_id, n.thread_id, n.message_id, n.parent_note_id, n.resolved_at, n.body, n.created_at, n.updated_at,
                u.id AS user_id, u.name, u.email, u.avatar_hash,
                ru.name AS resolved_by_name, ru.email AS resolved_by_email,
                rd.seen_at, rd.last_note_id AS seen_note_id,
                m.from_name AS a_from_name, m.from_email AS a_from_email, m.occurred_at AS a_at, m.subject AS a_subject
           FROM `' . ecw_table('case_notes') . '` n
           LEFT JOIN `' . ecw_table('case_notes') . '` p ON p.id = n.parent_note_id
           LEFT JOIN `' . ecw_table('users') . '` u ON u.id = n.user_id
           LEFT JOIN `' . ecw_table('users') . '` ru ON ru.id = n.resolved_by_user_id
           LEFT JOIN `' . ecw_table('note_reads') . '` rd ON rd.root_note_id = COALESCE(n.parent_note_id, n.id) AND rd.user_id = ' . (int)$viewerUserId . '
           LEFT JOIN `' . ecw_table('email_messages') . '` m ON m.id = n.message_id
          WHERE n.deleted_at IS NULL AND (n.parent_note_id IS NULL OR p.deleted_at IS NULL) AND (' . $where . ')
          ORDER BY n.created_at ASC, n.id ASC'
    );
    $st->execute($params);
    return array_map(static fn(array $r): array => ecw_note_row($r, $viewerUserId), $st->fetchAll());
}

function ecw_note_row(array $r, int $viewerUserId): array
{
    return [
        'id'         => (int)$r['id'],
        'case_id'    => $r['case_id'] === null ? null : (int)$r['case_id'],
        'thread_id'  => $r['thread_id'] === null ? null : (int)$r['thread_id'],
        'message_id' => $r['message_id'] === null ? null : (int)$r['message_id'],
        'parent_id'  => ($r['parent_note_id'] ?? null) === null ? null : (int)$r['parent_note_id'],
        'resolved_at' => $r['resolved_at'] ?? null,
        'resolved_by' => ($r['resolved_at'] ?? null) === null ? null : (string)(($r['resolved_by_name'] ?? '') ?: ($r['resolved_by_email'] ?? '')),
        'seen_at'    => $r['seen_at'] ?? null,
        'seen_note_id' => (int)($r['seen_note_id'] ?? 0),
        'html'       => ecw_note_render((string)$r['body']),
        'created_at' => $r['created_at'],
        'edited'     => $r['updated_at'] !== null,
        'mine'       => (int)$r['user_id'] === $viewerUserId,
        'author'     => $r['user_id'] === null ? ['id' => null, 'name' => 'Former member', 'email' => '', 'avatar_url' => null]
            : ['id' => (int)$r['user_id'], 'name' => $r['name'], 'email' => $r['email'], 'avatar_url' => ecw_avatar_url((int)$r['user_id'], $r['avatar_hash'])],
        // The email this note is about, for the small quote above it.
        'about'      => $r['message_id'] === null || $r['a_at'] === null ? null : [
            'message_id' => (int)$r['message_id'],
            'from'       => (string)($r['a_from_name'] ?: $r['a_from_email']),
            'at'         => $r['a_at'],
            'subject'    => (string)$r['a_subject'],
        ],
    ];
}

/** Every note of a case: case-wide notes plus notes on any of its conversations. */
function ecw_case_notes(int $caseId, int $viewerUserId): array
{
    return ecw_notes_select(
        'n.case_id = ? OR n.thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)',
        [$caseId, $caseId], $viewerUserId
    );
}

/** Notes on one conversation. */
function ecw_thread_notes(int $threadId, int $viewerUserId): array
{
    return ecw_notes_select('n.thread_id = ?', [$threadId], $viewerUserId);
}

/**
 * Notes as conversations (Word-style comment threads): each first note with its
 * replies. Oldest conversation first; the panel sorts as it needs.
 *
 * @param array $notes rows from ecw_notes_select()
 * @return array<int,array{id:int, message_id:?int, thread_id:?int, case_id:?int, about:?array,
 *               resolved:bool, resolved_at:?string, resolved_by:?string, unread:bool,
 *               last_at:string, notes:array, participants:array}>
 */
function ecw_note_groups(array $notes): array
{
    $groups = [];
    foreach ($notes as $n) {
        if ($n['parent_id'] === null) {
            $groups[$n['id']] = [
                'id' => $n['id'], 'message_id' => $n['message_id'], 'thread_id' => $n['thread_id'], 'case_id' => $n['case_id'],
                'about' => $n['about'], 'resolved' => $n['resolved_at'] !== null, 'resolved_at' => $n['resolved_at'],
                'resolved_by' => $n['resolved_by'], 'unread' => false, 'last_at' => $n['created_at'], 'notes' => [], 'participants' => [],
            ];
        }
    }
    foreach ($notes as $n) {
        $root = $n['parent_id'] ?? $n['id'];
        if (!isset($groups[$root])) continue;
        $g = &$groups[$root];
        $g['notes'][] = $n;
        if ($n['created_at'] > $g['last_at']) $g['last_at'] = $n['created_at'];
        // Unread: someone else wrote something after this person last opened the conversation.
        if (!$n['mine'] && $n['id'] > $n['seen_note_id']) $g['unread'] = true;
        $key = (string)($n['author']['id'] ?? 'x');
        if (!isset($g['participants'][$key])) $g['participants'][$key] = $n['author'];
        unset($g);
    }
    return array_values(array_map(static function (array $g): array {
        $g['participants'] = array_values($g['participants']);
        return $g;
    }, $groups));
}

/** Is this note row inside the scope the caller checked (a case, or one conversation)? */
function ecw_note_in_scope(array $row, ?int $caseId, ?int $threadId): bool
{
    if ($caseId !== null) {
        if ((int)($row['case_id'] ?? 0) === $caseId) return true;
        if ($row['thread_id'] === null) return false;
        $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('case_threads') . '` WHERE case_id = ? AND email_thread_id = ?');
        $st->execute([$caseId, (int)$row['thread_id']]);
        return (bool)$st->fetchColumn();
    }
    return $threadId !== null && (int)($row['thread_id'] ?? 0) === $threadId;
}

/** The first note of the conversation $noteId belongs to (not deleted), or null. */
function ecw_note_root(int $noteId): ?array
{
    $t = ecw_table('case_notes');
    $st = ecw_db()->prepare("SELECT r.* FROM `{$t}` n JOIN `{$t}` r ON r.id = COALESCE(n.parent_note_id, n.id)
                              WHERE n.id = ? AND n.deleted_at IS NULL AND r.deleted_at IS NULL");
    $st->execute([$noteId]);
    return $st->fetch() ?: null;
}

/** Resolve (true) or reopen (false) a conversation. Anyone who can see it may. */
function ecw_note_resolve(int $noteId, int $userId, bool $resolved, ?int $caseId, ?int $threadId): bool
{
    $root = ecw_note_root($noteId);
    if ($root === null || !ecw_note_in_scope($root, $caseId, $threadId)) return false;
    ecw_db()->prepare('UPDATE `' . ecw_table('case_notes') . '` SET resolved_at = ?, resolved_by_user_id = ? WHERE id = ?')
        ->execute([$resolved ? ecw_now() : null, $resolved ? $userId : null, (int)$root['id']]);
    return true;
}

/** This person has now seen the conversation (clears its unread dot). */
function ecw_note_seen(int $noteId, int $userId, ?int $caseId, ?int $threadId): bool
{
    $root = ecw_note_root($noteId);
    if ($root === null || !ecw_note_in_scope($root, $caseId, $threadId)) return false;
    $st = ecw_db()->prepare('SELECT MAX(id) FROM `' . ecw_table('case_notes') . '` WHERE id = ? OR parent_note_id = ?');
    $st->execute([(int)$root['id'], (int)$root['id']]);
    ecw_note_mark_seen($userId, (int)$root['id'], (int)$st->fetchColumn());
    return true;
}

/** Record that $userId has seen conversation $rootId up to note $lastNoteId (never goes back). */
function ecw_note_mark_seen(int $userId, int $rootId, int $lastNoteId): void
{
    ecw_db()->prepare('INSERT INTO `' . ecw_table('note_reads') . '` (user_id, root_note_id, seen_at, last_note_id) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at), last_note_id = GREATEST(last_note_id, VALUES(last_note_id))')
        ->execute([$userId, $rootId, ecw_now(), $lastNoteId]);
}

// ------------------------------------------------------------------ writing

/**
 * Add a note on a case as a whole ($caseId) or on a conversation ($threadId,
 * optionally about one email $messageId). The caller has checked the author
 * can see that case / conversation.
 *
 * Mentions bring people in: on a case they are added to it (members-only
 * cases); on a conversation that is in no case, a colleague who cannot open
 * the mailbox is refused BEFORE anything is saved, so nobody is sent a link
 * they cannot open.
 *
 * @return array{note_id:int, mentioned:int[], added_to_case:int[], case_id:?int}
 */
function ecw_note_add(int $orgId, int $userId, string $body, ?int $caseId, ?int $threadId, ?int $messageId = null, ?int $parentId = null): array
{
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '' || mb_strlen($body) > ECW_NOTE_MAX_CHARS) {
        throw new InvalidArgumentException('Write a note (up to ' . ECW_NOTE_MAX_CHARS . ' characters).');
    }
    $pdo = ecw_db();

    // A reply joins its conversation: same case / conversation / email as the first note.
    $root = null;
    if ($parentId !== null) {
        $root = ecw_note_root($parentId);
        if ($root === null || !ecw_note_in_scope($root, $caseId, $threadId)) {
            throw new InvalidArgumentException('That note conversation is not here any more.');
        }
        $parentId  = (int)$root['id'];
        $caseId    = $root['case_id'] !== null ? (int)$root['case_id'] : null;
        $threadId  = $root['thread_id'] !== null ? (int)$root['thread_id'] : null;
        $messageId = $root['message_id'] !== null ? (int)$root['message_id'] : null;
    }

    if ($messageId !== null) {
        $st = $pdo->prepare('SELECT email_thread_id FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
        $st->execute([$messageId]);
        $msgThread = $st->fetchColumn();
        if ($msgThread === false || ($threadId !== null && (int)$msgThread !== $threadId)) {
            throw new InvalidArgumentException('That email is not part of this conversation.');
        }
        $threadId = (int)$msgThread;
    }
    if ($threadId !== null) {
        $caseId = null;   // a conversation note follows its thread, into whatever case it is in
    }
    if ($caseId === null && $threadId === null) {
        throw new InvalidArgumentException('A note needs a case or a conversation.');
    }

    // Which case (if any) gives access to this note, and whose mailbox is it?
    $accessCase = $caseId;
    $mailboxOwner = null;
    if ($threadId !== null) {
        $c = ecw_case_for_thread($threadId);
        $accessCase = ($c !== null && $c['deleted_at'] === null) ? (int)$c['id'] : null;
        $st = $pdo->prepare('SELECT mb.connected_by_user_id FROM `' . ecw_table('email_threads') . '` t
                               JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id WHERE t.id = ?');
        $st->execute([$threadId]);
        $mailboxOwner = ($o = $st->fetchColumn()) === false || $o === null ? null : (int)$o;
    }

    $mentioned = array_values(array_filter(ecw_org_member_ids($orgId, ecw_note_mentions($body)), static fn(int $id): bool => $id !== $userId));
    if ($accessCase === null && ecw_private_mailboxes()) {
        foreach ($mentioned as $mid) {
            if ($mid !== $mailboxOwner) {
                $n = $pdo->prepare('SELECT name, email FROM `' . ecw_table('users') . '` WHERE id = ?');
                $n->execute([$mid]);
                $u = $n->fetch() ?: ['name' => 'That colleague', 'email' => ''];
                throw new InvalidArgumentException(($u['name'] ?: $u['email']) . ' cannot see this email yet. Add the conversation to a case first, then mention them.');
            }
        }
    }

    $pdo->prepare('INSERT INTO `' . ecw_table('case_notes') . '` (case_id, thread_id, message_id, parent_note_id, user_id, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$caseId, $threadId, $messageId, $parentId, $userId, $body, ecw_now()]);
    $noteId = (int)$pdo->lastInsertId();
    $rootId = $parentId ?? $noteId;
    // Replying to a resolved conversation opens it again (as in Word).
    if ($root !== null && $root['resolved_at'] !== null) {
        $pdo->prepare('UPDATE `' . ecw_table('case_notes') . '` SET resolved_at = NULL, resolved_by_user_id = NULL WHERE id = ?')->execute([$rootId]);
    }
    // The author has seen their own conversation up to here (later replies by others stay unread).
    ecw_note_mark_seen($userId, $rootId, $noteId);
    if ($accessCase !== null) {
        ecw_case_log_event($accessCase, 'NOTE_ADDED', $userId, 'note', $noteId);
    }

    $author = $pdo->prepare('SELECT name, email FROM `' . ecw_table('users') . '` WHERE id = ?');
    $author->execute([$userId]);
    $a = $author->fetch() ?: ['name' => '', 'email' => ''];
    $who = (string)($a['name'] ?: $a['email']);
    if ($accessCase !== null) {
        $cs = $pdo->prepare('SELECT case_number, name FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $cs->execute([$accessCase]);
        $cr = $cs->fetch() ?: ['case_number' => '', 'name' => ''];
        $where = $cr['case_number'] . ' ' . $cr['name'];
        $link = 'case.php?id=' . $accessCase . '&note=' . $rootId . '#notes';
    } else {
        $ts = $pdo->prepare('SELECT normalized_subject FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
        $ts->execute([$threadId]);
        $where = '“' . ((string)$ts->fetchColumn() ?: '(no subject)') . '”';
        $link = 'app.php?thread=' . $threadId . '&note=' . $rootId;
    }

    $added = [];
    foreach ($mentioned as $mid) {
        if ($accessCase !== null && !ecw_case_visible($orgId, $mid, $accessCase)) {
            $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('case_members') . '` (case_id, user_id, added_by_user_id, added_at) VALUES (?, ?, ?, ?)')
                ->execute([$accessCase, $mid, $userId, ecw_now()]);
            $added[] = $mid;
        }
        ecw_notify($orgId, $mid, $userId, 'mention',
            $who . ' mentioned you on ' . $where . ': ' . mb_strimwidth(ecw_note_plain($body), 0, 160, '…'),
            $link, $accessCase, $threadId);
    }
    // A reply also tells the others already in that conversation (not the ones just mentioned).
    if ($root !== null) {
        $st = $pdo->prepare('SELECT DISTINCT user_id FROM `' . ecw_table('case_notes') . '` WHERE (id = ? OR parent_note_id = ?) AND deleted_at IS NULL AND user_id IS NOT NULL');
        $st->execute([$rootId, $rootId]);
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $pid) {
            if ($pid === $userId || in_array($pid, $mentioned, true)) continue;
            ecw_notify($orgId, $pid, $userId, 'note_reply',
                $who . ' replied to a note on ' . $where . ': ' . mb_strimwidth(ecw_note_plain($body), 0, 160, '…'),
                $link, $accessCase, $threadId);
        }
    }
    return ['note_id' => $noteId, 'root_id' => $rootId, 'mentioned' => $mentioned, 'added_to_case' => $added, 'case_id' => $accessCase];
}

/**
 * Case page: a case-wide note, or (with $messageId) a note about one email of
 * the case — which must belong to one of the case's conversations.
 */
function ecw_case_note_add(int $orgId, int $caseId, int $userId, string $body, ?int $messageId = null, ?int $parentId = null): array
{
    if ($parentId !== null) {
        return ecw_note_add($orgId, $userId, $body, $caseId, null, null, $parentId);
    }
    if ($messageId !== null) {
        $st = ecw_db()->prepare('SELECT m.email_thread_id FROM `' . ecw_table('email_messages') . '` m
                                   JOIN `' . ecw_table('case_threads') . '` ct ON ct.email_thread_id = m.email_thread_id
                                  WHERE m.id = ? AND ct.case_id = ?');
        $st->execute([$messageId, $caseId]);
        $threadId = $st->fetchColumn();
        if ($threadId === false) {
            throw new InvalidArgumentException('That email is not part of this case.');
        }
        return ecw_note_add($orgId, $userId, $body, null, (int)$threadId, $messageId);
    }
    return ecw_note_add($orgId, $userId, $body, $caseId, null);
}

/**
 * Soft delete — the author, or an admin — of a note inside the given scope:
 * a case (its own notes and its conversations' notes) or one conversation.
 */
function ecw_note_delete(int $noteId, int $userId, bool $isAdmin, ?int $caseId, ?int $threadId): bool
{
    $sql = 'SELECT user_id FROM `' . ecw_table('case_notes') . '` WHERE id = ? AND deleted_at IS NULL AND ';
    if ($caseId !== null) {
        $sql .= '(case_id = ? OR thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?))';
        $params = [$noteId, $caseId, $caseId];
    } else {
        $sql .= 'thread_id = ?';
        $params = [$noteId, (int)$threadId];
    }
    $st = ecw_db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    if ($row === false || (!$isAdmin && (int)$row['user_id'] !== $userId)) {
        return false;
    }
    // Deleting the first note deletes the whole conversation (its replies go with it).
    ecw_db()->prepare('UPDATE `' . ecw_table('case_notes') . '` SET deleted_at = ? WHERE (id = ? OR parent_note_id = ?) AND deleted_at IS NULL')
        ->execute([ecw_now(), $noteId, $noteId]);
    return true;
}

/** Kept for existing callers: delete within a case. */
function ecw_case_note_delete(int $caseId, int $noteId, int $userId, bool $isAdmin): bool
{
    return ecw_note_delete($noteId, $userId, $isAdmin, $caseId, null);
}
