<?php declare(strict_types=1);
/**
 * Email Case Workspace — sync queue worker. CLI ONLY.
 *
 *   php bin/sync_worker.php [--max-jobs=25] [--once]
 *
 * Drains the queue: webhook-triggered delta rounds, folder discovery, the
 * newest-first history walk, body backfill. Safe to run several at once — the
 * queue claim and the per-folder lease both serialize at the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/sync/queue.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';
require_once __DIR__ . '/sync_jobs.php';

$opts     = getopt('', ['max-jobs::', 'once']);
$maxJobs  = (int)($opts['max-jobs'] ?? 25);
$workerId = gethostname() . ':' . getmypid();

ecw_queue_recover_abandoned();
$done = 0;
while ($done < $maxJobs) {
    $job = ecw_queue_claim($workerId);
    if ($job === null) {
        break;
    }

    $mailboxId = (int)$job['mailbox_id'];
    $folderId  = $job['mail_folder_id'] !== null ? (int)$job['mail_folder_id'] : null;
    $type      = (string)$job['job_type'];

    echo "job #{$job['id']} {$type} mailbox={$mailboxId} folder=" . ($folderId ?? '-') . " ... ";

    try {
        $result = ecw_run_sync_job($type, $mailboxId, $folderId);
        ecw_queue_complete((int)$job['id']);
        echo json_encode($result), "\n";
    } catch (Throwable $e) {
        ecw_queue_fail((int)$job['id'], $e->getMessage());
        echo 'FAILED: ', $e->getMessage(), "\n";
    }

    $done++;
    if (isset($opts['once'])) {
        break;
    }
}

echo "worker finished, {$done} job(s) handled\n";
