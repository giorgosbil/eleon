<?php declare(strict_types=1);
/**
 * Email Case Workspace — what is synchronizing right now, for the spinners.
 *
 * The Sync button turns while the mailbox is importing or a sync someone asked
 * for is running; a folder turns while it is importing or syncing on request;
 * both stop only when that work is complete (100%): history imported, every
 * email's text and attachments fetched, no requested job left. The background
 * refreshes the sync daemon runs every minute do NOT make anything turn: they
 * are not something anyone is waiting for.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailbox.php';
require_once __DIR__ . '/queue.php';

/**
 * history_done: the last two years are in (phase 'older' or 'done'); phase 'older':
 * older emails are still coming in the background (older_importing).
 *
 * @return array{busy:bool, importing:bool, history_done:bool, phase:string, older_importing:bool, conversations:int, messages:int,
 *               bodies_left:int, attachments_left:int, folders:list<int>, requested:bool, checked_at:?string, behind:list<array{id:int,name:string,checked_at:?string}>}
 */
function ecw_mailbox_sync_activity(int $mailboxId): array
{
    $pdo = ecw_db();
    $T = static fn(string $t): string => '`' . ecw_table($t) . '`';
    $one = static function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };

    $mb = ecw_mailbox($mailboxId);
    if ($mb === null) return ['busy' => false, 'importing' => false, 'history_done' => true, 'phase' => 'done', 'older_importing' => false, 'conversations' => 0, 'messages' => 0, 'bodies_left' => 0, 'attachments_left' => 0, 'folders' => [], 'requested' => false, 'checked_at' => null, 'behind' => []];
    $connected = !in_array($mb['sync_status'], ['disconnected', 'needs_reauth'], true);

    // Jobs that are really happening: due now (or within a minute), or running
    // with a lock that is still fresh (a lock older than 15 minutes is a job
    // its worker abandoned; ecw_queue_recover_abandoned frees it).
    $st = $pdo->prepare("SELECT mail_folder_id, job_type, reason FROM {$T('sync_queue')}
                          WHERE mailbox_id = ?
                            AND ((status = 'queued' AND available_at <= ?) OR (status = 'running' AND locked_at >= ?))");
    $st->execute([$mailboxId, gmdate('Y-m-d H:i:s', time() + 60), gmdate('Y-m-d H:i:s', time() - 900)]);
    $folders = [];
    $requested = false;
    $backfill = false;
    foreach ($st->fetchAll() as $j) {
        if ($j['reason'] === 'older') continue;   // older emails come in the background: nothing turns for them
        $asked = in_array($j['reason'], ['manual', 'initial'], true);
        if ($asked) $requested = true;
        if ($j['job_type'] === 'backfill') $backfill = true;
        if ($j['mail_folder_id'] !== null && ($asked || $j['job_type'] === 'backfill')) $folders[(int)$j['mail_folder_id']] = true;
        if ($j['job_type'] === 'folders' && $asked) $requested = true;
    }

    // History in two steps (lib/sync/messages.php): the last two years are what
    // the spinners wait for; the older emails follow quietly in the background.
    require_once __DIR__ . '/messages.php';
    $phase = ecw_history_phase($mailboxId);
    $recent = ecw_history_recent_cutoff();
    $bodiesLeft = $connected ? (int)$one("SELECT COUNT(*) FROM {$T('email_messages')} WHERE mailbox_id = ? AND body_fetch_state = 'not_fetched' AND provider_removed_at IS NULL AND occurred_at >= ?", [$mailboxId, $recent]) : 0;
    $attLeft = $connected ? (int)$one("SELECT COUNT(*) FROM {$T('email_messages')} WHERE mailbox_id = ? AND attachments_state = 'pending' AND provider_removed_at IS NULL AND occurred_at >= ?", [$mailboxId, $recent]) : 0;
    $importing = $connected && ($phase === 'recent' || $backfill || $bodiesLeft > 0 || $attLeft > 0);

    // Up to date: every synced folder asked Outlook / Gmail for its changes lately (a delta
    // round every 15 s). checked_at is the OLDEST of those checks, so "checked 18:20" holds for
    // all folders; a folder not checked for 5 minutes is behind (refused, or the line is down).
    $checked = [];
    $st = $pdo->prepare("SELECT f.id, f.name, s.last_success_at FROM {$T('mail_folders')} f
                           LEFT JOIN {$T('mailbox_sync_state')} s ON s.mail_folder_id = f.id AND s.sync_type = 'delta'
                          WHERE f.mailbox_id = ? AND f.is_selected_for_sync = 1");
    $st->execute([$mailboxId]);
    $behind = [];
    $stale = gmdate('Y-m-d H:i:s', time() - 300);
    foreach ($st->fetchAll() as $f) {
        $checked[] = $f['last_success_at'];
        if ($connected && ($f['last_success_at'] === null || $f['last_success_at'] < $stale)) {
            $behind[] = ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'checked_at' => $f['last_success_at']];
        }
    }
    $known = array_filter($checked);
    $checkedAt = $known === [] || count($known) < count($checked) ? null : min($known);

    return [
        'busy'             => $connected && ($importing || $requested),
        'importing'        => $importing,
        'history_done'     => $phase !== 'recent',
        'phase'            => $phase,
        'older_importing'  => $connected && $phase === 'older',
        'conversations'    => (int)$one("SELECT COUNT(*) FROM {$T('email_threads')} WHERE mailbox_id = ?", [$mailboxId]),
        'messages'         => (int)$one("SELECT COUNT(*) FROM {$T('email_messages')} WHERE mailbox_id = ?", [$mailboxId]),
        'bodies_left'      => $bodiesLeft,
        'attachments_left' => $attLeft,
        'folders'          => array_keys($folders),
        'requested'        => $requested,
        'checked_at'       => $checkedAt,
        'behind'           => $behind,
    ];
}

/** "Sync now": discover folders, then import and refresh every selected folder. */
function ecw_mailbox_sync_now(int $mailboxId): bool
{
    $mb = ecw_mailbox($mailboxId);
    if ($mb === null || !in_array($mb['sync_status'], ['connected', 'syncing', 'temp_error'], true)) return false;
    ecw_queue_enqueue($mailboxId, null, 'folders', 'manual');
    ecw_queue_enqueue($mailboxId, null, 'bodies', 'manual');
    ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_SYNCING, 'Synchronization queued.');
    return true;
}
