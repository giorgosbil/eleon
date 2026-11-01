<?php declare(strict_types=1);
/**
 * Running the app as a team, for years: shared mailboxes and claiming,
 * handover and holiday cover, system health and alerts, off-site backups,
 * signed updates.
 *
 *   php tests/operations_test.php
 *
 * Runs inside one transaction that is always rolled back. Nothing reaches
 * the network: storage and mail are faked.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
putenv('ECW_PRIVATE_MAILBOXES=1');

require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/access.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/shared_mailboxes.php';
require_once dirname(__DIR__) . '/lib/handover.php';
require_once dirname(__DIR__) . '/lib/notifications.php';
require_once dirname(__DIR__) . '/lib/followups.php';
require_once dirname(__DIR__) . '/lib/draft_share.php';
require_once dirname(__DIR__) . '/lib/unread.php';
require_once dirname(__DIR__) . '/lib/health.php';
require_once dirname(__DIR__) . '/lib/offsite.php';
require_once dirname(__DIR__) . '/lib/updates.php';
require_once dirname(__DIR__) . '/lib/provider/MicrosoftGraphProvider.php';

$pass = 0; $fail = 0;
function group(string $t): void { echo "\n=== {$t} ===\n"; }
function check(bool $c, string $label): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$label}\n"; } else { $fail++; echo "  FAIL  {$label}\n"; } }

$pdo = ecw_db();
@ecw_session_start();
$pdo->beginTransaction();
try {
    $ins = function (string $t, array $row) use ($pdo): int {
        $c = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'Ops test']);
    $u = [];
    foreach (['boss' => 'admin', 'ann' => 'user', 'bob' => 'user', 'cat' => 'read_only', 'dan' => 'user'] as $n => $role) {
        $u[$n] = $ins('users', ['name' => ucfirst($n) . ' Ops', 'email' => $n . uniqid() . '@ops.test', 'password_hash' => 'x']);
        $ins('organization_users', ['organization_id' => $org, 'user_id' => $u[$n], 'role' => $role]);
    }
    $box = function (int $owner, string $addr, int $shared = 0, ?string $sharedAddress = null) use ($ins, $org): int {
        return $ins('mailboxes', ['organization_id' => $org, 'connected_by_user_id' => $owner, 'provider' => 'microsoft', 'email_address' => $addr,
            'sync_status' => 'connected', 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
            'snooze_in_mailbox' => 0, 'is_shared' => $shared, 'shared_address' => $sharedAddress]);
    };
    $annBox = $box($u['ann'], 'ann' . uniqid() . '@ops.test');
    $info = $box($u['boss'], 'info' . uniqid() . '@ops.test', 1, null);
    $ingest = function (int $mb, string $subject, string $from) {
        return ecw_ingest_message($mb, null, 'inbox', [
            'provider_message_id' => 'p' . bin2hex(random_bytes(6)), 'internet_message_id' => '<' . uniqid() . '@x>',
            'provider_conversation_id' => 'c' . uniqid(), 'subject' => $subject, 'from_email' => $from, 'from_name' => 'Customer',
            'to' => [], 'cc' => [], 'bcc' => [], 'received_at' => gmdate('Y-m-d H:i:s', time() - 600), 'sent_at' => gmdate('Y-m-d H:i:s', time() - 600),
            'is_read' => false, 'has_attachments' => false, 'references' => [],
        ]);
    };

    group('shared mailboxes: who sees them');
    check(ecw_access_mailbox($org, $u['bob'], $annBox) === null, 'a personal mailbox stays private');
    check(ecw_access_mailbox($org, $u['bob'], $info) !== null && ecw_access_mailbox($org, $u['cat'], $info) !== null, 'a shared mailbox with no member list: everyone sees it');
    $p = [$org];
    $visible = $pdo->prepare('SELECT mb.id FROM ecw_mailboxes mb WHERE mb.organization_id = ? AND ' . ecw_mailbox_visible_sql('mb', $u['bob'], $p));
    $visible->execute($p);
    $ids = array_map('intval', $visible->fetchAll(PDO::FETCH_COLUMN));
    check(in_array($info, $ids, true) && !in_array($annBox, $ids, true), 'the mailbox list rule (SQL) agrees');
    check(ecw_mailbox_share_save($org, $u['bob'], $info, true, [$u['ann']]) !== null, 'only its owner or an admin can change who sees it');
    check(ecw_mailbox_share_save($org, $u['boss'], $info, true, [$u['ann'], $u['bob']]) === null && ecw_mailbox_members($info) === [$u['ann'], $u['bob']], 'an admin limits it to Ann and Bob');
    check(ecw_access_mailbox($org, $u['dan'], $info) === null && ecw_access_mailbox($org, $u['ann'], $info) !== null, 'Dan no longer sees it, Ann does');
    check(in_array($info, ecw_unread_mailbox_ids($org, $u['ann']), true) && !in_array($info, ecw_unread_mailbox_ids($org, $u['dan']), true), 'unread counts follow the same rule');
    check(ecw_mailbox_audience($info) == [$u['boss'], $u['ann'], $u['bob']], 'its audience: the owner and the members');
    check(ecw_mailbox_share_save($org, $u['ann'], $annBox, true, []) === null && ecw_access_mailbox($org, $u['bob'], $annBox) !== null, 'Ann shares her own mailbox with the team');
    ecw_mailbox_share_save($org, $u['ann'], $annBox, false, []);
    check(ecw_access_mailbox($org, $u['bob'], $annBox) === null, 'and makes it private again');
    $msBox = $box($u['boss'], 'sales' . uniqid() . '@ops.test', 1, 'sales@ops.test');
    check(ecw_mailbox_share_save($org, $u['boss'], $msBox, false, []) !== null, 'a Microsoft 365 shared mailbox cannot be made private');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'mailbox.share'")->fetchColumn() >= 3, 'sharing changes are audited');

    group('shared mailboxes: Microsoft 365 shared mailbox in Graph');
    $own = new MicrosoftGraphProvider(['client_id' => 'x']);
    $sh = new MicrosoftGraphProvider(['client_id' => 'x', 'shared_address' => 'Info@Acme.test']);
    check($own->root() === '/me' && $sh->root() === '/users/Info%40Acme.test', 'a shared mailbox is reached under /users/{address}');
    check(!in_array(MicrosoftGraphProvider::SHARED_SCOPES[0], $own->scopes(), true) && in_array(MicrosoftGraphProvider::SHARED_SCOPES[1], $sh->scopes(), true), 'only the shared mailbox asks for the .Shared permissions');
    check(str_contains($sh->authorizationUrl('s', 'https://x/cb', 'v', 'n'), rawurlencode('Mail.ReadWrite.Shared')), 'and its sign-in asks for them');

    group('shared mailboxes: claiming conversations');
    $t1 = $ingest($info, 'Order question', 'buyer@cust.test')['thread_id'];
    $t2 = $ingest($info, 'Invoice copy', 'acc@cust.test')['thread_id'];
    $r = ecw_thread_claim($org, $u['ann'], $info, $t1, $u['ann']);
    check(($r['claimed']['id'] ?? 0) === $u['ann'], 'Ann claims a conversation');
    try { ecw_thread_claim($org, $u['ann'], $info, $t1, $u['dan']); check(false, 'cannot give it to someone who does not see the mailbox'); }
    catch (InvalidArgumentException $e) { check(true, 'cannot give it to someone who does not see the mailbox'); }
    ecw_notifications_mark_read($u['bob']);
    $r = ecw_thread_claim($org, $u['ann'], $info, $t1, $u['bob']);
    check(($r['claimed']['id'] ?? 0) === $u['bob'] && ecw_notifications_unread($u['bob']) === 1, 'she gives it to Bob, who is told');
    $list = ecw_thread_list($info, ['user_id' => $u['bob'], 'claim' => 'mine']);
    check(array_column($list['threads'], 'id') === [$t1] && ($list['threads'][0]['claimed']['id'] ?? 0) === $u['bob'], '"Mine" lists only Bob\'s, with the badge');
    check(array_column(ecw_thread_list($info, ['user_id' => $u['bob'], 'claim' => 'unclaimed'])['threads'], 'id') === [$t2], '"Unassigned" lists the other');
    check(array_column(ecw_thread_list($info, ['user_id' => $u['ann'], 'claim' => 'others'])['threads'], 'id') === [$t1], '"Others" for Ann shows Bob\'s');
    ecw_thread_claim_on_reply($t2, $u['ann']);
    ecw_thread_claim_on_reply($t1, $u['ann']);
    check((ecw_thread_claim_info($t2)['id'] ?? 0) === $u['ann'] && (ecw_thread_claim_info($t1)['id'] ?? 0) === $u['bob'], 'a reply claims an unclaimed conversation, never someone else\'s');
    check(ecw_thread_claim($org, $u['bob'], $info, $t1, null)['claimed'] === null, 'released back to unassigned');
    $tp = $ingest($annBox, 'Private', 'x@cust.test')['thread_id'];
    try { ecw_thread_claim($org, $u['ann'], $annBox, $tp, $u['ann']); check(false, 'personal mailboxes have no claiming'); }
    catch (InvalidArgumentException $e) { check(true, 'personal mailboxes have no claiming'); }
    $draft = ['organization_id' => $org, 'mailbox_id' => $info, 'user_id' => $u['bob'], 'shared_at' => null];
    check(ecw_draft_can_send($org, $u['bob'], $draft), 'members send from the shared mailbox');
    check(!ecw_draft_can_send($org, $u['dan'], ['user_id' => $u['dan']] + $draft), 'others do not');

    group('holiday cover');
    $case = ecw_case_create($org, 'Ann\'s customer', $u['ann']);
    ecw_case_sharing_save($org, $case, $u['ann'], ['visibility' => 'members', 'member_ids' => []]);
    $pdo->prepare('UPDATE ecw_cases SET owner_user_id = ? WHERE id = ?')->execute([$u['ann'], $case]);
    check(!ecw_case_visible($org, $u['bob'], $case), 'Bob cannot see Ann\'s members-only case');
    $today = gmdate('Y-m-d'); $later = gmdate('Y-m-d', time() + 7 * 86400);
    check(ecw_away_set($org, $u['ann'], $u['ann'], $today, $later, $u['ann']) !== null, 'nobody covers for themselves');
    check(ecw_away_set($org, $u['ann'], $u['ann'], $today, $later, $u['cat']) !== null, 'a read-only colleague cannot cover');
    check(ecw_away_set($org, $u['bob'], $u['ann'], $today, $later, $u['dan']) !== null, 'a colleague cannot set Ann\'s cover (only she or an admin)');
    check(ecw_away_set($org, $u['ann'], $u['ann'], $later, $today, $u['bob']) !== null, 'the last day must not be before the first');
    ecw_notifications_mark_read($u['bob']);
    check(ecw_away_set($org, $u['ann'], $u['ann'], $today, $later, $u['bob']) === null && ecw_notifications_unread($u['bob']) === 1, 'Ann is away for a week, Bob covers and is told');
    check(ecw_case_visible($org, $u['bob'], $case), 'while she is away, Bob sees her case');
    ecw_notifications_mark_read($u['bob']);
    ecw_notify($org, $u['ann'], $u['dan'], 'mention', 'Dan mentioned you', 'case.php?id=' . $case, $case);
    $copy = $pdo->query("SELECT text FROM ecw_notifications WHERE user_id = {$u['bob']} ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(ecw_notifications_unread($u['ann']) >= 1 && str_starts_with((string)$copy, 'For Ann Ops (away): Dan mentioned you'), 'her notifications also reach Bob, marked as for her');
    $pdo->prepare('UPDATE ecw_users SET away_from = ?, away_until = ? WHERE id = ?')->execute([gmdate('Y-m-d', time() - 9 * 86400), gmdate('Y-m-d', time() - 2 * 86400), $u['ann']]);
    check(!ecw_case_visible($org, $u['bob'], $case), 'once she is back, the cover ends by itself');
    ecw_away_clear($org, $u['ann'], $u['ann']);
    check(ecw_away_get($u['ann'])['until'] === null, 'and can be removed');

    group('handover');
    $c2 = ecw_case_create($org, 'Ann handles', $u['ann']);
    $pdo->prepare('UPDATE ecw_cases SET handler_user_id = ? WHERE id = ?')->execute([$u['ann'], $c2]);
    $closed = ecw_case_create($org, 'Old closed', $u['ann']);
    $pdo->prepare("UPDATE ecw_cases SET owner_user_id = ?, status = 'closed' WHERE id = ?")->execute([$u['ann'], $closed]);
    $ins('followups', ['organization_id' => $org, 'user_id' => $u['ann'], 'case_id' => $case, 'due_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'note' => 'Call back', 'created_at' => gmdate('Y-m-d H:i:s')]);
    ecw_thread_claim($org, $u['ann'], $info, $t2, $u['ann']);
    $prev = ecw_handover_preview($org, $u['ann']);
    check($prev['cases'] === 2 && $prev['reminders'] === 1 && $prev['claimed'] === 1 && $prev['mailboxes'] === 1, 'the preview counts what would move');
    try { ecw_handover($org, $u['bob'], $u['ann'], $u['dan'], false); check(false, 'only an admin hands over'); } catch (InvalidArgumentException $e) { check(true, 'only an admin hands over'); }
    try { ecw_handover($org, $u['boss'], $u['ann'], $u['cat'], false); check(false, 'not to a read-only colleague'); } catch (InvalidArgumentException $e) { check(true, 'not to a read-only colleague'); }
    $done = ecw_handover($org, $u['boss'], $u['ann'], $u['dan'], true);
    $field = fn(int $id, string $f) => (int)$pdo->query("SELECT {$f} FROM ecw_cases WHERE id = {$id}")->fetchColumn();
    check($done['cases'] === 2 && $field($case, 'owner_user_id') === $u['dan'] && $field($c2, 'handler_user_id') === $u['dan'], 'her open cases move to Dan');
    check($field($closed, 'owner_user_id') === $u['ann'], 'closed cases keep their history');
    check((int)$pdo->query("SELECT user_id FROM ecw_followups WHERE case_id = {$case}")->fetchColumn() === $u['dan'] && (ecw_thread_claim_info($t2)['id'] ?? 0) === $u['dan'], 'and her reminders and claimed conversations');
    check(ecw_case_visible($org, $u['dan'], $case), 'Dan sees the members-only case');
    check($done['mailboxes'] === 1 && ecw_access_mailbox($org, $u['dan'], $annBox) !== null && ecw_access_mailbox($org, $u['bob'], $annBox) === null, 'her mailbox is shared with Dan only');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_events WHERE case_id = {$case} AND event_type = 'CASE_ASSIGNED'")->fetchColumn() >= 1
        && (int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'user.handover'")->fetchColumn() === 1, 'it is in the case history and the audit log');

    group('system health');
    $pdo->prepare("INSERT INTO ecw_app_settings (name, value) VALUES ('alert_settings', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([json_encode(['email' => false, 'extra' => []])]);
    ecw_app_setting_set('heartbeat_sync', gmdate('Y-m-d H:i:s'));
    ecw_app_setting_set('heartbeat_worker', gmdate('Y-m-d H:i:s', time() - 3600));
    $pdo->prepare("UPDATE ecw_mailboxes SET sync_status = 'needs_reauth' WHERE id = ?")->execute([$msBox]);
    $checks = ecw_health_checks();
    $byKey = array_column($checks, null, 'key');
    check(($byKey['daemon.sync']['state'] ?? '') === 'ok' && ($byKey['daemon.worker']['state'] ?? '') === 'fail', 'a stopped worker shows as failing, a running sync as fine');
    check(($byKey['mailbox.' . $msBox]['state'] ?? '') === 'fail' && str_contains((string)$byKey['mailbox.' . $msBox]['fix'], 'Reconnect'), 'a mailbox whose sign-in expired fails, with how to fix it');
    check(isset($byKey['disk'], $byKey['backup'], $byKey['offsite'], $byKey['license'], $byKey['version']) && ecw_health_overall($checks) === 'fail', 'disk, backups, off-site, license and version are checked');
    check(ecw_alert_settings_save(['email' => '1', 'extra' => 'it@partner.test, nope']) !== null, 'alert addresses are checked');
    ecw_app_setting_set('health_state', null); ecw_app_setting_set('health_alerts_ran', null);
    ecw_notifications_mark_read($u['boss']);
    check(ecw_health_alerts_due(true) === 0 && ecw_notifications_unread($u['boss']) === 0, 'a fresh problem waits 10 minutes (a restart is not an emergency)');
    $st = json_decode((string)ecw_app_setting('health_state'), true);
    foreach ($st as $k => $v) $st[$k]['since'] = time() - 700;
    ecw_app_setting_set('health_state', json_encode($st));
    check(ecw_health_alerts_due(true) >= 2 && ecw_notifications_unread($u['boss']) >= 1, 'after 10 minutes the admins are told');
    $text = (string)$pdo->query("SELECT text FROM ecw_notifications WHERE user_id = {$u['boss']} AND type = 'system' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(str_contains($text, 'needs attention') && str_contains($text, 'worker'), 'the alert says what is wrong');
    $n = ecw_notifications_unread($u['boss']);
    check(ecw_health_alerts_due(true) === 0 && ecw_notifications_unread($u['boss']) === $n, 'not again while it lasts (until tomorrow)');
    ecw_app_setting_set('heartbeat_worker', gmdate('Y-m-d H:i:s'));
    $pdo->prepare("UPDATE ecw_mailboxes SET sync_status = 'connected', last_sync_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$msBox]);
    check(ecw_health_alerts_due(true) >= 2 && str_contains((string)$pdo->query("SELECT text FROM ecw_notifications WHERE user_id = {$u['boss']} AND type = 'system' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'fixed'), 'and told again when it is fixed');

    group('off-site backups');
    check(ecw_offsite_settings_save(['type' => 's3', 's3_endpoint' => 'https://s3.example.com/bucket', 's3_bucket' => 'b']) !== null, 'an S3 address with a path is refused');
    check(ecw_offsite_settings_save(['type' => 's3', 's3_endpoint' => 'http://s3.example.com', 's3_bucket' => 'company-backups', 's3_key' => 'k', 's3_secret' => 's']) !== null, 'plain http to the internet is refused');
    check(ecw_offsite_settings_save(['type' => 's3', 's3_endpoint' => 'https://s3.eu-central-1.amazonaws.com', 's3_region' => 'eu-central-1', 's3_bucket' => 'company-backups', 's3_prefix' => 'ecw', 's3_key' => 'AKIDEXAMPLE', 's3_secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'keep' => 2]) === null, 'Amazon S3 is set up');
    check(!str_contains((string)ecw_app_setting('offsite'), 'wJalrXUtnFEMI') && ecw_offsite_settings()['has_s3_secret'], 'the secret key is stored encrypted, never shown');
    check(ecw_offsite_settings_save(['type' => 's3', 's3_endpoint' => 'https://s3.eu-central-1.amazonaws.com', 's3_region' => 'eu-central-1', 's3_bucket' => 'company-backups', 's3_prefix' => 'ecw', 's3_key' => 'AKIDEXAMPLE', 's3_secret' => '', 'keep' => 2]) === null && ecw_offsite_settings()['has_s3_secret'], 'saving again without the secret keeps it');
    $calls = [];
    $GLOBALS['ecw_offsite_transport'] = function (string $method, string $url, array $headers, ?string $file) use (&$calls): void { $calls[] = compact('method', 'url', 'headers', 'file'); };
    $bdir = sys_get_temp_dir() . '/ecwbk' . bin2hex(random_bytes(3));
    mkdir($bdir);
    putenv('ECW_BACKUP_DIR=' . $bdir);
    $hadPw = ecw_app_setting('backup_password');
    ecw_app_setting_set('backup_password', null);
    file_put_contents($bdir . '/ecw-backup-20261001-020000-auto.zip', 'zip one');
    touch($bdir . '/ecw-backup-20261001-020000-auto.zip', time() - 300);
    ecw_app_setting_set('offsite_status', null); ecw_app_setting_set('offsite_files', null);
    check(!ecw_offsite_due() && $calls === [] && str_contains((string)ecw_offsite_status()['error'], 'backup password'), 'without a backup password nothing leaves the building');
    putenv('ECW_BACKUP_PASSWORD=test password 1234');
    ecw_app_setting_set('offsite_status', null);
    check(ecw_offsite_due() && count($calls) === 1 && $calls[0]['method'] === 'PUT', 'with one, the newest backup is uploaded');
    $auth = (string)($calls[0]['headers'][0] ?? '');
    check(str_starts_with($calls[0]['url'], 'https://company-backups.s3.eu-central-1.amazonaws.com/ecw/ecw-backup-20261001-020000-auto.zip')
        && preg_match('#^Authorization: AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/\d{8}/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $auth) === 1
        && in_array('x-amz-content-sha256: ' . hash('sha256', 'zip one'), $calls[0]['headers'], true), 'signed for S3 (Signature V4, the file\'s SHA-256)');
    check(!ecw_offsite_due() && count($calls) === 1, 'the same backup is not uploaded twice');
    foreach (['20261002-020000', '20261003-020000'] as $i => $stamp) { file_put_contents($bdir . "/ecw-backup-{$stamp}-auto.zip", 'zip ' . $i); touch($bdir . "/ecw-backup-{$stamp}-auto.zip", time() - 200 + $i * 100); ecw_offsite_due(); }
    $deletes = array_values(array_filter($calls, fn($c) => $c['method'] === 'DELETE'));
    check(count($deletes) === 1 && str_ends_with($deletes[0]['url'], 'ecw-backup-20261001-020000-auto.zip') && count(json_decode((string)ecw_app_setting('offsite_files'), true)) === 2, 'only the newest 2 are kept there');
    ecw_offsite_settings_save(['type' => 's3', 's3_endpoint' => 'http://minio:9000', 's3_region' => 'us-east-1', 's3_bucket' => 'nas-backups', 's3_key' => 'k', 's3_secret' => 's', 'keep' => 5]);
    $calls = []; ecw_offsite_test();
    check(($calls[0]['url'] ?? '') === 'http://minio:9000/nas-backups/ecw-offsite-test.txt' && ($calls[1]['method'] ?? '') === 'DELETE', 'MinIO on the own network: path-style address, and "Test" writes and removes a file');
    ecw_offsite_settings_save(['type' => 'webdav', 'dav_url' => 'https://cloud.example.com/remote.php/dav/files/ecw/backups', 'dav_user' => 'ecw', 'dav_pass' => 'app-password', 'keep' => 5]);
    $calls = []; ecw_offsite_test();
    check(($calls[0]['url'] ?? '') === 'https://cloud.example.com/remote.php/dav/files/ecw/backups/ecw-offsite-test.txt' && !str_contains((string)ecw_app_setting('offsite'), 'app-password'), 'WebDAV (Nextcloud) works the same, its password encrypted');
    $GLOBALS['ecw_offsite_transport'] = function () { throw new RuntimeException('The storage refused the file: AccessDenied.'); };
    check(str_contains((string)ecw_offsite_test(), 'AccessDenied'), 'a refusal is shown in plain words');
    unset($GLOBALS['ecw_offsite_transport']);
    putenv('ECW_BACKUP_PASSWORD'); putenv('ECW_BACKUP_DIR');
    array_map('unlink', glob($bdir . '/*') ?: []); @rmdir($bdir);
    if ($hadPw !== null) ecw_app_setting_set('backup_password', $hadPw);

    group('AI suggestions: the pressed button, and clean drafts');
    // The button number must not be sent as "action": the API route is ?action=…, which wins.
    $js = (string)file_get_contents(dirname(__DIR__) . '/web/assets/suggestions.js');
    $api = (string)file_get_contents(dirname(__DIR__) . '/web/api/index.php');
    check(str_contains($js, 'action_index: i') && str_contains($api, "ecw_param('action_index', -1)") && !preg_match("/suggestion_act[^\n]*ecw_param\('action',/", $api), 'the card sends which button was pressed under its own name');
    require_once dirname(__DIR__) . '/lib/agents.php';
    $html = ecw_ai_text_to_email_html("Subject: Re: quote\n\nDear Anna,\n\nThank you. To answer:\n*   **Lead time:** 3 weeks\n*   **Price:** [price]\n\n## Next steps\nI will call you <b>tomorrow</b>.\n\nKind regards");
    check(!str_contains($html, '**') && !str_contains($html, '## ') && !str_contains($html, 'Subject:'), 'markdown and a subject line never reach the email');
    check(str_contains($html, '<ul><li><strong>Lead time:</strong> 3 weeks</li><li><strong>Price:</strong> [price]</li></ul>'), 'lists and bold become real email formatting');
    check(str_contains($html, '&lt;b&gt;tomorrow&lt;/b&gt;') && str_contains($html, '<p>Dear Anna,</p>'), 'everything else is escaped text in paragraphs');
    $form = ['from_email' => 'noreply@ops.test', 'mailbox_address' => 'hello@ops.test', 'body_text' => "New demo request:\nName: Jerry He\nEmail: Jerry@Devpratech.com\nCompany: Foshan Devpra\nRole: Supplier"];
    check(!ecw_agent_is_bulk($form), 'a website form from our own domain is a lead, not bulk mail');
    check(ecw_agent_is_bulk(['from_email' => 'noreply@shop.example', 'mailbox_address' => 'hello@ops.test', 'body_text' => 'Your order shipped']), 'a no-reply from elsewhere still is');
    $formMsg = $ingest($info, 'New Zetisis Demo Request: Jerry He', 'noreply@ops.test');
    $pdo->prepare('UPDATE ecw_email_messages SET body_text = ? WHERE id = ?')->execute([$form['body_text'], $formMsg['message_id']]);
    $target = ecw_agent_reply_target((int)$formMsg['message_id']);
    check($target['email'] === 'jerry@devpratech.com' && $target['name'] === 'Jerry He', 'the reply goes to the person in the form, not to noreply@');
    $normal = $ingest($info, 'Question', 'buyer@cust.test');
    check(ecw_agent_reply_target((int)$normal['message_id'])['email'] === 'buyer@cust.test', 'a normal email is answered to its sender');

    group('checklists');
    require_once dirname(__DIR__) . '/lib/checklists.php';
    require_once dirname(__DIR__) . '/lib/case_extras.php';
    require_once dirname(__DIR__) . '/lib/timeline.php';
    $cc = ecw_case_create($org, 'Checklist case', $u['ann']);
    $pdo->prepare('UPDATE ecw_cases SET owner_user_id = ?, handler_user_id = ? WHERE id = ?')->execute([$u['ann'], $u['bob'], $cc]);
    try { ecw_checklist_add($org, $cc, $u['ann'], '   '); check(false, 'an empty step is refused'); } catch (InvalidArgumentException $e) { check(true, 'an empty step is refused'); }
    ecw_notifications_mark_read($u['bob']);
    $s1 = ecw_checklist_add($org, $cc, $u['ann'], 'Call the customer', $u['bob'], gmdate('Y-m-d'));
    $s2 = ecw_checklist_add($org, $cc, $u['ann'], 'Send the price list');
    $s3 = ecw_checklist_add($org, $cc, $u['ann'], 'Book the visit', $u['dan']);
    check(count(ecw_checklist($cc)) === 3 && ecw_notifications_unread($u['bob']) === 1, 'steps are added; the person given one is told');
    check(ecw_checklist_add($org, $cc, $u['ann'], 'Stranger step', 999999) > 0 && ecw_checklist($cc)[3]['assignee_id'] === null, 'a step cannot be given to someone outside the workspace');
    ecw_checklist_toggle($org, $s1, $u['bob'], true);
    $items = array_column(ecw_checklist($cc), null, 'id');
    check($items[$s1]['done'] && $items[$s1]['done_by'] === 'Bob Ops' && !$items[$s2]['done'], 'Bob ticks his step; it shows who did it');
    check(ecw_checklist_progress([$cc])[$cc] === ['done' => 1, 'total' => 4], 'progress for the case list: 1/4');
    ecw_checklist_update($org, $s2, $u['ann'], ['text' => 'Send the 2026 price list', 'due_date' => gmdate('Y-m-d', time() - 86400)]);
    $items = array_column(ecw_checklist($cc), null, 'id');
    check($items[$s2]['text'] === 'Send the 2026 price list' && $items[$s2]['late'], 'a step can be renamed and dated; past its date it shows as overdue');
    try { ecw_checklist_update($org, $s2, $u['ann'], ['due_date' => '31/12/2026']); check(false, 'a bad date is refused'); } catch (InvalidArgumentException $e) { check(true, 'a bad date is refused'); }
    ecw_checklist_reorder($org, $cc, [$s3, $s2, $s1]);
    check(array_slice(array_column(ecw_checklist($cc), 'id'), 0, 3) === [$s3, $s2, $s1], 'steps can be put in another order');
    $otherOrg = $ins('organizations', ['name' => 'Other org']);
    check(ecw_checklist_item($otherOrg, $s1) === null, 'another organization cannot reach the steps');
    ecw_notifications_mark_read($u['ann']); ecw_notifications_mark_read($u['dan']);
    $pdo->prepare('UPDATE ecw_case_checklist SET reminded_at = NULL WHERE case_id = ?')->execute([$cc]);
    $pdo->prepare('UPDATE ecw_case_checklist SET due_date = ? WHERE id = ?')->execute([gmdate('Y-m-d'), $s3]);
    ecw_checklist_due_notify();
    check(ecw_notifications_unread($u['dan']) === 1 && ecw_notifications_unread($u['ann']) >= 1, 'on the due day its person is reminded (no person: Responsible)');
    $n = ecw_notifications_unread($u['dan']);
    ecw_checklist_due_notify();
    check(ecw_notifications_unread($u['dan']) === $n, 'only once');
    ecw_checklist_delete($org, $s3, $u['ann']);
    $hist = array_column(ecw_case_history($cc), 'text');
    check(count(ecw_checklist($cc)) === 3 && in_array('ticked “Call the customer”', $hist, true) && in_array('removed the step “Book the visit”', $hist, true), 'the case history says who added, ticked and removed steps');

    group('case templates');
    try { ecw_case_template_save($org, $u['boss'], ['name' => '']); check(false, 'a template needs a name'); } catch (InvalidArgumentException $e) { check(true, 'a template needs a name'); }
    $grp = $ins('case_groups', ['organization_id' => $org, 'name' => 'Quality']);
    $tpl = ecw_case_template_save($org, $u['boss'], ['name' => 'Complaint', 'name_prefix' => 'Complaint · ', 'due_days' => 7, 'group_id' => $grp, 'tags' => 'Complaint, quality',
        'steps' => [['text' => 'Confirm receipt', 'days' => 0, 'who' => 'owner'], ['text' => 'Find the cause', 'days' => 3, 'who' => 'handler'], ['text' => '  ', 'days' => 1], ['text' => 'Close with the customer', 'days' => null, 'who' => 'nobody']]]);
    $t = ecw_case_template($org, $tpl);
    check(count($t['steps']) === 3 && $t['tags'] === ['complaint', 'quality'] && $t['steps'][2]['who'] === null, 'empty steps are dropped, tags cleaned, unknown "who" ignored');
    $fromTpl = ecw_case_create_from_template($org, $u['ann'], 'Bad batch 114', $tpl);
    $case = ecw_case_load($org, $fromTpl);
    $steps = ecw_checklist($fromTpl);
    $owner = (int)$pdo->query("SELECT owner_user_id FROM ecw_cases WHERE id = {$fromTpl}")->fetchColumn();
    check($case['name'] === 'Complaint · Bad batch 114' && count($steps) === 3, 'a case made from it gets the name prefix and the steps');
    check($steps[0]['due_date'] === gmdate('Y-m-d') && $steps[1]['due_date'] === gmdate('Y-m-d', time() + 3 * 86400) && $steps[2]['due_date'] === null, 'step dates count from today');
    check($owner === 0 || $steps[0]['assignee_id'] === $owner, 'the first step goes to the case\'s Responsible');
    check(ecw_case_tags($fromTpl) === ['complaint', 'quality'] && (int)$pdo->query("SELECT group_id FROM ecw_cases WHERE id = {$fromTpl}")->fetchColumn() === $grp
        && (string)$pdo->query("SELECT due_at FROM ecw_cases WHERE id = {$fromTpl}")->fetchColumn() === gmdate('Y-m-d', time() + 7 * 86400), 'and its tags, group and due date');
    $again = ecw_case_apply_template($org, $fromTpl, $tpl, $u['ann']);
    check($again['steps'] === 0 && count(ecw_checklist($fromTpl)) === 3 && $again['due'] === null, 'applying it again adds nothing twice and keeps the due date');
    $pdo->prepare('UPDATE ecw_cases SET handler_user_id = ? WHERE id = ?')->execute([$u['bob'], $cc]);
    $ap = ecw_case_apply_template($org, $cc, $tpl, $u['ann']);
    $bob = array_values(array_filter(ecw_checklist($cc), fn($i) => $i['text'] === 'Find the cause'))[0] ?? null;
    check($ap['steps'] === 3 && ($bob['assignee_id'] ?? 0) === $u['bob'], 'on an existing case its steps are added, "Handled by" steps to the handler');
    ecw_case_template_archive($org, $tpl);
    try { ecw_case_apply_template($org, $cc, $tpl, $u['ann']); check(false, 'an archived template cannot be used'); } catch (InvalidArgumentException $e) { check(true, 'an archived template cannot be used'); }
    check(ecw_case_templates($org) === [] && count(ecw_case_templates($org, true)) === 1, 'archived templates leave the pick list, stay in the admin list');
    try { ecw_case_apply_template($otherOrg, $cc, $tpl, $u['ann']); check(false, 'another organization cannot use it'); } catch (InvalidArgumentException $e) { check(true, 'another organization cannot use it'); }
    check(in_array('case_templates', ecw_packages()['free']['features'], true) && in_array('checklists', ecw_packages()['free']['features'], true), 'case templates and checklists are in every package');
    $starter = ecw_case_template_save($org, $u['boss'], ['name' => 'New customer'] + ECW_TEMPLATE_STARTERS['New customer']);
    check(count(ecw_case_template($org, $starter)['steps']) === 5, 'the examples can be started from');
    $apiSrc = (string)file_get_contents(dirname(__DIR__) . '/web/api/index.php');
    check(str_contains($apiSrc, "=== 'read_only'") && str_contains($apiSrc, 'ECW_READ_ONLY_ALLOWED') && !str_contains($apiSrc, "'case_checklist_add', 'case_checklist_toggle',\n    'ai_assist'"), 'read-only accounts are refused every change in the API');

    group('cases page: quick views, groups');
    $vo = $ins('organizations', ['name' => 'Views org']);
    $va = $ins('users', ['name' => 'Vera', 'email' => 'vera' . uniqid() . '@ops.test', 'password_hash' => 'x']);
    $vb = $ins('users', ['name' => 'Walt', 'email' => 'walt' . uniqid() . '@ops.test', 'password_hash' => 'x']);
    foreach ([$va, $vb] as $vu) $ins('organization_users', ['organization_id' => $vo, 'user_id' => $vu, 'role' => 'user']);
    $mk = function (string $name, ?int $owner, ?int $handler, ?string $due, string $status = 'open') use ($pdo, $vo, $va): int {
        $id = ecw_case_create($vo, $name, $va);
        $pdo->prepare('UPDATE ecw_cases SET owner_user_id = ?, handler_user_id = ?, due_at = ?, status = ? WHERE id = ?')->execute([$owner, $handler, $due, $status, $id]);
        return $id;
    };
    $cMine = $mk('Mine', $va, null, gmdate('Y-m-d', time() - 86400));
    $cHandled = $mk('I handle it', $vb, $va, gmdate('Y-m-d', time() + 3 * 86400), 'waiting');
    $cFree = $mk('Nobody', null, null, null);
    $cClosed = $mk('Closed one', $va, null, gmdate('Y-m-d', time() - 9 * 86400), 'closed');
    $cLater = $mk('Later', $vb, null, gmdate('Y-m-d', time() + 30 * 86400));
    $ids = fn(string $view) => array_column(ecw_case_list($vo, ['user_id' => $va, 'view' => $view, 'status' => 'all'])['cases'], 'id');
    $sorted = function (array $a) { sort($a); return $a; };
    check($sorted($ids('mine')) === $sorted([$cMine, $cHandled]), 'My cases: where I am Responsible or Handled by, never closed ones');
    check($ids('unassigned') === [$cFree], 'Unassigned: nobody on it');
    check($ids('overdue') === [$cMine], 'Overdue: past the due date, not closed');
    check($ids('week') === [$cHandled], 'Due this week: the next 7 days');
    check(ecw_case_view_counts($vo, $va) === ['all' => 5, 'open' => 3, 'mine' => 2, 'unassigned' => 1, 'overdue' => 1, 'week' => 1, 'supplier' => 0, 'review' => 0, 'nonext' => 4, 'waiting' => 1], 'the counts on the chips agree (All includes closed ones)');
    $row = array_values(array_filter(ecw_case_list($vo, ['user_id' => $va, 'status' => 'all'])['cases'], fn($c) => $c['id'] === $cHandled))[0];
    check($row['owner_name'] === 'Walt' && $row['handler_name'] === 'Vera' && $row['handler_user_id'] === $va, 'the list carries Responsible and Handled by');
    $grpA = $ins('case_groups', ['organization_id' => $vo, 'name' => 'Support', 'auto_assign' => 1]);
    $ins('case_group_members', ['group_id' => $grpA, 'user_id' => $vb]);
    check(ecw_case_set_group($vo, $cFree, $grpA, $va) && (int)$pdo->query("SELECT group_id FROM ecw_cases WHERE id = {$cFree}")->fetchColumn() === $grpA, 'a case moves to a group');
    check((int)$pdo->query("SELECT owner_user_id FROM ecw_cases WHERE id = {$cFree}")->fetchColumn() === $vb, 'a group that hands out cases gives it a Responsible');
    try { ecw_case_set_group($vo, $cFree, $grp, $va); check(false, 'not to another organization\'s group'); } catch (InvalidArgumentException $e) { check(true, 'not to another organization\'s group'); }
    check(ecw_case_set_group($vo, $cFree, null, $va) && $pdo->query("SELECT group_id FROM ecw_cases WHERE id = {$cFree}")->fetchColumn() === null, 'and out of a group');
    check(!ecw_case_set_group($org, $cFree, null, $u['ann']), 'another organization cannot move it');
    check(ecw_case_template_name(['name' => 'Complaint', 'name_prefix' => 'Complaint ·'], 'Late delivery') === 'Complaint · Late delivery'
        && ecw_case_template_name(['name' => 'Complaint', 'name_prefix' => 'Complaint ·'], 'Complaint · Late') === 'Complaint · Late'
        && ecw_case_template_name(['name' => 'Complaint', 'name_prefix' => null], '') === 'Complaint', 'template names: prefix once, the template\'s name when empty');

    group('next steps: what happens next on a case');
    require_once dirname(__DIR__) . '/lib/next_actions.php';
    require_once dirname(__DIR__) . '/lib/timeline.php';
    require_once dirname(__DIR__) . '/lib/case_extras.php';
    require_once dirname(__DIR__) . '/lib/agents.php';
    check(ecw_case_next_set($vo, $cLater, ['next_action' => '  Chase   the RMA ', 'next_user_id' => $vb, 'waiting_on' => 'supplier', 'review_on' => gmdate('Y-m-d')], $va), 'a person sets the next step');
    $nx = ecw_case_next_get($cLater);
    check($nx['next_action'] === 'Chase the RMA' && $nx['next_user_id'] === $vb && $nx['waiting_on'] === 'supplier' && $nx['waiting_since'] !== null && $nx['by'] === 'user' && !$nx['can_undo'], 'it is stored tidily, with since when it waits');
    try { ecw_case_next_set($vo, $cLater, ['waiting_on' => 'martians'], $va); check(false, 'only known "waiting on" values'); } catch (InvalidArgumentException $e) { check(true, 'only known "waiting on" values'); }
    try { ecw_case_next_set($vo, $cLater, ['next_user_id' => $u['ann']], $va); check(false, 'only people of the workspace do it'); } catch (InvalidArgumentException $e) { check(true, 'only people of the workspace do it'); }
    check($ids('supplier') === [$cLater] && $ids('review') === [$cLater] && !in_array($cLater, $ids('nonext'), true), 'quick views: Waiting on supplier, Look at today, No next step');
    $row = array_values(array_filter(ecw_case_list($vo, ['user_id' => $va, 'status' => 'all'])['cases'], fn($c) => $c['id'] === $cLater))[0];
    check($row['next']['action'] === 'Chase the RMA' && $row['next']['waiting_on'] === 'supplier' && $row['next']['user_name'] === 'Walt', 'the case list carries it');
    check(str_contains(implode(' | ', array_column(ecw_case_history($cLater), 'text')), 'next step “Chase the RMA”; waiting on supplier'), 'the history says what changed');

    check(ecw_case_ref_add($cLater, 'serial', 'SN 44-71', $va) !== null && ecw_case_ref_add($cLater, 'serial', 'sn4471', $va) === null, 'a reference is kept once, spaces and dashes aside');
    $find = fn(string $q) => array_column(ecw_case_list($vo, ['user_id' => $va, 'status' => 'all', 'q' => $q])['cases'], 'id');
    check($find('4471') === [$cLater] && $find('SN-4471') === [$cLater], 'searching the case list finds a case by its reference');
    $ref = ecw_case_refs($cLater)[0];
    check($ref['label'] === 'Serial number' && ecw_case_ref_delete($cLater, $ref['id'], $va) && ecw_case_refs($cLater) === [], 'and it can be removed');

    // The rule: an email to the customer or the supplier sets who the case waits on.
    $vbox = $ins('mailboxes', ['organization_id' => $vo, 'connected_by_user_id' => $va, 'provider' => 'microsoft', 'email_address' => 'vera' . uniqid() . '@ops.test',
        'sync_status' => 'connected', 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'snooze_in_mailbox' => 0]);
    $vaddr = (string)$pdo->query("SELECT email_address FROM ecw_mailboxes WHERE id = {$vbox}")->fetchColumn();
    $mail = function (string $conv, string $folder, string $from, int $ago = 600) use ($vbox) {
        return ecw_ingest_message($vbox, null, $folder, [
            'provider_message_id' => 'p' . bin2hex(random_bytes(6)), 'internet_message_id' => '<' . uniqid() . '@x>', 'provider_conversation_id' => $conv,
            'subject' => 'Broken unit', 'from_email' => $from, 'from_name' => 'X', 'to' => [], 'cc' => [], 'bcc' => [],
            'received_at' => gmdate('Y-m-d H:i:s', time() - $ago), 'sent_at' => gmdate('Y-m-d H:i:s', time() - $ago), 'is_read' => true, 'has_attachments' => false, 'references' => [],
        ]);
    };
    $cRule = ecw_case_create($vo, 'Broken unit', $va);
    $custConv = 'cust' . uniqid(); $supConv = 'sup' . uniqid();
    $tc = $mail($custConv, 'inbox', 'buyer@cust.test', 7200)['thread_id'];
    ecw_case_link_thread($cRule, $tc, null, $va);
    $mail($custConv, 'sent', $vaddr);
    $nx = ecw_case_next_get($cRule);
    check($nx['waiting_on'] === 'customer' && $nx['by'] === 'rule' && $nx['review_on'] !== null, 'we answer the customer (the case’s first conversation): waiting on the customer, with a look-again date');
    $mail($custConv, 'inbox', 'buyer@cust.test');
    check(ecw_case_next_get($cRule)['waiting_on'] === 'us', 'the customer answers: our turn');
    $ts = $mail($supConv, 'sent', $vaddr, 7200)['thread_id'];
    ecw_case_link_thread($cRule, $ts, null, $va);
    check(ecw_case_thread_role($cRule, $ts) === null, 'a second conversation has no side until someone says');
    ecw_case_thread_role_set($cRule, $ts, 'supplier', $va);
    $mail($supConv, 'sent', $vaddr);
    check(ecw_case_next_get($cRule)['waiting_on'] === 'supplier', 'we write to the supplier: waiting on the supplier');
    ecw_case_next_set($vo, $cRule, ['waiting_on' => 'internal'], $va);
    $mail($supConv, 'inbox', 'rma@maker.test', 5 * 86400);
    check(ecw_case_next_get($cRule)['waiting_on'] === 'internal', 'old mail being synced changes nothing');
    check(ecw_case_columns($cRule)[1]['party_role'] === 'supplier', 'the case page shows whose conversation it is');

    // The AI's answer (the agent itself needs a model; this is what it applies).
    ecw_case_next_set($vo, $cRule, ['next_action' => 'Ring them', 'waiting_on' => 'us'], $va);
    $msgs = array_map('intval', $pdo->query("SELECT m.id FROM ecw_email_messages m JOIN ecw_case_threads ct ON ct.email_thread_id = m.email_thread_id WHERE ct.case_id = {$cRule}")->fetchAll(PDO::FETCH_COLUMN));
    $r = ecw_case_next_apply_ai($vo, $cRule, ['next_action' => 'Chase the supplier for the RMA', 'waiting_on' => 'supplier', 'review_on' => '1999-01-01', 'source' => 'M' . $msgs[0],
        'references' => [['kind' => 'supplier_ticket', 'value' => 'RMA-889', 'source' => 'M' . $msgs[0]], ['kind' => 'serial', 'value' => '  '], ['kind' => 'bogus', 'value' => 'X1']],
        'thread_roles' => ['T' . $tc => 'customer', 'T' . $ts => 'internal']], gmdate('Y-m-d H:i:s', time() - 3600), $msgs);
    check($r['next'] === false && $r['refs'] === 2 && ecw_case_next_get($cRule)['next_action'] === 'Ring them', 'a person’s choice made after the newest email stays; references are still added');
    $r = ecw_case_next_apply_ai($vo, $cRule, ['next_action' => 'Chase the supplier for the RMA', 'waiting_on' => 'supplier', 'review_on' => '1999-01-01', 'source' => 'M' . $msgs[0]], gmdate('Y-m-d H:i:s', time() + 60), $msgs);
    $nx = ecw_case_next_get($cRule);
    check($r['next'] && $nx['by'] === 'ai' && $nx['next_action'] === 'Chase the supplier for the RMA' && $nx['source']['message_id'] === $msgs[0] && $nx['can_undo'], 'after a newer email the AI fills it in, with the email it read');
    check($nx['review_on'] !== null && $nx['review_on'] > gmdate('Y-m-d'), 'a date in the past is not used: waiting on someone gets a look-again day ahead');
    check(array_column($nx['refs'], 'value') === ['RMA-889', 'X1'] && $nx['refs'][1]['kind'] === 'other' && $nx['refs'][0]['source'] === 'ai', 'only real references, an unknown kind kept as "Reference"');
    check(ecw_case_thread_role($cRule, $ts) === 'supplier' && ecw_case_thread_role($cRule, $tc) === 'customer', 'the AI marks only conversations nobody marked');
    check(ecw_case_next_undo($vo, $cRule, $va) && ecw_case_next_get($cRule)['next_action'] === 'Ring them' && !ecw_case_next_undo($vo, $cRule, $va), 'Undo puts back what the AI replaced, once');

    $pdo->exec('DELETE FROM ecw_notifications WHERE user_id = ' . (int)$vb);
    $pdo->prepare('UPDATE ecw_cases SET review_notified_at = NULL, review_on = ? WHERE id = ?')->execute([gmdate('Y-m-d'), $cLater]);
    ecw_cases_review_notify();
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$vb} AND type = 'review' AND case_id = {$cLater}")->fetchColumn() === 1, 'on the look-again day whoever does the next step is told');
    ecw_cases_review_notify();
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$vb} AND type = 'review'")->fetchColumn() === 1, 'once');
    check(isset(ecw_feature_catalog()['next_actions']) && in_array('next_actions', ecw_packages()['free']['features'], true) && in_array('next_actions', ECW_PROFILES['standard'][2], true), 'in every package and in the Standard view, with its own switch');
    check(ECW_AGENTS['next_step'][3] === true, 'the AI that fills it in is one of the agents (Business, with AI)');

    group('emails find their case by themselves');
    require_once dirname(__DIR__) . '/lib/automation.php';
    $cA = ecw_case_create($vo, 'Pump failure', $va);
    $cB = ecw_case_create($vo, 'Valve claim', $va);
    $numA = (string)$pdo->query("SELECT case_number FROM ecw_cases WHERE id = {$cA}")->fetchColumn();
    $numB = (string)$pdo->query("SELECT case_number FROM ecw_cases WHERE id = {$cB}")->fetchColumn();
    ecw_case_ref_add($cA, 'supplier_ticket', 'RMA-5512', $va);
    ecw_case_ref_add($cB, 'serial', '4471', $va);          // a bare short number: could be a price, never matched
    ecw_case_ref_add($cB, 'claim', 'CL 2026/77', $va);
    $th = fn() => $mail('auto' . uniqid(), 'inbox', 'x@maker.test')['thread_id'];
    $t1 = $th();
    check(($r = ecw_automation_link_auto($vo, $va, $t1, 'Update', "Hello,\nabout your case {$numA}: parts shipped.")) && $r['case_id'] === $cA && ecw_case_for_thread($t1)['id'] == $cA, 'a case number in the text links the email');
    check(str_contains(implode(' | ', array_column(ecw_case_history($cA), 'text')), 'added a conversation by itself: it mentions the case number ' . $numA), 'and the case says why');
    $t2 = $th();
    check(ecw_automation_link_auto($vo, $va, $t2, 'Two cases', "See {$numA} and {$numB}") === null && ecw_case_for_thread($t2) === null, 'two different cases in one email: a person decides');
    $t3 = $th();
    check(($r = ecw_automation_link_auto($vo, $va, $t3, 'Re: rma 5512 approved', '')) && $r['case_id'] === $cA && $r['reason'] === 'the reference RMA-5512', 'a reference number links it, written another way (rma 5512)');
    $t4 = $th();
    check(ecw_automation_link_auto($vo, $va, $t4, 'RMA-55120', 'Total 4471 EUR') === null, 'not inside a longer number, and not a bare short number');
    $t5 = $th();
    check(($r = ecw_automation_link_auto($vo, $va, $t5, 'Claim', 'Our claim CL-2026-77 is accepted')) && $r['case_id'] === $cB, 'a claim number with other separators');
    $t6 = $th();
    check(ecw_automation_link_auto($vo, $va, $t6, 'Both', 'RMA-5512 and CL 2026/77') === null, 'references of two different cases: left alone');
    $t7 = $th();
    $pdo->prepare("UPDATE ecw_cases SET visibility = 'members' WHERE id = ?")->execute([$cA]);
    check(ecw_automation_link_auto($vo, $vb, $t7, 'Re: RMA-5512', '') === null, 'not into a case the mailbox owner cannot see');
    $pdo->prepare("UPDATE ecw_cases SET visibility = 'organization' WHERE id = ?")->execute([$cA]);

    group('search lands on the email that matches');
    $sc = 'sconv' . uniqid();
    $m1 = $mail($sc, 'inbox', 'a@cust.test', 9000);
    $m2 = $mail($sc, 'inbox', 'b@cust.test', 3000);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = 'The pump PX-77 is broken' WHERE id = ?")->execute([$m1['message_id']]);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = 'Thanks, we look into it' WHERE id = ?")->execute([$m2['message_id']]);
    $ins('email_attachments', ['email_message_id' => $m2['message_id'], 'filename' => 'report.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'is_inline' => 0, 'text_content' => 'Inspection of pump PX-77: bearing worn']);
    $hit = ecw_search_in_thread((int)$m1['thread_id'], '"PX-77"');
    check($hit['message_ids'] === [(int)$m1['message_id'], (int)$m2['message_id']], 'the emails that mention it (in the text, or in an attached file)');
    check(array_values($hit['attachments']) === ['PX-77'], 'and the file that holds the words is marked');
    check(ecw_search_in_thread((int)$m1['thread_id'], 'from:b@cust.test')['message_ids'] === [(int)$m2['message_id']], 'filters work inside the conversation too');

    group('error reporting');
    $pdo->exec('DELETE FROM ecw_error_reports');
    // A one-off command or a test is not the app: its errors are never reported (nor emailed).
    ecw_error_report('daemon', 'PDOException', 'a typo in a one-off query', 'Command line code:1');
    check((int)$pdo->query('SELECT COUNT(*) FROM ecw_error_reports')->fetchColumn() === 0, 'errors of one-off commands and tests are not reported to the admins');
    $GLOBALS['ecw_errors_record_in_tests'] = true;
    ecw_error_report('server', 'RuntimeException', 'Case 12 could not be saved', 'lib/x.php:10');
    ecw_error_report('server', 'RuntimeException', 'Case 99 could not be saved', 'lib/x.php:12');
    ecw_error_report('browser', 'TypeError', "Cannot read 'foo' of null with access_token=abc123secret", 'assets/app.js:5');
    $rows = $pdo->query('SELECT source, count, message FROM ecw_error_reports ORDER BY id')->fetchAll();
    check(count($rows) === 2 && (int)$rows[0]['count'] === 2, 'the same error with another number is one problem, counted twice');
    check(!str_contains($rows[1]['message'], 'abc123secret'), 'secrets are removed before anything is stored');
    $e = ecw_errors_recent();
    check($e['kinds'] === 2 && $e['times'] === 3 && $e['new_kinds'] === 2, 'System health counts them');
    $hc = array_values(array_filter(ecw_health_checks(), fn($c) => $c['key'] === 'errors'))[0];
    check($hc['state'] === 'warn' && str_contains($hc['detail'], '2 kinds of error'), 'and shows them as something to look at');
    $id = (int)$pdo->query("SELECT id FROM ecw_error_reports WHERE source = 'server'")->fetchColumn();
    $pdo->exec("UPDATE ecw_error_reports SET notified_at = UTC_TIMESTAMP() WHERE id = {$id}");
    check(ecw_error_resolve($id, $u['boss']) && count(ecw_errors_open()) === 1, 'Mark as fixed takes it off the list');
    ecw_error_report('server', 'RuntimeException', 'Case 7 could not be saved', 'lib/x.php:10');
    $back = $pdo->query("SELECT resolved_at, notified_at, count FROM ecw_error_reports WHERE id = {$id}")->fetch();
    check($back['resolved_at'] === null && $back['notified_at'] === null && (int)$back['count'] === 3, 'it comes back when it happens again, and admins are told again');
    $GLOBALS['ecw_errors_record_in_tests'] = false;
    ecw_error_report('server', 'X', 'not recorded', '');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_error_reports WHERE message = 'not recorded'")->fetchColumn() === 0, 'the test suite itself records nothing');

    group('export');
    require_once dirname(__DIR__) . '/lib/export.php';
    $eml = ecw_export_eml(['from_email' => 'maria@cust.test', 'from_name' => 'Μαρία', 'to_json' => json_encode([['email' => 'ops@ops.test']]), 'subject' => 'Αντλία PX-77',
        'sent_at' => '2026-10-01 09:00:00', 'internet_message_id' => '<a@b>', 'body_text' => 'Γεια σας', 'body_html' => '<p>Γεια σας</p>'], [['report.pdf', 'application/pdf', '%PDF-1.4']]);
    check(str_contains($eml, 'From: =?UTF-8?B?' . base64_encode('Μαρία') . '?= <maria@cust.test>') && str_contains($eml, 'Date: Thu, 01 Oct 2026 09:00:00 +0000') && str_contains($eml, 'multipart/mixed')
        && str_contains($eml, base64_encode('%PDF-1.4')) && str_contains($eml, 'Message-ID: <a@b>'), 'an email becomes a standard .eml file, Greek names and attachments included');
    ecw_case_link_thread($cB, (int)$m1['thread_id'], null, $va);
    $zp = tempnam(sys_get_temp_dir(), 'ectest');
    $z = new ZipArchive(); $z->open($zp, ZipArchive::OVERWRITE);
    $res = ecw_export_case_into($z, 'x/', $vo, $cB, false);
    $z->close(); $z->open($zp);
    $names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    $convPages = array_values(preg_grep('#^x/conversations/\d+ [^/]+/conversation\.html$#', $names));
    check($res['emails'] >= 3 && in_array('x/index.html', $names, true) && in_array('x/data/case.json', $names, true) && in_array('x/data/emails.csv', $names, true) && in_array('x/data/people.csv', $names, true)
        && count($convPages) === 2 && count(preg_grep('#^x/conversations/\d+ [^/]+/emails/\d{3} .+\.eml$#', $names)) === $res['emails'], 'a case exports as an overview page, one folder per conversation (its page and .eml files), and data/');
    $json = json_decode((string)$z->getFromName('x/data/case.json'), true);
    $pxPage = implode('', array_map(fn($p) => (string)$z->getFromName($p), $convPages));
    check($json['name'] === 'Valve claim' && in_array('CL 2026/77', array_column($json['references'], 'value'), true) && str_contains($pxPage, 'PX-77'), 'with its references, and each conversation readable on its own page');
    $index = (string)$z->getFromName('x/index.html');
    check(str_contains($index, 'People we were in contact with') && str_contains($index, 'maker.test') && str_contains($index, 'All emails in order') && str_contains($index, 'conversation.html#email-'), 'the overview lists who we were in contact with, by company, and every email in order');
    check(str_contains($index, 'All files (1)') && str_contains($index, 'report.pdf'), 'and every file of the case, next to its email and in one list');
    check(str_starts_with((string)$z->getFromName('x/data/emails.csv'), "\xEF\xBB\xBF"), 'spreadsheets open in Excel with Greek intact');
    $z->close(); @unlink($zp);

    group('drafts: a deleted one does not come back');
    require_once dirname(__DIR__) . '/lib/drafts_sync.php';
    $_SESSION['ecw_mailbox_drafts'] = [$annBox => ['at' => time(), 'rows' => [['id' => 'AAA-1', 'subject' => 'Old'], ['id' => 'BBB-2', 'subject' => 'Keep', 'to' => [], 'preview' => '', 'has_attachments' => false, 'updated_at' => gmdate('Y-m-d H:i:s')]]]];
    ecw_mailbox_draft_forget('AAA-1');
    check(array_column($_SESSION['ecw_mailbox_drafts'][$annBox]['rows'], 'id') === ['BBB-2'] && isset(ecw_mailbox_drafts_gone()['AAA-1']), 'deleting a draft takes it out of the cached Outlook list at once');
    $_SESSION['ecw_mailbox_drafts'][$annBox]['rows'][] = ['id' => 'AAA-1', 'subject' => 'Old', 'to' => [], 'preview' => '', 'has_attachments' => false, 'updated_at' => gmdate('Y-m-d H:i:s')];
    $pdo->prepare('UPDATE ecw_mailboxes SET connected_by_user_id = ? WHERE id = ?')->execute([$u['ann'], $annBox]);
    $listed = array_column(ecw_mailbox_drafts($org, $u['ann']), 'provider_id');
    check(!in_array('AAA-1', $listed, true), 'and if the mailbox still lists it for a moment, it stays hidden');
    $_SESSION = ['ecw_user_id' => $dee ?? 0];

    group('get started');
    require_once dirname(__DIR__) . '/lib/getting_started.php';
    $gsOrg = $ins('organizations', ['name' => 'Fresh Co', 'timezone' => 'UTC']);
    $gsAdmin = $ins('users', ['name' => 'Gina Admin', 'email' => 'gina' . uniqid() . '@fresh.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $gsOrg, 'user_id' => $gsAdmin, 'role' => 'admin']);
    $gsUser = $ins('users', ['name' => 'Hal', 'email' => 'hal' . uniqid() . '@fresh.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $gsOrg, 'user_id' => $gsUser, 'role' => 'user']);
    $keys = fn(array $s) => array_column($s, 'key');
    $adminSteps = ecw_getting_started_steps($gsOrg, $gsAdmin, true);
    $userSteps = ecw_getting_started_steps($gsOrg, $gsUser, false);
    check(array_slice($keys($adminSteps), 0, 3) === ['company', 'mailbox', 'team'] && in_array('backups', $keys($adminSteps), true), 'an admin gets the workspace steps');
    check($keys($userSteps) === ['mailbox', 'signature', 'signin'], 'a colleague only their own: mailbox, signature, safe sign-in');
    $byKey = array_column($adminSteps, null, 'key');
    check(!$byKey['company']['done'] && !$byKey['mailbox']['done'] && $byKey['team']['done'], 'each step reflects the real state (two people: the team step is done)');
    check(ecw_getting_started_company($gsOrg, $gsAdmin, 'Fresh Co SA', 'Mars/Olympus') !== null, 'an unknown time zone is refused');
    check(ecw_getting_started_company($gsOrg, $gsAdmin, 'Fresh Co SA', 'Europe/Athens') === null
        && (string)$pdo->query("SELECT timezone FROM ecw_organizations WHERE id = {$gsOrg}")->fetchColumn() === 'Europe/Athens', 'the company step saves the name and time zone');
    $box = $ins('mailboxes', ['organization_id' => $gsOrg, 'connected_by_user_id' => $gsAdmin, 'provider' => 'microsoft', 'email_address' => 'gina' . uniqid() . '@fresh.test', 'sync_status' => 'connected']);
    $byKey = array_column(ecw_getting_started_steps($gsOrg, $gsAdmin, true), null, 'key');
    check($byKey['company']['done'] && $byKey['mailbox']['done'], 'and both are ticked');
    [$d, $t] = ecw_getting_started_progress(ecw_getting_started_steps($gsOrg, $gsAdmin, true));
    check($d >= 3 && $t >= $d, 'progress counts the required steps');
    $pdo->prepare("UPDATE ecw_mailboxes SET sync_status = 'needs_reauth' WHERE id = ?")->execute([$box]);   // no sending in a test
    $inv = ecw_getting_started_invite($gsOrg, $gsAdmin, "nora@fresh.test, not-an-address\nolaf@fresh.test");
    check(count($inv['links']) === 2 && isset($inv['links']['nora@fresh.test']) && count($inv['errors']) === 1, 'several invitations at once; a bad address is reported, the rest still go');
    check(str_contains($inv['links']['olaf@fresh.test'], 'register.php?token='), 'without a mailbox that can send, the links are shown to copy');
    check(!ecw_getting_started_hidden($gsUser), 'the list shows until it is hidden');
    ecw_getting_started_hide($gsUser);
    check(ecw_getting_started_hidden($gsUser), 'and can be hidden');
    ecw_getting_started_hide($gsUser, false);
    check(!ecw_getting_started_hidden($gsUser), 'and brought back');

    group('in-app help');
    require_once dirname(__DIR__) . '/lib/help.php';
    $adminHelp = array_column(ecw_help_articles(['role' => 'admin', 'organization_id' => $org, 'user' => ['id' => $u['boss']]]), 'slug');
    $userHelp = array_column(ecw_help_articles(['role' => 'user', 'organization_id' => $org, 'user' => ['id' => $u['ann']]]), 'slug');
    check(in_array('backups', $adminHelp, true) && !in_array('backups', $userHelp, true) && in_array('search', $userHelp, true), 'admins see the admin how-tos; everyone sees the daily ones');
    check(!array_diff(array_column(array_map(fn($a) => ['t' => $a[0]], ECW_HELP), 't'), array_keys(ECW_HELP_TOPICS)), 'every article belongs to a topic');
    // Every "?" link on every page opens an article that exists.
    $missing = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/web', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!preg_match('/\.(php|js)$/', $f->getFilename()) || str_contains($f->getPathname(), '/vendor/')) continue;
        preg_match_all('/data-help="([a-z0-9-]+)"/', (string)file_get_contents($f->getPathname()), $mm);
        foreach ($mm[1] as $slug) if (!isset(ECW_HELP[$slug])) $missing[] = $slug . ' (' . $f->getFilename() . ')';
    }
    check($missing === [], 'every help link points to an article' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
    check(ecw_help_page_key('/other_things/web/settings/mailbox.php') === 'settings/mailbox' && ecw_help_page_key('/other_things/web/cases.php') === 'cases', 'pages are recognised for "About this page"');
    $short = array_filter(ECW_HELP_ANSWERS, static fn($a) => trim($a[0]) !== '' && mb_strlen($a[0]) <= 110);
    check(array_keys(ECW_HELP) == array_keys($short), 'every answer has a one-line version, and only one line');
    // A renamed button must not quietly break "Show me" or a tour: each target is in its page.
    $web = dirname(__DIR__) . '/web/';
    $inPage = static function (string $file, ?string $sel) use ($web): bool {
        if (!is_file($web . $file)) return false;
        if ($sel === null) return true;
        $src = file_get_contents($web . $file) . file_get_contents($web . 'partials/nav.php');
        if (preg_match('/^#([\w-]+)$/', $sel, $m)) return str_contains($src, 'id="' . $m[1] . '"');
        if (preg_match('/^\[data-tab="(\w+)"\]$/', $sel, $m)) return str_contains($src, 'data-tab="' . $m[1] . '"');
        return false;
    };
    $broken = [];
    foreach (ECW_HELP_ANSWERS as $slug => [, $showIt]) if ($showIt !== null && !$inPage($showIt[0], $showIt[1])) $broken[] = $slug;
    foreach (ECW_HELP_TOURS as $key => [, $tourPage, $steps]) foreach ($steps as [$sel]) if (!$inPage($tourPage ?? $key . '.php', $sel)) $broken[] = $key . ' ' . $sel;
    check($broken === [], '"Show me" and the tours point at things that are on their pages' . ($broken ? ': ' . implode(', ', $broken) : ''));
    $tours = ecw_help_tours(['role' => 'user', 'organization_id' => $org, 'user' => ['id' => $u['ann']]]);
    check(isset($tours['app'], $tours['cases']) && $tours['case']['href'] === null, 'a tour per main page; the case tour only starts inside a case');

    group('signed updates');
    $kp = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($kp));
    $public = base64_encode(sodium_crypto_sign_publickey($kp));
    $zip = tempnam(sys_get_temp_dir(), 'ecwrel');
    file_put_contents($zip, 'release bytes');
    $token = ecw_release_sign('9.1.0', hash_file('sha256', $zip), (int)filesize($zip), 'New things', $secret);
    check(ecw_release_verify($token, $zip, $public)['ok'] && ecw_release_verify($token, $zip, $public)['release']['version'] === '9.1.0', 'a signed release verifies');
    check(!ecw_release_verify($token, $zip)['ok'], 'with any other key (the real vendor key) it does not');
    file_put_contents($zip, 'release bytes, changed');
    check(str_contains((string)ecw_release_verify($token, $zip, $public)['error'], 'does not match'), 'a changed zip is refused');
    $licenseToken = ecw_license_sign(['package' => 'business', 'customer' => 'X'], $secret);
    check(!ecw_release_verify($licenseToken, null, $public)['ok'], 'a license key is never taken for a release');
    check(ecw_version_newer('1.10.0', '1.9.3') && !ecw_version_newer('1.0.0', '1.0.0'), 'versions compare as numbers');
    check(preg_match('/^\d+\.\d+\.\d+$/', ecw_app_version()) === 1, 'the running version comes from VERSION');
    $feed = tempnam(sys_get_temp_dir(), 'ecwfeed');
    file_put_contents($feed, json_encode(['version' => '9.1.0', 'zip' => 'ecw-9.1.0.zip', 'token' => $token]));
    $GLOBALS['ecw_release_test_public_key'] = $public;
    ecw_app_setting_set('update_url', 'file://' . $feed);
    $u1 = ecw_update_check(true);
    check($u1['available'] && $u1['latest'] === '9.1.0' && $u1['notes'] === 'New things', 'the update channel announces a newer signed version');
    unset($GLOBALS['ecw_release_test_public_key']);
    $u2 = ecw_update_check(true);
    check(!empty($u2['error']) && str_contains($u2['error'], 'signature'), 'an unsigned (or wrongly signed) announcement is not believed');
    ecw_app_setting_set('update_url', 'http://updates.example.com/x.json');
    check(str_contains((string)ecw_update_check(true)['error'], 'https'), 'the channel must be https');
    @unlink($zip); @unlink($feed);
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
