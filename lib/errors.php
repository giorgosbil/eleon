<?php declare(strict_types=1);
/**
 * Error reporting: so a problem at a customer's office is known before they
 * call about it.
 *
 *   - server: an uncaught exception or a fatal error in a page, the API or a
 *     daemon (installed by ecw_errors_install(), from lib/db.php);
 *   - browser: a script error on someone's screen (partials/nav.php sends it
 *     to api client_error).
 *
 * Reports are grouped by kind (the same error from the same place is one row
 * with a count), secrets are stripped (ecw_redact), and nothing ever leaves
 * the server: admins see them in Settings → System health and get one email
 * the first time a kind appears (ecw_errors_alert_due, from the daemons), and
 * again if one marked fixed comes back.
 *
 * Reporting must never cause an error of its own: every step is wrapped.
 */

require_once __DIR__ . '/log.php';

const ECW_ERRORS_KEEP_DAYS = 60;

/** Record one error. $where: file:line, or the page for the browser. */
/**
 * Not the app: a one-off command someone typed on the server (php -r, piped
 * PHP), the test suite, or any script outside bin/. Its mistakes are not
 * problems of the running app and must never email the admins. On 2026-10-10
 * a developer's typos in one-off database queries were reported as "1 new
 * problem" emails.
 */
function ecw_errors_ignored(): bool
{
    if (PHP_SAPI !== 'cli') return false;   // the website: always reported
    if (!empty($GLOBALS['ecw_errors_record_in_tests'])) return false;   // a test of the reporting itself
    if (defined('ECW_TESTING') || getenv('ECW_NO_ERROR_REPORTS') === '1') return true;
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['argv'][0] ?? '');
    $real = $script !== '' ? realpath($script) : false;
    return $real === false || !str_starts_with($real, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR);
}

function ecw_error_report(string $source, string $kind, string $message, string $where = '', string $detail = '', ?string $url = null): void
{
    if ($source !== 'browser' && ecw_errors_ignored()) return;
    static $busy = false;
    if ($busy || (defined('ECW_TESTING') && empty($GLOBALS['ecw_errors_record_in_tests']))) return;
    $busy = true;
    try {
        $message = mb_substr(trim(ecw_redact($message)), 0, 500) ?: '(no message)';
        $where = mb_substr(ecw_redact($where), 0, 255);
        // Same kind: numbers, ids and quoted values do not make it a new problem.
        $shape = (string)preg_replace(['/\d+/', "/'[^']*'/", '/"[^"]*"/', '/\s+/'], ['N', "'…'", '"…"', ' '], $message);
        $fp = sha1($source . '|' . $kind . '|' . $shape . '|' . preg_replace('/:\d+$/', '', $where));
        $userId = null;
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) $userId = isset($_SESSION['ecw_user_id']) ? (int)$_SESSION['ecw_user_id'] : null;
        $url ??= PHP_SAPI === 'cli' ? ('cli: ' . basename((string)($_SERVER['argv'][0] ?? ''))) : (string)($_SERVER['REQUEST_URI'] ?? '');
        $url = mb_substr((string)preg_replace('/([?&](?:csrf|token|code|state)=)[^&]*/i', '$1…', ecw_redact($url)), 0, 255);
        $pdo = ecw_db();
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO `' . ecw_table('error_reports') . '` (fingerprint, source, kind, message, location, detail, count, first_at, last_at, last_user_id, last_url)
                       VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE count = count + 1, last_at = VALUES(last_at), message = VALUES(message), location = VALUES(location),
                           detail = VALUES(detail), last_user_id = VALUES(last_user_id), last_url = VALUES(last_url),
                           notified_at = IF(resolved_at IS NULL, notified_at, NULL), resolved_at = NULL')
            ->execute([$fp, $source, mb_substr($kind, 0, 120), $message, $where ?: null, $detail !== '' ? mb_substr(ecw_redact($detail), 0, 8000) : null, $now, $now, $userId, $url ?: null]);
    } catch (Throwable $e) {
        error_log('[ecw] error report could not be stored: ' . $e->getMessage());
    } finally {
        $busy = false;
    }
}

/** A path inside the app, without the server's folders. */
function ecw_error_where(string $file, int $line): string
{
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    return (str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file)) . ':' . $line;
}

function ecw_error_report_throwable(Throwable $e, string $source = ''): void
{
    $source = $source ?: (PHP_SAPI === 'cli' ? 'daemon' : 'server');
    $trace = get_class($e) . ': ' . $e->getMessage() . "\n" . implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 20));
    if ($prev = $e->getPrevious()) $trace .= "\nCaused by " . get_class($prev) . ': ' . $prev->getMessage();
    ecw_error_report($source, get_class($e), $e->getMessage(), ecw_error_where($e->getFile(), $e->getLine()), $trace);
}

/** Catch what nothing else caught. Installed once, for pages, the API and the daemons. */
function ecw_errors_install(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    // A request that failed half-way: its unfinished changes are not kept anyway,
    // and the report must not wait for (or vanish with) that transaction.
    $rollback = static function (): void { try { if (ecw_db()->inTransaction()) ecw_db()->rollBack(); } catch (Throwable $e) {} };
    set_exception_handler(static function (Throwable $e) use ($rollback): void {
        $rollback();
        ecw_error_report_throwable($e);
        error_log('PHP Fatal error:  Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n");
            exit(255);
        }
        if (!headers_sent()) {
            http_response_code(500);
            $json = str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json');
            header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
            echo $json ? '{"error":"Something went wrong on the server. It has been reported to your admin."}' : "Something went wrong on the server. It has been reported to your admin.\n";
        }
    });
    register_shutdown_function(static function () use ($rollback): void {
        $err = error_get_last();
        if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $rollback();
        $kind = str_contains((string)$err['message'], 'Allowed memory size') ? 'Out of memory' : (str_contains((string)$err['message'], 'Maximum execution time') ? 'Took too long' : 'Fatal error');
        ecw_error_report(PHP_SAPI === 'cli' ? 'daemon' : 'server', $kind, (string)strtok((string)$err['message'], "\n"), ecw_error_where((string)$err['file'], (int)$err['line']), (string)$err['message']);
    });
}

// ------------------------------------------------------------------ reading

/** The open problems, newest first (System health). */
function ecw_errors_open(int $limit = 50): array
{
    $st = ecw_db()->prepare('SELECT e.*, u.name AS user_name, u.email AS user_email FROM `' . ecw_table('error_reports') . '` e
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = e.last_user_id
                              WHERE e.resolved_at IS NULL ORDER BY e.last_at DESC LIMIT ' . max(1, $limit));
    $st->execute();
    return $st->fetchAll();
}

/** For the health check: open problems seen in the last day, and how many of them are new. */
function ecw_errors_recent(): array
{
    $st = ecw_db()->query('SELECT COUNT(*) AS n, SUM(first_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS new_kinds, SUM(count) AS times
                             FROM `' . ecw_table('error_reports') . '` WHERE resolved_at IS NULL AND last_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $r = $st->fetch() ?: [];
    return ['kinds' => (int)($r['n'] ?? 0), 'new_kinds' => (int)($r['new_kinds'] ?? 0), 'times' => (int)($r['times'] ?? 0)];
}

/** Mark one fixed (it comes back as open, and is reported again, if it happens again). */
function ecw_error_resolve(int $id, int $actorId): bool
{
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('error_reports') . '` SET resolved_at = UTC_TIMESTAMP() WHERE id = ? AND resolved_at IS NULL');
    $st->execute([$id]);
    if ($st->rowCount() === 0) return false;
    require_once __DIR__ . '/audit.php';
    ecw_audit('error.resolved', 'error', $id, []);
    return true;
}

/**
 * From the daemons (every minute): tell the admins about kinds of error they
 * have not heard of yet — in the app and by email — and forget fixed ones
 * after ECW_ERRORS_KEEP_DAYS. Returns how many were reported.
 */
function ecw_errors_alert_due(): int
{
    $pdo = ecw_db();
    $pdo->exec('DELETE FROM `' . ecw_table('error_reports') . '` WHERE resolved_at IS NOT NULL AND last_at < UTC_TIMESTAMP() - INTERVAL ' . ECW_ERRORS_KEEP_DAYS . ' DAY');
    $rows = $pdo->query('SELECT id, source, kind, message, location, count FROM `' . ecw_table('error_reports') . '`
                          WHERE notified_at IS NULL AND resolved_at IS NULL ORDER BY first_at LIMIT 10')->fetchAll();
    if (!$rows) return 0;
    $pdo->prepare('UPDATE `' . ecw_table('error_reports') . '` SET notified_at = UTC_TIMESTAMP() WHERE id IN (' . implode(',', array_map(static fn($r) => (int)$r['id'], $rows)) . ')')->execute();
    $where = ['server' => 'on the server', 'daemon' => 'in the background', 'browser' => 'in someone’s browser'];
    $lines = array_map(static fn($r) => '⚠️ ' . ($where[$r['source']] ?? $r['source']) . ': ' . $r['message'] . ($r['location'] ? ' (' . $r['location'] . ')' : '') . ((int)$r['count'] > 1 ? ' — ' . (int)$r['count'] . ' times' : ''), $rows);
    $lines[] = 'Settings → System health → Problems lists them with details. Send them to your vendor if they keep happening.';
    require_once __DIR__ . '/health.php';
    ecw_health_notify('Email Case Workspace: ' . count($rows) . ' new problem' . (count($rows) === 1 ? '' : 's'), $lines);
    return count($rows);
}
