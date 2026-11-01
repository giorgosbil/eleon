<?php declare(strict_types=1);
/**
 * Backups: the whole database plus the files that exist only here, in one .zip.
 *
 *   database.sql     every table: DROP + CREATE + INSERTs, ONE statement per line
 *                    (values are escaped, so a line break never appears inside one);
 *                    "-- ecw:table" / "-- ecw:batch" lines let a restore skip what is the same
 *   storage/drafts/  attachments added to unsent drafts
 *   storage/branding/ the business logo (Settings → Branding)
 *   storage/avatars/  profile photos
 *   storage/…        folders modules name in their manifest ('backup_dirs', e.g.
 *                    Telegram's photos and files; never its sign-ins)
 *   encryption-key   the key the stored mailbox tokens are encrypted with — without
 *                    it a restored copy has to reconnect every mailbox. It makes the
 *                    backup as sensitive as the mailbox itself: keep backups private.
 *   manifest.json    what is inside, and from which version
 *   encryption.json  whether the files above are AES-256 encrypted with the backup
 *                    password (Settings → Security, or ECW_BACKUP_PASSWORD). A
 *                    restore elsewhere needs ECW_BACKUP_PASSWORD set to it.
 *
 * Not included, because they come back by themselves: cached attachment files
 * (downloaded again on demand) and login sessions.
 *
 * Backups go to /opt/ecw/backups, which compose.yaml maps to ./backups on the
 * host so they can be copied elsewhere. The sync daemon makes one a day
 * (ecw_backup_due) and keeps ECW_BACKUP_KEEP (default 14). ECW_BACKUPS=0 turns
 * the daily backup off.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

/** 2: tables with a whole-number id in id order, in blocks with their id range and fingerprint (fast restore). */
const ECW_BACKUP_FORMAT = 2;
/** Files that exist only here, kept in every backup: unsent drafts' attachments, the business logo (Settings → Branding), profile photos. */
const ECW_BACKUP_STORAGE_DIRS = ['drafts', 'branding', 'avatars'];

/** Those, and the storage folders modules keep files in ('backup_dirs' in their manifest, e.g. "telegram/media"). */
function ecw_backup_storage_dirs(): array
{
    require_once __DIR__ . '/modules.php';
    $dirs = ECW_BACKUP_STORAGE_DIRS;
    foreach (ecw_modules() as $m) {
        foreach ((array)($m['backup_dirs'] ?? []) as $d) {
            // A plain path under storage/: letters, digits, _ - and /, never .. or a leading /.
            if (is_string($d) && preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*$#D', $d)) $dirs[] = $d;
        }
    }
    return array_values(array_unique($dirs));
}

/** May a restore write this zip entry? Only files inside one of those folders. */
function ecw_backup_storage_wanted(string $entry): bool
{
    if (!str_starts_with($entry, 'storage/') || str_contains($entry, '..') || str_ends_with($entry, '/')) return false;
    foreach (ecw_backup_storage_dirs() as $d) if (str_starts_with($entry, 'storage/' . $d . '/')) return true;
    return false;
}

function ecw_backup_dir(): string
{
    $dir = getenv('ECW_BACKUP_DIR') ?: dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

/** @return array<int,array{name:string,size:int,created:int}> newest first */
function ecw_backup_list(): array
{
    $out = [];
    foreach (glob(ecw_backup_dir() . '/ecw-backup-*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'created' => (int)filemtime($f)];
    }
    usort($out, static fn($a, $b) => $b['created'] <=> $a['created']);
    return $out;
}

/** A backup file name from the list, or null. Never a path someone typed. */
function ecw_backup_path(string $name): ?string
{
    if (!preg_match('/^ecw-backup-[0-9]{8}-[0-9]{6}(-[a-z]+)?\.zip$/D', $name)) return null;
    $path = ecw_backup_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}

/** Write the SQL dump to $file. @return array{tables:int, rows:int} */
/**
 * The table's primary key when it is one whole-number column (every big table here: id), else null.
 * Such tables are written in id order in blocks that carry their id range and a fingerprint,
 * so a restore over the same data rewrites only the blocks that differ (ecw_backup_restore).
 */
function ecw_backup_int_pk(PDO $pdo, string $table): ?string
{
    $st = $pdo->prepare("SELECT k.column_name, c.data_type FROM information_schema.key_column_usage k
                           JOIN information_schema.columns c ON c.table_schema = k.table_schema AND c.table_name = k.table_name AND c.column_name = k.column_name
                          WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.constraint_name = 'PRIMARY'");
    $st->execute([$table]);
    $cols = $st->fetchAll(PDO::FETCH_NUM);
    return count($cols) === 1 && in_array(strtolower((string)$cols[0][1]), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) ? (string)$cols[0][0] : null;
}

/** One row's fingerprint, worked out by MySQL the same way on every PC (backup and restore). */
function ecw_backup_row_hash_sql(array $columns): string
{
    return "MD5(CONCAT_WS(',', " . implode(', ', array_map(static fn($c) => 'QUOTE(`' . str_replace('`', '``', (string)$c) . '`)', $columns)) . '))';
}

/**
 * Every table as SQL, one statement per line. Tables with a whole-number id are written in id
 * order, 200 rows a statement, each block after a line "-- ecw:batch <first id> <last id> <md5>"
 * (format 2). $progress(string $what, float $percent) is told as it goes.
 */
function ecw_backup_dump_sql(string $file, ?callable $progress = null): array
{
    $pdo = ecw_db();
    $out = fopen($file, 'wb');
    if ($out === false) throw new RuntimeException('cannot write ' . $file);
    fwrite($out, "-- Email Case Workspace backup " . gmdate('c') . "\n");
    fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    // MySQL's own row counts (an estimate, close enough for a percentage).
    $estimate = [];
    foreach ($pdo->query("SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_NUM) as [$n, $c]) $estimate[(string)$n] = (int)$c;
    $total = max(1, array_sum($estimate));
    $rows = 0; $told = -1.0;
    $tell = static function (string $t) use (&$rows, &$told, $total, $progress): void {
        if (!$progress) return;
        $pct = min(99.9, $rows * 100 / $total);
        if ($pct - $told >= 0.5) { $told = $pct; $progress('Saving the database: ' . preg_replace('/^ecw_/', '', $t) . ' (' . number_format($rows) . ' of about ' . number_format($total) . ' rows)', $pct); }
    };
    foreach ($tables as $t) {
        $q = '`' . str_replace('`', '``', (string)$t) . '`';
        $pk = ecw_backup_int_pk($pdo, (string)$t);
        fwrite($out, '-- ecw:table ' . $t . ' ' . ($pk ?? '-') . "\n");
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
        fwrite($out, 'DROP TABLE IF EXISTS ' . $q . ";\n");
        fwrite($out, preg_replace('/\s*\R\s*/', ' ', (string)$create) . ";\n");
        // Generated columns (e.g. email_messages.occurred_at) are computed by
        // MySQL and refuse inserted values, so they are left out: extra is
        // "VIRTUAL GENERATED" or "STORED GENERATED". NOT "DEFAULT_GENERATED",
        // which only means DEFAULT CURRENT_TIMESTAMP: until 2026-10-16 those were
        // left out too, and every restore stamped created_at, case history times,
        // linked_at… with the moment of the restore.
        $colSt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND extra NOT LIKE '%VIRTUAL GENERATED%' AND extra NOT LIKE '%STORED GENERATED%' ORDER BY ordinal_position");
        $colSt->execute([$t]);
        $names = $colSt->fetchAll(PDO::FETCH_COLUMN);
        $colList = implode(',', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', $names));
        $pkAt = $pk === null ? false : array_search($pk, $names, true);
        if ($pkAt === false) $pk = null;
        $select = 'SELECT ' . $colList . ($pk !== null ? ', ' . ecw_backup_row_hash_sql($names) : '') . ' FROM ' . $q . ($pk !== null ? ' ORDER BY `' . str_replace('`', '``', $pk) . '`' : '');
        // Unbuffered, so a big mail table is streamed, not loaded into memory.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $st = $pdo->query($select);
            $batch = []; $cols = '(' . $colList . ')'; $hashes = ''; $first = null; $last = null;
            $write = static function () use (&$batch, &$hashes, &$first, &$last, $out, $q, $cols, $pk): void {
                if ($pk !== null) fwrite($out, '-- ecw:batch ' . $first . ' ' . $last . ' ' . md5($hashes) . "\n");
                fwrite($out, 'INSERT INTO ' . $q . ' ' . $cols . ' VALUES ' . implode(',', $batch) . ";\n");
                $batch = []; $hashes = ''; $first = null;
            };
            while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
                if ($pk !== null) { $hashes .= (string)array_pop($r); $first ??= (string)$r[$pkAt]; $last = (string)$r[$pkAt]; }
                $batch[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
                $rows++;
                if (count($batch) >= 200) { $write(); $tell((string)$t); }
            }
            if ($batch) $write();
            $st->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        $tell((string)$t);
    }
    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
    fclose($out);
    return ['tables' => count($tables), 'rows' => $rows];
}

/**
 * Make a backup now. $progress(string $what, float $percent): the database is most of the time
 * (to 85 %), packing and encrypting the rest. A safety copy before a restore ('prerestore') is
 * packed with the fastest compression: it only has to be quick and complete.
 * @return string the file name
 */
function ecw_backup_create(string $reason = 'manual', ?callable $progress = null): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP zip support is missing (rebuild the image).');
    @set_time_limit(0);
    $dir = ecw_backup_dir();
    if (!is_writable($dir)) throw new RuntimeException('The backups folder is not writable: ' . $dir);
    $name = 'ecw-backup-' . gmdate('Ymd-His') . ($reason === 'auto' ? '-auto' : ($reason === 'prerestore' ? '-prerestore' : '')) . '.zip';
    $sql = tempnam(sys_get_temp_dir(), 'ecwsql');
    $tmpZip = $dir . '/.' . $name . '.part';
    try {
        $stats = ecw_backup_dump_sql($sql, $progress ? static fn(string $w, float $p) => $progress($w, $p * 0.85) : null);
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('cannot create the zip file');
        $zip->addFile($sql, 'database.sql');
        if ($reason === 'prerestore') $zip->setCompressionName('database.sql', ZipArchive::CM_DEFLATE, 1);
        foreach (ecw_backup_storage_dirs() as $sub) {
            $from = dirname(__DIR__) . '/storage/' . $sub;
            if (!is_dir($from)) continue;
            // A folder or file this process cannot read (made by a service running as another user)
            // is left out with a note in the log: one unreadable picture must not stop the backup
            // (and DataPush, which is a backup).
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                if (!$f->isReadable()) { ecw_log('backup: left out a file it cannot read: storage/' . $sub . '/' . substr($f->getPathname(), strlen($from) + 1)); continue; }
                $zip->addFile($f->getPathname(), 'storage/' . $sub . '/' . substr($f->getPathname(), strlen($from) + 1));
            }
        }
        $keyFile = getenv('ECW_ENCRYPTION_KEY_FILE') ?: '';
        if ($keyFile !== '' && is_readable($keyFile)) $zip->addFromString('encryption-key', (string)file_get_contents($keyFile));
        $migrations = [];
        // bin/migrate.php's own table (not in ecw_table()'s list, so named here): which code version the data needs.
        try { $migrations = ecw_db()->query('SELECT filename FROM `ecw_schema_migrations` ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
        $zip->addFromString('manifest.json', json_encode([
            'app' => 'Email Case Workspace', 'format' => ECW_BACKUP_FORMAT, 'created_at' => gmdate('c'), 'reason' => $reason,
            'tables' => $stats['tables'], 'rows' => $stats['rows'], 'migrations' => $migrations,
            'includes_encryption_key' => $keyFile !== '' && is_readable($keyFile),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // Settings → Security → backup password: every file inside is AES-256
        // encrypted (the manifest stays readable, so a restore can say what it needs).
        require_once __DIR__ . '/security.php';
        $password = ecw_backup_password();
        if ($password !== null) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string)$zip->getNameIndex($i);
                if ($n !== 'manifest.json' && !$zip->setEncryptionName($n, ZipArchive::EM_AES_256, $password)) throw new RuntimeException('this PHP cannot encrypt zip files');
            }
        }
        $zip->addFromString('encryption.json', json_encode(['encrypted' => $password !== null, 'method' => $password !== null ? 'AES-256' : null]));
        // Packing (and encrypting) happens on close: libzip says how far it is.
        if ($progress) {
            $progress('Packing and encrypting the copy', 85.0);
            $told = 0.0;
            $zip->registerProgressCallback(0.01, static function (float $r) use ($progress, &$told): void {
                if ($r - $told >= 0.02) { $told = $r; $progress('Packing and encrypting the copy', 85 + $r * 15); }
            });
        }
        if (!$zip->close()) throw new RuntimeException('the zip file could not be written');
        rename($tmpZip, $dir . '/' . $name);
        @chmod($dir . '/' . $name, 0640);
        $progress && $progress('Copy made: ' . $name, 100.0);
    } finally {
        @unlink($sql);
        if (is_file($tmpZip)) @unlink($tmpZip);
    }
    ecw_backup_prune();
    ecw_log("backup created: {$name}");
    return $name;
}

/** Keep the newest ECW_BACKUP_KEEP automatic backups (manual ones are kept until deleted). */
function ecw_backup_prune(): void
{
    $keep = max(1, (int)(getenv('ECW_BACKUP_KEEP') ?: 14));
    $auto = array_values(array_filter(ecw_backup_list(), static fn($b) => str_contains($b['name'], '-auto') || str_contains($b['name'], '-prerestore')));
    foreach (array_slice($auto, $keep) as $old) @unlink(ecw_backup_dir() . '/' . $old['name']);
}

/** Called every minute by the sync daemon: one automatic backup a day. */
function ecw_backup_due(): void
{
    if (getenv('ECW_BACKUPS') === '0') return;
    $latest = 0;
    foreach (ecw_backup_list() as $b) $latest = max($latest, $b['created']);
    if (time() - $latest < 86400) return;
    try { ecw_backup_create('auto'); }
    catch (Throwable $e) { ecw_log('automatic backup failed: ' . $e->getMessage()); }
}

/**
 * Restore a backup: database, draft files and (if present) the encryption key.
 * Run it from the command line with the sync daemon stopped (scripts/restore.ps1 / restore.sh,
 * DataPull do that). $safety: a safety backup of the current state is made first.
 *
 * Fast (2026-10-29: a 450 MB copy took about 40 minutes):
 *   - written in transactions of a few hundred statements, not each one on its own;
 *   - over a database that already holds most of it (DataPull onto the other PC), a format-2
 *     backup is compared block by block (its "-- ecw:batch" lines: id range and fingerprint):
 *     a block the same here is left as it is, one that differs is replaced, rows between the
 *     blocks are deleted. The result is the backup exactly; only far less is written.
 * $progress(string $what, float $percent) is told as it goes.
 */
function ecw_backup_restore(string $path, ?callable $progress = null, bool $safety = true): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP zip support is missing.');
    @set_time_limit(0);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Not a backup file: ' . $path);
    $manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
    if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Email Case Workspace') throw new RuntimeException('This zip is not an Email Case Workspace backup.');
    $enc = json_decode((string)$zip->getFromName('encryption.json'), true);
    if (!empty($enc['encrypted'])) {
        require_once __DIR__ . '/security.php';
        $password = ecw_backup_password();
        if ($password === null) throw new RuntimeException('This backup is encrypted. Set ECW_BACKUP_PASSWORD to its password and run the restore again.');
        $zip->setPassword($password);
        if ($zip->getFromName('manifest.json') === false || $zip->statName('database.sql') === false || @$zip->getFromIndex((int)$zip->locateName('database.sql'), 64) === false) {
            throw new RuntimeException('Wrong backup password (or a damaged file).');
        }
    }
    $sqlSize = max(1, (int)(($zip->statName('database.sql') ?: [])['size'] ?? 1));
    $sqlStream = $zip->getStream('database.sql');
    if ($sqlStream === false) throw new RuntimeException('The backup has no database.');
    // With a safety copy first, that copy is the first 35 % of the way.
    $from = $safety ? 35.0 : 0.0;
    $say = static function (string $w, float $p) use ($progress): void { if ($progress) $progress($w, $p); };

    $safetyName = null;
    if ($safety) {
        $safetyName = ecw_backup_create('prerestore', $progress ? static fn(string $w, float $p) => $progress("Safety copy of this PC's data: " . $w, $p * 0.35) : null);
    }

    $pdo = ecw_db();
    $pdo->exec('SET SESSION group_concat_max_len = 16777216');
    $compare = (int)($manifest['format'] ?? 1) >= 2;
    $count = 0; $read = 0; $told = -1.0; $inTx = false; $sinceCommit = 0;
    $kept = 0; $replaced = 0;
    $begin = static function () use ($pdo, &$inTx): void { if (!$inTx) { $pdo->beginTransaction(); $inTx = true; } };
    $commit = static function () use ($pdo, &$inTx, &$sinceCommit): void { if ($inTx) { $pdo->commit(); $inTx = false; } $sinceCommit = 0; };
    $run = static function (string $sql) use ($pdo, $begin, $commit, &$count, &$sinceCommit): void {
        // DROP / CREATE end a transaction by themselves in MySQL: written outside one.
        if (preg_match('/^(DROP|CREATE|ALTER)\s/i', $sql)) { $commit(); $pdo->exec($sql); }
        else { $begin(); $pdo->exec($sql); if (++$sinceCommit >= 300) $commit(); }
        $count++;
    };
    $sameCreate = static function (string $table, string $createLine) use ($pdo): bool {
        try { $now = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1] ?? ''; }
        catch (Throwable $e) { return false; }   // not here yet
        $norm = static fn(string $s) => preg_replace('/ AUTO_INCREMENT=\d+/', '', rtrim(preg_replace('/\s*\R\s*/', ' ', $s), ';'));
        return $norm((string)$now) === $norm($createLine);
    };

    // The table being read, and how: 'full' (dropped, created, every row written) or 'compare'.
    $table = null; $pk = null; $mode = 'full'; $pendingDrop = null; $prev = null; $block = null;
    $q = static fn(string $t) => '`' . str_replace('`', '``', $t) . '`';
    $endTable = static function () use (&$table, &$pk, &$mode, &$prev, $run, $q): void {
        // Rows after the last block exist here only: gone on the other PC.
        if ($table !== null && $mode === 'compare') $run('DELETE FROM ' . $q($table) . ($prev !== null ? ' WHERE ' . $q($pk) . ' > ' . $prev : ''));
        $table = null; $mode = 'full'; $prev = null;
    };

    while (($line = fgets($sqlStream)) !== false) {
        $read += strlen($line);
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        if (str_starts_with($line, '-- ecw:table ')) {
            $endTable();
            $parts = explode(' ', $line);
            $table = $parts[2] ?? null;
            $pk = ($parts[3] ?? '-') === '-' ? null : $parts[3];
            $mode = 'full'; $pendingDrop = null; $prev = null;
            continue;
        }
        if (str_starts_with($line, '-- ecw:batch ')) { $block = explode(' ', $line); continue; }   // -- ecw:batch first last md5
        if (str_starts_with($line, '--')) continue;
        if ($compare && $table !== null && $pk !== null && str_starts_with($line, 'DROP TABLE IF EXISTS ')) { $pendingDrop = $line; continue; }
        if ($pendingDrop !== null && str_starts_with($line, 'CREATE TABLE ')) {
            // The same table here already: compared block by block. Otherwise made new and filled.
            if ($sameCreate((string)$table, $line)) { $mode = 'compare'; }
            else { $run($pendingDrop); $run($line); $mode = 'full'; }
            $pendingDrop = null;
            continue;
        }
        if (str_starts_with($line, 'SET FOREIGN_KEY_CHECKS=1')) $endTable();
        if ($mode === 'compare' && $block !== null && str_starts_with($line, 'INSERT INTO ')) {
            $first = (int)($block[2] ?? 0); $last = (int)($block[3] ?? 0); $hash = (string)($block[4] ?? '');
            $block = null;
            // Rows between the previous block and this one exist here only.
            $run('DELETE FROM ' . $q($table) . ' WHERE ' . $q($pk) . ' < ' . $first . ($prev !== null ? ' AND ' . $q($pk) . ' > ' . $prev : ''));
            $open = strpos($line, '('); $close = strpos($line, ') VALUES ');
            $names = array_map(static fn($c) => str_replace('``', '`', $c), explode('`,`', trim(substr($line, $open + 1, $close - $open - 1), '`')));
            try {
                $st = $pdo->query('SELECT GROUP_CONCAT(' . ecw_backup_row_hash_sql($names) . ' ORDER BY ' . $q($pk) . " SEPARATOR '') FROM " . $q($table)
                    . ' WHERE ' . $q($pk) . ' BETWEEN ' . $first . ' AND ' . $last);
                $here = md5((string)$st->fetchColumn());
            } catch (Throwable $e) { $here = ''; }   // cannot compare: written again
            if ($here === $hash) { $kept++; }
            else {
                $run('DELETE FROM ' . $q($table) . ' WHERE ' . $q($pk) . ' BETWEEN ' . $first . ' AND ' . $last);
                $run($line);
                $replaced++;
            }
            $prev = $last;
        } else {
            $run($line);
        }
        $pct = $from + ($read / $sqlSize) * (97 - $from);
        if ($progress && $pct - $told >= 0.5) {
            $told = $pct;
            $what = $table !== null ? preg_replace('/^ecw_/', '', (string)$table) : 'database';
            $say('Bringing in the data: ' . $what . ($compare ? ' (' . number_format($kept) . ' blocks already the same, ' . number_format($replaced) . ' new or changed)' : ''), $pct);
        }
    }
    $endTable();
    $commit();
    fclose($sqlStream);

    $say('Bringing in the files', 97.0);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if (!ecw_backup_storage_wanted($n)) continue;
        $target = dirname(__DIR__) . '/' . $n;
        if (!is_dir(dirname($target))) @mkdir(dirname($target), 0770, true);
        file_put_contents($target, (string)$zip->getFromIndex($i));
    }
    $keyRestored = false;
    $key = $zip->getFromName('encryption-key');
    $keyFile = getenv('ECW_ENCRYPTION_KEY_FILE') ?: '';
    if ($key !== false && $keyFile !== '' && trim($key) !== '') {
        $current = is_readable($keyFile) ? (string)file_get_contents($keyFile) : '';
        if (trim($current) !== trim($key)) { $keyRestored = @file_put_contents($keyFile, $key) !== false; }
    }
    $zip->close();
    $say('Done', 100.0);
    return ['statements' => $count, 'safety_backup' => $safetyName, 'key_restored' => $keyRestored, 'created_at' => $manifest['created_at'] ?? null,
            'blocks_kept' => $kept, 'blocks_replaced' => $replaced];
}
