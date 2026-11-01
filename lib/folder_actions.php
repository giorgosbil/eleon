<?php declare(strict_types=1);
/**
 * Folder management from the app: new folder (or subfolder), rename, delete —
 * done in Outlook / Gmail first, then mirrored here, like every other action.
 * Only your own folders: Inbox, Sent, Deleted Items etc. cannot be renamed or
 * deleted. A folder with subfolders is deleted only after them (no surprises).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/sync/folders.php';

function ecw_folder_clean_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Give the folder a name (up to 100 characters).');
    if (preg_match('/[\\\\\/]/', $name)) throw new InvalidArgumentException('A folder name cannot contain / or \\.');
    return $name;
}

/** The folder, if it belongs to the mailbox. */
function ecw_folder_of(int $mailboxId, int $folderId): array
{
    $f = ecw_folder_row($folderId);
    if ($f === null || (int)$f['mailbox_id'] !== $mailboxId) throw new InvalidArgumentException('Folder not found.');
    return $f;
}

/** Same name already under the same parent? */
function ecw_folder_name_taken(int $mailboxId, ?string $parentProviderId, string $name, ?int $exceptId = null): bool
{
    $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND parent_provider_folder_id <=> ? AND LOWER(name) = LOWER(?)' . ($exceptId ? ' AND id <> ' . (int)$exceptId : ''));
    $st->execute([$mailboxId, $parentProviderId, $name]);
    return (bool)$st->fetchColumn();
}

/** @return array the new folder row */
function ecw_folder_create(int $mailboxId, string $name, ?int $parentFolderId = null): array
{
    $name = ecw_folder_clean_name($name);
    $parent = $parentFolderId ? ecw_folder_of($mailboxId, $parentFolderId) : null;
    if ($parent !== null && in_array($parent['folder_type'], ['drafts', 'sent'], true)) throw new InvalidArgumentException('Folders cannot go inside Sent or Drafts.');
    $parentProvider = $parent !== null ? (string)$parent['provider_folder_id'] : null;
    if (ecw_folder_name_taken($mailboxId, $parentProvider, $name)) throw new InvalidArgumentException('There is already a folder called “' . $name . '” there.');
    $mailbox = ecw_mailbox($mailboxId);
    // Gmail has no real subfolders: a nested label is "Parent/Child".
    $providerName = $parent !== null && ($mailbox['provider'] ?? '') === 'google' ? $parent['name'] . '/' . $name : $name;
    $created = ecw_provider_for_mailbox($mailboxId)->createFolder($providerName, ($mailbox['provider'] ?? '') === 'google' ? null : $parentProvider);
    if (($created['provider_folder_id'] ?? '') === '') throw new RuntimeException('The mailbox did not create the folder. Try again in a moment.');
    // Selected for sync straight away, so mail moved into it is seen.
    ecw_db()->prepare('INSERT INTO `' . ecw_table('mail_folders') . '` (mailbox_id, provider_folder_id, parent_provider_folder_id, name, folder_type, is_system, is_selected_for_sync)
                       VALUES (?, ?, ?, ?, \'custom\', 0, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), is_selected_for_sync = 1')
        ->execute([$mailboxId, $created['provider_folder_id'], $parentProvider, $name]);
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND provider_folder_id = ?');
    $st->execute([$mailboxId, $created['provider_folder_id']]);
    ecw_log("mailbox {$mailboxId}: folder “{$name}” created");
    return $st->fetch();
}

function ecw_folder_rename(int $mailboxId, int $folderId, string $name): void
{
    $name = ecw_folder_clean_name($name);
    $f = ecw_folder_of($mailboxId, $folderId);
    if ((int)$f['is_system'] === 1 || $f['folder_type'] !== 'custom') throw new InvalidArgumentException('Inbox, Sent, Drafts, Archive and Deleted Items keep their names.');
    if (ecw_folder_name_taken($mailboxId, $f['parent_provider_folder_id'], $name, $folderId)) throw new InvalidArgumentException('There is already a folder called “' . $name . '” there.');
    $mailbox = ecw_mailbox($mailboxId);
    $providerName = $name;
    if (($mailbox['provider'] ?? '') === 'google' && $f['parent_provider_folder_id'] !== null) {
        $p = ecw_db()->prepare('SELECT name FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND provider_folder_id = ?');
        $p->execute([$mailboxId, $f['parent_provider_folder_id']]);
        $providerName = (string)$p->fetchColumn() . '/' . $name;
    }
    ecw_provider_for_mailbox($mailboxId)->renameFolder((string)$f['provider_folder_id'], $providerName);
    ecw_db()->prepare('UPDATE `' . ecw_table('mail_folders') . '` SET name = ? WHERE id = ?')->execute([$name, $folderId]);
}

/** @return int emails that were in it */
function ecw_folder_delete(int $mailboxId, int $folderId): int
{
    $f = ecw_folder_of($mailboxId, $folderId);
    if ((int)$f['is_system'] === 1 || $f['folder_type'] !== 'custom' || (int)($f['is_case_intake'] ?? 0) === 1) {
        throw new InvalidArgumentException((int)($f['is_case_intake'] ?? 0) === 1 ? 'The “Create Case” folder is used to open cases — change that in Settings first.' : 'This folder belongs to the mailbox and cannot be deleted.');
    }
    $kids = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND parent_provider_folder_id = ?');
    $kids->execute([$mailboxId, $f['provider_folder_id']]);
    if ((int)$kids->fetchColumn() > 0) throw new InvalidArgumentException('Delete or move its subfolders first.');

    $result = ecw_provider_for_mailbox($mailboxId)->deleteFolder((string)$f['provider_folder_id']);
    $msgs = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . '` WHERE mail_folder_id = ? AND provider_removed_at IS NULL');
    $msgs->execute([$folderId]);
    $count = (int)$msgs->fetchColumn();
    if ($result === 'removed') {
        // Outlook put it, with its mail, in Deleted Items: gone from view here too
        // (the rows stay for case timelines, like any removal the sync sees).
        ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET provider_removed_at = ? WHERE mail_folder_id = ? AND provider_removed_at IS NULL')->execute([ecw_now(), $folderId]);
    }
    $threads = ecw_db()->prepare('SELECT DISTINCT email_thread_id FROM `' . ecw_table('email_messages') . '` WHERE mail_folder_id = ?');
    $threads->execute([$folderId]);
    $threadIds = array_map('intval', $threads->fetchAll(PDO::FETCH_COLUMN));
    ecw_db()->prepare('DELETE FROM `' . ecw_table('mail_folders') . '` WHERE id = ?')->execute([$folderId]);   // messages: folder set NULL
    require_once __DIR__ . '/threading.php';
    foreach ($threadIds as $t) ecw_thread_refresh_counters($t);
    ecw_log("mailbox {$mailboxId}: folder “{$f['name']}” deleted ({$count} emails, {$result})");
    return $count;
}

/**
 * "Empty folder" on Deleted Items or Junk Email: their emails deleted for good,
 * like Outlook (to Recoverable Items, where Outlook keeps them a while). A batch
 * per call, so a folder of thousands never runs into a time-out: the app calls
 * again until nothing is left. Emails in a case on legal hold stay. The rows stay,
 * stamped removed, like a removal seen by the sync, so case timelines keep them.
 *
 * @return array{deleted:int, failed:int, held:int, remaining:int}
 */
function ecw_folder_empty(int $mailboxId, int $folderId, int $batch = 50): array
{
    $f = ecw_folder_of($mailboxId, $folderId);
    if (!in_array($f['folder_type'], ['deleted', 'junk'], true)) throw new InvalidArgumentException('Only Deleted Items and Junk Email can be emptied.');
    require_once __DIR__ . '/security.php';
    require_once __DIR__ . '/threading.php';
    $t = ecw_table('email_messages');
    $st = ecw_db()->prepare("SELECT id, email_thread_id, provider_message_id FROM `{$t}` WHERE mailbox_id = ? AND mail_folder_id = ? AND provider_removed_at IS NULL ORDER BY id");
    $st->execute([$mailboxId, $folderId]);
    $provider = ecw_provider_for_mailbox($mailboxId);
    $stamp = ecw_db()->prepare("UPDATE `{$t}` SET provider_removed_at = ? WHERE id = ?");
    $held = []; $threads = []; $deleted = 0; $failed = 0; $heldCount = 0; $remaining = 0;
    // Outlook: 20 deletes per call ($batch), 200 a round — about 20 times faster than one call per email.
    if (method_exists($provider, 'deleteMany')) {
        $todo = [];
        foreach ($st->fetchAll() as $r) {
            $thread = (int)$r['email_thread_id'];
            $held[$thread] ??= ecw_thread_held($thread);
            if ($held[$thread]) { $heldCount++; continue; }
            if (count($todo) >= $batch * 4) { $remaining++; continue; }
            $todo[(string)$r['provider_message_id']] = $r;
        }
        $res = $todo ? $provider->deleteMany(array_map('strval', array_keys($todo)), $f['folder_type'] === 'junk') : ['done' => [], 'retry' => [], 'failed' => []];
        foreach ($res['done'] as $pid) {
            $r = $todo[$pid];
            $stamp->execute([ecw_now(), (int)$r['id']]);
            $threads[(int)$r['email_thread_id']] = true;
            $deleted++;
        }
        $failed = count($res['failed']);
        $remaining += count($res['retry']);   // Outlook said "slow down": the next round tries them again
        if ($failed) ecw_log('empty folder: ' . $failed . ' messages not deleted in folder ' . $folderId);
    } else foreach ($st->fetchAll() as $r) {
        $thread = (int)$r['email_thread_id'];
        $held[$thread] ??= ecw_thread_held($thread);
        if ($held[$thread]) { $heldCount++; continue; }
        if ($deleted + $failed >= $batch) { $remaining++; continue; }
        try {
            if ($f['folder_type'] === 'junk' && method_exists($provider, 'permanentlyDelete')) $provider->permanentlyDelete((string)$r['provider_message_id']);
            else $provider->deleteMessage((string)$r['provider_message_id']);   // from Deleted Items: for good
            $stamp->execute([ecw_now(), (int)$r['id']]);
            $threads[$thread] = true;
            $deleted++;
        } catch (InvalidArgumentException $e) {
            throw $e;   // the provider cannot do it at all (Gmail): say why
        } catch (Throwable $e) {
            $failed++;
            ecw_log('empty folder: message ' . $r['id'] . ' not deleted: ' . $e->getMessage());
        }
    }
    foreach (array_keys($threads) as $thread) ecw_thread_refresh_counters($thread);
    if ($deleted > 0) { require_once __DIR__ . '/audit.php'; ecw_audit('email.delete_forever', 'folder', $folderId, ['emails' => $deleted, 'mailbox' => $mailboxId, 'folder' => $f['name']]); }
    if ($deleted === 0 && $failed > 0) throw new RuntimeException('The mailbox did not accept the delete. Try again in a moment.');
    return ['deleted' => $deleted, 'failed' => $failed, 'held' => $heldCount, 'remaining' => $remaining];
}
