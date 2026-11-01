<?php declare(strict_types=1);
/**
 * Email Case Workspace — create an organization and a user. CLI ONLY.
 *
 *   php bin/dev_user.php --org="Acme GmbH" --name="Georgios" --email=g@acme.test --password=secret
 *
 * BOOTSTRAP ONLY — creating the FIRST admin of the FIRST organization, when no
 * account exists yet and therefore nobody can sign in to create one.
 *
 * After that, the normal way to add people is Settings -> Users in the browser.
 * This command stays because a chicken-and-egg problem has to be broken
 * somewhere, and because it is the recovery path if every admin is locked out.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/db.php';

$opts = getopt('', ['org:', 'name:', 'email:', 'password:', 'role::']);
foreach (['org', 'name', 'email', 'password'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "missing --{$required}\n");
        exit(1);
    }
}

$pdo = ecw_db();
$pdo->beginTransaction();

try {
    $st = $pdo->prepare('SELECT id FROM `' . ecw_table('organizations') . '` WHERE name = ?');
    $st->execute([$opts['org']]);
    $orgId = $st->fetchColumn();
    if ($orgId === false) {
        $pdo->prepare('INSERT INTO `' . ecw_table('organizations') . '` (name, timezone) VALUES (?, ?)')
            ->execute([$opts['org'], 'Europe/Berlin']);
        $orgId = (int)$pdo->lastInsertId();
    }

    $st = $pdo->prepare('SELECT id FROM `' . ecw_table('users') . '` WHERE email = ?');
    $st->execute([$opts['email']]);
    $userId = $st->fetchColumn();
    if ($userId === false) {
        $pdo->prepare('INSERT INTO `' . ecw_table('users') . '` (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$opts['name'], $opts['email'], password_hash((string)$opts['password'], PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO `' . ecw_table('organization_users') . '` (organization_id, user_id, role)
         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)'
    )->execute([(int)$orgId, (int)$userId, (string)($opts['role'] ?? 'admin')]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo "organization #{$orgId}, user #{$userId}\n";
echo "next: open the application and sign in as {$opts['email']}\n";
