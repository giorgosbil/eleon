<?php declare(strict_types=1);
/**
 * Task 04 — in-app preview for image/PDF attachments.
 *
 *   php tests/attachment_preview_test.php
 *
 * ecw_inline_preview_allowed() is a pure function, tested directly. The rest
 * of the decision only shows up in HTTP response headers (Content-Disposition
 * is what decides whether a browser renders bytes in place or offers to save
 * them), and header() is a documented no-op under the plain CLI SAPI —
 * headers_list() always comes back empty there, so render_test.php's
 * subprocess technique (which only ever asserts on the JSON body) can't see
 * them. This file instead launches PHP's own built-in web server for the
 * `web/` docroot — a real "cli-server" SAPI, where header() behaves — makes
 * genuine HTTP requests against it, and reads the actual response headers
 * off the wire. Session auth is seeded by writing a native PHP session file
 * for a known id directly into a scratch save_path shared with the server,
 * rather than fighting cookie-jar plumbing for a one-shot GET.
 *
 * The core case: a file named invoice.pdf, claimed by the DB's mime_type
 * column (i.e. whatever the sending mail client claimed) as application/pdf,
 * whose bytes on disk are actually HTML. The endpoint must not take the
 * DB's word for it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/provider/MicrosoftGraphProvider.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0;
$fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function ok(string $w): void { global $pass; $pass++; echo "  ok    {$w}\n"; }
function bad(string $w, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
function check(bool $c, string $w, string $d = ''): void { $c ? ok($w) : bad($w, $d); }

/** Write a real PHP session file for $userId under a known id, in isolation from the real session store. */
function seedSession(string $savePath, string $sid, int $userId): void
{
    $script = 'session_save_path(' . var_export($savePath, true) . ');'
        . 'session_id(' . var_export($sid, true) . ');'
        . 'session_start();'
        . '$_SESSION["ecw_user_id"] = ' . $userId . ';'
        . '$_SESSION["ecw_csrf"] = "test-csrf";'
        . 'session_write_close();';
    exec(PHP_BINARY . ' -r ' . escapeshellarg($script));
}

/** GET the attachment endpoint through the real HTTP server, session-authenticated. */
function fetchAttachment(string $baseUrl, string $sid, int $attachmentId, bool $inline): array
{
    $qs = http_build_query(['action' => 'attachment', 'id' => $attachmentId] + ($inline ? ['inline' => '1'] : []));
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Cookie: PHPSESSID={$sid}\r\n",
    ]]);
    $body = file_get_contents($baseUrl . '/api/index.php?' . $qs, false, $ctx);
    return ['body' => (string)$body, 'headers' => $http_response_header ?? []];
}

function headerLine(array $result, string $name): ?string
{
    foreach ($result['headers'] as $line) {
        if (stripos($line, $name . ':') === 0) return trim($line);
    }
    return null;
}

/** True when Content-Disposition's *value* (not the header name) starts with $want. */
function dispositionIs(array $result, string $want): bool
{
    $line = (string)headerLine($result, 'Content-Disposition');
    $value = trim((string)preg_replace('/^Content-Disposition:\s*/i', '', $line));
    return stripos($value, $want) === 0;
}

$root = dirname(__DIR__);
$pdo  = ecw_db();
$orgId = 0;
$tmpFiles = [];
$sessionDir = sys_get_temp_dir() . '/ecw-preview-sess-' . bin2hex(random_bytes(4));
mkdir($sessionDir, 0700);
$port = 20000 + (getmypid() % 20000);
$baseUrl = "http://127.0.0.1:{$port}";
$serverProc = proc_open(
    PHP_BINARY . ' -S 127.0.0.1:' . $port
        . ' -d session.save_path=' . escapeshellarg($sessionDir)
        . ' -t ' . escapeshellarg($root . '/web'),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes
);
$serverUp = false;
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($probe) { fclose($probe); $serverUp = true; break; }
    usleep(100000);
}

try {
    check($serverUp, 'the built-in PHP server for web/ came up');
    if (!$serverUp) {
        throw new RuntimeException('cannot continue without the test server');
    }

    // ------------------------------------------------------------- fixture
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId    = $ins('organizations', ['name' => 'Attachment Preview Test Org ' . bin2hex(random_bytes(3))]);
    $otherOrg = $ins('organizations', ['name' => 'Attachment Preview Other Org ' . bin2hex(random_bytes(3))]);
    $userId   = $ins('users', ['name' => 'Preview Tester', 'email' => 'preview' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $mailboxId = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-p' . $orgId,
        'email_address' => 'preview' . $orgId . '@example.com', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $inboxId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'F-P-IN', 'name' => 'Inbox',
        'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);

    $provider = new MicrosoftGraphProvider(['tenant_id' => 't', 'client_id' => 'c']);
    $message  = $provider->normalizeMessage(FakeGraph::message([
        'id' => 'PREVIEW-1', 'subject' => 'Quote attached', 'conversationId' => 'conv-preview',
    ]));
    $ingested  = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $message);
    $messageId = (int)$ingested['message_id'];

    /** Write real bytes to a tmp file and register an email_attachments row pointing at it. */
    $makeAttachment = static function (string $filename, string $claimedMime, string $bytes) use ($messageId, $pdo, &$tmpFiles): int {
        ecw_ingest_attachments($messageId, [[
            'provider_attachment_id' => 'ATT-' . bin2hex(random_bytes(4)),
            'filename' => $filename, 'mime_type' => $claimedMime, 'size_bytes' => strlen($bytes),
        ]]);
        $id = (int)$pdo->lastInsertId();
        $path = tempnam(sys_get_temp_dir(), 'ecw-preview-');
        file_put_contents($path, $bytes);
        $tmpFiles[] = $path;
        $pdo->prepare('UPDATE `' . ecw_table('email_attachments') . "` SET local_path = ? WHERE id = ?")
            ->execute([$path, $id]);
        return $id;
    };

    // A minimal but genuine 1x1 PNG.
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $htmlBytes = '<html><body><script>alert(1)</script>fake invoice</body></html>';
    $docxBytes = str_repeat('not really a docx but not html or an image either', 5);

    $honestPngId   = $makeAttachment('photo.png', 'image/png', $pngBytes);
    $mislabeledPng = $makeAttachment('scan.dat', 'application/octet-stream', $pngBytes);
    $fakePdfId     = $makeAttachment('invoice.pdf', 'application/pdf', $htmlBytes);
    $docxId        = $makeAttachment('specs.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docxBytes);

    $sid = 'ecwattprev' . bin2hex(random_bytes(4));
    seedSession($sessionDir, $sid, $userId);

    // --------------------------------------------------------------- tests

    group('ecw_inline_preview_allowed() — pure allow-list, real content-types only');
    check(ecw_inline_preview_allowed('application/pdf'), 'application/pdf is allowed');
    check(ecw_inline_preview_allowed('image/png'), 'image/png is allowed');
    check(ecw_inline_preview_allowed('image/jpeg'), 'image/jpeg is allowed');
    check(ecw_inline_preview_allowed('image/gif'), 'image/gif is allowed');
    check(ecw_inline_preview_allowed('image/webp'), 'image/webp is allowed');
    check(!ecw_inline_preview_allowed('text/html'), 'text/html is refused');
    check(!ecw_inline_preview_allowed('image/svg+xml'), 'image/svg+xml is refused');
    check(!ecw_inline_preview_allowed('application/octet-stream'), 'application/octet-stream is refused');
    check(!ecw_inline_preview_allowed(''), 'empty string is refused');

    group('?inline=1 — a genuine, honestly-labeled PNG');
    $result = fetchAttachment($baseUrl, $sid, $honestPngId, true);
    check(dispositionIs($result, 'inline'), 'served inline', (string)headerLine($result, 'Content-Disposition'));
    check(headerLine($result, 'Content-Type') === 'Content-Type: image/png', 'Content-Type is image/png', (string)headerLine($result, 'Content-Type'));
    check(headerLine($result, 'Content-Security-Policy') === 'Content-Security-Policy: sandbox', 'CSP sandbox is set on the inline response');
    check(headerLine($result, 'X-Content-Type-Options') === 'X-Content-Type-Options: nosniff', 'nosniff is set');
    check($result['body'] === $pngBytes, 'body bytes are the original PNG, unmodified');

    group('?inline=1 — real PNG bytes, but the DB/email claimed a different mime type');
    $result = fetchAttachment($baseUrl, $sid, $mislabeledPng, true);
    check(headerLine($result, 'Content-Type') === 'Content-Type: image/png',
        'still served as image/png — decided from finfo on the bytes, not the DB mime_type column claim',
        (string)headerLine($result, 'Content-Type'));
    check(dispositionIs($result, 'inline'), 'still inline', (string)headerLine($result, 'Content-Disposition'));

    group('?inline=1 — invoice.pdf that is actually HTML (the disguised-attachment case)');
    $result = fetchAttachment($baseUrl, $sid, $fakePdfId, true);
    check(!dispositionIs($result, 'inline'), 'NOT rendered inline despite the .pdf name and claimed application/pdf mime',
        (string)headerLine($result, 'Content-Disposition'));
    check(dispositionIs($result, 'attachment'), 'falls back to today\'s ordinary attachment response',
        (string)headerLine($result, 'Content-Disposition'));

    group('?inline=1 — a real but non-allow-listed file type (docx)');
    $result = fetchAttachment($baseUrl, $sid, $docxId, true);
    check(!dispositionIs($result, 'inline'), 'not rendered inline — docx is not on the allow-list',
        (string)headerLine($result, 'Content-Disposition'));

    group('plain download (no ?inline=1) is unchanged by this task');
    $result = fetchAttachment($baseUrl, $sid, $honestPngId, false);
    check(dispositionIs($result, 'attachment'),
        'a non-inline-flagged image attachment still downloads as an attachment, same as before this task',
        (string)headerLine($result, 'Content-Disposition'));
    check($result['body'] === $pngBytes, 'body is still served correctly for a plain download');

    group('authorization — inline does not bypass organization scoping');
    $otherUserId = $ins('users', ['name' => 'Other Org User', 'email' => 'other-user' . $otherOrg . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $otherOrg, 'user_id' => $otherUserId, 'role' => 'admin']);
    $otherSid = 'ecwattprevother' . bin2hex(random_bytes(4));
    seedSession($sessionDir, $otherSid, $otherUserId);
    $result = fetchAttachment($baseUrl, $otherSid, $honestPngId, true);
    check(strpos($result['body'], '"error"') !== false && strpos($result['body'], 'not found') !== false,
        'a user from another organization gets the same 404 as an unauthenticated request, inline or not', $result['body']);

} finally {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
    foreach (glob($sessionDir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($sessionDir);
    foreach ($tmpFiles as $path) {
        if (is_file($path)) { unlink($path); }
    }
    if ($orgId > 0) {
        // Cascades remove the mailbox, folder, message and attachment rows.
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE id = ?')->execute([$orgId]);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['preview%@example.test']);
    }
    if (isset($otherOrg) && $otherOrg > 0) {
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE id = ?')->execute([$otherOrg]);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['other-user%@example.test']);
    }
}

$left = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('organizations') . "` WHERE name LIKE 'Attachment Preview Test Org %'")->fetchColumn();
check($left === 0, 'the fixture is cleaned up');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
