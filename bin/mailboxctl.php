<?php declare(strict_types=1);
/**
 * Email Case Workspace — mailbox operations. CLI ONLY.
 *
 *   php bin/mailboxctl.php --list
 *   php bin/mailboxctl.php --status=3
 *   php bin/mailboxctl.php --folders=3                 discover folders now
 *   php bin/mailboxctl.php --history=3 --pages=4       newest-first import
 *   php bin/mailboxctl.php --delta=3                   delta round, all selected folders
 *   php bin/mailboxctl.php --bodies=3
 *   php bin/mailboxctl.php --intake=3 [--name="Create Case"]
 *   php bin/mailboxctl.php --subscribe=3 --notification-url=https://...
 *   php bin/mailboxctl.php --disconnect=3
 *   php bin/mailboxctl.php --remove=3 --yes   delete the mailbox and ALL its synced mail
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';
require_once dirname(__DIR__) . '/lib/sync/subscriptions.php';

$opts = getopt('', [
    'list', 'status::', 'folders::', 'history::', 'delta::', 'bodies::',
    'intake::', 'subscribe::', 'disconnect::', 'remove::', 'yes', 'pages::', 'name::', 'notification-url::',
]);

$pdo = ecw_db();

if (isset($opts['list'])) {
    $rows = $pdo->query(
        'SELECT id, email_address, sync_status, status_message, last_sync_at,
                initial_sync_completed_at, historical_sync_complete
           FROM `' . ecw_table('mailboxes') . '` ORDER BY id'
    )->fetchAll();
    foreach ($rows as $r) {
        printf("#%-3d %-38s %-13s %s\n", $r['id'], $r['email_address'], $r['sync_status'], $r['status_message'] ?? '');
    }
    if ($rows === []) {
        echo "no mailboxes connected\n";
    }
    exit(0);
}

$mailboxId = (int)($opts['status'] ?? $opts['folders'] ?? $opts['history'] ?? $opts['delta']
    ?? $opts['bodies'] ?? $opts['intake'] ?? $opts['subscribe'] ?? $opts['disconnect'] ?? $opts['remove'] ?? 0);

if ($mailboxId <= 0) {
    fwrite(STDERR, "usage: see the docblock at the top of this file\n");
    exit(1);
}

if (isset($opts['status'])) {
    $mb = ecw_mailbox($mailboxId);
    if ($mb === null) {
        fwrite(STDERR, "mailbox {$mailboxId} not found\n");
        exit(1);
    }
    foreach (['email_address', 'sync_status', 'status_message', 'last_sync_at',
              'initial_sync_completed_at', 'history_synced_through', 'historical_sync_complete'] as $k) {
        printf("%-26s %s\n", $k, $mb[$k] ?? '-');
    }
    echo "\nfolders:\n";
    foreach (ecw_selected_folders($mailboxId) as $f) {
        printf("  #%-4d %-28s %-8s %s\n", $f['id'], $f['name'], $f['folder_type'],
            $f['last_sync_at'] ? 'synced ' . $f['last_sync_at'] : 'never synced');
    }
    echo "\nsubscriptions:\n";
    foreach (ecw_subscription_health($mailboxId) as $s) {
        printf("  %-12s expires in %6ds  silent for %s\n", $s['status'],
            $s['expires_in_seconds'], $s['silent_for_seconds'] ?? 'never notified');
    }
    exit(0);
}

if (isset($opts['folders']))    { echo json_encode(ecw_sync_folders($mailboxId)), "\n"; exit(0); }
if (isset($opts['bodies']))     { echo json_encode(ecw_sync_bodies($mailboxId)), "\n";  exit(0); }
if (isset($opts['disconnect'])) { ecw_mailbox_disconnect($mailboxId); echo "disconnected\n"; exit(0); }
if (isset($opts['remove'])) {
    if (!isset($opts['yes'])) { fwrite(STDERR, "This deletes the mailbox and all its synced mail. Add --yes to confirm.\n"); exit(1); }
    echo json_encode(ecw_mailbox_remove($mailboxId)), "\n";
    exit(0);
}

if (isset($opts['intake'])) {
    $folder = ecw_ensure_case_intake_folder($mailboxId, (string)($opts['name'] ?? 'Create Case'));
    echo "case intake folder #{$folder['id']} ({$folder['name']})\n";
    exit(0);
}

if (isset($opts['history'])) {
    $pages = (int)($opts['pages'] ?? ECW_INITIAL_IMPORT_PAGES);
    foreach (ecw_selected_folders($mailboxId) as $f) {
        echo "  {$f['name']}: ", json_encode(ecw_sync_history($mailboxId, (int)$f['id'], $pages)), "\n";
    }
    exit(0);
}

if (isset($opts['delta'])) {
    foreach (ecw_selected_folders($mailboxId) as $f) {
        echo "  {$f['name']}: ", json_encode(ecw_sync_folder_delta($mailboxId, (int)$f['id'])), "\n";
    }
    exit(0);
}

if (isset($opts['subscribe'])) {
    $url = (string)($opts['notification-url'] ?? '');
    if ($url === '') {
        fwrite(STDERR, "--subscribe needs --notification-url=https://...\n");
        exit(1);
    }
    foreach (ecw_selected_folders($mailboxId) as $f) {
        $sub = ecw_subscription_ensure($mailboxId, (int)$f['id'], $url);
        echo "  {$f['name']}: subscription {$sub['subscription_id']} until {$sub['expires_at']}\n";
    }
    exit(0);
}
