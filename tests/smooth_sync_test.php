<?php declare(strict_types=1);
/**
 * Smooth sync: the import and delta pages bring each email's text with the
 * list (so conversations open complete), attachments follow in the background
 * without fetching the text again, the spinners know what is really syncing,
 * and a mailbox can be removed with everything synced from it.
 *
 *   php tests/smooth_sync_test.php
 *
 * Microsoft is scripted with FakeGraph. Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/activity.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

ecw_set_graph_config_override(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://example.test/cb']);
$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Smooth sync']);
    $u = $ins('users', ['name' => 'Ada', 'email' => 'ada' . $org . '@sync.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-ss' . $org, 'email_address' => 'ops' . $org . '@sync.test',
        'sync_status' => 'connected', 'connected_by_user_id' => $u, 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'F-IN-' . $org, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);

    echo "\n=== the import page brings the text ===\n";
    $withBody = static fn(string $id, string $html, bool $att = false) => FakeGraph::message(['id' => $id, 'conversationId' => 'conv-' . $id, 'hasAttachments' => $att,
        'body' => ['contentType' => 'html', 'content' => $html]]);
    $g = new FakeGraph();
    $g->on('GET', '#/mailFolders/F-IN-' . $org . '/messages\?#', ['body' => ['value' => [
        $withBody('M1', '<p>Is the pump ready?</p>'),
        $withBody('M2', '<p>See the drawing.</p><p><img src="cid:img1"></p><div style="border-top:1px solid #E1E1E1"><b>From:</b> Us &lt;us@x.test&gt;<br><b>Sent:</b> Monday<br><b>To:</b> Them<br><b>Subject:</b> Pump</div><p>OLD TEXT</p>', true),
    ]]]);
    $g->install();
    $r = ecw_sync_history($mb, $inbox, 1);
    $url = $g->calls[0]['url'] ?? '';
    check(str_contains(urldecode($url), 'body') && !str_contains(urldecode($url), 'internetMessageHeaders'), 'the list request asks for the text (and still not for the slow headers)', urldecode($url));
    $rows = $pdo->query("SELECT provider_message_id, body_fetch_state, attachments_state, body_html, quoted_html FROM ecw_email_messages WHERE mailbox_id = {$mb} ORDER BY provider_message_id")->fetchAll(PDO::FETCH_UNIQUE);
    check($rows['M1']['body_fetch_state'] === 'fetched' && $rows['M1']['attachments_state'] === null, 'an email without attachments is complete straight away');
    check($rows['M2']['body_fetch_state'] === 'fetched' && $rows['M2']['attachments_state'] === 'pending', 'an email with attachments has its text now, its files marked to follow');
    check(str_contains((string)$rows['M2']['body_html'], 'See the drawing') && !str_contains((string)$rows['M2']['body_html'], 'OLD TEXT') && str_contains((string)$rows['M2']['quoted_html'], 'OLD TEXT'), 'the quoted history is split off, as with a full fetch');

    echo "\n=== the attachments follow, without the text again ===\n";
    $g = new FakeGraph();
    $g->on('GET', '#/messages/M2/attachments#', ['body' => ['value' => [
        ['id' => 'A1', 'name' => 'drawing.png', 'contentType' => 'image/png', 'size' => 1200, 'isInline' => true, 'contentId' => 'img1'],
    ]]]);
    $g->install();
    $c = ecw_sync_attachments($mb, 50);
    $m2 = (int)$pdo->query("SELECT id FROM ecw_email_messages WHERE mailbox_id = {$mb} AND provider_message_id = 'M2'")->fetchColumn();
    check($c['attachments_listed'] === 1 && $pdo->query("SELECT attachments_state FROM ecw_email_messages WHERE id = {$m2}")->fetchColumn() === 'done', 'the attachment list is fetched and the email marked complete');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_email_attachments WHERE email_message_id = {$m2}")->fetchColumn() === 1, 'the picture is known, so it shows inside the text');
    check($g->callsMatching('#/messages/M2\?#') === [] && count($g->calls) === 1, 'the text was not downloaded a second time');

    echo "\n=== a later change keeps it complete ===\n";
    $g = new FakeGraph();
    $g->on('GET', '#/messages/delta#', ['body' => ['value' => [$withBody('M2', '<p>See the drawing.</p><p><img src="cid:img1"></p>', true)], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?token=x']]);
    $g->install();
    ecw_sync_folder_delta($mb, $inbox, 1);
    $delta = $g->calls[0]['url'] ?? '';
    check(str_contains(urldecode($delta), 'body'), 'the refresh (delta) asks for the text too');
    check($pdo->query("SELECT attachments_state FROM ecw_email_messages WHERE id = {$m2}")->fetchColumn() === 'done', 'its attachments are not fetched again');

    echo "\n=== Sync now does not re-import complete folders ===\n";
    $sent = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'F-SENT-' . $org, 'name' => 'Sent Items', 'folder_type' => 'sent', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    check(ecw_folder_history_complete($mb, $inbox), 'a folder whose history walk reached the end is complete');
    check(!ecw_folder_history_complete($mb, $sent), 'a folder never walked is not complete (Sync now imports it)');
    // An email whose attachments were listed before the column existed: a later pass leaves it done.
    $pdo->exec("UPDATE ecw_email_messages SET attachments_state = NULL WHERE id = {$m2}");
    $g = new FakeGraph();
    $g->on('GET', '#/delta\?token=x#', ['body' => ['value' => [$withBody('M2', '<p>See the drawing.</p><p><img src="cid:img1"></p>', true)], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?token=y']]);
    $g->install();
    ecw_sync_folder_delta($mb, $inbox, 1);
    check($pdo->query("SELECT attachments_state FROM ecw_email_messages WHERE id = {$m2}")->fetchColumn() === 'done', 'attachments already known are not marked to fetch again');

    echo "\n=== what makes the spinners turn ===\n";
    // A synced folder never imported counts as importing (account_scope_test.php): Sent stops syncing here.
    $pdo->exec("UPDATE ecw_mail_folders SET is_selected_for_sync = 0 WHERE id = {$sent}");
    $act = ecw_mailbox_sync_activity($mb);
    check(!$act['busy'] && !$act['importing'], 'nothing left to do: no spinner', json_encode($act));
    $pdo->prepare("INSERT INTO ecw_sync_queue (mailbox_id, mail_folder_id, job_type, reason, status, available_at) VALUES (?, ?, 'delta', 'daemon', 'queued', ?)")->execute([$mb, $inbox, gmdate('Y-m-d H:i:s')]);
    check(!ecw_mailbox_sync_activity($mb)['busy'], 'the background refresh every minute does not make anything turn');
    $pdo->prepare("INSERT INTO ecw_sync_queue (mailbox_id, mail_folder_id, job_type, reason, status, available_at) VALUES (?, ?, 'delta', 'manual', 'queued', ?)")->execute([$mb, $inbox, gmdate('Y-m-d H:i:s')]);
    $act = ecw_mailbox_sync_activity($mb);
    check($act['busy'] && $act['folders'] === [$inbox], 'a sync someone asked for: the button and the Inbox turn');
    $pdo->exec("UPDATE ecw_sync_queue SET status = 'running', locked_by = 'gone:1', locked_at = '" . gmdate('Y-m-d H:i:s', time() - 3600) . "' WHERE mailbox_id = {$mb} AND reason = 'manual'");
    check(!ecw_mailbox_sync_activity($mb)['busy'], 'a job its worker abandoned an hour ago does not keep it turning');
    $pdo->exec("UPDATE ecw_email_messages SET attachments_state = 'pending' WHERE id = {$m2}");
    $act = ecw_mailbox_sync_activity($mb);
    check($act['busy'] && $act['importing'] && $act['attachments_left'] === 1, 'emails still to complete: it keeps turning until 100%');

    echo "\n=== folders: Outlook's own counts, and the import order ===\n";
    $g = new FakeGraph();
    foreach (['inbox' => 'X-IN', 'sentitems' => 'X-SENT', 'drafts' => 'X-DRAFTS', 'deleteditems' => 'X-DEL', 'archive' => 'X-ARCH', 'junkemail' => 'X-JUNK',
              'conversationhistory' => 'X-CH', 'outbox' => 'X-OUT', 'syncissues' => 'X-SI', 'conflicts' => 'X-CO', 'localfailures' => 'X-LF', 'serverfailures' => 'X-SF', 'scheduled' => 'X-SN'] as $name => $fid) {
        $g->on('GET', '#/me/mailFolders/' . $name . '\?#', ['body' => ['id' => $fid]]);
    }
    $g->on('GET', '#/me/mailFolders\?#', ['body' => ['value' => [
        ['id' => 'X-ARCH', 'displayName' => 'Archive', 'childFolderCount' => 0, 'totalItemCount' => 31],
        ['id' => 'X-DEL', 'displayName' => 'Deleted Items', 'childFolderCount' => 0, 'totalItemCount' => 5200],
        ['id' => 'X-IN', 'displayName' => 'Inbox', 'childFolderCount' => 0, 'totalItemCount' => 1240],
        ['id' => 'X-JUNK', 'displayName' => 'Junk Email', 'childFolderCount' => 0, 'totalItemCount' => 9],
        ['id' => 'X-SENT', 'displayName' => 'Sent Items', 'childFolderCount' => 0, 'totalItemCount' => 800],
        ['id' => 'X-SUP', 'displayName' => 'Support', 'childFolderCount' => 0, 'totalItemCount' => 77],
    ]]]);
    // The exact email count (the folder total also counts calendar items, contacts…).
    $g->on('GET', '#/mailFolders/X-IN/messages\?\$top=1#', ['body' => ['value' => [], '@odata.count' => 1238]]);
    $g->install();
    $mb3 = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-f3' . $org, 'email_address' => 'f3' . $org . '@sync.test',
        'sync_status' => 'connected', 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    ecw_sync_folders($mb3);
    $totals = $pdo->query("SELECT name, total_count FROM ecw_mail_folders WHERE mailbox_id = {$mb3}")->fetchAll(PDO::FETCH_KEY_PAIR);
    check((int)$totals['Inbox'] === 1238, "a synced folder keeps Outlook's exact number of EMAILS (not its 1,240 items: calendar items and contacts are never synced)", json_encode($totals));
    check((int)$totals['Deleted Items'] === 5200, "when the exact count is not available, the folder's item count is kept", json_encode($totals));
    $order = array_column(ecw_selected_folders($mb3), 'name');
    check(array_slice($order, 0, 2) === ['Inbox', 'Sent Items'] && end($order) === 'Deleted Items' && array_search('Junk Email', $order, true) > array_search('Archive', $order, true),
        'a new mailbox imports Inbox and Sent first, Junk and Deleted Items last (it was alphabetical: Archive, Deleted Items…)', implode(' > ', $order));

    echo "\n=== removing a mailbox ===\n";
    $other = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-keep' . $org, 'email_address' => 'keep' . $org . '@sync.test', 'sync_status' => 'connected']);
    $file = tempnam(sys_get_temp_dir(), 'ecw-att-');
    file_put_contents($file, 'png');
    $pdo->prepare('UPDATE ecw_email_attachments SET local_path = ? WHERE email_message_id = ?')->execute([$file, $m2]);
    $thread = (int)$pdo->query("SELECT email_thread_id FROM ecw_email_messages WHERE id = {$m2}")->fetchColumn();
    $ins('ai_suggestions', ['organization_id' => $org, 'user_id' => $u, 'agent' => 'triage', 'kind' => 'triage', 'thread_id' => $thread, 'title' => 'T', 'state' => 'open']);
    $out = ecw_mailbox_remove($mb);
    check($out['messages'] === 2 && $out['files'] === 1 && !is_file($file), 'it reports what it removed, including the file on disk');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_email_messages WHERE mailbox_id = {$mb}")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM ecw_mailboxes WHERE id = {$mb}")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM ecw_ai_suggestions WHERE thread_id = {$thread}")->fetchColumn() === 0, 'the mailbox, its emails and the suggestions about them are gone');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_mailboxes WHERE id = {$other}")->fetchColumn() === 1, 'another mailbox is untouched');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE action = 'mailbox.removed' AND target_id = {$mb}")->fetchColumn() === 1, 'the removal is in the audit log');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
