<?php declare(strict_types=1);
/**
 * Conversation actions that change the mailbox itself: Delete (to Deleted
 * Items / Trash), Archive, Move to a folder, Flag — and Undo.
 *
 * A move is done at the provider straight away (one call per message, a
 * conversation is a handful) and mirrored locally, so the list is right at once
 * and the next delta sync agrees. Outlook gives a moved message a new id; it
 * is stored, so the sync recognises it in its new folder.
 *
 * Which messages move: every message of the conversation in this mailbox
 * except the ones in Sent and Drafts — moving a conversation out of the Inbox
 * must not empty your Sent folder (Outlook behaves the same way).
 *
 * Flags are like read/unread: set here, pushed by the read_state job.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/threading.php';

/** The mailbox's folder of a type (inbox / deleted / archive), or null. */
function ecw_mailbox_folder_of_type(int $mailboxId, string $type): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND folder_type = ? ORDER BY is_system DESC, id LIMIT 1');
    $st->execute([$mailboxId, $type]);
    return $st->fetch() ?: null;
}

function ecw_mailbox_folder(int $mailboxId, int $folderId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND id = ?');
    $st->execute([$mailboxId, $folderId]);
    return $st->fetch() ?: null;
}

/**
 * Move a conversation. $target: 'deleted' | 'archive' | 'inbox' | a folder id, or 'unarchive':
 * its emails in Archive back to the Inbox (the others stay where they are).
 *
 * @return array{moved: array<int,array{id:int,from:?int}>, failed:int, to:?int, to_name:string}
 */
function ecw_thread_move(int $mailboxId, int $threadId, string $target): array
{
    if ($target === 'forever') return ecw_thread_delete_forever($mailboxId, $threadId);
    $mailbox = ecw_mailbox($mailboxId);
    if ($mailbox === null) throw new InvalidArgumentException('Mailbox not found.');
    $google = ($mailbox['provider'] ?? 'microsoft') === 'google';
    $onlyFromArchive = $target === 'unarchive';
    if ($onlyFromArchive) $target = 'inbox';

    if (ctype_digit($target)) {
        $dest = ecw_mailbox_folder($mailboxId, (int)$target);
        if ($dest === null) throw new InvalidArgumentException('That folder does not exist.');
    } elseif (in_array($target, ['deleted', 'archive', 'inbox'], true)) {
        $dest = ecw_mailbox_folder_of_type($mailboxId, $target);
        if ($dest === null && !($target === 'archive' && $google)) {
            throw new InvalidArgumentException($target === 'archive' ? 'This mailbox has no Archive folder.' : 'Folder not found.');
        }
    } else {
        throw new InvalidArgumentException('Unknown destination.');
    }
    if ($dest !== null && in_array($dest['folder_type'], ['sent', 'drafts'], true)) {
        throw new InvalidArgumentException('Conversations cannot be moved into Sent or Drafts.');
    }
    $destProvider = $dest !== null ? (string)$dest['provider_folder_id'] : 'ARCHIVE';   // Gmail archive = no label
    $destId = $dest !== null ? (int)$dest['id'] : null;

    $st = ecw_db()->prepare(
        'SELECT m.id, m.provider_message_id, m.mail_folder_id, f.provider_folder_id AS from_provider
           FROM `' . ecw_table('email_messages') . '` m
           LEFT JOIN `' . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id
          WHERE m.email_thread_id = ? AND m.mailbox_id = ? AND m.provider_removed_at IS NULL
            AND (f.folder_type IS NULL OR f.folder_type NOT IN ('sent', 'drafts'))
            AND NOT (m.mail_folder_id <=> ?)" . ($onlyFromArchive ? " AND f.folder_type = 'archive'" : '')
    );
    $st->execute([$threadId, $mailboxId, $destId]);
    $rows = $st->fetchAll();
    if ($rows === []) {
        return ['moved' => [], 'failed' => 0, 'to' => $destId, 'to_name' => $dest['name'] ?? 'Archive'];
    }

    $provider = ecw_provider_for_mailbox($mailboxId);
    $update = ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET mail_folder_id = ?, provider_message_id = ? WHERE id = ?');
    $moved = []; $failed = 0;
    // Outlook: the whole conversation in one request ($batch), not one request per email.
    if (count($rows) > 1 && method_exists($provider, 'moveMany')) {
        $res = $provider->moveMany(array_map(static fn($r) => (string)$r['provider_message_id'], $rows), $destProvider);
        foreach ($rows as $r) {
            $old = (string)$r['provider_message_id'];
            if (!isset($res['moved'][$old])) { $failed++; ecw_log('move failed for message ' . $r['id']); continue; }
            $update->execute([$destId, $res['moved'][$old], (int)$r['id']]);
            $moved[] = ['id' => (int)$r['id'], 'from' => $r['mail_folder_id'] !== null ? (int)$r['mail_folder_id'] : null];
        }
        $rows = [];
    }
    foreach ($rows as $r) {
        try {
            $res = $provider->moveMessage((string)$r['provider_message_id'], $destProvider, $r['from_provider'] !== null ? (string)$r['from_provider'] : null);
            $newId = (string)($res['provider_message_id'] ?? '') ?: (string)$r['provider_message_id'];
            $update->execute([$destId, $newId, (int)$r['id']]);
            $moved[] = ['id' => (int)$r['id'], 'from' => $r['mail_folder_id'] !== null ? (int)$r['mail_folder_id'] : null];
        } catch (Throwable $e) {
            $failed++;
            ecw_log('move failed for message ' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    ecw_thread_refresh_counters($threadId);
    if ($moved === [] && $failed > 0) throw new RuntimeException('The mailbox did not accept the move. Try again in a moment.');
    return ['moved' => $moved, 'failed' => $failed, 'to' => $destId, 'to_name' => $dest['name'] ?? 'Archive'];
}

/**
 * Delete for good the messages of a conversation that are already in Deleted
 * Items / Trash (what Delete does there in Outlook). Outlook keeps them in
 * "Recoverable Items" for a while. No Undo. The rows stay, stamped removed,
 * like a removal seen by the sync, so case timelines do not change.
 *
 * @return array{moved: array, deleted:int, failed:int, to:null, to_name:string}
 */
function ecw_thread_delete_forever(int $mailboxId, int $threadId): array
{
    require_once __DIR__ . '/security.php';
    if (ecw_thread_held($threadId)) throw new InvalidArgumentException('This conversation is in a case on legal hold, so it cannot be deleted for good.');
    $st = ecw_db()->prepare(
        'SELECT m.id, m.provider_message_id
           FROM `' . ecw_table('email_messages') . '` m
           JOIN `' . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id
          WHERE m.email_thread_id = ? AND m.mailbox_id = ? AND m.provider_removed_at IS NULL AND f.folder_type = 'deleted'"
    );
    $st->execute([$threadId, $mailboxId]);
    $rows = $st->fetchAll();
    if ($rows === []) return ['moved' => [], 'deleted' => 0, 'failed' => 0, 'to' => null, 'to_name' => 'Deleted'];

    $provider = ecw_provider_for_mailbox($mailboxId);
    $stamp = ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET provider_removed_at = ? WHERE id = ?');
    $deleted = 0; $failed = 0;
    foreach ($rows as $r) {
        try {
            $provider->deleteMessage((string)$r['provider_message_id']);
            $stamp->execute([ecw_now(), (int)$r['id']]);
            $deleted++;
        } catch (InvalidArgumentException $e) {
            throw $e;   // the provider cannot do it at all (Gmail): say why
        } catch (Throwable $e) {
            $failed++;
            ecw_log('permanent delete failed for message ' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    ecw_thread_refresh_counters($threadId);
    if ($deleted === 0 && $failed > 0) throw new RuntimeException('The mailbox did not accept the delete. Try again in a moment.');
    if ($deleted > 0) { require_once __DIR__ . '/audit.php'; ecw_audit('email.delete_forever', 'thread', $threadId, ['emails' => $deleted, 'mailbox' => $mailboxId]); }
    return ['moved' => [], 'deleted' => $deleted, 'failed' => $failed, 'to' => null, 'to_name' => 'Deleted'];
}

/** Undo: put messages back where they were. @param array<int,array{id:int,from:?int}> $moves */
function ecw_messages_restore(int $mailboxId, array $moves): int
{
    $provider = ecw_provider_for_mailbox($mailboxId);
    $st = ecw_db()->prepare(
        'SELECT m.id, m.email_thread_id, m.provider_message_id, m.mail_folder_id, f.provider_folder_id AS from_provider
           FROM `' . ecw_table('email_messages') . '` m
           LEFT JOIN `' . ecw_table('mail_folders') . '` f ON f.id = m.mail_folder_id
          WHERE m.id = ? AND m.mailbox_id = ?'
    );
    $update = ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET mail_folder_id = ?, provider_message_id = ? WHERE id = ?');
    $done = 0; $threads = [];
    // Grouped by the folder each email goes back to: at Outlook one request per group ($batch).
    $groups = [];
    foreach (array_slice($moves, 0, 200) as $mv) {
        $st->execute([(int)($mv['id'] ?? 0), $mailboxId]);
        $m = $st->fetch();
        if ($m === false) continue;
        $back = isset($mv['from']) && $mv['from'] !== null ? ecw_mailbox_folder($mailboxId, (int)$mv['from']) : ecw_mailbox_folder_of_type($mailboxId, 'inbox');
        if ($back === null || (int)$back['id'] === (int)$m['mail_folder_id']) continue;
        $groups[(int)$back['id']]['back'] = $back;
        $groups[(int)$back['id']]['messages'][] = $m;
    }
    $one = [];
    foreach ($groups as $g) {
        if (count($g['messages']) < 2 || !method_exists($provider, 'moveMany')) { foreach ($g['messages'] as $m) $one[] = [$m, $g['back']]; continue; }
        $res = $provider->moveMany(array_map(static fn($m) => (string)$m['provider_message_id'], $g['messages']), (string)$g['back']['provider_folder_id']);
        foreach ($g['messages'] as $m) {
            $new = $res['moved'][(string)$m['provider_message_id']] ?? null;
            if ($new === null) { ecw_log('undo move failed for message ' . $m['id']); continue; }
            $update->execute([(int)$g['back']['id'], $new, (int)$m['id']]);
            $done++; $threads[(int)$m['email_thread_id']] = true;
        }
    }
    foreach ($one as [$m, $back]) {
        try {
            $res = $provider->moveMessage((string)$m['provider_message_id'], (string)$back['provider_folder_id'], $m['from_provider'] !== null ? (string)$m['from_provider'] : 'ARCHIVE');
            $update->execute([(int)$back['id'], (string)($res['provider_message_id'] ?? '') ?: (string)$m['provider_message_id'], (int)$m['id']]);
            $done++; $threads[(int)$m['email_thread_id']] = true;
        } catch (Throwable $e) {
            ecw_log('undo move failed for message ' . $m['id'] . ': ' . $e->getMessage());
        }
    }
    foreach (array_keys($threads) as $t) ecw_thread_refresh_counters($t);
    return $done;
}

/**
 * Flag a conversation (its latest incoming email, like Outlook) or clear every
 * flag in it. Pushed to the provider by the read_state job.
 */
function ecw_thread_flag(int $mailboxId, int $threadId, bool $flagged): int
{
    $table = ecw_table('email_messages');
    if ($flagged) {
        $st = ecw_db()->prepare(
            "UPDATE `{$table}` SET is_flagged = 1, flag_push_pending = 1
              WHERE email_thread_id = ? AND mailbox_id = ? AND provider_removed_at IS NULL AND is_flagged = 0
              ORDER BY direction = 'incoming' DESC, occurred_at DESC, id DESC LIMIT 1"
        );
        $st->execute([$threadId, $mailboxId]);
    } else {
        $st = ecw_db()->prepare("UPDATE `{$table}` SET is_flagged = 0, flag_push_pending = 1 WHERE email_thread_id = ? AND mailbox_id = ? AND is_flagged = 1");
        $st->execute([$threadId, $mailboxId]);
    }
    $changed = $st->rowCount();
    if ($changed > 0) {
        ecw_thread_refresh_counters($threadId);
        require_once __DIR__ . '/sync/queue.php';
        try { ecw_queue_enqueue($mailboxId, null, 'read_state', 'user'); } catch (Throwable $e) { ecw_log('flag enqueue failed: ' . $e->getMessage()); }
    }
    return $changed;
}
