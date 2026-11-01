<?php declare(strict_types=1);
/**
 * Large attachments (review R07): files above Graph's ~3 MB simple limit reach Outlook through an
 * upload session, in pieces of 320 KiB multiples with their byte ranges, never with the sign-in
 * token; a piece that broke off is sent again, a refused upload is cancelled with a clear error.
 * Small files keep the one-request path. The app takes up to 25 MB of files per email.
 *
 *   php tests/large_attachment_test.php
 *
 * Microsoft is scripted with FakeGraph (no network). The draft part runs in a transaction that is
 * rolled back, and its stored files are removed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/compose.php';
require_once dirname(__DIR__) . '/lib/provider/MicrosoftGraphProvider.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

const ME = 'https://graph\.microsoft\.com/v1\.0/me';
const UPLOAD = 'https://outlook.office.com/api/v2.0/Users(\'u\')/Messages(\'d1\')/AttachmentSessions(\'s1\')?authtoken=abc';
$big = random_bytes(7 * 1024 * 1024);   // 7 MB: three pieces of 3,276,800 bytes, the last shorter
$small = 'a small file';
$send = static fn(array $attachments): array => [
    'subject' => 'Drawings', 'body_html' => '<p>Attached</p>', 'to' => [['email' => 'client@example.test', 'name' => 'Client']], 'cc' => [], 'bcc' => [],
    'attachments' => $attachments,
];
$file = static fn(string $name, string $bytes, string $type = 'application/pdf'): array => ['filename' => $name, 'mime_type' => $type, 'content_bytes' => base64_encode($bytes), 'is_inline' => false, 'content_id' => null];
$puts = static fn(FakeGraph $g): array => array_values(array_filter($g->calls, static fn($c) => $c['method'] === 'PUT'));
$range = static fn(array $call): string => (string)(preg_grep('/^Content-Range:/', $call['headers'])[array_key_first(preg_grep('/^Content-Range:/', $call['headers']) ?: [0 => ''])] ?? '');
$sending = static fn(FakeGraph $g): FakeGraph => $g
    ->on('POST', '#' . ME . '/messages$#', ['body' => ['id' => 'd1', 'internetMessageId' => '<big@example.test>']])
    ->on('POST', '#' . ME . '/messages/d1/send$#', ['status' => 202])
    ->on('GET', '#/mailFolders/sentitems/messages#', ['body' => ['value' => [['id' => 'sent1']]]]);

try {
    $provider = new MicrosoftGraphProvider(['client_id' => 'test'], fn() => 'secret-token');

    group('a 7 MB file goes up in pieces, a small one in one request');
    $g = $sending(new FakeGraph())
        ->on('POST', '#' . ME . '/messages/d1/attachments$#', ['body' => ['id' => 'att-small']])
        ->on('POST', '#' . ME . '/messages/d1/attachments/createUploadSession$#', ['body' => ['uploadUrl' => UPLOAD]])
        ->on('PUT', '#AttachmentSessions#',
            ['body' => ['nextExpectedRanges' => ['3276800-']]],
            ['status' => 503, 'body' => ['error' => ['code' => 'ServiceUnavailable']]],   // a piece that broke off
            ['body' => ['nextExpectedRanges' => ['6553600-']]],
            ['status' => 201, 'headers' => ['Location' => "https://outlook.office.com/api/v2.0/Users('u')/Messages('d1')/Attachments('att-big')"]]);
    $g->install();
    $res = $provider->sendMessage($send([$file('notes.txt', $small, 'text/plain'), $file('drawings.pdf', $big)]));
    $simple = array_values(array_filter($g->calls, static fn($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/messages/d1/attachments')));
    check(count($simple) === 1 && ($simple[0]['json']['name'] ?? '') === 'notes.txt' && base64_decode($simple[0]['json']['contentBytes']) === $small, 'the small file: one request, as before');
    $session = $g->callsMatching('#createUploadSession$#');
    check(count($session) === 1 && ($session[0]['json']['AttachmentItem']['size'] ?? 0) === strlen($big) && ($session[0]['json']['AttachmentItem']['name'] ?? '') === 'drawings.pdf'
        && ($session[0]['json']['AttachmentItem']['attachmentType'] ?? '') === 'file', 'the large file: an upload session with its name, type and size');
    $p = $puts($g);
    check(count($p) === 4, 'four requests for three pieces: the one that broke off went again', (string)count($p));
    check($range($p[0]) === 'Content-Range: bytes 0-3276799/7340032' && $range($p[1]) === 'Content-Range: bytes 3276800-6553599/7340032'
        && $range($p[2]) === 'Content-Range: bytes 3276800-6553599/7340032' && $range($p[3]) === 'Content-Range: bytes 6553600-7340031/7340032', 'each piece with its byte range, 320 KiB multiples');
    check($p[0]['raw'] . $p[2]['raw'] . $p[3]['raw'] === $big, 'the pieces put together are exactly the file');
    check(!array_filter($p, static fn($c) => empty($c['headers']['no-bearer'])), 'the upload address gets no sign-in token (it carries its own)');
    $order = array_map(static fn($c) => $c['method'] . ' ' . preg_replace('#^.*/#', '', $c['url']), $g->calls);
    check(array_search('POST send', $order, true) > array_key_last(array_filter($order, static fn($o) => str_starts_with($o, 'PUT'))), 'the email is sent only after the last piece');
    check(($res['provider_message_id'] ?? '') === 'sent1', 'and it is found in Sent Items');
    FakeGraph::uninstall();

    group('a refused upload stops the email, with the file named');
    $g = $sending(new FakeGraph())
        ->on('POST', '#' . ME . '/messages/d1/attachments/createUploadSession$#', ['body' => ['uploadUrl' => UPLOAD]])
        ->on('PUT', '#AttachmentSessions#', ['status' => 413, 'body' => ['error' => ['code' => 'ErrorMessageSizeExceeded']]])
        ->on('DELETE', '#AttachmentSessions#', ['status' => 204]);
    $g->on('DELETE', '#' . ME . '/messages/d1$#', ['status' => 204]);
    $g->install();
    try { $provider->sendMessage($send([$file('huge.zip', $big)])); check(false, 'refused upload throws'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'huge.zip') && str_contains($e->getMessage(), 'Outlook'), 'the error names the file', $e->getMessage()); }
    check(count($g->callsMatching('#/send$#')) === 0, 'nothing is sent');
    check(count(array_filter($g->calls, static fn($c) => $c['method'] === 'DELETE' && str_contains($c['url'], 'AttachmentSessions'))) === 1, 'the half-done upload is cancelled');
    check(count($g->callsMatching('#' . ME . '/messages/d1$#')) === 1, 'and the half-made draft is removed from Outlook, so a retry leaves no extra draft');
    FakeGraph::uninstall();

    group('an upload address that is not Microsoft\'s is not used');
    $g = $sending(new FakeGraph())
        ->on('POST', '#' . ME . '/messages/d1/attachments/createUploadSession$#', ['body' => ['uploadUrl' => 'https://evil.example.test/collect']]);
    $g->install();
    try { $provider->sendMessage($send([$file('drawings.pdf', $big)])); check(false, 'a foreign upload address throws'); }
    catch (RuntimeException $e) { check(true, 'a foreign upload address throws'); }
    check($puts($g) === [], 'no byte goes to it');
    FakeGraph::uninstall();

    group('drafts in Outlook get the large file too, once');
    $g = new FakeGraph();
    $g->on('POST', '#' . ME . '/messages$#', ['body' => ['id' => 'd1']])
      ->on('POST', '#' . ME . '/messages/d1/attachments/createUploadSession$#', ['body' => ['uploadUrl' => UPLOAD]])
      ->on('PUT', '#AttachmentSessions#', ['body' => ['nextExpectedRanges' => ['3276800-']]], ['body' => ['nextExpectedRanges' => ['6553600-']]],
          ['status' => 201, 'headers' => ['Location' => "https://outlook.office.com/api/v2.0/Users('u')/Messages('d1')/Attachments('att-big')"]])
      ->on('GET', '#' . ME . '/messages/d1/attachments\?#', ['body' => ['value' => [['id' => 'att-big', 'isInline' => false]]]]);
    $g->install();
    $saved = $provider->saveDraft(['subject' => 'Drawings', 'body_html' => '<p>x</p>', 'to' => [], 'cc' => [], 'bcc' => [],
        'attachments' => [['local_id' => 'L1', 'provider_attachment_id' => null, 'filename' => 'drawings.pdf', 'mime_type' => 'application/pdf', 'content_bytes' => base64_encode($big)]]], null);
    check(($saved['attachments']['L1'] ?? '') === 'att-big', 'the draft keeps the id Outlook gave it, so the next save does not upload it again');
    check(count(array_filter($g->calls, static fn($c) => $c['method'] === 'DELETE')) === 0, 'and it is not removed as "taken off in the app"');
    FakeGraph::uninstall();
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
}

group('the app takes up to 25 MB of files per email');
check(ECW_MAX_ATTACHMENT_BYTES === 25 * 1024 * 1024, '25 MB');
$pdo = ecw_db();
$pdo->beginTransaction();
$stored = [];
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Big files']);
    $me = $ins('users', ['name' => 'Maria', 'email' => 'maria' . $org . '@acme.test', 'password_hash' => 'x']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-' . $org, 'email_address' => 'sales' . $org . '@acme.test', 'sync_status' => 'connected', 'connected_by_user_id' => $me]);
    $draft = ecw_draft_save($org, $mb, $me, ['mode' => 'new', 'subject' => 'Drawings', 'body_html' => '<p>x</p>', 'to' => [], 'cc' => [], 'bcc' => []]);
    $tmp = static function (int $mb): string { $f = tempnam(sys_get_temp_dir(), 'ecwbig'); $h = fopen($f, 'wb'); ftruncate($h, $mb * 1024 * 1024); fclose($h); return $f; };
    $a = ecw_draft_attach($org, $draft, $tmp(20), 'model.step', 'application/octet-stream');
    check(($a['size'] ?? 0) === 20 * 1024 * 1024, 'a 20 MB file is attached');
    try { ecw_draft_attach($org, $draft, $t = $tmp(6), 'more.pdf', 'application/pdf'); check(false, 'over 25 MB together is refused'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), '25 MB'), 'a 6 MB file more (26 MB together) is refused, saying why', $e->getMessage()); @unlink($t); }
    $fresh = ecw_draft_save($org, $mb, $me, ['mode' => 'new', 'subject' => 'One huge file', 'body_html' => '<p>x</p>', 'to' => [], 'cc' => [], 'bcc' => []]);
    try { ecw_draft_attach($org, $fresh, $t = $tmp(26), 'huge.zip', 'application/zip'); check(false, 'a 26 MB file is refused'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'larger than 25 MB'), 'one 26 MB file is refused', $e->getMessage()); @unlink($t); }
    foreach ([$draft, $fresh] as $d) foreach (json_decode((string)(ecw_draft_load($org, $d)['attachments_json'] ?? '[]'), true) ?: [] as $f) $stored[] = $f['path'];
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($stored as $f) { @unlink($f); @rmdir(dirname($f)); }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
