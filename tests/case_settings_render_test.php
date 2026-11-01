<?php declare(strict_types=1);
/**
 * Task 07 — render/API smoke test for case settings (rename, soft delete,
 * restore).
 *
 *   php tests/case_settings_render_test.php
 *
 * case_settings_test.php pins the lib-level functions directly. This file
 * drives the actual API actions and pages as subprocesses — same
 * subprocess/committed-fixture technique as render_test.php and
 * case_list_render_test.php: every API action ends in exit(), a separate
 * process cannot see an uncommitted transaction, and nginx on this host
 * answers 403 to local curl, so HTTP is not an option.
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
$sessionId = 'ecwcasesettings' . bin2hex(random_bytes(4));
$orgId = 0;

/** Run one endpoint in its own process, with a session for $userId. Mirrors render_test.php. */
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
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Case Settings Render Org ' . bin2hex(random_bytes(3))]);
    $userId = $ins('users', ['name' => 'Render Tester', 'email' => 'casesettingsrender' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $otherOrgId  = $ins('organizations', ['name' => 'Case Settings Render Other Org ' . bin2hex(random_bytes(3))]);
    $otherUserId = $ins('users', ['name' => 'Other Org User', 'email' => 'csrenderother' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $otherOrgId, 'user_id' => $otherUserId, 'role' => 'admin']);

    $caseId = $ins('cases', [
        'organization_id' => $orgId, 'case_number' => 'CASE-CSR0001', 'name' => 'Render Settings Case', 'status' => 'open',
    ]);

    $api  = $root . '/web/api/index.php';
    $csrfHeader = ['HTTP_X_ECW_CSRF' => 'test-csrf'];

    // -------------------------------------------------------------- rename

    group('case_rename API action');

    $result = render($api, ['action' => 'case_rename', 'case_id' => $caseId], $userId, $sessionId, 'GET');
    check(str_contains($result['out'], 'POST required') || str_contains($result['out'], 'error'),
        'a GET request to case_rename is refused', substr($result['out'], 0, 200));

    $result = render($api, ['action' => 'case_rename', 'case_id' => $caseId], $userId, $sessionId, 'POST', [], ['name' => 'Hostile']);
    check(str_contains($result['out'], 'csrf'), 'a POST with no CSRF header is refused');

    $result = render($api, ['action' => 'case_rename', 'case_id' => $caseId], $userId, $sessionId, 'POST', $csrfHeader, ['name' => '   ']);
    $json = json_decode($result['out'], true);
    check(is_array($json) && isset($json['error']), 'an empty (post-trim) name is rejected through the live endpoint',
        substr($result['out'], 0, 200));

    $result = render($api, ['action' => 'case_rename', 'case_id' => $caseId], $userId, $sessionId, 'POST', $csrfHeader, ['name' => str_repeat('z', 121)]);
    $json = json_decode($result['out'], true);
    check(is_array($json) && isset($json['error']), 'a too-long name is rejected through the live endpoint');

    $result = render($api, ['action' => 'case_rename', 'case_id' => $caseId], $userId, $sessionId, 'POST', $csrfHeader, ['name' => '  Live Renamed  ']);
    $json = json_decode($result['out'], true);
    check(is_array($json) && ($json['name'] ?? null) === 'Live Renamed', 'a valid rename succeeds and trims through the live endpoint',
        substr($result['out'], 0, 200));

    $result = render($api, ['action' => 'case_list', 'status' => 'all'], $userId, $sessionId);
    check(str_contains($result['out'], 'Live Renamed'), 'the rename is visible from case_list right after');

    $result = render($api, ['action' => 'case_rename', 'case_id' => $caseId], $otherUserId, $sessionId . 'other', 'POST', $csrfHeader, ['name' => 'Hijacked']);
    check(str_contains($result['out'], 'not found'), "another organization's user renaming this case gets not-found, not a silent success");
    $stillNamed = $pdo->prepare('SELECT name FROM `' . ecw_table('cases') . '` WHERE id = ?');
    $stillNamed->execute([$caseId]);
    check($stillNamed->fetchColumn() === 'Live Renamed', 'and the name is actually untouched');

    // -------------------------------------------------------------- delete

    group('case_delete / case_restore API actions');

    $result = render($api, ['action' => 'case_delete', 'case_id' => $caseId], $userId, $sessionId, 'GET');
    check(str_contains($result['out'], 'POST required') || $result['out'] === '', 'a GET request to case_delete is refused',
        substr($result['out'], 0, 200));

    $result = render($api, ['action' => 'case_delete', 'case_id' => $caseId], $otherUserId, $sessionId . 'other', 'POST', $csrfHeader);
    check(str_contains($result['out'], 'not found'), "another organization's user cannot delete this case");

    $result = render($api, ['action' => 'case_delete', 'case_id' => $caseId], $userId, $sessionId, 'POST', $csrfHeader);
    $json = json_decode($result['out'], true);
    check(is_array($json) && ($json['deleted'] ?? null) === true, 'delete succeeds for the owning organization', substr($result['out'], 0, 200));

    $result = render($api, ['action' => 'case_list', 'status' => 'all'], $userId, $sessionId);
    check(!str_contains($result['out'], 'Live Renamed'), 'the deleted case is gone from case_list through the live endpoint');

    $result = render($root . '/web/case.php', ['id' => $caseId], $userId, $sessionId);
    check(str_contains($result['out'], 'Case not found'), 'the deleted case 404s from case.php directly');

    $result = render($api, ['action' => 'case_delete', 'case_id' => $caseId], $userId, $sessionId, 'POST', $csrfHeader);
    check(str_contains($result['out'], 'not found'), 'deleting an already-deleted case is rejected, not a silent no-op success');

    $result = render($api, ['action' => 'case_restore', 'case_id' => $caseId], $otherUserId, $sessionId . 'other', 'POST', $csrfHeader);
    check(str_contains($result['out'], 'not found'), "another organization's user cannot restore this case");

    $result = render($api, ['action' => 'case_restore', 'case_id' => $caseId], $userId, $sessionId, 'POST', $csrfHeader);
    $json = json_decode($result['out'], true);
    check(is_array($json) && ($json['restored'] ?? null) === true, 'restore succeeds for the owning organization', substr($result['out'], 0, 200));

    $result = render($api, ['action' => 'case_list', 'status' => 'all'], $userId, $sessionId);
    check(str_contains($result['out'], 'Live Renamed'), 'the restored case reappears in case_list through the live endpoint');

    $result = render($root . '/web/case.php', ['id' => $caseId], $userId, $sessionId);
    check(str_contains($result['out'], 'Live Renamed') && !str_contains($result['out'], 'Case not found'),
        'and case.php serves it normally again');

    // ------------------------------------------------------------- markup

    group('settings menu markup');

    $result = render($root . '/web/cases.php', ['status' => 'all'], $userId, $sessionId);
    check(str_contains($result['out'], 'class="menu case-settings-menu"'), 'the case list row renders a settings menu');
    check(str_contains($result['out'], 'data-case-action="rename"') && str_contains($result['out'], 'data-case-action="delete"'),
        'built from the row actions array — both actions render as data-case-action buttons');
    check((bool)preg_match('/data-case-id="' . $caseId . '"[^>]*data-case-name="[^"]*"[^>]*data-case-threads="\d+"/', $result['out']),
        'the row carries the id/name/thread-count data the client needs for rename and the delete dialog');
    check(str_contains($result['out'], 'id="deleteCaseOverlay"') && str_contains($result['out'], 'never deleted or moved'),
        'the delete confirmation dialog is present and says emails are never touched');

    $result = render($root . '/web/case.php', ['id' => $caseId], $userId, $sessionId);
    check(str_contains($result['out'], 'id="caseRenameBtn"') && str_contains($result['out'], 'id="caseDeleteBtn"'), 'the open case header has its rename and delete buttons');
    check(str_contains($result['out'], 'id="deleteCaseOverlay"') && str_contains($result['out'], 'never deleted or moved'),
        'and its own delete confirmation dialog, with the same never-touches-email text');

} finally {
    if ($orgId > 0) {
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')
            ->execute(['Case Settings Render Org %']);
        $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')
            ->execute(['Case Settings Render Other Org %']);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['casesettingsrender%@example.test']);
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(['csrenderother%@example.test']);
    }
}

$left = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('organizations') . "` WHERE name LIKE 'Case Settings Render Org %'")->fetchColumn();
check($left === 0, 'the fixture is cleaned up');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
