<?php declare(strict_types=1);
/**
 * Task 06 — render smoke test for the case list filter bar.
 *
 *   php tests/case_list_render_test.php
 *
 * case_list_test.php pins the filtering SQL by calling ecw_case_list()
 * directly. This file drives the actual page and the actual API action —
 * same reasoning and same subprocess/committed-fixture technique as
 * render_test.php: every API action ends in exit(), a separate process
 * cannot see an uncommitted transaction, and nginx on this host answers 403
 * to local curl, so HTTP is not an option.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/crypto.php';

$pass = 0;
$fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function ok(string $w): void { global $pass; $pass++; echo "  ok    {$w}\n"; }
function bad(string $w, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
function check(bool $c, string $w, string $d = ''): void { $c ? ok($w) : bad($w, $d); }

$root = dirname(__DIR__);
$pdo  = ecw_db();
$sessionId = 'ecwcaselist' . bin2hex(random_bytes(4));
$orgId = 0;

/** Run one endpoint in its own process, with a session for $userId. Mirrors render_test.php. */
function render(string $file, array $get, int $userId, string $sessionId): array
{
    $script = 'session_id(' . var_export($sessionId, true) . '); session_start();'
        . '$_SESSION["ecw_user_id"] = ' . $userId . ';'
        . '$_SESSION["ecw_csrf"] = "test-csrf";'
        . '$_GET = ' . var_export($get, true) . ';'
        . '$_SERVER["REQUEST_METHOD"] = "GET";'
        . '$_SERVER["HTTP_HOST"] = "localhost";'
        . 'include ' . var_export($file, true) . ';';

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(PHP_BINARY . ' -r ' . escapeshellarg($script), $descriptors, $pipes, dirname($file));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['out' => (string)$stdout, 'err' => (string)$stderr];
}

try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Case List Render Org ' . bin2hex(random_bytes(3))]);
    $userId = $ins('users', ['name' => 'Render Tester', 'email' => 'caselistrender' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $mailbox1 = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-cl1-' . $orgId,
        'email_address' => 'ops' . $orgId . '@example.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $mailbox2 = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-cl2-' . $orgId,
        'email_address' => 'sales' . $orgId . '@example.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);

    $threadId = $ins('email_threads', [
        'mailbox_id' => $mailbox1, 'provider_conversation_id' => 'conv-cl-' . bin2hex(random_bytes(4)),
        'normalized_subject' => 'Render Case Thread',
    ]);
    $msgId = $ins('email_messages', [
        'mailbox_id' => $mailbox1, 'email_thread_id' => $threadId,
        'provider_message_id' => 'msg-cl-' . bin2hex(random_bytes(4)),
        'subject' => 'irrelevant', 'from_email' => 'buyer@example.test',
        'direction' => 'incoming', 'has_attachments' => 1,
        'received_at' => ecw_now(), 'sent_at' => ecw_now(),
    ]);
    $ins('message_participants', ['email_message_id' => $msgId, 'role' => 'from', 'email' => 'buyer@example.test']);

    $caseId = $ins('cases', [
        'organization_id' => $orgId, 'case_number' => 'CASE-RENDER1', 'name' => 'Render Filter Case', 'status' => 'open',
    ]);
    $ins('case_threads', ['case_id' => $caseId, 'email_thread_id' => $threadId, 'linked_at' => ecw_now()]);

    // ---------------------------------------------------------------- page
    group('cases.php shell');
    $result = render($root . '/web/cases.php', [], $userId, $sessionId);
    check(!str_contains($result['out'] . $result['err'], 'Fatal error'), 'cases.php renders without a fatal',
        substr($result['err'], 0, 300));
    check(str_contains($result['out'], '<title>Cases'), 'the page has its title');
    check(str_contains($result['out'], 'id="caseFilterBar"'), 'the filter bar mounts');
    check(str_contains($result['out'], 'id="caseFiltersPopover"'), 'the filters popover mounts');
    check(str_contains($result['out'], 'Render Filter Case'), 'the seeded case is server-rendered into the table');
    check(!str_contains($result['out'], 'class="case-filter-mailbox"'), 'no Mailbox filter: the account chosen at the top decides (lib/account.php)');
    check((bool)preg_match('/id="caseListBootstrap"[^>]*>(\{.*?\})<\/script>/s', $result['out'], $m), 'the JS bootstrap JSON is embedded');
    if (isset($m[1])) {
        $bootstrap = json_decode($m[1], true);
        check(is_array($bootstrap) && ($bootstrap['filters']['status'] ?? null) === 'open', 'the bootstrap carries the default status filter');
    }

    group('cases.php with filters in the query string');
    $result = render($root . '/web/cases.php', ['status' => 'all', 'q' => 'Render'], $userId, $sessionId);
    check(!str_contains($result['out'] . $result['err'], 'Fatal error'), 'a filtered request renders without a fatal',
        substr($result['err'], 0, 300));
    check(str_contains($result['out'], 'Render Filter Case'), 'the text filter still matches the seeded case');
    check(str_contains($result['out'], 'data-chip-key="q"'), 'the active search term shows as a removable chip');

    $result = render($root . '/web/cases.php', ['status' => 'closed'], $userId, $sessionId);
    check(!str_contains($result['out'], 'Render Filter Case'), 'a status filter that excludes the case actually excludes it');
    check(str_contains($result['out'], 'id="caseEmptyState"'), 'the empty state renders with nothing left to show');

    // ----------------------------------------------------------------- api
    group('case_list API action');
    $api = $root . '/web/api/index.php';

    $result = render($api, ['action' => 'case_list'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && ($json['total'] ?? null) === 1, 'case_list returns the one seeded case', substr($result['err'], 0, 200));

    $result = render($api, ['action' => 'case_list', 'mailbox_ids' => [$mailbox2]], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && ($json['total'] ?? null) === 0, 'mailbox_ids filters through the live endpoint, not just the function');

    $result = render($api, ['action' => 'case_list', 'sort' => "x'); DROP TABLE ecw_cases; --", 'dir' => 'garbage'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(!str_contains($result['out'] . $result['err'], 'Fatal error'), 'a hostile sort/dir value does not fatal the live endpoint',
        substr($result['err'], 0, 300));
    check(is_array($json) && ($json['total'] ?? null) === 1, 'the same hostile request still returns the correct, unaffected result');

    // Signed out entirely.
    $result = render($api, ['action' => 'case_list'], 0, $sessionId . 'b');
    check(str_contains($result['out'], 'unauthenticated'), 'an unauthenticated request gets the 401 JSON body, same as every other action');

} finally {
    if ($orgId > 0) {
        // Cascades remove the mailboxes, threads, messages and cases.
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')
            ->execute(['Case List Render Org %']);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['caselistrender%@example.test']);
    }
}

$left = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('organizations') . "` WHERE name LIKE 'Case List Render Org %'")->fetchColumn();
check($left === 0, 'the fixture is cleaned up');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
