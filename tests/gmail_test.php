<?php declare(strict_types=1);
/**
 * Gmail provider: labels -> folders, history import, history delta (archive,
 * delete, new), MIME bodies and attachments, reply threading and MIME safety,
 * token refresh failure, Google identity checks, OAuth completion, and
 * provider selection.
 *
 *   php tests/gmail_test.php
 *
 * Uses the scripted HTTP transport (tests/support/FakeGraph.php) — no network —
 * and one database transaction that is always rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/mailbox.php';
require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/google_identity.php';
require_once dirname(__DIR__) . '/lib/teams.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function group(string $t): void { echo "\n=== {$t} ===\n"; }
function check(bool $c, string $label): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$label}\n"; } else { $fail++; echo "  FAIL  {$label}\n"; } }

const API = 'https://gmail\.googleapis\.com/gmail/v1/users/me';
$GLOBALS['ecw_google_config_override'] = ['client_id' => 'gid.apps.googleusercontent.com', 'client_secret' => 'gsecret',
    'redirect_uri' => 'http://localhost:8080/other_things/web/oauth/callback.php'];

$b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$hdrs = static fn(array $h): array => array_map(static fn($k, $v) => ['name' => $k, 'value' => $v], array_keys($h), $h);
$meta = static function (string $id, array $labels, array $headers, int $ts, string $thread = 't1', string $mime = 'multipart/alternative') use ($hdrs): array {
    return ['body' => ['id' => $id, 'threadId' => $thread, 'labelIds' => $labels, 'internalDate' => (string)($ts * 1000),
        'payload' => ['mimeType' => $mime, 'headers' => $hdrs($headers)]]];
};
$now = time();
$m1Headers = ['From' => '"Doe, John" <John@Client.test>', 'To' => 'me@acme.test, Ann <ann@acme.test>',
    'Subject' => '=?UTF-8?B?' . base64_encode('Προσφορά valve') . '?=', 'Date' => gmdate('r', $now - 3600),
    'Message-ID' => '<orig@client.test>', 'References' => '<first@client.test>'];
$m2Headers = ['From' => 'me@acme.test', 'To' => 'john@client.test', 'Subject' => 'Re: Προσφορά valve',
    'Date' => gmdate('r', $now - 1800), 'Message-ID' => '<reply@acme.test>', 'In-Reply-To' => '<orig@client.test>'];

$pdo = ecw_db();
$pdo->beginTransaction();
$fake = new FakeGraph();
try {
    $ins = function (string $t, array $row) use ($pdo): int {
        $c = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'Gmail test']);
    $user = $ins('users', ['name' => 'Gina', 'email' => 'gina' . uniqid() . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $user, 'role' => 'admin']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'connected_by_user_id' => $user, 'provider' => 'google',
        'provider_account_id' => 'sub-1', 'email_address' => 'me@acme.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('ya29.token'), 'refresh_token_encrypted' => ecw_encrypt('1//refresh'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', $now + 3600)]);

    group('provider selection');
    check(ecw_provider_for_mailbox($mb) instanceof GmailProvider, 'a google mailbox gets the Gmail provider');
    check(ecw_provider_configured('google'), 'Google config is detected');
    check(ecw_teams_mailbox($user) === null && !ecw_teams_status($user)['connected'], 'Teams ignores Gmail mailboxes');

    $fake
        ->on('GET', '#' . API . '/labels$#', ['body' => ['labels' => [
            ['id' => 'INBOX', 'type' => 'system'], ['id' => 'SENT', 'type' => 'system'], ['id' => 'CATEGORY_SOCIAL', 'type' => 'system'],
            ['id' => 'Label_7', 'name' => 'Suppliers', 'type' => 'user']]]])
        ->on('GET', '#' . API . '/messages\?labelIds=INBOX#', ['body' => ['messages' => [['id' => 'm2'], ['id' => 'm1']]]])
        ->on('GET', '#' . API . '/messages/m1\?format=metadata#', $meta('m1', ['INBOX', 'UNREAD'], $m1Headers, $now - 3600, 't1', 'multipart/mixed'),
            $meta('m1', ['UNREAD'], $m1Headers, $now - 3600, 't1', 'multipart/mixed'))
        ->on('GET', '#' . API . '/messages/m2\?format=metadata#', $meta('m2', ['INBOX', 'SENT'], $m2Headers, $now - 1800))
        ->on('GET', '#' . API . '/profile$#', ['body' => ['emailAddress' => 'me@acme.test', 'historyId' => '100']])
        ->on('GET', '#' . API . '/history\?startHistoryId=100#', ['body' => ['historyId' => '120', 'history' => [
            ['messagesAdded' => [['message' => ['id' => 'm3', 'threadId' => 't2', 'labelIds' => ['INBOX']]]]],
            ['labelsRemoved' => [['message' => ['id' => 'm1', 'threadId' => 't1'], 'labelIds' => ['INBOX']]]],
            ['messagesDeleted' => [['message' => ['id' => 'm2', 'threadId' => 't1']]]],
        ]]])
        ->on('GET', '#' . API . '/history\?startHistoryId=5#', ['status' => 404, 'body' => ['error' => ['code' => 404, 'message' => 'Requested entity was not found.']]])
        ->on('GET', '#' . API . '/messages/m3\?format=metadata#', $meta('m3', ['INBOX'], ['From' => 'new@client.test', 'To' => 'me@acme.test', 'Subject' => 'New', 'Date' => gmdate('r', $now), 'Message-ID' => '<new@client.test>'], $now, 't2'))
        ->install();

    group('labels become folders');
    ecw_sync_folders($mb);
    $folders = $pdo->query("SELECT provider_folder_id, folder_type, is_selected_for_sync FROM ecw_mail_folders WHERE mailbox_id = {$mb} ORDER BY provider_folder_id")->fetchAll();
    $byId = array_column($folders, null, 'provider_folder_id');
    check(count($folders) === 3 && !isset($byId['CATEGORY_SOCIAL']), 'Inbox, Sent and the user label; category labels skipped');
    check($byId['INBOX']['folder_type'] === 'inbox' && (int)$byId['INBOX']['is_selected_for_sync'] === 1 && (int)$byId['Label_7']['is_selected_for_sync'] === 1, 'Inbox and the user’s own labels are synchronized by default (like their Outlook folders)');
    $inbox = (int)$pdo->query("SELECT id FROM ecw_mail_folders WHERE mailbox_id = {$mb} AND provider_folder_id = 'INBOX'")->fetchColumn();

    group('history import');
    ecw_sync_history($mb, $inbox, 1, 50);
    $rows = $pdo->query("SELECT provider_message_id, subject, from_name, from_email, is_read, email_thread_id, direction, has_attachments, web_link FROM ecw_email_messages WHERE mailbox_id = {$mb} ORDER BY provider_message_id")->fetchAll();
    $msg = array_column($rows, null, 'provider_message_id');
    check(count($rows) === 2, 'two messages imported');
    check($msg['m1']['subject'] === 'Προσφορά valve', 'RFC 2047 subject decoded');
    check($msg['m1']['from_name'] === 'Doe, John' && $msg['m1']['from_email'] === 'john@client.test', 'quoted display name with a comma and lower-cased address');
    check((int)$msg['m1']['is_read'] === 0 && (int)$msg['m1']['has_attachments'] === 1, 'UNREAD label and multipart/mixed are honoured');
    check($msg['m1']['email_thread_id'] === $msg['m2']['email_thread_id'], 'Gmail threadId threads both messages together');
    check($msg['m2']['direction'] === 'outgoing', 'my own message is outgoing');
    check(str_contains((string)$msg['m1']['web_link'], 'mail.google.com') && str_contains((string)$msg['m1']['web_link'], '#all/t1'), '"Open original" links to Gmail');

    group('history delta');
    $r1 = ecw_sync_folder_delta($mb, $inbox);
    check(count($fake->callsMatching('#/profile$#')) === 1, 'first round takes a history baseline');
    $r2 = ecw_sync_folder_delta($mb, $inbox);
    check(count($fake->callsMatching('#startHistoryId=100#')) === 1, 'next round reads history from the baseline');
    $state = $pdo->query("SELECT provider_message_id, provider_removed_at FROM ecw_email_messages WHERE mailbox_id = {$mb}")->fetchAll(PDO::FETCH_KEY_PAIR);
    check(array_key_exists('m3', $state) && $state['m3'] === null, 'new message arrives');
    check($state['m2'] !== null, 'deleted message is marked removed');
    check(array_key_exists('m1', $state) && $state['m1'] === null, 'archived message (INBOX removed) stays in the app');
    $p = ecw_provider_for_mailbox($mb);
    $expired = $p->deltaPage('INBOX', 'h:5', null, 50);
    check($expired['needs_full_resync'] === true && $expired['cursor'] === null, 'expired history id asks for a full resync');

    group('bodies and attachments');
    $full = ['id' => 'm1', 'threadId' => 't1', 'labelIds' => ['INBOX'], 'internalDate' => (string)($now * 1000), 'payload' => [
        'mimeType' => 'multipart/mixed', 'headers' => $hdrs($m1Headers), 'parts' => [
            ['partId' => '0', 'mimeType' => 'multipart/related', 'parts' => [
                ['partId' => '0.0', 'mimeType' => 'multipart/alternative', 'parts' => [
                    ['partId' => '0.0.0', 'mimeType' => 'text/plain', 'headers' => $hdrs(['Content-Type' => 'text/plain; charset="UTF-8"']), 'body' => ['data' => $b64('Hello there')]],
                    ['partId' => '0.0.1', 'mimeType' => 'text/html', 'headers' => $hdrs(['Content-Type' => 'text/html; charset="ISO-8859-7"']), 'body' => ['data' => $b64(mb_convert_encoding('<p>Γεια <img src="cid:img1"></p>', 'ISO-8859-7', 'UTF-8'))]],
                ]],
                ['partId' => '0.1', 'mimeType' => 'image/png', 'filename' => 'logo.png', 'headers' => $hdrs(['Content-ID' => '<img1>', 'Content-Disposition' => 'inline']), 'body' => ['attachmentId' => 'ANGjdJ-inline', 'size' => 10]],
            ]],
            ['partId' => '1', 'mimeType' => 'application/pdf', 'filename' => 'quote.pdf', 'headers' => $hdrs(['Content-Disposition' => 'attachment; filename="quote.pdf"']), 'body' => ['attachmentId' => 'ANGjdJ-pdf-' . uniqid(), 'size' => 4]],
        ]]];
    $fake->on('GET', '#' . API . '/messages/m1\?format=full#', ['body' => $full])
         ->on('GET', '#' . API . '/messages/m1/attachments/ANGjdJ-pdf-#', ['body' => ['data' => $b64('%PDF'), 'size' => 4]]);
    $g = $p->getMessage('m1');
    check($g['body_text'] === 'Hello there', 'plain text part');
    check($g['body_html'] === '<p>Γεια <img src="cid:img1"></p>', 'HTML part converted from ISO-8859-7 to UTF-8');
    check($g['has_attachments'] === true, 'a real attachment sets has_attachments');
    $atts = array_column($p->listAttachments('m1'), null, 'provider_attachment_id');
    check(isset($atts['1'], $atts['0.1']) && $atts['1']['filename'] === 'quote.pdf', 'attachments keyed by MIME part id (Gmail attachment ids are not stable)');
    check($atts['0.1']['is_inline'] === true && $atts['0.1']['content_id'] === 'img1', 'inline image carries its Content-ID');
    check($p->downloadAttachment('m1', '1') === '%PDF', 'download resolves the current attachment id');

    group('sending');
    $fake->on('POST', '#' . API . '/messages/send$#', ['body' => ['id' => 'sent1', 'threadId' => 't1', 'labelIds' => ['SENT']]])
         ->on('GET', '#' . API . '/messages/sent1\?format=metadata#', $meta('sent1', ['SENT'], ['Message-ID' => '<CAgmail-123@mail.gmail.com>'], $now));
    $res = $p->sendMessage([
        'subject' => "Re: Προσφορά\r\nBcc: evil@attacker.test", 'body_html' => '<p>Thanks <b>John</b></p>',
        'to' => [['email' => 'john@client.test', 'name' => 'Doe, John']], 'cc' => [], 'bcc' => [['email' => 'not-an-address']],
        'reply_to_provider_message_id' => 'm1',
        'attachments' => [['filename' => 'a.txt', 'mime_type' => 'text/plain', 'content_bytes' => base64_encode('hi'), 'is_inline' => false, 'content_id' => null]],
    ]);
    $sendCall = $fake->callsMatching('#/messages/send$#')[0]['json'];
    $raw = base64_decode(strtr($sendCall['raw'], '-_', '+/'));
    [$head] = explode("\r\n\r\n", $raw, 2);
    check($sendCall['threadId'] === 't1', 'reply is sent into the Gmail thread');
    check(str_contains($head, "In-Reply-To: <orig@client.test>") && str_contains($head, 'References: <first@client.test> <orig@client.test>'), 'In-Reply-To and References thread it for every recipient');
    check(!preg_match('/^Bcc:/mi', $head) && !str_contains($head, 'evil@attacker.test'), 'CR/LF in the subject cannot inject a header; invalid Bcc dropped');
    check(str_contains($head, 'Subject: =?UTF-8?B?') && str_contains($head, '<john@client.test>'), 'non-ASCII subject is encoded; recipient kept');
    check(str_contains($raw, 'multipart/mixed') && str_contains($raw, 'filename="a.txt"'), 'attachment included');
    check($res['provider_message_id'] === 'sent1' && $res['internet_message_id'] === '<CAgmail-123@mail.gmail.com>', 'send returns the Sent id and Gmail\'s Message-ID');

    // A large email (big files) goes to Google's upload address as it is, still in its thread.
    $bigFile = random_bytes(5 * 1024 * 1024);
    $fake->on('POST', '#gmail\.googleapis\.com/upload/gmail/v1/users/me/messages/send\?uploadType=multipart#', ['body' => ['id' => 'sent2', 'threadId' => 't1', 'labelIds' => ['SENT']]])
         ->on('GET', '#' . API . '/messages/sent2\?format=metadata#', $meta('sent2', ['SENT'], ['Message-ID' => '<CAgmail-456@mail.gmail.com>'], $now));
    $before = count($fake->callsMatching('#/messages/send$#'));
    $res = $p->sendMessage(['subject' => 'Re: Προσφορά', 'body_html' => '<p>Drawings attached</p>', 'to' => [['email' => 'john@client.test', 'name' => 'John']], 'cc' => [], 'bcc' => [],
        'reply_to_provider_message_id' => 'm1',
        'attachments' => [['filename' => 'model.step', 'mime_type' => 'application/octet-stream', 'content_bytes' => base64_encode($bigFile), 'is_inline' => false, 'content_id' => null]]]);
    $up = $fake->callsMatching('#/upload/gmail/v1/users/me/messages/send#');
    $upRaw = (string)($up[0]['raw'] ?? '');
    $ct = (string)(array_values(preg_grep('/^Content-Type:/', $up[0]['headers'] ?? []))[0] ?? '');
    check(count($up) === 1 && count($fake->callsMatching('#/messages/send$#')) === $before, 'a 5 MB email goes to the upload address, not inside a JSON request');
    check(str_starts_with($ct, 'Content-Type: multipart/related; boundary=') && str_contains($upRaw, '{"threadId":"t1"}') && str_contains($upRaw, "Content-Type: message/rfc822\r\n\r\n"), 'multipart: the thread, then the email itself');
    check(strlen($upRaw) > strlen($bigFile) * 4 / 3 && str_contains($upRaw, 'filename="model.step"'), 'the file travels whole inside it');
    check(empty($up[0]['headers']['no-bearer']) && $res['provider_message_id'] === 'sent2', 'sent with the sign-in token, and the Sent id comes back');

    group('token refresh');
    $pdo->prepare('UPDATE ecw_mailboxes SET token_expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now - 10), $mb]);
    $fake->on('POST', '#oauth2\.googleapis\.com/token#', ['status' => 400, 'body' => ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']]);
    try { ecw_mailbox_access_token($mb); check(false, 'revoked Google grant throws'); } catch (Throwable $e) { check(true, 'revoked Google grant throws'); }
    check($pdo->query("SELECT sync_status FROM ecw_mailboxes WHERE id = {$mb}")->fetchColumn() === 'needs_reauth', 'and the mailbox asks to reconnect');

    group('Google identity');
    $jwt = static fn(array $c): string => 'x.' . rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=') . '.sig';
    $good = ['iss' => 'https://accounts.google.com', 'aud' => 'gid.apps.googleusercontent.com', 'exp' => $now + 600, 'sub' => 'sub-9', 'nonce' => 'n1', 'email' => 'x@gmail.com', 'email_verified' => true];
    check(ecw_google_identity($jwt($good), 'gid.apps.googleusercontent.com', 'n1')['sub'] === 'sub-9', 'valid ID token accepted');
    foreach (['wrong audience' => ['aud' => 'other'], 'expired' => ['exp' => $now - 1], 'nonce mismatch' => ['nonce' => 'n2'], 'unverified email' => ['email_verified' => false], 'foreign issuer' => ['iss' => 'https://evil.test']] as $label => $bad) {
        try { ecw_google_identity($jwt($bad + $good), 'gid.apps.googleusercontent.com', 'n1'); check(false, $label . ' rejected'); }
        catch (RuntimeException $e) { check(true, $label . ' rejected'); }
    }

    group('OAuth completion');
    $fake2 = new FakeGraph();
    $fake2->on('POST', '#oauth2\.googleapis\.com/token#', ['body' => ['access_token' => 'ya29.new', 'refresh_token' => '1//new', 'expires_in' => 3599,
            'scope' => 'openid https://www.googleapis.com/auth/gmail.modify email profile',
            'id_token' => $jwt(['sub' => 'sub-42', 'nonce' => 'nonce-x', 'email' => 'new@gmail.com'] + $good)]])
          ->on('GET', '#openidconnect\.googleapis\.com/v1/userinfo#', ['body' => ['sub' => 'sub-42', 'email' => 'New@Gmail.com', 'name' => 'New Person', 'email_verified' => true]])
          ->install();
    $newId = ecw_oauth_complete($org, $user, 'auth-code', ['provider' => 'google', 'verifier' => 'v', 'nonce' => 'nonce-x']);
    $row = $pdo->query("SELECT provider, provider_account_id, email_address, sync_status FROM ecw_mailboxes WHERE id = {$newId}")->fetch();
    check($row['provider'] === 'google' && $row['provider_account_id'] === 'sub-42' && $row['email_address'] === 'new@gmail.com', 'Gmail mailbox row created from the Google identity');
    check(in_array($row['sync_status'], ['syncing', 'connected'], true), 'and it starts synchronizing');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_sync_queue WHERE mailbox_id = {$newId} AND job_type = 'folders'")->fetchColumn() === 1, 'folder discovery is queued');
    check(str_contains(ecw_provider_unauthenticated('google')->authorizationUrl('s', 'http://localhost/cb', 'v', 'n'), 'access_type=offline'), 'authorization asks for offline access');
} finally {
    FakeGraph::uninstall();
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
