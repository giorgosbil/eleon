<?php declare(strict_types=1);
/**
 * Newest mail first, also on a slow line (lib/sync/queue.php, lib/sync/messages.php, GraphHttp):
 *   - the queue serves what the person did, then new mail of Inbox / Sent, then the other folders,
 *     then bodies, and the import of old mail last (not in order of arrival);
 *   - while Inbox is still in its first full read, its newest emails are fetched directly;
 *   - a delta page that times out is asked again in smaller pages;
 *   - a GET that breaks off is tried once more, a POST is not.
 *
 *   php tests/sync_priority_test.php
 *
 * A scripted Graph; runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/sync/queue.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Sync priority']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'prio-' . $org, 'email_address' => 'me@prio' . $org . '.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $folder = static fn(string $name, string $type) => $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'F-' . $type . $mb, 'name' => $name, 'folder_type' => $type, 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $inbox = $folder('Inbox', 'inbox'); $archive = $folder('Archive', 'archive');

    echo "\n=== the order of the queue ===\n";
    $past = static fn(int $s) => gmdate('Y-m-d H:i:s', time() - $s);
    $job = static fn(?int $f, string $type, string $reason, int $agoSeconds) => $ins('sync_queue', ['mailbox_id' => $mb, 'mail_folder_id' => $f, 'job_type' => $type, 'reason' => $reason, 'status' => 'queued', 'available_at' => $past($agoSeconds)]);
    // Queued in the "wrong" order: oldest first is the import of old mail.
    $older   = $job($archive, 'backfill', 'older', 600);
    $bodies  = $job(null, 'bodies', 'cron', 100);
    $archDel = $job($archive, 'delta', 'cron', 400);
    $recent  = $job($inbox, 'backfill', 'initial', 350);
    $inboxD  = $job($inbox, 'delta', 'cron', 300);
    $read    = $job(null, 'read_state', 'user', 10);
    $order = [];
    while (($c = ecw_queue_claim('w', $mb)) !== null) { $order[] = (int)$c['id']; ecw_queue_complete((int)$c['id']); }
    check($order === [$read, $inboxD, $archDel, $recent, $bodies, $older], 'what you did, Inbox new mail, other folders, the last two years, bodies, old mail last', json_encode($order));

    // A long read (the whole Inbox again, another account's import) is queued again at once, round
    // after round: completing the emails waits two minutes at most, never the whole day.
    $inboxD  = $job($inbox, 'delta', 'cron', 60);
    $waited  = $job(null, 'bodies', 'cron', 180);
    $read    = $job(null, 'read_state', 'user', 10);
    $order = [];
    while (($c = ecw_queue_claim('w', $mb)) !== null) { $order[] = (int)$c['id']; ecw_queue_complete((int)$c['id']); }
    check($order === [$read, $waited, $inboxD], 'completing emails that waited over two minutes goes next, before another round of reading', json_encode($order));

    echo "\n=== newest first while the Inbox is read in full; smaller pages on a slow line ===\n";
    $calls = [];
    GraphHttp::setTestSleep(static function (int $s): void {});
    GraphHttp::setTestTransport(static function (string $method, string $url, ?array $json, array $headers) use (&$calls): array {
        $calls[] = [$method, $url, implode(' ', $headers)];
        if (str_contains($url, '/messages/delta')) {
            if (str_contains(implode(' ', $headers), 'maxpagesize=100')) throw new RuntimeException('ecw: Graph GET transport error: Operation timed out after 90000 milliseconds');
            return ['status' => 200, 'body' => ['value' => [FakeGraph::message(['id' => 'OLD-1', 'subject' => 'An old one', 'receivedDateTime' => '2024-01-02T09:00:00Z'])],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=abc'], 'headers' => []];
        }
        if (str_contains($url, 'receivedDateTime+desc') || str_contains($url, 'receivedDateTime%20desc')) {
            return ['status' => 200, 'body' => ['value' => [FakeGraph::message(['id' => 'NEW-1', 'subject' => 'Arrived this morning', 'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z')]),
                FakeGraph::message(['id' => 'NEW-2', 'subject' => 'Arrived yesterday', 'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)])]], 'headers' => []];
        }
        throw new RuntimeException('no route for ' . $method . ' ' . $url);
    });
    $r = ecw_sync_folder_delta($mb, $inbox);
    $ids = $pdo->query('SELECT provider_message_id FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id = ' . $mb . ' ORDER BY provider_message_id')->fetchAll(PDO::FETCH_COLUMN);
    $firstCall = $calls[0][1] ?? '';
    check(($r['topup'] ?? 0) === 2 && str_contains($firstCall, 'receivedDateTime') && str_contains($firstCall, 'top=25'), 'the newest 25 of the Inbox are asked first, before the full read', $firstCall);
    check(!empty($r['slow']) && str_contains($calls[count($calls) - 1][2], 'maxpagesize=25'), 'the page that timed out is asked again in pages of 25', json_encode(array_column($calls, 2)));
    $deltaCalls = array_values(array_filter($calls, static fn($c) => str_contains($c[1], '/messages/delta')));
    check(count($deltaCalls) === 2, 'at once: not first the same big page again (it would only time out again)', (string)count($deltaCalls));
    check($ids === ['NEW-1', 'NEW-2', 'OLD-1'], 'the newest and the full read are all stored', json_encode($ids));
    $calls = [];
    $r2 = ecw_sync_folder_delta($mb, $inbox);
    check(($r2['topup'] ?? 0) === 0 && !array_filter($calls, static fn($c) => str_contains($c[1], 'receivedDateTime')), 'caught up (a delta token): no more top-up, delta brings new mail itself');
    GraphHttp::setTestTransport(null);

    echo "\n=== a restart ===\n";
    $old = ecw_sync_state_id($mb, $archive, 'delta');
    $tool = ecw_sync_state_id($mb, $archive, 'backfill');
    $pdo->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . '` SET run_lease_id = ?, run_lease_at = ? WHERE id = ?')->execute(['dm0123456789abcd', ecw_now(), $old]);
    $pdo->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . '` SET run_lease_id = ?, run_lease_at = ? WHERE id = ?')->execute(['0123456789abcdef', ecw_now(), $tool]);
    ecw_sync_free_daemon_leases();
    $lease = static fn(int $id) => $pdo->query('SELECT run_lease_id FROM `' . ecw_table('mailbox_sync_state') . '` WHERE id = ' . $id)->fetchColumn();
    check($lease($old) === null && ecw_sync_acquire_lease($old) !== null, 'a new sync daemon frees the folder the stopped one held, at once (not 5 minutes later)');
    check($lease($tool) === '0123456789abcdef', 'a lease of another run (the admin tool) is left alone');

    // The stopped daemon's job: running for ever, and the Sync button turning.
    $left = $job($inbox, 'folders', 'manual', 30);
    $pdo->prepare('UPDATE `' . ecw_table('sync_queue') . "` SET status = 'running', locked_by = 'daemon:old-container:42', locked_at = ? WHERE id = ?")->execute([ecw_now(), $left]);
    $mine = $job($archive, 'delta', 'cron', 30);
    $pdo->prepare('UPDATE `' . ecw_table('sync_queue') . "` SET status = 'running', locked_by = 'otherhost:77', locked_at = ? WHERE id = ?")->execute([ecw_now(), $mine]);
    require_once dirname(__DIR__) . '/lib/sync/activity.php';
    check(ecw_mailbox_sync_activity($mb)['requested'] === true, 'before: a job the stopped sync left "running" keeps the button turning');
    check(ecw_queue_take_back_daemon_jobs($mb) === 1, 'the new sync takes back the stopped one\'s job (and only a daemon\'s)');
    $status = $pdo->query('SELECT status FROM `' . ecw_table('sync_queue') . '` WHERE id = ' . $left)->fetchColumn();
    check($status === 'queued' && $pdo->query('SELECT status FROM `' . ecw_table('sync_queue') . '` WHERE id = ' . $mine)->fetchColumn() === 'running', 'it is due again at once; another runner\'s job is left alone');
    $pdo->prepare('DELETE FROM `' . ecw_table('sync_queue') . '` WHERE id IN (?, ?)')->execute([$left, $mine]);

    echo "\n=== up to date ===\n";
    $state = static function (int $f, ?string $at) use ($pdo, $mb): void {
        $id = ecw_sync_state_id($mb, $f, 'delta');
        $pdo->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . '` SET last_success_at = ? WHERE id = ?')->execute([$at, $id]);
    };
    $state($inbox, gmdate('Y-m-d H:i:s', time() - 20)); $state($archive, gmdate('Y-m-d H:i:s', time() - 40));
    $a = ecw_mailbox_sync_activity($mb);
    check($a['behind'] === [] && $a['checked_at'] === gmdate('Y-m-d H:i:s', time() - 40), 'every folder checked lately: up to date, as of the oldest check', json_encode([$a['checked_at'], $a['behind']]));
    $state($archive, gmdate('Y-m-d H:i:s', time() - 900));
    $a = ecw_mailbox_sync_activity($mb);
    check(count($a['behind']) === 1 && $a['behind'][0]['name'] === 'Archive', 'a folder not checked for 5 minutes is named as behind', json_encode($a['behind']));

    echo "\n=== a request that breaks off ===\n";
    $tries = 0;
    GraphHttp::setTestTransport(static function (string $method, string $url) use (&$tries): array {
        $tries++;
        if ($tries === 1) throw new RuntimeException('ecw: Graph ' . $method . ' transport error: Connection reset by peer');
        return ['status' => 200, 'body' => ['ok' => true], 'headers' => []];
    });
    $http = new GraphHttp(null);
    $res = $http->request('GET', '/me/messages/X');
    check($tries === 2 && ($res['body']['ok'] ?? false), 'a GET that broke off is asked once more');
    $tries = 0;
    $threw = false;
    try { $http->request('POST', '/me/sendMail', ['m' => 1]); } catch (RuntimeException $e) { $threw = true; }
    check($threw && $tries === 1, 'a POST (it may have arrived) is not sent twice');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    GraphHttp::setTestTransport(null);
    GraphHttp::setTestSleep(null);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
