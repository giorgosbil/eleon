<?php declare(strict_types=1);
/**
 * Email Case Workspace — continuous synchronization. CLI ONLY.
 *
 *   php bin/sync_daemon.php [--interval=15] [--lifetime=3600] [--once]
 *
 * WHY A DAEMON AND NOT CRON
 * -------------------------
 * Before this existed, the Sync button in Settings only ENQUEUED work and
 * nothing ever drained the queue: jobs sat there for hours and mail simply
 * never arrived. Cron would fix that, but its floor is one minute, and a
 * mailbox that updates a minute after the fact does not feel live — you watch
 * the screen and nothing happens.
 *
 * This loop enqueues a delta round for every synced folder every
 * --interval seconds and drains the queue continuously, so new mail lands in
 * the database within roughly that interval of arriving. The browser polls the
 * local database separately (see app.js), which is what makes it appear without
 * anyone pressing anything.
 *
 * WHY IT EXITS ON PURPOSE
 * -----------------------
 * --lifetime bounds the run and then the process quits cleanly for systemd to
 * restart. A PHP process that lives for weeks accumulates whatever the slowest
 * leak in its dependency tree gives it, and a scheduled restart is far cheaper
 * to reason about than hunting that down. Restarting costs one connection.
 *
 * ONE AT A TIME
 * -------------
 * An exclusive flock means a second daemon exits immediately instead of
 * doubling every Graph call. The per-folder leases inside the sync already make
 * concurrent runs SAFE; this makes them unnecessary.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/sync/queue.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';
require_once dirname(__DIR__) . '/lib/sync/subscriptions.php';
require_once __DIR__ . '/sync_jobs.php';
require_once dirname(__DIR__) . '/lib/avatars.php';
require_once dirname(__DIR__) . '/lib/followups.php';
require_once dirname(__DIR__) . '/lib/modules.php';
require_once dirname(__DIR__) . '/lib/backup.php';
require_once dirname(__DIR__) . '/lib/compose.php';

$opts     = getopt('', ['interval::', 'lifetime::', 'once']);
$interval = max(5, (int)($opts['interval'] ?? 15));
$lifetime = max(60, (int)($opts['lifetime'] ?? 3600));
$once     = isset($opts['once']);

// --------------------------------------------------------------- single run
$lockFile = dirname(__DIR__) . '/logs/.sync_daemon.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another sync daemon is already running\n");
    exit(0);
}
// The one daemon from here on: its leases are marked "dm", and the last daemon's are freed
// now (it was stopped mid-task), instead of every folder it held waiting 5 minutes.
define('ECW_SYNC_DAEMON', true);
if (($freed = ecw_sync_free_daemon_leases()) > 0) ecw_log("freed {$freed} folder lease(s) left by the previous sync daemon");
// Its jobs too: left "running" when it was stopped (an update, Docker restarting, the PC
// sleeping), they kept the Sync button turning for 15 minutes and were freed only after an
// hour (another container's process cannot be checked). Only daemons run sync jobs here.
if (($taken = ecw_queue_take_back_daemon_jobs()) > 0) ecw_log("took back {$taken} job(s) the previous sync daemon left running");

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    // Finish the job in hand, then stop: killing mid-page would leave a lease
    // held until it ages out.
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

/** Body warming is background work; it must never crowd out delta rounds. */
const ECW_DAEMON_BODY_INTERVAL = 120;

$startedAt   = time();
$lastEnqueue = 0;
$lastBodies  = 0;
$lastHousekeeping = 0;
$lastFollowups = 0;
$workerId    = 'daemon:' . gethostname() . ':' . getmypid();

ecw_log("sync daemon started (interval {$interval}s, lifetime {$lifetime}s)");

ecw_queue_recover_abandoned();

require_once dirname(__DIR__) . '/lib/health.php';
while ($running) {
    $now = time();
    ecw_heartbeat('sync');   // Settings → System health; the worker alerts when this stops

    // 1. Ask every active mailbox what changed, on a timer.
    if ($now - $lastEnqueue >= $interval) {
        $lastEnqueue = $now;
        try {
            ecw_daemon_enqueue_rounds();
        } catch (Throwable $e) {
            ecw_log('daemon enqueue failed: ' . $e->getMessage());
        }
    }

    // 2. Drain whatever is due. A bounded batch so the enqueue timer above
    //    still gets a turn during a long historical backfill.
    $handled = 0;
    while ($running && $handled < 5) {
        $job = ecw_queue_claim($workerId);
        if ($job === null) {
            break;
        }
        $handled++;

        try {
            ecw_run_sync_job(
                (string)$job['job_type'],
                (int)$job['mailbox_id'],
                $job['mail_folder_id'] !== null ? (int)$job['mail_folder_id'] : null
            );
            ecw_queue_complete((int)$job['id']);
        } catch (Throwable $e) {
            ecw_queue_fail((int)$job['id'], $e->getMessage());
        }
        ecw_heartbeat('sync');
    }

    // 2b. Emails queued to send (Undo window over, or Send later time reached).
    try {
        ecw_drafts_send_due();
    } catch (Throwable $e) {
        ecw_log('daemon scheduled send failed: ' . $e->getMessage());
    }

    // 3. Warm message bodies, newest first, on a slower timer.
    //
    // Bulk sync stores metadata only, so without this every message shows a
    // spinner the first time it is opened. ecw_sync_bodies() takes the newest
    // unfetched batch and returns immediately once there are none left, so this
    // fills in the recent mail people actually read and then costs nothing.
    if ($now - $lastBodies >= ECW_DAEMON_BODY_INTERVAL) {
        $lastBodies = $now;
        try {
            foreach (ecw_daemon_active_mailboxes() as $mailboxId) {
                ecw_queue_enqueue($mailboxId, null, 'bodies', 'daemon');
            }
        } catch (Throwable $e) {
            ecw_log('daemon body enqueue failed: ' . $e->getMessage());
        }
    }

    // 3b. Reminders and snoozes every minute; linked Teams chats every two.
    if ($now - $lastFollowups >= 60) {
        $lastFollowups = $now;
        try {
            ecw_followups_run_due();
        } catch (Throwable $e) {
            ecw_log('daemon follow-ups failed: ' . $e->getMessage());
        }
        // One automatic backup a day (lib/backup.php; ECW_BACKUPS=0 turns it off).
        ecw_backup_due();
        // Something broke (or recovered): tell the admins (lib/health.php; at most every 5 minutes).
        try { ecw_health_alerts_due(); } catch (Throwable $e) { ecw_log('daemon health check failed: ' . $e->getMessage()); }
        // New mail → push to the owner's phone / computer (if they turned it on).
        try {
            require_once dirname(__DIR__) . '/lib/push.php';
            ecw_push_new_mail();
        } catch (Throwable $e) {
            ecw_log('daemon push failed: ' . $e->getMessage());
        }
        // Cases due today / overdue: tell Responsible and Handled by once.
        try {
            require_once dirname(__DIR__) . '/lib/case_extras.php';
            ecw_cases_due_notify();
        } catch (Throwable $e) {
            ecw_log('daemon due-date reminders failed: ' . $e->getMessage());
        }
        // Cases to look at again today (lib/next_actions.php): tell whoever does the next step, once.
        try {
            require_once dirname(__DIR__) . '/lib/next_actions.php';
            ecw_cases_review_notify();
        } catch (Throwable $e) {
            ecw_log('daemon look-again reminders failed: ' . $e->getMessage());
        }        // Checklist steps due today / overdue: tell their person once (lib/checklists.php).
        try {
            require_once dirname(__DIR__) . '/lib/checklists.php';
            ecw_checklist_due_notify();
        } catch (Throwable $e) {
            ecw_log('daemon checklist reminders failed: ' . $e->getMessage());
        }
        // Case categories waiting for Outlook / Gmail (e.g. after an upgrade): queue them.
        try {
            require_once dirname(__DIR__) . '/lib/sync/queue.php';
            foreach (ecw_db()->query('SELECT DISTINCT mailbox_id FROM `' . ecw_table('email_messages') . '` WHERE categories_push_pending BETWEEN 1 AND 3 LIMIT 50')->fetchAll(PDO::FETCH_COLUMN) as $mb) {
                ecw_queue_enqueue((int)$mb, null, 'categories', 'cron');
            }
        } catch (Throwable $e) {
            ecw_log('daemon categories check failed: ' . $e->getMessage());
        }
        // Modules' own periodic work (e.g. chats linked to cases that follow new messages); each decides how often.
        ecw_hook_run('sync.tick');
    }

    // 4. Subscription renewal and queue tidying, hourly.
    if ($now - $lastHousekeeping >= 3600) {
        $lastHousekeeping = $now;
        try {
            ecw_subscriptions_renew_due();
            ecw_avatars_refresh_due();
            ecw_queue_prune();
            ecw_queue_recover_abandoned();
        } catch (Throwable $e) {
            ecw_log('daemon housekeeping failed: ' . $e->getMessage());
        }
    }

    if ($once) {
        break;
    }
    if (time() - $startedAt >= $lifetime) {
        ecw_log('sync daemon reached its lifetime, exiting for a clean restart');
        break;
    }

    // Idle politely. A busy loop would spin the CPU between rounds.
    if ($handled === 0) {
        sleep(2);
    }
}

ecw_log('sync daemon stopped');
flock($lock, LOCK_UN);
fclose($lock);

/**
 * Queue a delta round per synced folder, for every mailbox worth syncing.
 *
 * ecw_queue_enqueue() already skips a job that is still queued, so a slow round
 * cannot pile up behind itself.
 */
function ecw_daemon_enqueue_rounds(): void
{
    foreach (ecw_daemon_active_mailboxes() as $mailboxId) {
        foreach (ecw_selected_folders($mailboxId) as $folder) {
            ecw_queue_enqueue($mailboxId, (int)$folder['id'], 'delta', 'daemon');
        }
    }
}

/** @return int[] mailboxes worth syncing right now */
function ecw_daemon_active_mailboxes(): array
{
    $ids = ecw_db()->query(
        'SELECT id FROM `' . ecw_table('mailboxes') . "`
          WHERE sync_status IN ('connected','syncing','temp_error')"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $ids);
}
