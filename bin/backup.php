<?php declare(strict_types=1);
/**
 * Backups from the command line. CLI ONLY.
 *
 *   php bin/backup.php create              make a backup now
 *   php bin/backup.php list                show the backups
 *   php bin/backup.php restore FILE --yes  restore one (replaces ALL current data;
 *                                          a safety backup is made first)
 *     --progress    a line "@@ <percent> <what>" at every step forward, for the scripts
 *                   (scripts/data-sync.ps1 shows them as a bar); without it, a line every 10 %
 *     --no-safety   restore without the safety backup first (the person chose so)
 *
 * From the host: scripts/backup.ps1 / backup.sh and scripts/restore.ps1 / restore.sh.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/backup.php';

$cmd = $argv[1] ?? 'help';
$machine = in_array('--progress', $argv, true);
$shown = -10.0;
$progress = static function (string $what, float $pct) use ($machine, &$shown): void {
    if ($machine) { echo '@@ ' . number_format($pct, 1, '.', '') . ' ' . $what . "\n"; flush(); return; }
    if ($pct - $shown >= 10 || $pct >= 100) { $shown = $pct; printf("[%3d%%] %s\n", (int)$pct, $what); flush(); }
};
try {
    switch ($cmd) {
        case 'create':
            $name = ecw_backup_create('manual', $progress);
            echo "Backup created: backups/{$name}\n";
            break;
        case 'list':
            $list = ecw_backup_list();
            if (!$list) { echo "No backups yet.\n"; break; }
            foreach ($list as $b) printf("%-44s %8.1f MB  %s\n", $b['name'], $b['size'] / 1048576, gmdate('Y-m-d H:i', $b['created']) . ' UTC');
            break;
        case 'restore':
            $arg = $argv[2] ?? '';
            $path = ecw_backup_path(basename($arg)) ?? (is_file($arg) ? $arg : null);
            if ($path === null) { fwrite(STDERR, "Backup not found: {$arg}\nRun: php bin/backup.php list\n"); exit(1); }
            if (!in_array('--yes', $argv, true)) {
                fwrite(STDERR, "This replaces ALL current data with the backup " . basename($path) . ".\nRun again with --yes to continue.\n");
                exit(1);
            }
            $r = ecw_backup_restore($path, $progress, !in_array('--no-safety', $argv, true));
            echo "Restored " . basename($path) . ($r['created_at'] ? " (made {$r['created_at']})" : '') . ": {$r['statements']} statements written"
                . ($r['blocks_kept'] ? ", {$r['blocks_kept']} blocks already the same were left as they were" : '') . ".\n";
            if ($r['safety_backup']) echo "Your data before the restore was saved as backups/{$r['safety_backup']}.\n";
            if ($r['key_restored']) echo "The encryption key from the backup was restored; restart the app so it is used.\n";
            break;
        default:
            echo "Usage: php bin/backup.php create | list | restore FILE --yes [--progress] [--no-safety]\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup error: ' . $e->getMessage() . "\n");
    exit(1);
}
