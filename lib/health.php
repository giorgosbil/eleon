<?php declare(strict_types=1);
/**
 * System health: is everything that runs by itself still running?
 *
 *   - the sync daemon and the background worker (heartbeats)
 *   - every mailbox (connected, syncing recently, not waiting to be reconnected)
 *   - the sync queue (nothing stuck, few failures)
 *   - disk space, backups (recent, and the off-site copy), license, updates
 *
 * Shown to admins in Settings → System health. The daemons check every few
 * minutes (ecw_health_alerts_due) and tell the admins — in the app and by
 * email, from an admin's connected mailbox — when something goes wrong and
 * again when it is fixed. Each daemon reports the other one, so a stopped
 * worker is noticed by the sync daemon and the other way round.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/license.php';

const ECW_HEALTH_OK = 'ok';
const ECW_HEALTH_WARN = 'warn';
const ECW_HEALTH_FAIL = 'fail';

/** A daemon is alive: called from its loop. */
function ecw_heartbeat(string $name): void
{
    static $last = [];
    if (isset($last[$name]) && time() - $last[$name] < 30) return;   // a write every 30 s at most
    $last[$name] = time();
    try { ecw_app_setting_set('heartbeat_' . $name, gmdate('Y-m-d H:i:s')); } catch (Throwable $e) {}
}

function ecw_heartbeat_age(string $name): ?int
{
    $v = ecw_app_setting('heartbeat_' . $name);
    return $v ? max(0, time() - (int)strtotime($v . ' UTC')) : null;
}

/** @return array<int,array{key:string, group:string, label:string, state:string, detail:string, fix:?string}> */
function ecw_health_checks(): array
{
    $out = [];
    $add = static function (string $key, string $group, string $label, string $state, string $detail, ?string $fix = null) use (&$out): void {
        $out[] = compact('key', 'group', 'label', 'state', 'detail', 'fix');
    };
    $ago = static function (?int $s): string {
        if ($s === null) return 'never';
        if ($s < 120) return $s . ' seconds ago';
        if ($s < 7200) return round($s / 60) . ' minutes ago';
        if ($s < 172800) return round($s / 3600) . ' hours ago';
        return round($s / 86400) . ' days ago';
    };
    $pdo = ecw_db();

    // Background processes.
    foreach (['sync' => ['Mail sync', 180, 'docker compose up -d sync'], 'worker' => ['Background worker (AI agents, reading files, retention)', 300, 'docker compose up -d worker']] as $name => [$label, $limit, $fix]) {
        $age = ecw_heartbeat_age($name);
        $add('daemon.' . $name, 'Running', $label, $age !== null && $age <= $limit ? ECW_HEALTH_OK : ECW_HEALTH_FAIL,
            $age === null ? 'Has not reported yet.' : 'Last seen ' . $ago($age) . '.', $age !== null && $age <= $limit ? null : 'Start it on the server: ' . $fix);
    }

    // Mailboxes.
    $mbs = $pdo->query("SELECT id, email_address, sync_status, status_message, last_sync_at, is_shared FROM `" . ecw_table('mailboxes') . "` WHERE sync_status <> 'disconnected' ORDER BY id")->fetchAll();
    foreach ($mbs as $mb) {
        $age = $mb['last_sync_at'] ? time() - (int)strtotime($mb['last_sync_at'] . ' UTC') : null;
        $state = ECW_HEALTH_OK; $detail = 'Synced ' . $ago($age) . '.'; $fix = null;
        if ($mb['sync_status'] === 'needs_reauth') { $state = ECW_HEALTH_FAIL; $detail = 'The sign-in expired: no new mail arrives.'; $fix = 'Reconnect it in Settings → Mailbox (the person who connected it).'; }
        elseif ($mb['sync_status'] === 'temp_error' && ($age === null || $age > 1800)) { $state = ECW_HEALTH_WARN; $detail = (string)($mb['status_message'] ?: 'Errors while syncing') . ' Last good sync ' . $ago($age) . '.'; }
        elseif (in_array($mb['sync_status'], ['connected', 'syncing'], true) && $age !== null && $age > 3600) { $state = ECW_HEALTH_WARN; $detail = 'No sync for ' . $ago($age) . '.'; $fix = 'Check that Mail sync is running.'; }
        $add('mailbox.' . $mb['id'], 'Mailboxes', ((int)$mb['is_shared'] === 1 ? 'Shared mailbox ' : 'Mailbox ') . $mb['email_address'], $state, $detail, $fix);
    }
    if (!$mbs) $add('mailbox.none', 'Mailboxes', 'Mailboxes', ECW_HEALTH_WARN, 'No mailbox is connected.', 'Connect one in Settings → Mailbox.');

    // The sync queue.
    $stuck = (int)$pdo->query("SELECT COUNT(*) FROM `" . ecw_table('sync_queue') . "` WHERE status = 'queued' AND available_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE")->fetchColumn();
    $failed = (int)$pdo->query("SELECT COUNT(*) FROM `" . ecw_table('sync_queue') . "` WHERE status = 'failed' AND updated_at > UTC_TIMESTAMP() - INTERVAL 1 DAY")->fetchColumn();
    $add('queue', 'Running', 'Sync queue', $stuck > 20 ? ECW_HEALTH_WARN : ECW_HEALTH_OK,
        ($stuck ? $stuck . ' job' . ($stuck === 1 ? '' : 's') . ' waiting over 30 minutes. ' : 'Nothing waiting long. ') . ($failed ? $failed . ' failed in the last day (retried by themselves).' : 'No failures in the last day.'));

    // Problems (lib/errors.php): crashes on the server and script errors in browsers.
    try {
        $err = ecw_errors_recent();
        $add('errors', 'Running', 'Problems', $err['kinds'] > 0 ? ECW_HEALTH_WARN : ECW_HEALTH_OK,
            $err['kinds'] === 0 ? 'No errors in the last day.' : $err['kinds'] . ' kind' . ($err['kinds'] === 1 ? '' : 's') . ' of error in the last day (' . $err['times'] . ' time' . ($err['times'] === 1 ? '' : 's') . ($err['new_kinds'] ? ', ' . $err['new_kinds'] . ' new' : '') . ').',
            $err['kinds'] > 0 ? 'See Problems below. If one keeps happening, send its details to your vendor.' : null);
    } catch (Throwable $e) {}
    // AI connections (lib/ai.php): paused, or a job's main AI failing (the backup answering).
    try {
        require_once __DIR__ . '/ai.php';
        foreach ($pdo->query('SELECT id, name FROM `' . ecw_table('organizations') . "` WHERE ai_json IS NOT NULL AND ai_json <> ''")->fetchAll() as $org) {
            $s = ecw_ai_settings((int)$org['id']);
            if ($s['paused']) {
                $add('ai.' . $org['id'], 'Running', 'AI', ECW_HEALTH_WARN, 'All AI is paused (since ' . (static function () use ($s, $org) { require_once __DIR__ . '/timezone.php'; return ecw_tz_format($s['paused_at'], ecw_tz_for_user(null, (int)$org['id'])); })() . ').', 'Switch it back on in Settings → AI when you are ready.');
                continue;
            }
            $usage = ecw_ai_usage((int)$org['id']);
            $names = ['server' => 'Built-in Claude'] + array_column($s['connections'], 'name', 'id');
            $failing = [];
            foreach ($s['jobs'] as $job => [$main, $backup]) {
                $u = $usage[$main] ?? null;
                if ($u && (int)$u['failures'] > 0 && $u['last_error_at'] !== null && strtotime($u['last_error_at'] . ' UTC') > time() - 86400
                    && ($u['last_used_at'] === null || $u['last_error_at'] >= $u['last_used_at'])) {
                    // One line per failing AI; say so when a backup is answering for it.
                    if (!isset($failing[$main]) || $backup) $failing[$main] = ($names[$main] ?? $main) . ': ' . $u['last_error'] . ($backup ? ' (the backup ' . ($names[$backup] ?? $backup) . ' is answering)' : '');
                }
            }
            $add('ai.' . $org['id'], 'Running', 'AI', $failing ? ECW_HEALTH_WARN : ECW_HEALTH_OK,
                $failing ? implode(' · ', array_values($failing)) : 'The AI connections answer.', $failing ? 'Check the key and the address in Settings → AI, and press Test.' : null);
        }
    } catch (Throwable $e) {}

    // Disk.
    $dir = dirname(__DIR__);
    $free = @disk_free_space($dir); $total = @disk_total_space($dir);
    if ($free !== false && $total) {
        $gb = $free / 1073741824; $pct = $free / $total * 100;
        $state = ($gb < 0.5 || $pct < 3) ? ECW_HEALTH_FAIL : (($gb < 2 || $pct < 10) ? ECW_HEALTH_WARN : ECW_HEALTH_OK);
        $add('disk', 'Storage', 'Disk space', $state, round($gb, 1) . ' GB free (' . round($pct) . '%).', $state === ECW_HEALTH_OK ? null : 'Free space on the server, or delete old backups.');
    }
    try {
        $size = (float)$pdo->query('SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        // The database's memory (ECW_DB_MEMORY): once the mail is much bigger
        // than it, screens wait on the disk (tools/perf/bench.php: 10× slower).
        $pool = (float)$pdo->query('SELECT @@innodb_buffer_pool_size')->fetchColumn();
        $small = $pool > 0 && $size > 2 * $pool;
        $add('database', 'Storage', 'Database', $small ? ECW_HEALTH_WARN : ECW_HEALTH_OK,
            round($size / 1048576) . ' MB' . ($pool > 0 ? ', ' . round($pool / 1048576) . ' MB of memory for it' : '') . '.' . ($small ? ' Not enough memory: screens will slow down.' : ''),
            $small ? 'Set ECW_DB_MEMORY=' . (int)ceil($size / 1073741824) . 'G in .env and restart (docker compose up -d), if the server has the RAM.' : null);
    } catch (Throwable $e) {}
    try {
        // What the space is used for (lib/storage.php): emails per account, and the attachment cache.
        require_once __DIR__ . '/storage.php';
        $sz = ecw_storage_sizes();
        $per = implode(', ', array_map(static fn($m) => $m['email'] . ' ' . number_format($m['emails']) . ($m['oldest'] ? ' (since ' . substr((string)$m['oldest'], 0, 4) . ')' : ''), $sz['per_mailbox']));
        $add('storage.mail', 'Storage', 'Emails and files', ECW_HEALTH_OK,
            number_format($sz['emails']) . ' emails, ' . round($sz['mail_bytes'] / 1048576) . ' MB in the database' . ($per !== '' ? ': ' . $per : '') . '. '
            . 'Attachment files on this server: ' . number_format($sz['files']) . ', ' . round($sz['files_bytes'] / 1048576) . ' MB (only files opened in the last ' . ECW_ATTACHMENT_CACHE_DAYS . ' days and pictures inside emails; the rest stay in Outlook / Gmail).');
    } catch (Throwable $e) {}

    // Backups.
    require_once __DIR__ . '/backup.php';
    if (getenv('ECW_BACKUPS') === '0') {
        $add('backup', 'Backups', 'Daily backup', ECW_HEALTH_WARN, 'Automatic backups are turned off (ECW_BACKUPS=0).', 'Make sure the server is backed up another way.');
    } else {
        $latest = 0;
        foreach (ecw_backup_list() as $b) $latest = max($latest, $b['created']);
        $age = $latest ? time() - $latest : null;
        $add('backup', 'Backups', 'Daily backup', $age !== null && $age < 36 * 3600 ? ECW_HEALTH_OK : ($age === null ? ECW_HEALTH_WARN : ECW_HEALTH_FAIL),
            $age === null ? 'No backup yet.' : 'Last backup ' . $ago($age) . '.', $age !== null && $age < 36 * 3600 ? null : 'Check that Mail sync is running (it makes the daily backup), or make one in Settings → Backups.');
    }
    require_once __DIR__ . '/offsite.php';
    $off = ecw_offsite_settings();
    if ($off['type'] === 'none') {
        $add('offsite', 'Backups', 'Off-site copy', ECW_HEALTH_WARN, 'Backups exist only on this server: a broken disk or a stolen server takes them too.', 'Set up an off-site copy in Settings → Backups.');
    } else {
        $st = ecw_offsite_status();
        $age = $st['ok_at'] ? time() - (int)strtotime($st['ok_at'] . ' UTC') : null;
        $bad = $st['error'] !== null && ($st['error_at'] ?? '') >= ($st['ok_at'] ?? '');
        $add('offsite', 'Backups', 'Off-site copy (' . ecw_offsite_label($off) . ')', $bad || $age === null || $age > 48 * 3600 ? ECW_HEALTH_FAIL : ECW_HEALTH_OK,
            $bad ? 'The last copy failed: ' . $st['error'] : ($age === null ? 'Nothing copied yet.' : 'Last copied ' . $ago($age) . '.'), $bad ? 'Check the settings in Settings → Backups and press "Test".' : null);
    }

    // License.
    $lic = ecw_license_state();
    if (!empty($lic['expired'])) $add('license', 'License', 'License', ECW_HEALTH_FAIL, 'The license expired on ' . $lic['expires'] . ': the app runs as Free.', 'Install a new license key in Settings → License.');
    elseif (!empty($lic['expires']) && $lic['expires'] < gmdate('Y-m-d', time() + 21 * 86400)) $add('license', 'License', 'License', ECW_HEALTH_WARN, 'The license ends on ' . $lic['expires'] . '.', 'Renew it before then.');
    elseif ($lic['trial_days_left'] !== null) $add('license', 'License', 'License', $lic['trial_days_left'] <= 7 ? ECW_HEALTH_WARN : ECW_HEALTH_OK, 'Trial: ' . $lic['trial_days_left'] . ' days left.');
    else $add('license', 'License', 'License', ECW_HEALTH_OK, ($lic['name'] ?? 'Free') . ($lic['customer'] ? ' — ' . $lic['customer'] : '') . ($lic['expires'] ? ', until ' . $lic['expires'] : '') . '.');

    // Version.
    require_once __DIR__ . '/updates.php';
    $u = ecw_update_check();
    $add('version', 'Version', 'Version ' . $u['current'], !empty($u['available']) ? ECW_HEALTH_WARN : ECW_HEALTH_OK,
        !empty($u['available']) ? 'Version ' . $u['latest'] . ' is available.' : (!empty($u['no_channel']) ? 'No update channel set.' : ($u['error'] ? 'Could not check for updates: ' . $u['error'] : 'Up to date' . ($u['checked_at'] ? ' (checked ' . $ago(time() - (int)strtotime($u['checked_at'] . ' UTC')) . ')' : '') . '.')),
        !empty($u['available']) ? 'Run .\scripts\update.ps1 on the server (it backs up first and rolls back by itself if needed).' : null);
    return $out;
}

/** The worst state of a list of checks. */
function ecw_health_overall(array $checks): string
{
    $states = array_column($checks, 'state');
    return in_array(ECW_HEALTH_FAIL, $states, true) ? ECW_HEALTH_FAIL : (in_array(ECW_HEALTH_WARN, $states, true) ? ECW_HEALTH_WARN : ECW_HEALTH_OK);
}

/** Alert settings (instance-wide): email admins, extra addresses. */
function ecw_alert_settings(): array
{
    $s = json_decode((string)(ecw_app_setting('alert_settings') ?? ''), true) ?: [];
    return ['email' => ($s['email'] ?? true) !== false, 'extra' => array_values(array_filter((array)($s['extra'] ?? []), static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)))];
}

function ecw_alert_settings_save(array $in): ?string
{
    $extra = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($in['extra'] ?? '')) ?: [])));
    foreach ($extra as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) return '"' . $a . '" is not an email address.';
    ecw_app_setting_set('alert_settings', (string)json_encode(['email' => !empty($in['email']), 'extra' => array_slice($extra, 0, 5)]));
    require_once __DIR__ . '/audit.php';
    ecw_audit('settings.alerts', null, null, ['email' => !empty($in['email']), 'extra' => count($extra)]);
    return null;
}

/**
 * Every few minutes from both daemons: compare with the last run and tell the
 * admins about what broke and what recovered. Something still broken is
 * repeated once a day. Only failures alert (warnings are on the page).
 * @return int alerts sent
 */
function ecw_health_alerts_due(bool $force = false): int
{
    $now = time();
    $lastRun = (int)(ecw_app_setting('health_alerts_ran') ?? 0);
    if (!$force && $now - $lastRun < 300) return 0;
    ecw_app_setting_set('health_alerts_ran', (string)$now);
    $prev = json_decode((string)(ecw_app_setting('health_state') ?? ''), true) ?: [];
    $checks = ecw_health_checks();
    $state = []; $broke = []; $fixed = [];
    foreach ($checks as $c) {
        if ($c['state'] !== ECW_HEALTH_FAIL) {
            if (isset($prev[$c['key']])) $fixed[] = $c;
            continue;
        }
        $since = (int)($prev[$c['key']]['since'] ?? $now);
        $told = (int)($prev[$c['key']]['told'] ?? 0);
        $state[$c['key']] = ['since' => $since, 'told' => $told];
        // Only after 10 minutes: a restart or an update is not an emergency.
        if ($now - $since >= 600 && ($told === 0 || $now - $told > 86400)) { $broke[] = $c; $state[$c['key']]['told'] = $now; }
    }
    // "Fixed" only for what was actually reported as broken.
    $fixed = array_values(array_filter($fixed, static fn($c) => (int)($prev[$c['key']]['told'] ?? 0) > 0));
    ecw_app_setting_set('health_state', (string)json_encode($state));
    if (!$broke && !$fixed) return 0;
    $lines = [];
    foreach ($broke as $c) $lines[] = '❌ ' . $c['label'] . ': ' . $c['detail'] . ($c['fix'] ? ' → ' . $c['fix'] : '');
    foreach ($fixed as $c) $lines[] = '✅ ' . $c['label'] . ': fixed.';
    $title = $broke ? 'Email Case Workspace needs attention: ' . $broke[0]['label'] . (count($broke) > 1 ? ' and ' . (count($broke) - 1) . ' more' : '') : 'Email Case Workspace: ' . count($fixed) . ' problem' . (count($fixed) === 1 ? '' : 's') . ' fixed';
    ecw_health_notify($title, $lines);
    ecw_log('health alert: ' . $title);
    return count($broke) + count($fixed);
}

/** To every admin: in the app, and by email when an admin mailbox can send. */
function ecw_health_notify(string $title, array $lines): void
{
    require_once __DIR__ . '/notifications.php';
    $pdo = ecw_db();
    $settings = ecw_alert_settings();
    foreach ($pdo->query('SELECT id FROM `' . ecw_table('organizations') . '`')->fetchAll(PDO::FETCH_COLUMN) as $orgId) {
        $admins = $pdo->prepare("SELECT u.id, u.name, u.email FROM `" . ecw_table('users') . "` u JOIN `" . ecw_table('organization_users') . "` ou ON ou.user_id = u.id
                                  WHERE ou.organization_id = ? AND ou.role = 'admin' AND u.disabled_at IS NULL");
        $admins->execute([(int)$orgId]);
        $rows = $admins->fetchAll();
        foreach ($rows as $a) {
            try { ecw_notify((int)$orgId, (int)$a['id'], null, 'system', $title . ' — ' . implode(' ', $lines), 'settings/health.php'); } catch (Throwable $e) {}
        }
        if (!$settings['email']) continue;
        $to = array_map(static fn($a) => ['email' => $a['email'], 'name' => $a['name']], $rows);
        foreach ($settings['extra'] as $x) $to[] = ['email' => $x, 'name' => null];
        if (!$to) continue;
        try {
            require_once __DIR__ . '/account_security.php';
            require_once __DIR__ . '/mailbox.php';
            $mailboxId = ecw_system_mailbox_id((int)$orgId);
            if ($mailboxId === null) continue;
            $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            ecw_provider_for_mailbox($mailboxId)->sendMessage([
                'subject' => $title,
                'body_html' => '<p>' . implode('</p><p>', array_map($h, $lines)) . '</p><p style="color:#666">Details: Settings → System health. You get this because you are an admin of Email Case Workspace.</p>',
                'to' => $to, 'cc' => [], 'bcc' => [], 'attachments' => [],
            ]);
        } catch (Throwable $e) {
            ecw_log('health alert email failed: ' . $e->getMessage());
        }
    }
}
