<?php declare(strict_types=1);
/**
 * Automation rules on earlier emails (lib/rule_backfill.php) and the
 * "Folder is" condition.
 *
 *   php tests/rule_backfill_test.php
 *
 * Runs in a transaction that is rolled back. Microsoft is scripted with FakeGraph.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/rule_backfill.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

ecw_set_graph_config_override(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://example.test/cb']);
$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Rules on earlier mail']);
    $u = $ins('users', ['name' => 'Admin', 'email' => 'admin' . $org . '@rb.test', 'password_hash' => 'x']);
    $agent = $ins('users', ['name' => 'Agent', 'email' => 'agent' . $org . '@rb.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $agent, 'role' => 'member']);
    $addr = 'support' . $org . '@rb.test';
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-rb' . $org, 'email_address' => $addr,
        'sync_status' => 'connected', 'connected_by_user_id' => $u, 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $folder = static fn(string $name, string $type) => $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'F-' . $type . $org, 'name' => $name, 'folder_type' => $type, 'is_system' => 0, 'is_selected_for_sync' => 1]);
    $inbox = $folder('Inbox', 'inbox'); $support = $folder('Support', 'custom'); $deleted = $folder('Deleted Items', 'deleted'); $sent = $folder('Sent Items', 'sent');
    $mail = static fn(string $id, string $subject, string $from, string $when) => FakeGraph::message(['id' => $id, 'conversationId' => 'conv-' . $id, 'subject' => $subject,
        'from' => ['emailAddress' => ['address' => $from, 'name' => 'X']], 'sender' => ['emailAddress' => ['address' => $from, 'name' => 'X']],
        'toRecipients' => [['emailAddress' => ['address' => $from === $addr ? 'cust@client.test' : $addr]]],
        'body' => ['contentType' => 'html', 'content' => '<p>Form</p>'], 'receivedDateTime' => $when, 'sentDateTime' => $when]);
    $form = 'Φόρμα υποστήριξης πελάτη - ';
    $g = new FakeGraph();
    $g->on('GET', '#/mailFolders/F-custom' . $org . '/messages\?#', ['body' => ['value' => [
        $mail('S1', $form . 'Παπαδόπουλος', 'a@client.test', '2025-03-10T09:00:00Z'),
        $mail('S2', $form . 'Γεωργίου', 'b@client.test', '2026-05-02T09:00:00Z'),
    ]]]);
    $g->on('GET', '#/mailFolders/F-inbox' . $org . '/messages\?#', ['body' => ['value' => [
        $mail('I1', 'Invoice 42', 'c@client.test', '2026-05-03T09:00:00Z'),
        $mail('I2', $form . 'Inbox copy', 'd@client.test', '2026-05-04T09:00:00Z'),
    ]]]);
    $g->on('GET', '#/mailFolders/F-deleted' . $org . '/messages\?#', ['body' => ['value' => [$mail('D1', $form . 'deleted', 'e@client.test', '2026-05-05T09:00:00Z')]]]);
    $g->on('GET', '#/mailFolders/F-sent' . $org . '/messages\?#', ['body' => ['value' => [['conversationId' => 'conv-S2'] + $mail('O1', 'RE: ' . $form . 'Γεωργίου', $addr, '2026-05-02T10:00:00Z')]]]);
    $g->install();
    foreach ([$support, $inbox, $deleted, $sent] as $f) ecw_sync_history($mb, $f, 1);
    $msgId = static fn(string $pid) => (int)$pdo->query("SELECT id FROM ecw_email_messages WHERE mailbox_id = {$mb} AND provider_message_id = '{$pid}'")->fetchColumn();
    $threadOf = static fn(string $pid) => (int)$pdo->query("SELECT email_thread_id FROM ecw_email_messages WHERE mailbox_id = {$mb} AND provider_message_id = '{$pid}'")->fetchColumn();
    check($msgId('S1') > 0 && $msgId('O1') > 0, 'earlier emails are there (old: no rule ran when they came in)');

    echo "\n=== the Folder condition ===\n";
    $rid = ecw_rule_save($org, $u, true, ['kind' => 'route', 'name' => 'Support forms become cases',
        'conditions' => ['match' => 'all', 'items' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Φόρμα υποστήριξης πελάτη -'], ['field' => 'folder', 'op' => 'is', 'value' => (string)$support]]],
        'actions' => ['list' => [['type' => 'create_case', 'owner_user_id' => $agent], ['type' => 'tag', 'tag' => 'support'], ['type' => 'notify', 'user_id' => $agent]], 'stop' => true]]);
    $rule = ecw_rule_backfill_rule($org, $rid);
    check(($rule['conditions']['items'][1] ?? [])['field'] === 'folder', 'a rule can say "the email is in Support"');
    check(ecw_rule_matches($rule['conditions'], ['subject' => $form . 'x', 'folder_id' => $support], ['emails' => []])
        && !ecw_rule_matches($rule['conditions'], ['subject' => $form . 'x', 'folder_id' => $inbox], ['emails' => []]), 'it matches emails in that folder only');
    try { ecw_rule_conditions(['items' => [['field' => 'folder', 'op' => 'is', 'value' => 'Support']]]); $bad = false; } catch (InvalidArgumentException $e) { $bad = true; }
    check($bad, 'a folder is chosen from the list, not typed');

    echo "\n=== what matches, before running ===\n";
    $p = ecw_rule_backfill_preview($org, $rid, '2025-01-01', '2026-12-31');
    check($p['emails'] === 2 && $p['conversations'] === 2 && $p['to_run'] === 2, 'both support forms in the Support folder, not the one in the Inbox', json_encode($p));
    check(in_array('notify', $p['left_out'], true) && $p['opens_cases'], 'it says Notify is left out for old mail, and that it opens cases');
    $p = ecw_rule_backfill_preview($org, $rid, '2026-01-01', '2026-12-31');
    check($p['emails'] === 1 && ($p['sample'][0]['subject'] ?? '') === $form . 'Γεωργίου', 'the start date counts', json_encode($p['sample']));

    echo "\n=== running it ===\n";
    $before = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$agent}")->fetchColumn();
    ecw_rule_backfill_start($org, $u, $rid, '2025-01-01', '2026-12-31', true);
    try { ecw_rule_backfill_start($org, $u, $rid, '2025-01-01', '2026-12-31', false); $twice = true; } catch (InvalidArgumentException $e) { $twice = false; }
    check(!$twice, 'a rule runs on earlier emails once at a time');
    $n = ecw_rule_backfill_due(15);
    $run = ecw_rule_backfill_status($org, $rid);
    check($n === 2 && $run['status'] === 'done' && $run['acted'] === 2, 'the worker runs it on each conversation', json_encode($run));
    $case = ecw_case_for_thread($threadOf('S1'));
    check($case !== null && $case['status'] === 'closed' && substr((string)$case['closed_at'], 0, 10) === '2025-03-10' && (int)$case['owner_user_id'] === $agent,
        'a case per form, closed on the email\'s date (history only), with its Responsible', json_encode($case));
    require_once dirname(__DIR__) . '/lib/case_extras.php';
    check(ecw_case_tags((int)$case['id']) === ['support'], 'the other actions ran too (tag)');
    $case2 = ecw_case_for_thread($threadOf('S2'));
    check($case2 !== null && $threadOf('O1') === $threadOf('S2'), 'the reply in Sent is in the same case (same conversation)');
    check(ecw_case_for_thread($threadOf('I2')) === null && ecw_case_for_thread($threadOf('D1')) === null, 'emails outside Support are untouched');
    $after = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$agent}")->fetchColumn();
    check($after === $before, 'nobody is notified about old mail');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$u} AND text LIKE '%earlier conversation%'")->fetchColumn() === 1, 'whoever started it is told when it is done');

    echo "\n=== running it again changes nothing ===\n";
    $p = ecw_rule_backfill_preview($org, $rid, '2025-01-01', '2026-12-31');
    check($p['already'] === 2 && $p['to_run'] === 0, 'conversations it handled are skipped', json_encode($p));
    try { ecw_rule_backfill_start($org, $u, $rid, '2025-01-01', '2026-12-31', false); $again = true; } catch (InvalidArgumentException $e) { $again = false; }
    check(!$again, 'nothing to start');

    echo "\n=== Junk and Deleted Items, and outgoing mail ===\n";
    $any = ecw_rule_save($org, $u, true, ['kind' => 'route', 'name' => 'Any support form',
        'conditions' => ['match' => 'all', 'items' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Φόρμα υποστήριξης πελάτη -']]],
        'actions' => ['list' => [['type' => 'flag']], 'stop' => true]]);
    $p = ecw_rule_backfill_preview($org, $any, '2025-01-01', '2026-12-31');
    check($p['emails'] === 3, 'without a Folder condition: every folder but Deleted Items, received mail only (not the reply in Sent)', json_encode($p));
    $del = ecw_rule_save($org, $u, true, ['kind' => 'route', 'name' => 'Deleted forms',
        'conditions' => ['match' => 'all', 'items' => [['field' => 'folder', 'op' => 'is', 'value' => (string)$deleted]]], 'actions' => ['list' => [['type' => 'flag']], 'stop' => true]]);
    check(ecw_rule_backfill_preview($org, $del, '2025-01-01', '2026-12-31')['emails'] === 1, 'Deleted Items when the rule names it');

    echo "\n=== new mail: the Folder condition works there too ===\n";
    $g = new FakeGraph();
    $g->on('GET', '#/mailFolders/F-custom' . $org . '/messages\?#', ['body' => ['value' => [$mail('S9', $form . 'νέο', 'z@client.test', gmdate('Y-m-d\TH:i:s\Z', time() - 60))]]]);
    $g->on('GET', '#.#', ['body' => ['value' => []]]);
    $g->install();
    $pdo->prepare('UPDATE ecw_rules SET enabled = 0 WHERE id IN (?, ?)')->execute([$any, $del]);
    ecw_sync_history($mb, $support, 1);
    check(ecw_case_for_thread($threadOf('S9')) !== null, 'a new support form arriving in Support opens its case');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
