<?php declare(strict_types=1);
/**
 * Shared drafts — writing a reply together, one at a time.
 *
 * The author shares a draft that answers an email (or belongs to a case).
 * Everyone who can see that conversation / case may then open it from the
 * reading pane and edit it. Only one person edits at a time: opening it takes
 * a short lock that the composer renews every few seconds; the others see it
 * read-only, refreshed live, with "Take over". A save from someone without the
 * lock, or based on an older version, is refused (no silent overwrite).
 *
 * Sending stays with whoever may send from the mailbox (with private
 * mailboxes, its owner). Discarding stays with the author.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/config.php';

const ECW_DRAFT_LOCK_SECONDS = 40;

/** The conversation a draft answers (via the email it replies to), or null. */
function ecw_draft_thread_id(array $draft): ?int
{
    $msg = (int)($draft['reply_to_local_message_id'] ?? 0);
    if ($msg <= 0) return null;
    $st = ecw_db()->prepare('SELECT email_thread_id FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
    $st->execute([$msg]);
    $t = $st->fetchColumn();
    return $t === false ? null : (int)$t;
}

/** 'owner', 'editor' (shared with them) or null (no access). */
function ecw_draft_role(int $orgId, int $userId, array $draft): ?string
{
    if ((int)$draft['organization_id'] !== $orgId) return null;
    if ((int)($draft['user_id'] ?? 0) === $userId) return 'owner';
    if (empty($draft['shared_at'])) return null;
    require_once __DIR__ . '/license.php';
    if (!ecw_package_has('shared_drafts')) return null;   // sharing ended with the package
    $msg = (int)($draft['reply_to_local_message_id'] ?? 0);
    if ($msg > 0 && ecw_access_message($orgId, $userId, $msg)) return 'editor';
    $case = (int)($draft['case_id'] ?? 0);
    if ($case > 0 && ecw_case_visible($orgId, $userId, $case)) return 'editor';
    return null;
}

/** May $userId send this draft from its mailbox? */
function ecw_draft_can_send(int $orgId, int $userId, array $draft): bool
{
    if (ecw_draft_role($orgId, $userId, $draft) === null) return false;
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mailboxes') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([(int)$draft['mailbox_id'], $orgId]);
    $mailbox = $st->fetch();
    if ($mailbox === false) return false;
    if (!ecw_private_mailboxes() || (int)$mailbox['connected_by_user_id'] === $userId) return true;
    // A shared mailbox (info@): everyone who works from it sends from it.
    require_once __DIR__ . '/shared_mailboxes.php';
    return ecw_mailbox_shared_with($mailbox, $userId);
}

/** Share (true) or stop sharing (false). Author only; only replies and case emails can be shared. */
function ecw_draft_share(int $orgId, int $userId, array $draft, bool $on): void
{
    if (ecw_draft_role($orgId, $userId, $draft) !== 'owner') throw new InvalidArgumentException('Only the author can share this draft.');
    if ($on && empty($draft['reply_to_local_message_id']) && empty($draft['case_id'])) {
        throw new InvalidArgumentException('Only a reply, or an email in a case, can be shared — there is nobody to share a new email with yet.');
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET shared_at = ?, shared_by_user_id = ?, editing_user_id = IF(? = 1, editing_user_id, NULL) WHERE id = ?')
        ->execute([$on ? ecw_now() : null, $on ? $userId : null, $on ? 1 : 0, (int)$draft['id']]);
}

/** Is the lock held by someone other than $userId right now? */
function ecw_draft_locked_by_other(array $draft, int $userId): bool
{
    $holder = (int)($draft['editing_user_id'] ?? 0);
    if ($holder === 0 || $holder === $userId) return false;
    return !empty($draft['editing_at']) && strtotime($draft['editing_at'] . ' UTC') >= time() - ECW_DRAFT_LOCK_SECONDS;
}

/**
 * Heartbeat from an open composer: take or keep the edit lock if it is free
 * (or $take: "Take over"), and report who holds it.
 * @return array{editing:?array{id:int,name:string}, mine:bool, version:int, updated_at:string, last_edited_by:?string, shared:bool}
 */
function ecw_draft_lock(int $orgId, int $userId, array $draft, bool $take): array
{
    if (!ecw_draft_locked_by_other($draft, $userId) || $take) {
        ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET editing_user_id = ?, editing_at = ? WHERE id = ?')
            ->execute([$userId, ecw_now(), (int)$draft['id']]);
    }
    return ecw_draft_share_state((int)$draft['id'], $userId);
}

/** The composer is closing: let go of the lock. */
function ecw_draft_unlock(int $userId, int $draftId): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET editing_user_id = NULL, editing_at = NULL WHERE id = ? AND editing_user_id = ?')
        ->execute([$draftId, $userId]);
}

/** Who edits, which version, who saved last. */
function ecw_draft_share_state(int $draftId, int $userId): array
{
    $st = ecw_db()->prepare('SELECT d.*, eu.name AS e_name, eu.email AS e_email, lu.name AS l_name, lu.email AS l_email
                               FROM `' . ecw_table('drafts') . '` d
                               LEFT JOIN `' . ecw_table('users') . '` eu ON eu.id = d.editing_user_id
                               LEFT JOIN `' . ecw_table('users') . '` lu ON lu.id = d.last_edited_by_user_id
                              WHERE d.id = ?');
    $st->execute([$draftId]);
    $d = $st->fetch();
    if (!$d) throw new InvalidArgumentException('That draft no longer exists.');
    $fresh = !empty($d['editing_at']) && strtotime($d['editing_at'] . ' UTC') >= time() - ECW_DRAFT_LOCK_SECONDS;
    $editing = $fresh && $d['editing_user_id'] !== null ? ['id' => (int)$d['editing_user_id'], 'name' => (string)($d['e_name'] ?: $d['e_email'])] : null;
    return [
        'editing' => $editing,
        'mine' => $editing !== null && $editing['id'] === $userId,
        'version' => (int)$d['version'],
        'updated_at' => (string)$d['updated_at'],
        'last_edited_by' => $d['last_edited_by_user_id'] !== null && (int)$d['last_edited_by_user_id'] !== $userId ? (string)($d['l_name'] ?: $d['l_email']) : null,
        'shared' => $d['shared_at'] !== null,
    ];
}

/**
 * Shared drafts answering any email of a conversation, for the reading pane.
 * @return array<int,array{id:int, author:string, mine:bool, updated_at:string, editing:?string, subject:string}>
 */
function ecw_shared_drafts_for_thread(int $orgId, int $userId, int $threadId): array
{
    $st = ecw_db()->prepare('SELECT d.id, d.user_id, d.updated_at, d.subject, d.editing_user_id, d.editing_at, u.name, u.email, eu.name AS e_name, eu.email AS e_email
                               FROM `' . ecw_table('drafts') . '` d
                               JOIN `' . ecw_table('email_messages') . '` m ON m.id = d.reply_to_local_message_id
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = d.user_id
                               LEFT JOIN `' . ecw_table('users') . '` eu ON eu.id = d.editing_user_id
                              WHERE d.organization_id = ? AND m.email_thread_id = ? AND d.shared_at IS NOT NULL
                                AND (d.send_state IS NULL OR d.send_state = \'failed\')
                              ORDER BY d.updated_at DESC');
    $st->execute([$orgId, $threadId]);
    $out = [];
    foreach ($st->fetchAll() as $d) {
        $fresh = !empty($d['editing_at']) && strtotime($d['editing_at'] . ' UTC') >= time() - ECW_DRAFT_LOCK_SECONDS;
        $out[] = [
            'id' => (int)$d['id'], 'author' => (string)($d['name'] ?: $d['email'] ?: 'A colleague'), 'mine' => (int)$d['user_id'] === $userId,
            'updated_at' => (string)$d['updated_at'], 'subject' => (string)$d['subject'],
            'editing' => $fresh && (int)$d['editing_user_id'] !== $userId && $d['editing_user_id'] !== null ? (string)($d['e_name'] ?: $d['e_email']) : null,
        ];
    }
    return $out;
}

/**
 * Before sending: anything new in the conversation since the writer last looked?
 * @return ?array{from:string, at:string, direction:string, snippet:string} the newest such email
 */
function ecw_reply_collision(array $draft, int $seenMessageId): ?array
{
    $threadId = ecw_draft_thread_id($draft);
    if ($threadId === null || $seenMessageId <= 0) return null;
    $st = ecw_db()->prepare('SELECT from_name, from_email, occurred_at, direction, LEFT(COALESCE(body_text, \'\'), 600) AS body_preview FROM `' . ecw_table('email_messages') . '`
                              WHERE email_thread_id = ? AND id > ? AND provider_removed_at IS NULL ORDER BY occurred_at DESC, id DESC LIMIT 1');
    $st->execute([$threadId, $seenMessageId]);
    $m = $st->fetch();
    if (!$m) return null;
    return ['from' => (string)($m['from_name'] ?: $m['from_email']), 'at' => (string)$m['occurred_at'], 'direction' => (string)$m['direction'],
            'snippet' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$m['body_preview'])), 0, 140, '…')];
}
