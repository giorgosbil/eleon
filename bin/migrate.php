<?php declare(strict_types=1);
/**
 * Email Case Workspace — migration runner.
 *
 *   php bin/migrate.php --status     what is applied, what is pending
 *   php bin/migrate.php --dry-run    parse and list statements, change nothing
 *   php bin/migrate.php              apply everything pending, in filename order
 *
 * Applied files are recorded in ecw_schema_migrations by basename, so a file
 * already applied is never re-run even though every statement in it is
 * IF NOT EXISTS. CLI only: running migrations from a web request is never
 * correct here.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/modules.php';

const ECW_MIGRATIONS_TABLE = 'ecw_schema_migrations';

$dir     = dirname(__DIR__) . '/database/migrations';
$argvOpt = $argv ?? [];
$status  = in_array('--status', $argvOpt, true);
$dryRun  = in_array('--dry-run', $argvOpt, true);

$pdo = ecw_db();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `' . ECW_MIGRATIONS_TABLE . '` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `filename`   VARCHAR(255) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ecw_schema_migrations_file` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT filename, applied_at FROM `' . ECW_MIGRATIONS_TABLE . '`')
               ->fetchAll(PDO::FETCH_KEY_PAIR);

// The core's and every module's (lib/modules.php), by file name: they are recorded by file name too.
$files = ecw_migration_files();

// No schema files means a broken package: stop here (start-up fails at "init")
// instead of starting an app that has no tables.
if ((glob($dir . '/*.sql') ?: []) === []) {
    fwrite(STDERR, "ERROR: no database migrations found in {$dir}.\n"
        . "The installation package is incomplete: the folder database/migrations with its .sql files is missing.\n"
        . "Get a complete package and unpack it again.\n");
    exit(1);
}

if ($status) {
    foreach ($files as $file) {
        $base = basename($file);
        printf("%-12s %s%s\n", isset($applied[$base]) ? 'applied' : 'PENDING',
            $base, isset($applied[$base]) ? "  ({$applied[$base]} UTC)" : '');
    }
    exit(0);
}

/**
 * Split a migration into statements.
 *
 * Line-comment stripping happens first so that a `;` inside a `--` comment
 * cannot end a statement. Migrations in this project must not put `;` inside
 * a string literal, which keeps this splitter honest and the runner trivial.
 */
function ecw_split_sql(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $body  = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $body[] = $line;
    }
    $parts = explode(';', implode("\n", $body));
    return array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
}

$exitCode = 0;
foreach ($files as $file) {
    $base = basename($file);
    if (isset($applied[$base])) {
        echo "skip     {$base} (applied {$applied[$base]} UTC)\n";
        continue;
    }

    $statements = ecw_split_sql((string)file_get_contents($file));
    echo ($dryRun ? "would apply " : "applying ") . "{$base} (" . count($statements) . " statements)\n";
    if ($dryRun) {
        foreach ($statements as $i => $stmt) {
            $first = strtok($stmt, "\n");
            echo '  [' . ($i + 1) . "] {$first}\n";
        }
        continue;
    }

    // No transaction wrapper: MySQL DDL is not transactional, an implicit
    // commit fires per CREATE TABLE anyway. Statements are IF NOT EXISTS, so a
    // partial failure is fixed by correcting the file and re-running.
    try {
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $ins = $pdo->prepare('INSERT INTO `' . ECW_MIGRATIONS_TABLE . '` (filename, applied_at) VALUES (?, ?)');
        $ins->execute([$base, ecw_now()]);
        echo "  ok\n";
    } catch (PDOException $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
        $exitCode = 1;
        break;
    }
}

exit($exitCode);
