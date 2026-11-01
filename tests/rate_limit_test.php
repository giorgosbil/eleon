<?php declare(strict_types=1);
/**
 * Step 05 conformance test — rate limiting for auth and send endpoints.
 *
 *   php tests/rate_limit_test.php
 *
 * Part one checks shape: the table the migration created. Part two writes
 * real counter rows (rolled back at the end) and asserts the fixed-window
 * behaviour: independent counters per scope and per subject, the exact
 * attempt at which the limit trips, and a sane Retry-After value.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/rate_limit.php';

$pass = 0;
$fail = 0;
function ok(string $what): void   { global $pass; $pass++; echo "  ok    {$what}\n"; }
function bad(string $what): void  { global $fail; $fail++; echo "  FAIL  {$what}\n"; }
function check(bool $cond, string $what): void { $cond ? ok($what) : bad($what); }

$pdo = ecw_db();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

echo "\n=== 1. table shape ===\n";
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
$st->execute([$db, ecw_table('rate_limits')]);
check((int)$st->fetchColumn() === 1, 'ecw_rate_limits table exists');

$st = $pdo->prepare(
    'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
);
$st->execute([$db, ecw_table('rate_limits')]);
$indexes = [];
foreach ($st as $r) {
    $indexes[$r['INDEX_NAME']]['cols'][] = $r['COLUMN_NAME'];
    $indexes[$r['INDEX_NAME']]['unique'] = ((int)$r['NON_UNIQUE'] === 0);
}
$hasWindowUnique = false;
foreach ($indexes as $ix) {
    if ($ix['unique'] && $ix['cols'] === ['scope', 'subject', 'window_start']) {
        $hasWindowUnique = true;
    }
}
check($hasWindowUnique, 'UNIQUE (scope, subject, window_start) — the atomic-increment key');

echo "\n=== 2. behaviour (rolled back) ===\n";
$pdo->beginTransaction();
try {
    $scope    = 'test_scope_' . bin2hex(random_bytes(4));
    $subjectA = 'user:test-a';
    $subjectB = 'user:test-b';

    $allowed = 0;
    $blocked = false;
    for ($i = 0; $i < 5; $i++) {
        try {
            ecw_rate_limit_hit($scope, $subjectA, 3, 60);
            $allowed++;
        } catch (EcwRateLimitExceeded $e) {
            $blocked = true;
            check($e->retryAfterSeconds > 0 && $e->retryAfterSeconds <= 60,
                "retryAfterSeconds is within the window (got {$e->retryAfterSeconds})");
            break;
        }
    }
    check($allowed === 3, "exactly 3 calls allowed before the 4th is blocked (got {$allowed})");
    check($blocked, 'the 4th call throws EcwRateLimitExceeded');

    try {
        ecw_rate_limit_hit($scope, $subjectB, 3, 60);
        ok('a different subject has its own independent counter');
    } catch (EcwRateLimitExceeded $e) {
        bad('a different subject has its own independent counter');
    }

    try {
        ecw_rate_limit_hit($scope . '_other', $subjectA, 3, 60);
        ok('a different scope has its own independent counter, even for the same subject');
    } catch (EcwRateLimitExceeded $e) {
        bad('a different scope has its own independent counter, even for the same subject');
    }

    // A blocked call still counts, so retrying immediately cannot "reset" it
    // by finding a lower count than another concurrent request just saw.
    try {
        ecw_rate_limit_hit($scope, $subjectA, 3, 60);
        bad('a blocked subject stays blocked on the very next call');
    } catch (EcwRateLimitExceeded $e) {
        ok('a blocked subject stays blocked on the very next call');
    }
} finally {
    $pdo->rollBack();
}

$after = (int)$pdo->query(
    'SELECT COUNT(*) FROM `' . ecw_table('rate_limits') . '` WHERE scope LIKE "test_scope_%"'
)->fetchColumn();
check($after === 0, 'rollback left the database clean');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
