<?php declare(strict_types=1);
/**
 * The Telegram background process (the "telegram" container):
 *
 *   php modules/telegram/bin/daemon.php
 *
 * Keeps every account whose sign-in is on this computer signed in, does the
 * jobs the web queued and stores what Telegram reports (lib/sync.php). Each
 * account has its own MadelineProto session under storage/telegram/sessions/.
 * Writes storage/telegram/daemon-heartbeat so the tab can tell it is running.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('memory_limit', '512M');
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/sync.php';

// MadelineProto writes some files (its start-up log) to the current folder: ours, which is writable.
chdir(ecw_tg_dir());
$machine = ecw_tg_machine_id();
$beatFile = ecw_tg_dir() . '/daemon-heartbeat';
/** @var array<int, EcwTgMadeline> $clients */
$clients = [];
$running = true;
foreach ([SIGTERM, SIGINT] as $sig) \Revolt\EventLoop::onSignal($sig, static function () use (&$running) { $running = false; });

$sessionDir = static fn(int $id): string => ecw_tg_dir('sessions/' . $id);
$forget = static function (int $id) use (&$clients, $sessionDir): void {
    unset($clients[$id]);
    gc_collect_cycles();
    $dir = $sessionDir($id);
    // The sign-in is gone with it: nothing of it stays on the disk.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
};
$client = static function (array $a) use (&$clients, $sessionDir): EcwTgMadeline {
    $id = (int)$a['id'];
    if (isset($clients[$id])) return $clients[$id];
    $env = ecw_tg_env_api();
    $apiId = (int)($a['api_id'] ?: ($env['id'] ?? 0));
    $apiHash = $a['api_hash_enc'] ? (string)ecw_decrypt((string)$a['api_hash_enc']) : (string)($env['hash'] ?? '');
    if ($apiId <= 0 || $apiHash === '') throw new RuntimeException('API_ID_INVALID: no api_id / api_hash');
    return $clients[$id] = new EcwTgMadeline($sessionDir($id), $apiId, $apiHash);
};

echo '[telegram] started on machine ' . substr($machine, 0, 8) . "\n";
$lastBeat = 0;
while ($running) {
    if (time() - $lastBeat >= 5) { @touch($beatFile); $lastBeat = time(); }
    try {
        $st = ecw_db()->prepare("SELECT a.* FROM `" . ecw_table('tg_accounts') . "` a
                                  WHERE a.machine = ? AND (a.status IN ('new', 'code', 'password', 'ready')
                                     OR EXISTS (SELECT 1 FROM `" . ecw_table('tg_jobs') . "` j WHERE j.account_id = a.id AND j.status = 'queued'))");
        $st->execute([$machine]);
        $accounts = $st->fetchAll();
    } catch (Throwable $e) {
        // Before the migration, or the database is restarting.
        ecw_log('telegram daemon: ' . $e->getMessage());
        \Amp\delay(10);
        continue;
    }
    $active = [];
    foreach ($accounts as $a) {
        $id = (int)$a['id'];
        $active[$id] = true;
        try {
            // Jobs first (a few per round, so sending stays quick).
            for ($i = 0; $i < 5; $i++) {
                $job = ecw_tg_sync_next_job($id);
                if ($job === null) break;
                if ($job['type'] === 'send_code') $forget($id);   // each sign-in starts from a fresh session
                if ($job['type'] === 'logout' && !isset($clients[$id]) && !is_file($sessionDir($id) . '/session.madeline')) { ecw_tg_sync_finish($job); continue; }
                ecw_tg_sync_job($client($a), $a, $job);
                $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('tg_accounts') . '` WHERE id = ?');
                $st->execute([$id]);
                $a = $st->fetch() ?: $a;
                if ($job['type'] === 'logout' || $a['status'] === 'error') { $forget($id); break; }
            }
            if ($a['status'] !== 'ready') continue;
            $c = $client($a);
            if (!$c->loggedIn()) {
                ecw_tg_sync_account_set($id, ['status' => 'logged_out', 'last_error' => 'Telegram ended this sign-in (from your phone, Settings → Devices?). Connect again.']);
                $forget($id);
                continue;
            }
            foreach ($c->updates() as $u) if (is_array($u)) ecw_tg_sync_update($c, $a, $u);
            ecw_tg_sync_periodic($c, $a);
        } catch (Throwable $e) {
            ecw_log('telegram daemon (account ' . $id . '): ' . $e->getMessage());
            if (ecw_tg_sync_signed_out($e)) {
                ecw_tg_sync_account_set($id, ['status' => 'logged_out', 'last_error' => ecw_tg_sync_error($e)]);
                $forget($id);
            }
        }
    }
    // Accounts that were disconnected (or moved to another computer) let go of their connection.
    foreach (array_keys($clients) as $id) if (!isset($active[$id])) unset($clients[$id]);
    \Amp\delay(0.5);
}
echo "[telegram] stopped\n";
