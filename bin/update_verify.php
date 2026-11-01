<?php declare(strict_types=1);
/**
 * Used by scripts/update.ps1 / .sh before anything is touched:
 *
 *   php bin/update_verify.php <release.zip> <release.json>
 *
 * Exit 0 and prints the version when the zip is the vendor's, unchanged, and
 * newer than (or, with --allow-same, equal to) the running version.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/updates.php';

$zip = $argv[1] ?? ''; $json = $argv[2] ?? '';
$feed = json_decode((string)@file_get_contents($json), true);
if (!is_array($feed) || empty($feed['token'])) { fwrite(STDERR, "The release .json file is missing or damaged.\n"); exit(2); }
$v = ecw_release_verify((string)$feed['token'], $zip);
if (!$v['ok']) { fwrite(STDERR, $v['error'] . "\n"); exit(3); }
$current = ecw_app_version();
$new = (string)$v['release']['version'];
if (!ecw_version_newer($new, $current) && !($new === $current && in_array('--allow-same', $argv, true))) {
    fwrite(STDERR, "This release ({$new}) is not newer than the running version ({$current}).\n");
    exit(4);
}
echo $new . "\n";
