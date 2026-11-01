<?php declare(strict_types=1);
/**
 * Step 03 — render smoke test.
 *
 *   php tests/render_test.php
 *
 * The other suites call functions. This one renders the actual page and calls
 * the actual API endpoints, because a missing include, a typo in a template, or
 * an endpoint that fatals on its own first line is invisible to a unit test and
 * completely visible to a user.
 *
 * WHY SUBPROCESSES AND COMMITTED FIXTURES
 * ---------------------------------------
 * Every API action ends in exit(), which would end the test process, so each is
 * run in its own `php -r`. A separate process cannot see an uncommitted
 * transaction, so the fixture is committed and removed in a finally — deleting
 * the organization cascades the rest away. (nginx on this host answers 403 to
 * local curl, so driving the endpoints over HTTP is not an option.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/provider/MicrosoftGraphProvider.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0;
$fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function ok(string $w): void { global $pass; $pass++; echo "  ok    {$w}\n"; }
function bad(string $w, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
function check(bool $c, string $w, string $d = ''): void { $c ? ok($w) : bad($w, $d); }
function checkSame($e, $a, string $w): void
{
    $e === $a ? ok($w) : bad($w, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$root = dirname(__DIR__);
$pdo  = ecw_db();
$sessionId = 'ecwrender' . bin2hex(random_bytes(4));
$orgId = 0;

/** Run one endpoint in its own process, with a session for $userId. */
function render(string $file, array $get, int $userId, string $sessionId, string $method = 'GET', array $server = [], array $post = []): array
{
    $script = 'session_id(' . var_export($sessionId, true) . '); session_start();'
        . '$_SESSION["ecw_user_id"] = ' . $userId . ';'
        . '$_SESSION["ecw_csrf"] = "test-csrf";'
        . '$_GET = ' . var_export($get, true) . ';'
        . '$_POST = ' . var_export($post, true) . ';'
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';'
        . '$_SERVER["HTTP_HOST"] = "localhost";';
    foreach ($server as $k => $v) {
        $script .= '$_SERVER[' . var_export($k, true) . '] = ' . var_export($v, true) . ';';
    }
    $script .= 'include ' . var_export($file, true) . ';';

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
    // ------------------------------------------------------------- fixture
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Render Test Org ' . bin2hex(random_bytes(3))]);
    $userId = $ins('users', ['name' => 'Render Tester', 'email' => 'render' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $mailboxId = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-r' . $orgId,
        'email_address' => 'render' . $orgId . '@example.com', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $inboxId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'F-R-IN', 'name' => 'Inbox',
        'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);

    $provider = new MicrosoftGraphProvider(['tenant_id' => 't', 'client_id' => 'c']);
    $message  = $provider->normalizeMessage(FakeGraph::message([
        'id' => 'RENDER-1', 'subject' => 'Machine problem', 'conversationId' => 'conv-render',
    ]));
    $message['body_html'] = '<p>Can you advise?</p>';
    $ingested = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $message);

    $caseId = ecw_case_create($orgId, 'Render Case', $userId);
    ecw_case_link_thread($caseId, $ingested['thread_id'], 'Customer', $userId);

    // ---------------------------------------------------------------- page
    group('app shell');
    $result = render($root . '/web/app.php', [], $userId, $sessionId);
    check(!str_contains($result['out'] . $result['err'], 'Fatal error'), 'app.php renders without a fatal',
        substr($result['err'], 0, 300));
    check(str_contains($result['out'], '<title>Mail'), 'the page has its title');
    check(str_contains($result['out'], 'id="threadList"'), 'the thread list mounts');
    check(str_contains($result['out'], 'assets/vendor/quill/quill.js'), 'the editor is loaded from our own origin');
    check(!str_contains($result['out'], 'cdn.'), 'no third-party CDN is referenced at runtime');
    // The harness seeds its own short token, so this checks that the page carries
    // one at all, not its shape.
    check((bool)preg_match('/data-csrf="[^"]+"/', $result['out']), 'the page carries a CSRF token for the API');
    // Step 05C: Cc/Bcc collapse behind a toggle, and the folder/case sidebar
    // collapses into a drawer on narrow screens — both need their markup to
    // actually be on the page for app.js to find and wire up.
    check(str_contains($result['out'], 'id="toggleCcBcc"') && str_contains($result['out'], 'id="ccBccFields"'),
        'the composer has a Cc/Bcc toggle instead of two permanently open fields');
    check((bool)preg_match('/id="ccBccFields"[^>]*\bhidden\b/', $result['out']),
        'Cc/Bcc starts collapsed');
    check(str_contains($result['out'], 'id="navToggle"') && str_contains($result['out'], 'id="navBackdrop"'),
        'the folder/case sidebar has a drawer toggle for narrow screens');

    group('case timeline view');
    $result = render($root . '/web/case.php', ['id' => $caseId], $userId, $sessionId);
    check(!str_contains($result['out'] . $result['err'], 'Fatal error'), 'case.php renders without a fatal',
        substr($result['err'], 0, 300));
    check(str_contains($result['out'], 'Render Case'), 'the case name is server-rendered into the header');
    check(str_contains($result['out'], 'id="timeline"'), 'the timeline grid mounts');
    check((bool)preg_match('/data-case="' . $caseId . '"/', $result['out']), 'the case id reaches the client');
    check((bool)preg_match('/data-timezone="[^"]+"/', $result['out']),
        "the organization's timezone travels with the page, so times render the same everywhere");

    $result = render($root . '/web/case.php', ['id' => 999999999], $userId, $sessionId);
    check(str_contains($result['out'], 'Case not found'), 'an unknown case id is refused');

    // ----------------------------------------------------------------- api
    group('api endpoints');
    $api = $root . '/web/api/index.php';

    $result = render($api, ['action' => 'session'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && isset($json['csrf']), 'session returns a csrf token', substr($result['err'], 0, 200));

    $result = render($api, ['action' => 'mailboxes'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && count($json['mailboxes'] ?? []) === 1, 'mailboxes lists this organization only');

    $result = render($api, ['action' => 'folders', 'mailbox_id' => $mailboxId], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && count($json['folders'] ?? []) === 1, 'folders returns the tree');

    $result = render($api, ['action' => 'threads', 'mailbox_id' => $mailboxId], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && count($json['threads'] ?? []) === 1, 'threads lists the conversation');
    check(($json['threads'][0]['case']['name'] ?? '') === 'Render Case', 'the case marker rides along on the row');

    $result = render($api, ['action' => 'thread', 'mailbox_id' => $mailboxId, 'id' => $ingested['thread_id']], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(str_contains($json['messages'][0]['body_html'] ?? '', 'Can you advise?'), 'the reading pane gets a sanitized body');

    $result = render($api, ['action' => 'threads', 'mailbox_id' => $mailboxId, 'q' => 'subject:Machine'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(count($json['threads'] ?? []) === 1, 'search runs through the endpoint');

    $result = render($api, ['action' => 'nope'], $userId, $sessionId);
    check(str_contains($result['out'], 'unknown action'), 'an unknown action is rejected');

    group('case timeline endpoints');
    $result = render($api, ['action' => 'case_timeline', 'case_id' => $caseId], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json) && isset($json['summary'], $json['columns'], $json['events']),
        'case_timeline returns summary, columns and events', substr($result['err'], 0, 200));
    checkSame(1, count($json['columns'] ?? []), 'the linked thread appears as a column');
    checkSame('Customer', $json['columns'][0]['label'] ?? null, 'with the label it was linked under');
    check(count($json['events'] ?? []) > 0, 'and the message is on the axis');
    check(str_contains($json['events'][0]['bubble']['body_html'] ?? '', 'Can you advise?'),
        'the bubble carries a sanitized body');

    $result = render($api, ['action' => 'case_linkable_threads'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(is_array($json['threads'] ?? null), 'linkable threads can be listed for the picker');

    $result = render($api, ['action' => 'org_users'], $userId, $sessionId);
    $json   = json_decode($result['out'], true);
    check(count($json['users'] ?? []) >= 1, 'the owner picker can list organization members');


    // ------------------------------------------------------------ security
    group('authorization');
    $otherOrg  = $ins('organizations', ['name' => 'Intruder Org ' . bin2hex(random_bytes(3))]);
    $otherUser = $ins('users', ['name' => 'Intruder', 'email' => 'intruder' . $otherOrg . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $otherOrg, 'user_id' => $otherUser, 'role' => 'admin']);

    $result = render($api, ['action' => 'thread', 'mailbox_id' => $mailboxId, 'id' => $ingested['thread_id']], $otherUser, $sessionId . 'b');
    $json   = json_decode($result['out'], true);
    check(($json['error'] ?? '') === 'not found',
        "another organization's user cannot read this mailbox, and is told 'not found' rather than 'forbidden'");
    check(!str_contains($result['out'], 'Can you advise?'), 'and no message content leaks in the response');

    // A write without the CSRF header must be refused.
    $result = render($api, ['action' => 'mark_read', 'mailbox_id' => $mailboxId, 'thread_id' => $ingested['thread_id']],
        $userId, $sessionId, 'POST');
    check(str_contains($result['out'], 'csrf'), 'a write with no CSRF header is refused');

    $result = render($api, ['action' => 'mark_read', 'mailbox_id' => $mailboxId, 'thread_id' => $ingested['thread_id'], 'is_read' => 1],
        $userId, $sessionId, 'POST', ['HTTP_X_ECW_CSRF' => 'test-csrf']);
    $json = json_decode($result['out'], true);
    check(isset($json['updated']), 'a write with the CSRF header succeeds');

    $result = render($api, ['action' => 'case_timeline', 'case_id' => $caseId], $otherUser, $sessionId . 'd');
    check(!str_contains($result['out'], 'Render Case'),
        "another organization's user gets no case timeline");

    group('assistant API guards');
    $result = render($api, ['action'=>'ai_assist','mailbox_id'=>$mailboxId], $userId, $sessionId, 'GET');
    check(str_contains($result['out'],'POST required'), 'assistant paid calls require POST');
    $result = render($api, ['action'=>'ai_assist','mailbox_id'=>$mailboxId], $userId, $sessionId, 'POST');
    check(str_contains($result['out'],'invalid csrf token'), 'assistant requires CSRF before any provider call');
    $result = render($root . '/web/settings/ai.php', [], $userId, $sessionId);
    check($result['err'] === '' && str_contains($result['out'], 'Which AI does what') && str_contains($result['out'], 'Agents'), 'AI settings render, with their tabs');
    check(!str_contains($result['out'], 'sk-ant-'), 'assistant settings never expose API credentials');

    group('appearance settings');
    $pdo->prepare('UPDATE ecw_users SET preferences_json = ? WHERE id = ?')->execute([
        json_encode(['thread_order'=>'desc', 'appearance'=>['theme'=>'dark','background'=>'dots','sent'=>'#123456','received'=>'#abcdef']]), $userId,
    ]);
    $result = render($root . '/web/settings/appearance.php', [], $userId, $sessionId);
    check($result['err'] === '', 'appearance page renders without PHP errors');
    check(str_contains($result['out'], 'name="theme"') && str_contains($result['out'], 'name="sent"'), 'theme and message color controls render');
    check(str_contains($result['out'], '"theme":"dark"') && str_contains($result['out'], '#123456'), 'saved appearance loads into preview and navigation');
    $result = render($root . '/web/settings/appearance.php', [], $userId, $sessionId, 'POST');
    check(str_contains($result['out'], 'Invalid session token'), 'appearance changes require CSRF');
    $result = render($root . '/web/settings/appearance.php', [], $userId, $sessionId, 'POST', [],
        ['csrf'=>'test-csrf','theme'=>'mild','background'=>'lines','sent'=>'#abc123']);
    $prefs = json_decode((string)$pdo->query('SELECT preferences_json FROM ecw_users WHERE id = ' . $userId)->fetchColumn(), true);
    check($result['err'] === '' && $prefs['appearance']['theme'] === 'mild', 'authenticated appearance form persists personal settings');
    checkSame('desc', $prefs['thread_order'], 'appearance save preserves other preferences');


    group('named signatures');
    $result = render($api, ['action'=>'signature_profiles_save'], $userId, $sessionId, 'POST', ['HTTP_X_ECW_CSRF'=>'test-csrf'], ['name'=>'Work','html'=>'<p>Work signature<script>bad()</script></p>','is_default'=>true]);
    $set = json_decode($result['out'], true);
    check(isset($set['saved_id']) && $set['default_id'] === $set['saved_id'], 'a signature can be saved as default');
    $firstId = $set['saved_id'];
    check(!str_contains($set['profiles'][0]['html'], '<script'), 'signature HTML is sanitized');
    $result = render($api, ['action'=>'signature_profiles_save'], $userId, $sessionId, 'POST', ['HTTP_X_ECW_CSRF'=>'test-csrf'], ['name'=>'Support','html'=>'<p>Support signature</p>','is_default'=>true]);
    $set = json_decode($result['out'], true);
    check(count($set['profiles']) === 2 && $set['default_id'] !== $firstId, 'multiple signatures coexist with one selected default');
    $result = render($api, ['action'=>'compose','mailbox_id'=>$mailboxId,'mode'=>'new'], $userId, $sessionId);
    check(str_contains(json_decode($result['out'], true)['signature_html'], 'Support signature'), 'new composer resolves the selected default');
    $result = render($api, ['action'=>'signature_profiles_save'], $otherUser, $sessionId.'d', 'POST', ['HTTP_X_ECW_CSRF'=>'test-csrf'], ['id'=>$firstId,'operation'=>'delete']);
    check(str_contains($result['out'],'Signature not found'), 'another user cannot modify a signature');
    $result = render($api, ['action'=>'signature_profiles_save'], $userId, $sessionId, 'GET');
    check(str_contains($result['out'],'POST required'), 'signature mutations require POST');
    $result = render($api, ['action'=>'signature_profiles_save'], $userId, $sessionId, 'POST', ['HTTP_X_ECW_CSRF'=>'test-csrf'], ['id'=>$firstId,'operation'=>'delete']);
    check(count(json_decode($result['out'],true)['profiles']) === 1, 'individual signature deletion preserves the other signature');
    $prefs = json_decode((string)$pdo->query('SELECT preferences_json FROM ecw_users WHERE id = ' . $userId)->fetchColumn(), true);
    check($prefs['appearance']['theme'] === 'mild' && $prefs['thread_order'] === 'desc', 'signature saving preserves appearance and thread preferences');
    $result = render($root.'/web/settings/signatures.php', [], $userId, $sessionId);
    check($result['err'] === '' && str_contains($result['out'],'signatureProfilesForm'), 'signature settings page renders');

    // Signed out entirely.
    $result = render($api, ['action' => 'mailboxes'], 0, $sessionId . 'c');
    check(str_contains($result['out'], 'unauthenticated'),
        'an unauthenticated API request gets the 401 JSON body the step specifies');

} finally {
    if ($orgId > 0) {
        // Cascades remove the mailbox, folders, threads, messages and cases.
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')
            ->execute(['Render Test Org %']);
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')
            ->execute(['Intruder Org %']);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['render%@example.test']);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['intruder%@example.test']);
    }
}

$left = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('organizations') . "` WHERE name LIKE 'Render Test Org %'")->fetchColumn();
check($left === 0, 'the fixture is cleaned up');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
