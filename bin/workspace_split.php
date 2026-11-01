<?php declare(strict_types=1);
/**
 * Make one account (mailbox) a workspace of its own (lib/workspace_split.php).
 *
 *   php bin/workspace_split.php --mailbox=ID --name="New company" [--rename="Old company"]          shows what would move
 *   php bin/workspace_split.php --mailbox=ID --name="New company" [--rename="Old company"] --apply  does it
 *
 * Make a backup first (Settings → Backups, or php bin/backup.php create).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/workspace_split.php';

$opts = getopt('', ['mailbox:', 'name:', 'rename::', 'apply']);
if (empty($opts['mailbox']) || empty($opts['name'])) {
    fwrite(STDERR, "usage: php bin/workspace_split.php --mailbox=ID --name=\"New company\" [--rename=\"Old company\"] [--apply]\n");
    exit(2);
}
try {
    $r = ecw_workspace_split((int)$opts['mailbox'], (string)$opts['name'], isset($opts['rename']) ? (string)$opts['rename'] : null, isset($opts['apply']));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo isset($opts['apply']) ? "Done: new workspace #{$r['workspace_id']} \"{$opts['name']}\" (from #{$r['from']}).\n" : "Would move (nothing changed; add --apply):\n";
foreach ($r['moved'] as $what => $n) echo '  ' . str_pad($what, 22) . $n . "\n";
foreach ($r['notes'] as $n) echo 'Note: ' . $n . "\n";
