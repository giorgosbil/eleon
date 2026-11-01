<?php declare(strict_types=1);
/**
 * Email Case Workspace — the sync job queue.
 *
 * Webhook notifications, cron sweeps and manual triggers all do the same thing:
 * enqueue a job. The worker drains it. Nothing performs a provider call inline
 * on a web request — a notification handler that synced inline would hold
 * Graph's connection open while it paged, and Graph would drop the subscription.
 *
 * COALESCING IS BEST EFFORT
 * -------------------------
 * enqueue() skips insertion when an identical job is already queued, which
 * keeps a notification storm from producing a thousand rows. It is not
 * transactional and does not need to be: the per-folder lease means a duplicate
 * job costs one failed UPDATE and an immediate exit. Correctness lives in the
 * lease, not here.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailbox.php';
require_once __DIR__ . '/../log.php';

function ecw_queue_enqueue(int $mailboxId, ?int $folderId, string $jobType, string $reason = 'cron', int $delaySeconds = 0): ?int
{
    $mailbox = ecw_mailbox($mailboxId);
    if ($mailbox === null || in_array($mailbox['sync_status'], ['disconnected', 'needs_reauth'], true)) { return null; }
    $pdo   = ecw_db();
    $table = ecw_table('sync_queue');

    $st = $pdo->prepare(
        "SELECT id FROM `{$table}`
          WHERE mailbox_id = ? AND IFNULL(mail_folder_id,0) = IFNULL(?,0)
            AND job_type = ? AND status = 'queued' LIMIT 1"
    );
    $st->execute([$mailboxId, $folderId, $jobType]);
    if ($st->fetchColumn() !== false) {
        return null;
    }

    $st = $pdo->prepare(
        "INSERT INTO `{$table}` (mailbox_id, mail_folder_id, job_type, reason, status, available_at)
         VALUES (?, ?, ?, ?, 'queued', ?)"
    );
    $st->execute([
        $mailboxId, $folderId, $jobType, $reason,
        gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
    ]);
    return (int)$pdo->lastInsertId();
}

/** Claim one due job. The UPDATE ... WHERE status='queued' is the race guard. */
/**
 * @param int|null $onlyMailboxId tests claim only their own mailbox's jobs: the test
 *        database is the live one, and its oldest job may be a real mailbox's.
 */
function ecw_queue_claim(string $workerId, ?int $onlyMailboxId = null): ?array
{
    $pdo   = ecw_db();
    $table = ecw_table('sync_queue');

    // Newest first, like Outlook: of the jobs due, what the person did (a draft, read / unread,
    // a category) goes first, then new mail in Inbox and Sent, then the other folders' new mail,
    // then bodies and attachments, and the import of old mail last. Before, one line in order of
    // arrival: a long history page of Archive kept the Inbox's new mail waiting, worst on a slow line.
    $st = $pdo->prepare(
        "SELECT q.id FROM `{$table}` q
           LEFT JOIN `" . ecw_table('mail_folders') . "` qf ON qf.id = q.mail_folder_id
          WHERE q.status = 'queued' AND q.available_at <= ? AND q.mailbox_id IN (SELECT id FROM `" . ecw_table('mailboxes') . "` WHERE sync_status IN ('connected','syncing','temp_error'))"
        . ($onlyMailboxId !== null ? ' AND q.mailbox_id = ' . (int)$onlyMailboxId : '') . "
          ORDER BY CASE
                     WHEN q.job_type IN ('drafts_push', 'read_state', 'categories') THEN 0
                     WHEN q.job_type = 'delta' AND qf.folder_type IN ('inbox', 'sent') THEN 1
                     WHEN q.job_type IN ('delta', 'folders') THEN 2
                     -- the last two years, read newest first: what a new account shows first
                     WHEN q.job_type = 'backfill' AND q.reason <> 'older' THEN 2
                     -- completing emails (text, attachments) of an account whose own last two years are
                     -- in: as soon as the import pages, so another account's long import never keeps it
                     -- waiting (it did, for hours: Finishing the import, 20 emails still to complete)
                     -- and whatever else runs (a whole Inbox read again, an import), never more than two
                     -- minutes behind it: the rounds of a long read are queued again at once, all day
                     WHEN q.job_type = 'bodies' AND q.available_at <= ? THEN 1
                     WHEN q.job_type = 'bodies' AND NOT EXISTS (SELECT 1 FROM `{$table}` b WHERE b.mailbox_id = q.mailbox_id
                          AND b.job_type = 'backfill' AND b.reason <> 'older' AND b.status IN ('queued', 'running')) THEN 2
                     WHEN q.job_type = 'bodies' THEN 3
                     ELSE 4
                   END, q.available_at, q.id
          LIMIT 1"
    );
    $st->execute([ecw_now(), gmdate('Y-m-d H:i:s', time() - 120)]);
    $id = $st->fetchColumn();
    if ($id === false) {
        return null;
    }

    $st = $pdo->prepare(
        "UPDATE `{$table}`
            SET status = 'running', locked_by = ?, locked_at = ?, attempts = attempts + 1
          WHERE id = ? AND status = 'queued'"
    );
    $st->execute([$workerId, ecw_now(), $id]);
    if ($st->rowCount() !== 1) {
        return null;    // another worker took it between the SELECT and here
    }

    $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * The sync daemon starting (only one can run): the jobs the daemon before it left "running"
 * (stopped by an update, Docker restarting, the PC sleeping) are due again now. They kept the
 * Sync button turning for 15 minutes and were freed only after an hour.
 * @return int jobs taken back
 */
function ecw_queue_take_back_daemon_jobs(?int $onlyMailboxId = null): int
{
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('sync_queue') . "` SET status = 'queued', available_at = ?, locked_by = NULL, locked_at = NULL,
                             last_error = 'The sync was restarted before this job finished.' WHERE status = 'running' AND locked_by LIKE 'daemon:%'"
                             . ($onlyMailboxId !== null ? ' AND mailbox_id = ' . (int)$onlyMailboxId : ''));
    $st->execute([ecw_now()]);
    return $st->rowCount();
}

function ecw_queue_complete(int $jobId): void
{
    ecw_db()->prepare("UPDATE `" . ecw_table('sync_queue') . "`
        SET status = 'done', locked_by = NULL, locked_at = NULL, last_error = NULL WHERE id = ?")
        ->execute([$jobId]);
}

/**
 * Exponential backoff, capped. A job that has failed repeatedly stays visible
 * as 'failed' rather than disappearing — "never silently stop syncing".
 */
function ecw_queue_fail(int $jobId, string $error, int $maxAttempts = 6): void
{
    $pdo = ecw_db();
    $st  = $pdo->prepare('SELECT attempts FROM `' . ecw_table('sync_queue') . '` WHERE id = ?');
    $st->execute([$jobId]);
    $attempts = (int)($st->fetchColumn() ?: 1);

    $terminal = $attempts >= $maxAttempts;
    $delay    = min(3600, 30 * (2 ** max(0, $attempts - 1)));

    $pdo->prepare(
        'UPDATE `' . ecw_table('sync_queue') . '`
            SET status = ?, available_at = ?, locked_by = NULL, locked_at = NULL, last_error = ?
          WHERE id = ? AND status <> \'done\''
    )->execute([
        $terminal ? 'failed' : 'queued',
        gmdate('Y-m-d H:i:s', time() + $delay),
        substr(ecw_redact($error), 0, 2000),
        $jobId,
    ]);

    ecw_log("sync job {$jobId} " . ($terminal ? 'failed permanently' : "retrying in {$delay}s") . ": " . $error);
}

/** Housekeeping: completed jobs are noise after a day, failed ones after 30 days (System health reads the last day's). */
function ecw_queue_prune(int $olderThanDays = 1): int
{
    $st = ecw_db()->prepare(
        "DELETE FROM `" . ecw_table('sync_queue') . "` WHERE (status = 'done' AND updated_at < ?) OR (status = 'failed' AND updated_at < ?)"
    );
    $st->execute([gmdate('Y-m-d H:i:s', time() - $olderThanDays * 86400), gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    return $st->rowCount();
}

/** Recover local CLI workers that have exited, without stealing a live worker's job. */
function ecw_queue_recover_abandoned(): int
{
    if (PHP_SAPI !== 'cli' || !function_exists('posix_kill')) return 0;
    $table = ecw_table('sync_queue'); $pdo = ecw_db();
    $cutoff = gmdate('Y-m-d H:i:s', time() - 600);
    $st = $pdo->prepare("SELECT id,locked_by,locked_at,attempts FROM `{$table}` WHERE status='running' AND locked_at < ? ORDER BY locked_at LIMIT 100");
    $st->execute([$cutoff]); $count = 0;
    // A job on ANOTHER host cannot be checked by pid. In Docker every rebuild gives
    // the sync container a new hostname, so a job its old container held when it
    // stopped stayed "running" for ever, blocked its folder and kept the sync
    // spinner turning. No job runs for an hour: after that it is abandoned.
    $foreignCutoff = gmdate('Y-m-d H:i:s', time() - 3600);
    foreach ($st->fetchAll() as $row) {
        $parts = explode(':', (string)$row['locked_by']);
        $pid = array_pop($parts); $host = array_pop($parts);
        if ($host !== gethostname()) {
            if ((string)$row['locked_at'] >= $foreignCutoff) continue;   // maybe still working on that host
        } elseif (!ctype_digit((string)$pid) || (int)$pid < 1) {
            continue;
        } elseif (@posix_kill((int)$pid, 0) || posix_get_last_error() !== 3) {
            continue; // ESRCH only; EPERM means alive.
        }
        $update = $pdo->prepare("UPDATE `{$table}` SET status=?,available_at=?,locked_by=NULL,locked_at=NULL,last_error=? WHERE id=? AND status='running' AND locked_by=? AND locked_at=?");
        $update->execute([(int)$row['attempts'] >= 6 ? 'failed' : 'queued', ecw_now(),
            'Worker exited before completing this job.', $row['id'], $row['locked_by'], $row['locked_at']]);
        $count += $update->rowCount();
    }
    return $count;
}
