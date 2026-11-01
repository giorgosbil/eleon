<?php declare(strict_types=1);
/**
 * Email Case Workspace — database connection.
 *
 * Credentials come from config.local.php (outside the web root, never
 * committed), the same file the rest of the platform reads. Every DATETIME in
 * this schema is UTC (MySQL runs UTC, below), and so does PHP here, whatever the
 * server's php.ini says (XAMPP ships Europe/Berlin): a time for a person is turned
 * into their zone on purpose (lib/timezone.php), never by a server default.
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/config.php';
// Crashes are recorded and the admins told (lib/errors.php: System health → Problems).
require_once __DIR__ . '/errors.php';
ecw_errors_install();

function ecw_db(): PDO
{
    if (PHP_SAPI === 'cli' && defined('ECW_TESTING') && ($GLOBALS['ecw_test_pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['ecw_test_pdo'];
    }
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = ecw_config();
    if (empty($config['db'])) throw new RuntimeException('Database configuration is missing. See docs/LOCAL.md.');
    $name = $config['db']['name'] ?? 'registerdatabase_dev';
    $host = $config['db']['host'] ?? '127.0.0.1';
    $port = (int)($config['db']['port'] ?? 3306);
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Current UTC time in MySQL DATETIME form. Use this, never date(). */
function ecw_now(): string
{
    return gmdate('Y-m-d H:i:s');
}
