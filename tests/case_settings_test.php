<?php declare(strict_types=1);
/**
 * Task 07 tests — case settings: rename and soft delete/restore
 * (lib/timeline.php:ecw_case_rename/ecw_case_soft_delete/ecw_case_restore,
 * lib/cases.php:ecw_case_list()'s deleted_at exclusion).
 *
 *   php tests/case_settings_test.php
 *
 * Database writes are real and rolled back, same technique as
 * case_list_test.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/crypto.php';

$pass = 0;
$fail = 0;

function group(string $name): void { echo "\n=== {$name} ===\n"; }
function ok(string $what): void   { global $pass; $pass++; echo "  ok    {$what}\n"; }
function bad(string $what, string $detail = ''): void { global $fail; $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function check(bool $cond, string $what, string $detail = ''): void { $cond ? ok($what) : bad($what, $detail); }
function checkSame($expected, $actual, string $what): void
{
    $expected === $actual ? ok($what)
        : bad($what, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$pdo = ecw_db();
$pdo->beginTransaction();

try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $eventCount = static function (int $caseId, string $type) use ($pdo): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('case_events') . '` WHERE case_id = ? AND event_type = ?');
        $st->execute([$caseId, $type]);
        return (int)$st->fetchColumn();
    };
    $threadCountOf = static function (int $caseId) use ($pdo): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?');
        $st->execute([$caseId]);
        return (int)$st->fetchColumn();
    };

    $orgId  = $ins('organizations', ['name' => 'Case Settings Test Org']);
    $userId = $ins('users', ['name' => 'Owner', 'email' => 'ownercs' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $otherOrgId  = $ins('organizations', ['name' => 'Other Org CS']);
    $otherCaseId = $ins('cases', [
        'organization_id' => $otherOrgId, 'case_number' => 'CASE-CS9001', 'name' => 'Another org case', 'status' => 'open',
    ]);

    $mailbox = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-cs-' . $orgId,
        'email_address' => 'ops-cs' . $orgId . '@example.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $threadId = $ins('email_threads', [
        'mailbox_id' => $mailbox, 'provider_conversation_id' => 'conv-cs-' . bin2hex(random_bytes(4)),
        'normalized_subject' => 'Case Settings Thread',
    ]);
    $caseId = $ins('cases', [
        'organization_id' => $orgId, 'case_number' => 'CASE-CS0001', 'name' => 'Original Name', 'status' => 'open',
    ]);
    $ins('case_threads', ['case_id' => $caseId, 'email_thread_id' => $threadId, 'linked_at' => ecw_now()]);

    // ------------------------------------------------------------- rename

    group('ecw_case_rename — validation');

    $r = ecw_case_rename($orgId, $caseId, '  Renamed Case  ', $userId);
    check($r['ok'] === true, 'a trimmed, valid name is accepted');
    checkSame('Renamed Case', $r['name'] ?? null, 'the stored name is trimmed');
    checkSame('Renamed Case', ecw_case_load($orgId, $caseId)['name'], 'and it persists');
    checkSame(1, $eventCount($caseId, 'CASE_RENAMED'), 'exactly one CASE_RENAMED event is logged');

    $r = ecw_case_rename($orgId, $caseId, 'Renamed Case', $userId);
    check($r['ok'] === true, 'renaming to the same (trimmed) name is a no-op, not an error');
    checkSame(1, $eventCount($caseId, 'CASE_RENAMED'), 'and it does not log a second event');

    $r = ecw_case_rename($orgId, $caseId, '   ', $userId);
    check($r['ok'] === false && ($r['status'] ?? null) === 422, 'an empty (post-trim) name is rejected with 422');
    checkSame('Renamed Case', ecw_case_load($orgId, $caseId)['name'], 'and the name is unchanged');

    $r = ecw_case_rename($orgId, $caseId, str_repeat('x', 121), $userId);
    check($r['ok'] === false && ($r['status'] ?? null) === 422, 'a 121-character name is rejected with 422');

    $r = ecw_case_rename($orgId, $caseId, str_repeat('y', 120), $userId);
    check($r['ok'] === true, 'a 120-character name (the boundary) is accepted');

    $r = ecw_case_rename($orgId, $caseId, 'Renamed Case', $userId);
    check($r['ok'] === true, 'reset back to a short name for the rest of the tests');

    $r = ecw_case_rename($orgId, $otherCaseId, 'Hijacked', $userId);
    check($r['ok'] === false && ($r['status'] ?? null) === 404, "renaming another org's case through this org is rejected as not found");
    checkSame('Another org case', ecw_case_load($otherOrgId, $otherCaseId)['name'], 'and the other case is untouched');

    // ------------------------------------------------------- soft delete

    group('ecw_case_soft_delete / ecw_case_restore');

    $before = ecw_case_load($orgId, $caseId);
    check($before !== null && $before['deleted_at'] === null, 'the case starts out active');
    $threadsBefore = $threadCountOf($caseId);

    check(ecw_case_soft_delete($orgId, $otherCaseId, $userId) === false, "deleting another org's case through this org fails");

    check(ecw_case_soft_delete($orgId, $caseId, $userId) === true, 'soft delete succeeds for an owned, active case');
    checkSame(1, $eventCount($caseId, 'CASE_DELETED'), 'and logs CASE_DELETED');

    $raw = $pdo->prepare('SELECT deleted_at, deleted_by_user_id FROM `' . ecw_table('cases') . '` WHERE id = ?');
    $raw->execute([$caseId]);
    $row = $raw->fetch();
    check($row['deleted_at'] !== null, 'deleted_at is stamped');
    checkSame($userId, (int)$row['deleted_by_user_id'], 'deleted_by_user_id records who deleted it');

    check(ecw_case_load($orgId, $caseId) === null, 'ecw_case_load() hides the deleted case by default');
    check(ecw_case_load($orgId, $caseId, true) !== null, 'but still finds it when explicitly asked to include deleted rows');

    $list = ecw_case_list($orgId, ['status' => 'all']);
    check(!in_array('Renamed Case', array_map(static fn($c) => $c['name'], $list['cases']), true),
        'the deleted case no longer appears in ecw_case_list()');

    $linked = ecw_case_for_thread($threadId);
    check($linked !== null && (int)$linked['id'] === $caseId,
        'ecw_case_for_thread() still finds the deleted case — the intake dedupe guard depends on this');

    checkSame($threadsBefore, $threadCountOf($caseId), 'the thread link survives the delete untouched');

    check(ecw_case_soft_delete($orgId, $caseId, $userId) === false, 'deleting an already-deleted case fails (ecw_case_load excludes it)');

    check(ecw_case_restore($orgId, $otherCaseId, $userId) === false, "restoring another org's (never-deleted) case through this org fails");

    check(ecw_case_restore($orgId, $caseId, $userId) === true, 'restore succeeds for the deleted case');
    checkSame(1, $eventCount($caseId, 'CASE_RESTORED'), 'and logs CASE_RESTORED');

    $restored = ecw_case_load($orgId, $caseId);
    check($restored !== null && $restored['deleted_at'] === null, 'the case is active again through the default (deleted-excluding) loader');

    $raw->execute([$caseId]);
    $row = $raw->fetch();
    check($row['deleted_at'] === null && $row['deleted_by_user_id'] === null, 'deleted_at and deleted_by_user_id are both cleared');

    $list = ecw_case_list($orgId, ['status' => 'all']);
    check(in_array('Renamed Case', array_map(static fn($c) => $c['name'], $list['cases']), true),
        'the restored case reappears in ecw_case_list()');

    checkSame($threadsBefore, $threadCountOf($caseId), 'and its thread link is still exactly what it was before delete — nothing was ever unlinked');

    check(ecw_case_restore($orgId, $caseId, $userId) === false, 'restoring an already-active case is a no-op failure, not a duplicate event');
    checkSame(1, $eventCount($caseId, 'CASE_RESTORED'), 'confirmed by the event count staying at one');

} catch (Throwable $e) {
    echo "FATAL: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $fail++;
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
