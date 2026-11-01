<?php declare(strict_types=1);
/**
 * Email Case Workspace — per-folder sync leases and cursors.
 *
 * THE LEASE
 * ---------
 * One row-level lease per (mailbox, folder, sync_type). Acquisition is a single
 * conditional UPDATE, so MySQL — not the application — decides the winner when
 * two runs race. The loser exits immediately, having made no provider call.
 * This is what makes a burst of webhook notifications cheap and a duplicate
 * cron harmless. A crashed run leaves a stale lease that simply ages out.
 *
 * WHERE CURSORS LIVE
 * ------------------
 * Delta cursor: ecw_mail_folders.delta_link_encrypted — the authority declared
 * in step 01, one per folder, exactly matching the step's "each folder keeps
 * its own synchronization cursor".
 *
 * Backfill cursor: ecw_mailbox_sync_state.cursor_encrypted with
 * sync_type='backfill'. The historical walk runs newest-first through a
 * separate paging sequence and must not share the delta position — one would
 * constantly overwrite the other and the mailbox would oscillate between
 * "caught up" and "reading 2019".
 *
 * Both are encrypted: a Graph deltaLink embeds a mailbox-scoped token.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../health.php';   // ecw_heartbeat: System health hears from a daemon mid-job

/**
 * How long a lease survives without a heartbeat.
 *
 * A healthy run heartbeats after every page, which is seconds apart, so this
 * only governs how quickly a CRASHED run is recovered from. 900s was chosen
 * when sync ran from cron; with the daemon polling every 15s, a folder stuck
 * for 15 minutes after one killed process is the difference between "live" and
 * "broken". 300s is still two orders of magnitude longer than a page.
 */
const ECW_LEASE_TTL_SECONDS = 300;

function ecw_sync_state_id(int $mailboxId, ?int $folderId, string $syncType): int
{
    $pdo   = ecw_db();
    $table = ecw_table('mailbox_sync_state');

    $st = $pdo->prepare(
        "SELECT id FROM `{$table}`
          WHERE mailbox_id = ? AND IFNULL(mail_folder_id, 0) = IFNULL(?, 0) AND sync_type = ?"
    );
    $st->execute([$mailboxId, $folderId, $syncType]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    try {
        $st = $pdo->prepare("INSERT INTO `{$table}` (mailbox_id, mail_folder_id, sync_type) VALUES (?, ?, ?)");
        $st->execute([$mailboxId, $folderId, $syncType]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), '1062')) {
            throw $e;
        }
        // Another run created it in the gap. Re-read rather than fail.
        $st = $pdo->prepare(
            "SELECT id FROM `{$table}`
              WHERE mailbox_id = ? AND IFNULL(mail_folder_id, 0) = IFNULL(?, 0) AND sync_type = ?"
        );
        $st->execute([$mailboxId, $folderId, $syncType]);
        return (int)$st->fetchColumn();
    }
}

/** @return string|null the lease id when claimed, null when someone else holds it */
function ecw_sync_acquire_lease(int $stateId, int $ttlSeconds = ECW_LEASE_TTL_SECONDS): ?string
{
    // The sync daemon's leases start with "dm": only one daemon can run (its flock), so when one
    // starts, any "dm" lease is a killed daemon's and is freed at once (ecw_sync_free_daemon_leases).
    $leaseId = (defined('ECW_SYNC_DAEMON') ? 'dm' . bin2hex(random_bytes(7)) : bin2hex(random_bytes(8)));
    $now     = ecw_now();
    $stale   = gmdate('Y-m-d H:i:s', time() - $ttlSeconds);

    $st = ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailbox_sync_state') . '`
            SET run_lease_id = ?, run_lease_at = ?
          WHERE id = ?
            AND (run_lease_id IS NULL OR run_lease_at IS NULL OR run_lease_at < ?)'
    );
    $st->execute([$leaseId, $now, $stateId, $stale]);

    return $st->rowCount() === 1 ? $leaseId : null;
}

/**
 * The sync daemon starting: the leases of the daemon before it (stopped mid-task by a restart,
 * a sleep or a PC start-up) are freed now, not 5 minutes later. Other runs (bin/mailboxctl.php)
 * keep theirs. @return int leases freed
 */
function ecw_sync_free_daemon_leases(): int
{
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . "` SET run_lease_id = NULL, run_lease_at = NULL WHERE run_lease_id LIKE 'dm%'");
    $st->execute();
    return $st->rowCount();
}

/**
 * The sync daemon is alive, for Settings -> System health. Called per email too:
 * the daemon loop reports only between jobs, and a first delta of a big folder
 * on a slow network runs for an hour (the admin was told sync had stopped).
 * Only the daemon reports: a sync run from a web request proves nothing.
 */
function ecw_sync_daemon_alive(): void
{
    if (defined('ECW_SYNC_DAEMON')) ecw_heartbeat('sync');
}

/** Keep a long run's lease alive. Fails silently if the lease was stolen. */
function ecw_sync_heartbeat(int $stateId, string $leaseId): bool
{
    ecw_sync_daemon_alive();
    $st = ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailbox_sync_state') . '`
            SET run_lease_at = ? WHERE id = ? AND run_lease_id = ?'
    );
    $st->execute([ecw_now(), $stateId, $leaseId]);
    return $st->rowCount() === 1;
}

function ecw_sync_release_lease(int $stateId, string $leaseId): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailbox_sync_state') . '`
            SET run_lease_id = NULL, run_lease_at = NULL
          WHERE id = ? AND run_lease_id = ?'
    )->execute([$stateId, $leaseId]);
}

function ecw_sync_record_success(int $stateId): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailbox_sync_state') . '`
            SET last_success_at = ?, last_error = NULL, retry_count = 0
          WHERE id = ?'
    )->execute([ecw_now(), $stateId]);
}

function ecw_sync_record_error(int $stateId, string $error): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailbox_sync_state') . '`
            SET last_error = ?, retry_count = retry_count + 1
          WHERE id = ?'
    )->execute([substr(ecw_redact($error), 0, 2000), $stateId]);
}

// ----------------------------------------------------------------- cursors

function ecw_folder_delta_cursor(int $folderId): ?string
{
    $st = ecw_db()->prepare('SELECT delta_link_encrypted FROM `' . ecw_table('mail_folders') . '` WHERE id = ?');
    $st->execute([$folderId]);
    $v = $st->fetchColumn();
    return $v === false ? null : ecw_decrypt($v === null ? null : (string)$v);
}

function ecw_folder_set_delta_cursor(int $folderId, ?string $cursor): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mail_folders') . '`
            SET delta_link_encrypted = ?, last_sync_at = ? WHERE id = ?'
    )->execute([$cursor === null ? null : ecw_encrypt($cursor), ecw_now(), $folderId]);
}

function ecw_sync_cursor(int $stateId): ?string
{
    $st = ecw_db()->prepare('SELECT cursor_encrypted FROM `' . ecw_table('mailbox_sync_state') . '` WHERE id = ?');
    $st->execute([$stateId]);
    $v = $st->fetchColumn();
    return $v === false ? null : ecw_decrypt($v === null ? null : (string)$v);
}

function ecw_sync_set_cursor(int $stateId, ?string $cursor): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailbox_sync_state') . '` SET cursor_encrypted = ? WHERE id = ?'
    )->execute([$cursor === null ? null : ecw_encrypt($cursor), $stateId]);
}
