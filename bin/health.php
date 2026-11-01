<?php declare(strict_types=1);
/**
 *   php bin/health.php            every check (Settings → System health), as text
 *   php bin/health.php --startup  does this version work? (used by scripts/update.*)
 *                                 database answers, every migration applied, the
 *                                 sign-in page loads. Exit 0 = yes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/modules.php';

if (in_array('--startup', $argv ?? [], true)) {
    $fail = static function (string $why): never { fwrite(STDERR, "NOT OK: {$why}\n"); exit(1); };
    try { ecw_db()->query('SELECT 1')->fetchColumn(); } catch (Throwable $e) { $fail('the database does not answer: ' . $e->getMessage()); }
    $files = array_map('basename', ecw_migration_files());
    try { $applied = ecw_db()->query('SELECT filename FROM `ecw_schema_migrations`')->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { $fail('no migration record'); }
    $missing = array_diff($files, $applied);
    if ($missing) $fail('database changes not applied: ' . implode(', ', $missing));
    $ch = curl_init('http://localhost/other_things/web/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 || !str_contains($body, 'Email Case Workspace')) $fail('the sign-in page answers HTTP ' . $status);
    require_once dirname(__DIR__) . '/lib/updates.php';
    echo 'OK ' . ecw_app_version() . "\n";
    exit(0);
}

require_once dirname(__DIR__) . '/lib/health.php';
$checks = ecw_health_checks();
$mark = ['ok' => '  ok  ', 'warn' => ' WARN ', 'fail' => ' FAIL '];
foreach ($checks as $c) echo $mark[$c['state']], $c['label'], ': ', $c['detail'], $c['fix'] ? '  → ' . $c['fix'] : '', "\n";
echo "\nOverall: " . ecw_health_overall($checks) . "\n";
exit(ecw_health_overall($checks) === 'fail' ? 2 : 0);
