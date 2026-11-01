<?php declare(strict_types=1);
/**
 * Support agent (modules/support_agent): connecting it, what a conversation and a
 * case send (order, who wrote what, which files), whose mailboxes count, the limits,
 * and the agent's errors. The agent answers through $GLOBALS['ecw_sa_transport'].
 *
 *   php modules/support_agent/tests/support_agent_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/support_agent.php';
require_once dirname(__DIR__, 3) . '/lib/log.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }
function throws(callable $f, string $contains, string $what): void
{
    try { $f(); check(false, $what, 'nothing thrown'); }
    catch (Throwable $e) { check(str_contains($e->getMessage(), $contains), $what, get_class($e) . ': ' . $e->getMessage()); }
}

$sent = [];
$answer = ['ok' => true, 'id' => 7, 'status' => 'new', 'updated' => false, 'already_learned' => null, 'url' => 'http://localhost/support_agent/public/index.php?p=learn_item&id=7', 'message' => 'Sent: it waits for approval in the support agent.'];
$status = 200;
$GLOBALS['ecw_sa_transport'] = static function (string $method, string $url, ?array $body, string $token) use (&$sent, &$answer, &$status) {
    $sent[] = ['method' => $method, 'url' => $url, 'body' => $body, 'token' => $token];
    return [$status, str_contains($url, 'action=ping') ? ['ok' => true, 'agent' => 'support_agent', 'ai' => 'gemma4:12b'] : $answer];
};

$pdo = ecw_db();
$pdo->beginTransaction();
$tmp = [];
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Agent shop']);
    $admin = $ins('users', ['name' => 'George', 'email' => 'g' . $org . '@inq.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);
    $other = $ins('users', ['name' => 'Kostas', 'email' => 'k' . $org . '@inq.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $other, 'role' => 'member']);
    $user = ['id' => $admin, 'name' => 'George', 'email' => 'g' . $org . '@inq.test'];

    group('Connecting');
    throws(fn() => ecw_sa_save($org, ['url' => 'ftp://x/api.php', 'token' => ''], $admin), 'ending in api.php', 'a wrong address is refused');
    throws(fn() => ecw_sa_save($org, ['url' => 'http://localhost/support_agent/public/api.php', 'token' => 'password123'], $admin), 'does not look like', 'something that is not a token is refused');
    throws(fn() => ecw_sa_save($org, ['url' => 'http://localhost/support_agent/public/api.php', 'token' => ''], $admin), 'Paste the token', 'no token yet → asked for it');
    $token = 'sat_' . str_repeat('ab', 24);
    $s = ecw_sa_save($org, ['url' => 'http://localhost/support_agent/public/api.php', 'token' => $token], $admin);
    check($s['configured'] && $s['last_ok_at'] !== null && $s['last_error'] === null && end($sent)['token'] === $token, 'connected: the agent answered the test');
    $raw = (string)$pdo->query('SELECT support_agent_json FROM ' . ecw_table('organizations') . ' WHERE id = ' . $org)->fetchColumn();
    check(!str_contains($raw, $token) && !array_key_exists('token_enc', ecw_sa_settings($org)), 'the token is stored encrypted and never shown');
    $status = 401;
    $s = ecw_sa_save($org, ['url' => 'http://localhost/support_agent/public/api.php', 'token' => ''], $admin);
    check(str_contains((string)$s['last_error'], 'refused the token') && end($sent)['token'] === $token, 'empty token keeps the saved one; a refused token is reported');
    $status = 200;
    ecw_sa_save($org, ['url' => 'http://localhost/support_agent/public/api.php', 'token' => ''], $admin);

    group('Sending a conversation');
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'sa-' . $org, 'email_address' => 'support@inq.test', 'sync_status' => 'connected', 'connected_by_user_id' => $admin]);
    $thread = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'Labels smudged']);
    $msg = static function (string $dir, string $from, string $at, string $text, string $html = '') use ($ins, $mb, $thread): int {
        static $n = 0; $n++;
        return $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread, 'provider_message_id' => 'SA' . $n . '-' . $mb, 'subject' => 'Labels smudged', 'from_email' => $from, 'from_name' => ucfirst(strtok($from, '@')),
            'body_text' => $text, 'body_html' => $html, 'body_fetch_state' => 'fetched', 'direction' => $dir, 'sent_at' => $at, 'received_at' => $at, 'has_attachments' => 1]);
    };
    $m2 = $msg('outgoing', 'support@inq.test', '2026-10-20 10:00:00', 'Please clean the print head.');
    $m1 = $msg('incoming', 'maria@acme.gr', '2026-10-20 09:00:00', '', '<p>Our labels are <b>smudged</b>.</p>');
    $m3 = $msg('incoming', 'maria@acme.gr', '2026-10-20 12:00:00', 'It works now, thanks!');
    // Pasted pictures stay where they were in the text; a signature pasted under two emails is left out.
    $pdo->prepare('UPDATE ' . ecw_table('email_messages') . ' SET body_text = ?, body_html = ? WHERE id = ?')->execute(['plain version', '<p>Please clean the print head:</p><p><img src="cid:shot1@x" width="300"></p><p>Then restart.</p><p>--<br><img src="cid:sig@x"></p>', $m2]);
    $pdo->prepare('UPDATE ' . ecw_table('email_messages') . ' SET body_html = ? WHERE id = ?')->execute(['<p>It works now, thanks!</p><p>--<br><img src="cid:sig2@x"></p>', $m3]);
    $msg('draft', 'support@inq.test', '2026-10-20 13:00:00', 'unsent draft');
    $file = static function (int $messageId, string $name, string $bytes, int $inline = 0, string $mime = 'application/octet-stream') use ($ins, &$tmp): void {
        $p = tempnam(sys_get_temp_dir(), 'sat'); file_put_contents($p, $bytes); $tmp[] = $p;
        $ins('email_attachments', ['email_message_id' => $messageId, 'filename' => $name, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'is_inline' => $inline, 'local_path' => $p, 'provider_download_state' => 'downloaded']);
    };
    // Real PNGs (the size decides whether a picture is a screenshot or a logo).
    $pngOf = static function (int $w, int $h, int $seed = 0): string {
        $chunk = static fn(string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        $rows = ''; for ($y = 0; $y < $h; $y++) $rows .= "\0" . str_repeat(chr(($y + $seed) % 256) . chr($seed % 256) . "\x80", $w);
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($rows)) . $chunk('IEND', '');
    };
    $shot = $pngOf(400, 200);
    $file($m1, 'screenshot.png', $shot);
    $file($m1, 'cc-config.ini', "[A]\r\nK=1\r\n");
    $file($m1, 'report.pdf', "%PDF-1.7 a report");
    $file($m1, 'measurements.xlsx', "PK\x03\x04 a sheet");
    $file($m1, 'setup.exe', 'MZ');
    $file($m1, 'logo.png', $pngOf(90, 30, 7), 1, 'image/png');
    $file($m1, 'image001', $pngOf(300, 200, 3), 1, 'image/png');
    $file($m3, 'screenshot-again.png', $shot);
    $sig = $pngOf(320, 110, 99);
    $ins('email_attachments', ['email_message_id' => $m2, 'filename' => 'image001.png', 'mime_type' => 'image/png', 'size_bytes' => 9, 'is_inline' => 1, 'content_id' => '<shot1@x>', 'local_path' => (function () use ($pngOf, &$tmp) { $p = tempnam(sys_get_temp_dir(), 'sat'); file_put_contents($p, $pngOf(500, 300, 42)); $tmp[] = $p; return $p; })(), 'provider_download_state' => 'downloaded']);
    foreach ([[$m2, 'sig@x'], [$m3, 'sig2@x']] as [$mid, $cid]) {
        $p = tempnam(sys_get_temp_dir(), 'sat'); file_put_contents($p, $sig); $tmp[] = $p;
        $ins('email_attachments', ['email_message_id' => $mid, 'filename' => 'image002.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($sig), 'is_inline' => 1, 'content_id' => '<' . $cid . '>', 'local_path' => $p, 'provider_download_state' => 'downloaded']);
    }
    $r = ecw_sa_send_thread($org, $user, $thread);
    $body = end($sent)['body'];
    check($r['url'] === $answer['url'] && end($sent)['method'] === 'POST' && str_contains(end($sent)['url'], 'action=learn'), 'sent to the agent\'s learn action; its answer comes back');
    check($body['external_ref'] === 'thread:' . $thread && $body['kind'] === 'thread' && $body['title'] === 'Labels smudged' && $body['sent_by'] === 'George', 'the conversation is named and identified (sending again updates the same item)');
    check(array_column($body['messages'], 'direction') === ['incoming', 'outgoing', 'incoming'], 'emails in time order, the draft left out');
    check(str_contains($body['messages'][0]['body'], 'smudged') && !str_contains($body['messages'][0]['body'], '<b>'), 'an email with only HTML is sent as plain text');
    check(array_column($body['messages'][0]['attachments'], 'filename') === ['screenshot.png', 'cc-config.ini', 'report.pdf', 'measurements.xlsx', 'image001.png'] && base64_decode($body['messages'][0]['attachments'][1]['data_base64']) === "[A]\r\nK=1\r\n",
        'screenshots, settings, PDF and Excel files go along exactly; a picture pasted into the email too (named), a program and a small logo do not', json_encode(array_column($body['messages'][0]['attachments'], 'filename')));
    check($body['messages'][2]['attachments'] === [], 'the same screenshot in a later reply is not sent twice; the signature under two emails is left out');
    $reply = $body['messages'][1];
    check((bool)preg_match('/clean the print head:\s*\[\[image:shot1@x\]\]\s*Then restart/', $reply['body']) && !str_contains($reply['body'], 'sig@x') && !str_contains($body['messages'][2]['body'], 'sig2@x'),
        'a pasted screenshot stays exactly between the sentences around it; the signature\'s marker is gone', $reply['body']);
    check(array_column($reply['attachments'], 'content_id') === ['shot1@x'] && !array_key_exists('sha1', $reply['attachments'][0]), 'the pasted screenshot carries its id to match its marker');
    check(str_contains($body['link'], 'app.php?mailbox=' . $mb . '&thread=' . $thread), 'a link back to the conversation');

    group('Whose mailboxes count');
    $mbOther = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'sa2-' . $org, 'email_address' => 'kostas@inq.test', 'sync_status' => 'connected', 'connected_by_user_id' => $other]);
    $threadOther = $ins('email_threads', ['mailbox_id' => $mbOther, 'normalized_subject' => 'Private']);
    $ins('email_messages', ['mailbox_id' => $mbOther, 'email_thread_id' => $threadOther, 'provider_message_id' => 'SAP-' . $mbOther, 'subject' => 'Private', 'from_email' => 'x@y.gr', 'body_text' => 'private matter', 'body_fetch_state' => 'fetched', 'direction' => 'incoming', 'sent_at' => '2026-10-20 11:00:00']);
    if (ecw_private_mailboxes()) throws(fn() => ecw_sa_send_thread($org, $user, $threadOther), 'not found', 'a colleague\'s private mailbox cannot be sent, even by an admin');
    else check(true, 'mailboxes are not private on this installation (nothing to refuse)');
    $orgB = $ins('organizations', ['name' => 'Other workspace']);
    throws(fn() => ecw_sa_send_thread($orgB, $user, $thread), 'not found', 'another workspace\'s conversation is not found');

    group('Sending a case');
    $case = $ins('cases', ['organization_id' => $org, 'case_number' => 'C-' . $org, 'name' => 'Label printer', 'status' => 'open', 'owner_user_id' => $admin, 'created_by_user_id' => $admin]);
    $thread2 = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'Labels again']);
    $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread2, 'provider_message_id' => 'SAC-' . $mb, 'subject' => 'Labels again', 'from_email' => 'nick@acme.gr', 'body_text' => 'Second printer too.', 'body_fetch_state' => 'fetched', 'direction' => 'incoming', 'sent_at' => '2026-10-20 09:30:00']);
    foreach ([$thread, $thread2, $threadOther] as $i => $t) $ins('case_threads', ['case_id' => $case, 'email_thread_id' => $t, 'sort_order' => $i]);
    ecw_sa_send_case($org, $user, $case);
    $body = end($sent)['body'];
    $dates = array_column($body['messages'], 'date');
    $sorted = $dates; sort($sorted);
    check($body['external_ref'] === 'case:' . $case && $body['kind'] === 'case' && $body['title'] === 'C-' . $org . ' Label printer' && str_contains($body['link'], 'case.php?id=' . $case), 'the case is named, identified and linked');
    // A conversation in a case is shared with the case's people (lib/access.php), private mailbox or not.
    check($dates === $sorted && count($body['messages']) === 5, 'every conversation of the case in one time line (one from a colleague\'s mailbox is shared through the case)', json_encode($dates));

    group('Limits and errors');
    $big = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'Many shots']);
    $bm = $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $big, 'provider_message_id' => 'SAB-' . $mb, 'subject' => 'Many', 'from_email' => 'a@acme.gr', 'body_text' => 'see shots', 'body_fetch_state' => 'fetched', 'direction' => 'incoming', 'sent_at' => '2026-10-21 09:00:00', 'has_attachments' => 1]);
    for ($i = 0; $i < 25; $i++) $file($bm, "shot{$i}.png", $pngOf(200, 150, 10 + $i));
    ecw_sa_send_thread($org, $user, $big);
    check(count(end($sent)['body']['messages'][0]['attachments']) === ECW_SA_MAX_FILES, 'at most ' . ECW_SA_MAX_FILES . ' files per send');
    $answer = ['ok' => false, 'error' => 'The thread has no messages.'];
    $status = 422;
    throws(fn() => ecw_sa_send_thread($org, $user, $thread), 'The thread has no messages', 'the agent\'s own error message is shown to the admin');
    $pdo->prepare('UPDATE ' . ecw_table('organizations') . ' SET support_agent_json = NULL WHERE id = ?')->execute([$org]);
    throws(fn() => ecw_sa_send_thread($org, $user, $thread), 'not connected', 'not connected → says where to connect it');
} finally {
    $pdo->rollBack();
    foreach ($tmp as $p) @unlink($p);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
