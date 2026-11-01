<?php declare(strict_types=1);
/**
 * Email Case Workspace — background worker. CLI ONLY.
 *
 *   php bin/worker.php [--lifetime=3600] [--once]
 *
 * Work that is slow or can wait, kept out of the sync daemon so email always
 * arrives on time even when a local AI model takes a minute to answer:
 *
 *   - text versions of HTML-only emails (search and the AI need the words)
 *   - reading the text inside attachments (lib/attachment_text.php)
 *   - the AI agents: triage, case summaries, promises, digests (lib/agents.php)
 *
 * Like the sync daemon it exits after --lifetime and is restarted by Docker,
 * and an exclusive lock keeps it to one copy.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/log.php';
require_once dirname(__DIR__) . '/lib/body.php';
require_once dirname(__DIR__) . '/lib/attachment_text.php';

$opts = getopt('', ['lifetime::', 'once']);
$lifetime = max(60, (int)($opts['lifetime'] ?? 3600));
$once = isset($opts['once']);

$lock = fopen(dirname(__DIR__) . '/logs/.worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "another worker is already running\n"); exit(0); }

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

/**
 * Give HTML-only emails (most Outlook mail) their text version. New emails get it when their
 * body is stored (lib/body.php); this catches the rest. It walks the table once, 2000 ids a
 * round from the newest down, then only looks at emails added since: asking the whole table
 * every round read every email body (gigabytes) every few seconds and slowed everything else.
 */
function ecw_worker_body_text(int $limit = 200): int
{
    static $below = null, $above = 0;   // still to walk: ids under $below; already looked at: up to $above
    $t = ecw_table('email_messages');
    $pdo = ecw_db();
    $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$t}`")->fetchColumn();
    if ($below === null) { $below = $max + 1; $above = $max; }
    $missing = "body_fetch_state = 'fetched' AND (body_text IS NULL OR body_text = '') AND body_html IS NOT NULL AND body_html <> ''";
    if ($below > 1) {
        $from = max(1, $below - 2000);
        $rows = $pdo->query("SELECT id, body_html, quoted_html, quoted_text FROM `{$t}` WHERE id >= {$from} AND id < {$below} AND {$missing} LIMIT " . max(1, $limit))->fetchAll();
        if (count($rows) < $limit) $below = $from;   // a full batch: the same ids are looked at again
    } else {
        $rows = $pdo->query("SELECT id, body_html, quoted_html, quoted_text FROM `{$t}` WHERE id > {$above} AND id <= {$max} AND {$missing} LIMIT " . max(1, $limit))->fetchAll();
        if (count($rows) < $limit) $above = $max;
    }
    $up = $pdo->prepare("UPDATE `{$t}` SET body_text = ?, quoted_text = COALESCE(quoted_text, ?) WHERE id = ?");
    foreach ($rows as $r) {
        $text = ecw_html_plain((string)$r['body_html']);
        $quoted = ($r['quoted_text'] === null && (string)$r['quoted_html'] !== '') ? (ecw_html_plain((string)$r['quoted_html']) ?: null) : null;
        // A space keeps an email with no visible text from being picked up again.
        $up->execute([$text !== '' ? $text : ' ', $quoted, (int)$r['id']]);
    }
    return count($rows);
}

$started = time();
$lastAgents = 0;
ecw_log("worker started (lifetime {$lifetime}s)");
require_once dirname(__DIR__) . '/lib/health.php';
$lastOps = 0;
while ($running) {
    $did = 0;
    ecw_heartbeat('worker');
    // Every minute: off-site copy of the newest backup, update check (daily), health alerts.
    if (time() - $lastOps >= 60) {
        $lastOps = time();
        try { require_once dirname(__DIR__) . '/lib/offsite.php'; if (ecw_offsite_due()) $did++; } catch (Throwable $e) { ecw_log('worker: off-site copy failed: ' . $e->getMessage()); }
        try { require_once dirname(__DIR__) . '/lib/updates.php'; ecw_update_check(); } catch (Throwable $e) { ecw_log('worker: update check failed: ' . $e->getMessage()); }
        try { ecw_health_alerts_due(); } catch (Throwable $e) { ecw_log('worker: health check failed: ' . $e->getMessage()); }
        try { ecw_errors_alert_due(); } catch (Throwable $e) { ecw_log('worker: problem report failed: ' . $e->getMessage()); }
        try { require_once dirname(__DIR__) . '/lib/export.php'; if (ecw_export_due()) $did++; } catch (Throwable $e) { ecw_log('worker: export failed: ' . $e->getMessage()); }
    }
    try { $did += ecw_worker_body_text(200); } catch (Throwable $e) { ecw_log('worker: body text failed: ' . $e->getMessage()); }
    try { $did += ecw_attachment_text_due(4); } catch (Throwable $e) { ecw_log('worker: attachment text failed: ' . $e->getMessage()); }
    // Automation rules someone runs on earlier emails (lib/rule_backfill.php), a few conversations a round.
    try { require_once dirname(__DIR__) . '/lib/rule_backfill.php'; $did += ecw_rule_backfill_due(15); } catch (Throwable $e) { ecw_log('worker: rule on earlier emails failed: ' . $e->getMessage()); }
    // Hourly: attachment files nobody opened for a week leave the disk (lib/storage.php).
    try { require_once dirname(__DIR__) . '/lib/storage.php'; ecw_storage_housekeeping_due(); } catch (Throwable $e) { ecw_log('worker: storage clean-up failed: ' . $e->getMessage()); }
    // The AI agents run on their own, slower clock.
    if (time() - $lastAgents >= 20 && is_file(dirname(__DIR__) . '/lib/agents.php')) {
        $lastAgents = time();
        try {
            require_once dirname(__DIR__) . '/lib/agents.php';
            $did += ecw_agents_run_due();
        } catch (Throwable $e) { ecw_log('worker: agents failed: ' . $e->getMessage()); }
        // Data retention (Settings → Security): once a day.
        try {
            require_once dirname(__DIR__) . '/lib/security.php';
            ecw_retention_due();
        } catch (Throwable $e) { ecw_log('worker: retention failed: ' . $e->getMessage()); }
    }
    if ($once) break;
    if (time() - $started >= $lifetime) { ecw_log('worker reached its lifetime, exiting for a clean restart'); break; }
    if ($did === 0) sleep(5);
}
ecw_log('worker stopped');
