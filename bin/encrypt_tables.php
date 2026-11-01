<?php declare(strict_types=1);
/**
 * Encrypt every table on disk (after compose.encryption.yaml loaded the keyring):
 *
 *   php bin/encrypt_tables.php           encrypt the tables that are not yet
 *   php bin/encrypt_tables.php --status  show what is encrypted
 *   php bin/encrypt_tables.php --off     decrypt them again (before turning the keyring off)
 *
 * Run by scripts/encrypt-at-rest.ps1 / .sh. Each table is rewritten, so a big
 * mail table takes a while; the app keeps working meanwhile.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/security.php';

$args = $argv ?? [];
$s = ecw_db_encryption_status();
if (in_array('--status', $args, true)) {
    echo "keyring loaded: " . ($s['keyring'] ? 'yes' : 'no') . "\n";
    echo "new tables encrypted: " . ($s['default_on'] ? 'yes' : 'no') . ", redo/undo logs: " . ($s['redo'] ? 'yes' : 'no') . "\n";
    echo "tables encrypted: {$s['encrypted']} of {$s['tables']}\n";
    exit(0);
}
$off = in_array('--off', $args, true);
if (!$off && !$s['default_on']) {
    fwrite(STDERR, "The database runs without a keyring. Start it with compose.encryption.yaml first (scripts/encrypt-at-rest.ps1).\n");
    exit(1);
}
try {
    $n = ecw_db_encrypt_tables(!$off, static function (string $t): void { echo ($GLOBALS['off'] ? 'decrypted ' : 'encrypted ') . $t . "\n"; });
} catch (Throwable $e) {
    fwrite(STDERR, 'Stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
$after = ecw_db_encryption_status();
echo "{$n} table(s) changed; now {$after['encrypted']} of {$after['tables']} encrypted.\n";
require_once dirname(__DIR__) . '/lib/audit.php';
ecw_audit('settings.security', 'database', null, ['encryption' => $off ? 'off' : 'on', 'tables' => $after['encrypted']], null, null, 'cli');
