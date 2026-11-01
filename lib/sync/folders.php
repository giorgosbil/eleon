<?php declare(strict_types=1);
/**
 * Email Case Workspace — folder discovery.
 *
 * Runs once right after OAuth and periodically afterwards, because users create
 * folders in Outlook and the local tree has to notice.
 *
 * WHAT IS SELECTED FOR SYNC BY DEFAULT
 * ------------------------------------
 * Every folder people put mail in: Inbox, Sent, their own folders (Outlook
 * rules move mail there), Archive, Junk and Deleted Items. Not Drafts (the app
 * keeps its own drafts) and never Outlook's bookkeeping folders (Sync Issues,
 * Conflicts, Outbox…, type 'system'). Each folder can be switched off in
 * Settings → Mailbox → Folders to sync. Inbox and Sent are imported first:
 * the history walk is newest-first per folder, so the mailbox is usable at once.
 *
 * Before 2026-10 only Inbox and Sent were selected and nothing let people
 * choose; ecw_sync_folders() turns the rest on once for such mailboxes
 * (app setting folders_v2_<id>).
 *
 * THE TREE IS REBUILT FROM PROVIDER IDS
 * -------------------------------------
 * parent_provider_folder_id, never a local id: folders arrive in whatever order
 * the provider pages them, and a parent may be inserted after its child.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../mailbox.php';
require_once __DIR__ . '/lease.php';

/** Folders selected for sync on a brand-new mailbox. */
const ECW_DEFAULT_SYNC_TYPES = ['inbox', 'sent', 'custom', 'archive', 'junk', 'deleted', 'snoozed'];

function ecw_sync_folders(int $mailboxId): array
{
    $stateId = ecw_sync_state_id($mailboxId, null, 'folders');
    $lease   = ecw_sync_acquire_lease($stateId);
    if ($lease === null) {
        return ['skipped' => true, 'reason' => 'lease_held'];
    }

    $counts = ['seen' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => false];

    try {
        $provider = ecw_provider_for_mailbox($mailboxId);
        $folders  = $provider->listFolders();
        $table    = ecw_table('mail_folders');
        $pdo      = ecw_db();

        foreach ($folders as $f) {
            $counts['seen']++;
            // New folders (also ones created later in Outlook) are synced unless they are bookkeeping or Drafts.
            $selected = in_array($f['folder_type'], ECW_DEFAULT_SYNC_TYPES, true) ? 1 : 0;

            $st = $pdo->prepare(
                "INSERT INTO `{$table}`
                    (mailbox_id, provider_folder_id, parent_provider_folder_id, name,
                     folder_type, is_system, is_selected_for_sync, total_count, total_checked_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    parent_provider_folder_id = VALUES(parent_provider_folder_id),
                    name        = VALUES(name),
                    folder_type = VALUES(folder_type),
                    is_system   = VALUES(is_system),
                    total_count = COALESCE(VALUES(total_count), total_count),
                    total_checked_at = IF(VALUES(total_count) IS NULL, total_checked_at, VALUES(total_checked_at))"
                // is_selected_for_sync is deliberately NOT updated: it is the
                // user's choice, and a folder rediscovery must not silently
                // re-enable or disable syncing they configured.
            );
            $st->execute([
                $mailboxId,
                $f['provider_folder_id'],
                $f['parent_provider_folder_id'],
                $f['name'],
                $f['folder_type'],
                !empty($f['is_system']) ? 1 : 0,
                $selected,
                $f['total_count'] ?? null,
                isset($f['total_count']) ? gmdate('Y-m-d H:i:s') : null,
            ]);

            // rowCount is 1 for an insert, 2 for an update that changed something.
            if ($st->rowCount() === 1) {
                $counts['inserted']++;
            } elseif ($st->rowCount() === 2) {
                $counts['updated']++;
            }
        }

        // Bookkeeping folders are never synced, whatever was stored before.
        $pdo->prepare("UPDATE `{$table}` SET is_selected_for_sync = 0 WHERE mailbox_id = ? AND folder_type = 'system'")->execute([$mailboxId]);

        // The exact number of emails in each synced folder, to show "1,234 of 1,240"
        // and a tick when complete (Settings → Mailbox). Never fatal: it only informs.
        if (method_exists($provider, 'folderMessageCount')) {
            $sel = $pdo->prepare("SELECT id, provider_folder_id FROM `{$table}` WHERE mailbox_id = ? AND is_selected_for_sync = 1 AND folder_type <> 'system' LIMIT 60");
            $sel->execute([$mailboxId]);
            $put = $pdo->prepare("UPDATE `{$table}` SET total_count = ?, total_checked_at = ? WHERE id = ?");
            foreach ($sel->fetchAll() as $row) {
                try {
                    $n = $provider->folderMessageCount((string)$row['provider_folder_id']);
                    if ($n !== null) $put->execute([$n, gmdate('Y-m-d H:i:s'), (int)$row['id']]);
                } catch (Throwable $e) {
                    ecw_log("mailbox {$mailboxId}: email count for folder {$row['id']} unavailable: " . $e->getMessage());
                }
            }
        }
        // Once per mailbox connected before every folder was synced: turn the rest on.
        require_once __DIR__ . '/../license.php';
        if (ecw_app_setting('folders_v2_' . $mailboxId) === null) {
            $in = implode(',', array_fill(0, count(ECW_DEFAULT_SYNC_TYPES), '?'));
            $up = $pdo->prepare("UPDATE `{$table}` SET is_selected_for_sync = 1 WHERE mailbox_id = ? AND folder_type IN ({$in})");
            $up->execute(array_merge([$mailboxId], ECW_DEFAULT_SYNC_TYPES));
            $counts['turned_on'] = $up->rowCount();
            ecw_app_setting_set('folders_v2_' . $mailboxId, gmdate('Y-m-d H:i:s'));
        }

        ecw_sync_record_success($stateId);
    } catch (Throwable $e) {
        ecw_sync_record_error($stateId, $e->getMessage());
        ecw_mailbox_record_failure($mailboxId, $e);
        throw $e;
    } finally {
        ecw_sync_release_lease($stateId, $lease);
    }

    ecw_log("mailbox {$mailboxId} folder sync: " . json_encode($counts));
    return $counts;
}

/** One folder row by local id. Every sync path needs it, so it lives here. */
function ecw_folder_row(int $folderId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mail_folders') . '` WHERE id = ?');
    $st->execute([$folderId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array<int,array> folders this mailbox actually syncs, in import order:
 * Inbox and Sent first (what people read), then their own folders and Archive,
 * Deleted Items and Junk last. The queue runs jobs in the order they were
 * added, so this order is the order a new mailbox fills in.
 */
function ecw_selected_folders(int $mailboxId): array
{
    $st = ecw_db()->prepare(
        'SELECT * FROM `' . ecw_table('mail_folders') . "`
          WHERE mailbox_id = ? AND is_selected_for_sync = 1
          ORDER BY FIELD(folder_type, 'inbox', 'sent', 'custom', 'archive', 'snoozed', 'junk', 'deleted'), name"
    );
    $st->execute([$mailboxId]);
    return $st->fetchAll();
}

/**
 * Create (or adopt) the configurable "Create Case" folder.
 *
 * Creating it in the provider matters: the workflow is "drag a mail into this
 * folder in Outlook", so the folder has to exist where the user drags.
 */
function ecw_ensure_case_intake_folder(int $mailboxId, string $name = 'Create Case'): array
{
    $pdo   = ecw_db();
    $table = ecw_table('mail_folders');

    $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE mailbox_id = ? AND name = ?");
    $st->execute([$mailboxId, $name]);
    $existing = $st->fetch();

    if ($existing === false) {
        $provider = ecw_provider_for_mailbox($mailboxId);
        $created  = $provider->createFolder($name, null);
        $st = $pdo->prepare(
            "INSERT INTO `{$table}`
                (mailbox_id, provider_folder_id, parent_provider_folder_id, name, folder_type,
                 is_system, is_selected_for_sync, is_case_intake)
             VALUES (?, ?, NULL, ?, 'custom', 0, 1, 1)"
        );
        $st->execute([$mailboxId, $created['provider_folder_id'], $name]);
        $folderId = (int)$pdo->lastInsertId();
    } else {
        $folderId = (int)$existing['id'];
        $pdo->prepare("UPDATE `{$table}` SET is_case_intake = 1, is_selected_for_sync = 1 WHERE id = ?")
            ->execute([$folderId]);
    }

    ecw_log("mailbox {$mailboxId} case intake folder is #{$folderId} ({$name})");
    $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ?");
    $st->execute([$folderId]);
    return $st->fetch();
}
