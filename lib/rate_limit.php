<?php declare(strict_types=1);
/**
 * Email Case Workspace — rate limiting for auth and send endpoints.
 *
 * The step 05 security list requires this explicitly; nothing before this
 * step enforced it anywhere. A fixed-window counter is enough: it is exact
 * within the window, and the increment is one INSERT ... ON DUPLICATE KEY
 * UPDATE against a UNIQUE (scope, subject, window_start) key, so concurrent
 * requests from the same subject cannot race past the limit the way a
 * read-then-write check would.
 *
 * Callers pick the subject (typically "user:<id>", sometimes the remote IP
 * for pre-auth endpoints) and the scope name, so the same primitive serves
 * every limited endpoint without this file knowing about any of them.
 */

require_once __DIR__ . '/db.php';

final class EcwRateLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('rate limit exceeded, retry after ' . $retryAfterSeconds . 's');
    }
}

/**
 * Record one attempt for ($scope, $subject) and throw EcwRateLimitExceeded
 * once more than $maxAttempts have landed in the current $windowSeconds
 * window. Every call — allowed or not — counts, so a caller cannot avoid
 * being counted by inspecting the limit first.
 */
function ecw_rate_limit_hit(string $scope, string $subject, int $maxAttempts, int $windowSeconds): void
{
    $now         = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $windowStartSql = gmdate('Y-m-d H:i:s', $windowStart);

    $pdo = ecw_db();
    $pdo->prepare(
        'INSERT INTO `' . ecw_table('rate_limits') . '` (`scope`, `subject`, `window_start`, `count`)
              VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE `count` = `count` + 1'
    )->execute([$scope, $subject, $windowStartSql]);

    $st = $pdo->prepare(
        'SELECT `count` FROM `' . ecw_table('rate_limits') . '` WHERE `scope` = ? AND `subject` = ? AND `window_start` = ?'
    );
    $st->execute([$scope, $subject, $windowStartSql]);
    $count = (int)$st->fetchColumn();

    if ($count > $maxAttempts) {
        throw new EcwRateLimitExceeded(($windowStart + $windowSeconds) - $now);
    }
}
