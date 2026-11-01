<?php declare(strict_types=1);
/**
 * Email Case Workspace — periodic safety sync. CLI ONLY.
 *
 *   php bin/sync_cron.php            every few minutes
 *   php bin/sync_cron.php --renew    subscription health, hourly is enough
 *
 * WHY THIS EXISTS EVEN THOUGH WEBHOOKS DO
 * ---------------------------------------
 * Notifications get delayed, deduplicated by the provider, and lost outright
 * when a subscription silently lapses. Delta reconciliation is the mechanism
 * that is actually correct, so it runs on a timer regardless. What this does
 * NOT do is poll the whole mailbox: it enqueues one delta job per selected
 * folder, and delta reads only what changed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/sync/queue.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';
require_once dirname(__DIR__) . '/lib/sync/subscriptions.php';

$opts  = getopt('', ['renew', 'notification-url::']);
$pdo   = ecw_db();

$mailboxes = $pdo->query(
    'SELECT id, sync_status FROM `' . ecw_table('mailboxes') . "`
      WHERE sync_status IN ('connected','syncing','temp_error')"
)->fetchAll();

$queued = 0;
foreach ($mailboxes as $mb) {
    $mailboxId = (int)$mb['id'];

    foreach (ecw_selected_folders($mailboxId) as $folder) {
        if (ecw_queue_enqueue($mailboxId, (int)$folder['id'], 'delta', 'cron') !== null) {
            $queued++;
        }
    }
    ecw_queue_enqueue($mailboxId, null, 'folders', 'cron');
    ecw_queue_enqueue($mailboxId, null, 'bodies', 'cron');
}

echo "queued {$queued} delta job(s) across " . count($mailboxes) . " mailbox(es)\n";

if (isset($opts['renew'])) {
    $results = ecw_subscriptions_renew_due();
    echo 'subscription renewal: ' . json_encode(array_count_values($results)) . "\n";

    // Anything the renewal pass gave up on is re-created here, otherwise an
    // expired subscription would never come back and the mailbox would quietly
    // fall back to timer-only syncing.
    $url = (string)($opts['notification-url'] ?? '');
    if ($url !== '') {
        foreach ($mailboxes as $mb) {
            foreach (ecw_selected_folders((int)$mb['id']) as $folder) {
                try {
                    ecw_subscription_ensure((int)$mb['id'], (int)$folder['id'], $url);
                } catch (Throwable $e) {
                    echo "  subscription for folder {$folder['id']} failed: ", $e->getMessage(), "\n";
                }
            }
        }
    }
}

$pruned = ecw_queue_prune();
echo "pruned {$pruned} completed job(s)\n";
