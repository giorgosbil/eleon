<?php declare(strict_types=1);
/**
 * The fast restore (lib/backup.php ecw_backup_restore, format 2): over a database that already
 * holds most of the data, only the blocks that differ are written, and the result is the backup
 * EXACTLY — changed rows back as they were, rows added here gone, rows deleted here back, a table
 * whose layout changed made new.
 *
 *   php tests/backup_compare_restore_test.php
 *
 * Works in the scratch database ecw_restore_check (the app's user may use it), never the real one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/backup.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$real = ecw_db();
$host = getenv('ECW_DB_HOST') ?: '127.0.0.1';
try {
    $pdo = new PDO('mysql:host=' . $host . ';port=' . (getenv('ECW_DB_PORT') ?: '3306') . ';dbname=ecw_restore_check;charset=utf8mb4',
        getenv('ECW_DB_USER') ?: 'ecw', getenv('ECW_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    try { $real->exec('CREATE DATABASE IF NOT EXISTS ecw_restore_check'); } catch (Throwable $e2) {}
    try {
        $pdo = new PDO('mysql:host=' . $host . ';port=' . (getenv('ECW_DB_PORT') ?: '3306') . ';dbname=ecw_restore_check;charset=utf8mb4',
            getenv('ECW_DB_USER') ?: 'ecw', getenv('ECW_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (Throwable $e3) { echo "SKIP no scratch database ecw_restore_check: " . $e3->getMessage() . "\n\n0 passed, 0 failed\n"; exit(0); }
}
$GLOBALS['ecw_test_pdo'] = $pdo;   // every backup function now works in the scratch database
$dir = sys_get_temp_dir() . '/ecw-compare-' . bin2hex(random_bytes(3));
@mkdir($dir, 0770, true);

$sum = static fn(string $t) => md5(json_encode($pdo->query('SELECT * FROM `' . $t . '` ORDER BY 1, 2')->fetchAll(PDO::FETCH_NUM)));
$zipOf = static function (string $sqlFile, int $format) use ($dir): string {
    $z = $dir . '/b' . bin2hex(random_bytes(3)) . '.zip';
    $zip = new ZipArchive(); $zip->open($z, ZipArchive::CREATE);
    $zip->addFile($sqlFile, 'database.sql');
    $zip->addFromString('manifest.json', json_encode(['app' => 'Email Case Workspace', 'format' => $format]));
    $zip->addFromString('encryption.json', json_encode(['encrypted' => false]));
    $zip->close();
    return $z;
};
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec('DROP TABLE `' . $t . '`'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('CREATE TABLE ecw_mail (id INT AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(200), body MEDIUMTEXT NULL, at DATETIME NULL, n DECIMAL(10,2) NULL)');
    $pdo->exec('CREATE TABLE ecw_pairs (a INT, b INT, note VARCHAR(50), PRIMARY KEY (a, b))');   // no whole-number id: written in full
    $pdo->exec('CREATE TABLE ecw_shape (id INT PRIMARY KEY, x VARCHAR(20))');
    $ins = $pdo->prepare('INSERT INTO ecw_mail (subject, body, at, n) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= 1500; $i++) $ins->execute(['Subject ' . $i . " 'quoted' \\ ελληνικά", $i % 7 ? str_repeat('body ' . $i . ' ', 20) . "\nline two" : null, $i % 5 ? '2026-10-' . sprintf('%02d', $i % 28 + 1) . ' 10:00:00' : null, $i / 3]);
    for ($i = 1; $i <= 50; $i++) $pdo->exec("INSERT INTO ecw_pairs VALUES ($i, " . ($i * 2) . ", 'p$i')");
    for ($i = 1; $i <= 30; $i++) $pdo->exec("INSERT INTO ecw_shape VALUES ($i, 's$i')");
    $want = ['ecw_mail' => $sum('ecw_mail'), 'ecw_pairs' => $sum('ecw_pairs'), 'ecw_shape' => $sum('ecw_shape')];

    echo "\n=== A backup, then this database drifts away from it ===\n";
    $sql = $dir . '/db.sql';
    $stats = ecw_backup_dump_sql($sql);
    $text = (string)file_get_contents($sql);
    check($stats['rows'] === 1580 && substr_count($text, '-- ecw:batch ') === 8 + 1, 'blocks of 200 rows with their id range and fingerprint (8 for the mail table, 1 for the small one)', (string)substr_count($text, '-- ecw:batch '));
    check(str_contains($text, '-- ecw:table ecw_pairs -'), 'a table without a whole-number id is marked to be written in full');
    $pdo->exec("UPDATE ecw_mail SET subject = 'changed here' WHERE id IN (5, 777)");       // two blocks changed
    $pdo->exec('DELETE FROM ecw_mail WHERE id BETWEEN 1201 AND 1210');                     // deleted here: must come back
    $pdo->exec("INSERT INTO ecw_mail (id, subject) VALUES (5000, 'only on this PC')");     // after the last block: must go
    $pdo->exec("UPDATE ecw_pairs SET note = 'x' WHERE a = 3");
    $pdo->exec('ALTER TABLE ecw_shape ADD COLUMN extra INT NULL');                          // layout changed: made new
    $pdo->exec("UPDATE ecw_mail SET body = NULL WHERE id = 1499");

    echo "\n=== Restored by comparing ===\n";
    $r = ecw_backup_restore($zipOf($sql, 2), null, false);
    check($sum('ecw_mail') === $want['ecw_mail'], 'the mail table is the backup exactly (changed rows back, deleted rows back, a row only here gone)');
    check($sum('ecw_pairs') === $want['ecw_pairs'], 'a table without a whole-number id is the backup exactly');
    check($sum('ecw_shape') === $want['ecw_shape'] && !in_array('extra', $pdo->query('SHOW COLUMNS FROM ecw_shape')->fetchAll(PDO::FETCH_COLUMN), true), 'a table whose layout changed is made new, as in the backup');
    check($r['blocks_kept'] >= 4 && $r['blocks_replaced'] <= 5, 'blocks already the same are left as they are, only the others written', json_encode([$r['blocks_kept'], $r['blocks_replaced']]));
    $ai = (int)$pdo->query("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ecw_mail'")->fetchColumn();
    $pdo->exec("INSERT INTO ecw_mail (subject) VALUES ('new one')");
    check((int)$pdo->lastInsertId() >= 1501, 'new rows after the restore get new ids', (string)$ai);
    $pdo->exec("DELETE FROM ecw_mail WHERE subject = 'new one'");

    echo "\n=== Restored again: nothing to write ===\n";
    $r2 = ecw_backup_restore($zipOf($sql, 2), null, false);
    check($r2['blocks_replaced'] === 0 && $r2['blocks_kept'] === 9 && $sum('ecw_mail') === $want['ecw_mail'], 'the same data a second time: every block kept, nothing rewritten', json_encode([$r2['blocks_kept'], $r2['blocks_replaced']]));

    echo "\n=== An older backup (format 1): written in full ===\n";
    $pdo->exec("UPDATE ecw_mail SET subject = 'drift' WHERE id = 10");
    $r3 = ecw_backup_restore($zipOf($sql, 1), null, false);
    check($sum('ecw_mail') === $want['ecw_mail'] && $r3['blocks_kept'] === 0, 'an older backup is written in full, and is the backup exactly');

    echo "\n=== Into an empty database ===\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec('DROP TABLE `' . $t . '`'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    ecw_backup_restore($zipOf($sql, 2), null, false);
    check($sum('ecw_mail') === $want['ecw_mail'] && $sum('ecw_pairs') === $want['ecw_pairs'], 'into an empty database: every table made and filled');

    echo "\n=== Progress ===\n";
    $seen = [];
    ecw_backup_restore($zipOf($sql, 2), static function (string $w, float $p) use (&$seen): void { $seen[] = $p; }, false);
    check($seen !== [] && max($seen) === 100.0 && $seen === array_values(array_filter($seen, static fn($p) => $p >= 0 && $p <= 100)), 'the restore says how far it is, up to 100 %');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec('DROP TABLE `' . $t . '`'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
    unset($GLOBALS['ecw_test_pdo']);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
