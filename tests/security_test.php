<?php declare(strict_types=1);
/**
 * Account safety, flags and backups.
 *
 *   php tests/security_test.php
 *
 * Database work runs inside one transaction that is always rolled back
 * (the backup dump itself only reads).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/account_security.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/mail_actions.php';
require_once dirname(__DIR__) . '/lib/backup.php';

$pass = 0; $fail = 0;
function group(string $t): void { echo "\n=== {$t} ===\n"; }
function check(bool $c, string $label): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$label}\n"; } else { $fail++; echo "  FAIL  {$label}\n"; } }

group('TOTP (RFC 6238)');
// RFC 6238 test secret "12345678901234567890"; at T=59 the 8-digit code is 94287082 → 6 digits 287082.
$rfc = ecw_base32_encode('12345678901234567890');
check($rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 encodes the RFC secret');
check(ecw_base32_decode($rfc) === '12345678901234567890', 'base32 round-trips');
check(ecw_totp_code($rfc, intdiv(59, 30)) === '287082', 'code matches the RFC test vector (T=59)');
check(ecw_totp_code($rfc, intdiv(1111111109, 30)) === '081804', 'code matches the RFC test vector (T=1111111109)');
$now = 1111111109; $step = intdiv($now, 30);
check(ecw_totp_match($rfc, '081804', null, $now) === $step, 'the current code is accepted');
check(ecw_totp_match($rfc, ecw_totp_code($rfc, $step - 1), null, $now) === $step - 1, 'the previous code is accepted (clock drift)');
check(ecw_totp_match($rfc, ecw_totp_code($rfc, $step - 3), null, $now) === null, 'an old code is refused');
check(ecw_totp_match($rfc, '081804', $step, $now) === null, 'a code cannot be used twice');
check(ecw_totp_match($rfc, '12345', null, $now) === null, 'a short code is refused');
check(str_starts_with(ecw_totp_uri($rfc, 'a@b.c'), 'otpauth://totp/Case%20Workspace%3Aa%40b.c?secret=' . $rfc), 'otpauth URI for the QR code');

group('proxy client address');
$_SERVER['REMOTE_ADDR'] = '172.18.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 192.168.2.40';
putenv('ECW_PROXY_SECRET=s3cret');
unset($_SERVER['HTTP_X_ECW_PROXY']);
check(ecw_client_ip() === '172.18.0.5', 'X-Forwarded-For is ignored without the proxy secret');
$_SERVER['HTTP_X_ECW_PROXY'] = 'wrong';
check(ecw_client_ip() === '172.18.0.5', 'and with a wrong secret');
$_SERVER['HTTP_X_ECW_PROXY'] = 's3cret';
check(ecw_client_ip() === '192.168.2.40', 'with the secret, the address the proxy added is used');
putenv('ECW_PROXY_SECRET');

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = function (string $t, array $row) use ($pdo): int {
        $c = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'Security test']);
    $uid = $ins('users', ['name' => 'Sec Test', 'email' => 'sec' . uniqid() . '@acme.test', 'password_hash' => password_hash('correct horse battery', PASSWORD_DEFAULT)]);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $uid, 'role' => 'user']);

    group('two-factor sign-in');
    $secret = ecw_totp_new_secret();
    check(strlen($secret) === 32, 'a new secret has 160 bits');
    try { ecw_totp_enable($uid, $secret, '000000'); check(false, 'a wrong setup code is refused'); }
    catch (InvalidArgumentException $e) { check(true, 'a wrong setup code is refused'); }
    check(!ecw_totp_enabled($uid), 'and two-factor stays off');
    $setupStep = intdiv(time(), 30);   // one time window for both lines below, so a 30 s rollover cannot flip the result
    $codes = ecw_totp_enable($uid, $secret, ecw_totp_code($secret, $setupStep));
    check(ecw_totp_enabled($uid) && count($codes) === 10, 'a correct code turns it on and gives 10 recovery codes');
    $stored = (string)$pdo->query('SELECT totp_secret_encrypted FROM ecw_users WHERE id = ' . $uid)->fetchColumn();
    check($stored !== '' && !str_contains($stored, $secret), 'the secret is stored encrypted');
    check(!str_contains((string)$pdo->query('SELECT totp_recovery_json FROM ecw_users WHERE id = ' . $uid)->fetchColumn(), $codes[0]), 'recovery codes are stored only as hashes');
    check(!ecw_totp_verify_login($uid, ecw_totp_code($secret, $setupStep)), 'the code used for setup cannot sign in again');
    check(ecw_totp_verify_login($uid, ecw_totp_code($secret, intdiv(time(), 30) + 1)), 'the next code signs in');
    check(ecw_totp_verify_login($uid, strtolower($codes[3])), 'a recovery code signs in (any case)');
    check(!ecw_totp_verify_login($uid, $codes[3]), 'and only once');
    check(ecw_totp_recovery_left($uid) === 9, '9 recovery codes left');

    group('trusted computers and emailed codes');
    $_COOKIE = [];
    check(!ecw_trusted_check($uid), 'a browser without the cookie is not trusted');
    $_COOKIE[ECW_TRUST_COOKIE] = ecw_trusted_remember($uid);
    check(ecw_trusted_check($uid), 'the remembered browser is trusted');
    check(!ecw_trusted_check($uid + 999), 'but not for another person');
    check((int)$pdo->query('SELECT COUNT(*) FROM ecw_trusted_devices WHERE token_hash = ' . $pdo->quote($_COOKIE[ECW_TRUST_COOKIE]))->fetchColumn() === 0, 'only the hash of the token is stored');
    check(count(ecw_trusted_list($uid)) === 1 && ecw_trusted_list($uid)[0]['current'], 'the account page lists it as this computer');
    $pdo->exec('UPDATE ecw_trusted_devices SET expires_at = \'2000-01-01 00:00:00\' WHERE user_id = ' . $uid);
    check(!ecw_trusted_check($uid), 'trust runs out after 30 days');
    $_COOKIE[ECW_TRUST_COOKIE] = ecw_trusted_remember($uid);
    $sent = ['hash' => password_hash('123456', PASSWORD_DEFAULT), 'expires' => time() + 600, 'tries' => 0];
    check(ecw_email_code_matches($sent, '123 456'), 'an emailed code signs in');
    check(!ecw_email_code_matches($sent, '654321'), 'a wrong emailed code does not');
    check(!ecw_email_code_matches(['expires' => time() - 1] + $sent, '123456'), 'an emailed code runs out');
    check(!ecw_email_code_matches(['tries' => 5] + $sent, '123456'), 'and stops working after 5 wrong tries');
    check(!ecw_email_code_matches(null, '123456'), 'no code sent: nothing matches');
    ecw_totp_disable($uid);
    check(!ecw_totp_enabled($uid), 'it can be turned off');
    check(!ecw_trusted_check($uid), 'turning two-factor off forgets the trusted computers');

    group('password reset links');
    $old = ecw_password_reset_create($uid);
    $tok = ecw_password_reset_create($uid);
    check(ecw_password_reset_user($old) === null, 'making a new link cancels the older one');
    check((int)(ecw_password_reset_user($tok)['id'] ?? 0) === $uid, 'the new link belongs to the user');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_password_resets WHERE token_hash = " . $pdo->quote($tok))->fetchColumn() === 0, 'the token itself is never stored');
    check(!ecw_password_reset_use($tok, 'short')['ok'], 'a too-short password is refused');
    check(ecw_password_reset_use($tok, 'a brand new password')['ok'], 'the link sets a new password');
    check(ecw_attempt_login((string)$pdo->query('SELECT email FROM ecw_users WHERE id = ' . $uid)->fetchColumn(), 'a brand new password', '10.0.0.' . random_int(1, 250))['ok'], 'signing in works with it');
    check(!ecw_password_reset_use($tok, 'another new password!')['ok'], 'a link works only once');
    $expired = ecw_password_reset_create($uid);
    $pdo->exec("UPDATE ecw_password_resets SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = " . $pdo->quote(hash('sha256', $expired)));
    check(ecw_password_reset_user($expired) === null, 'an expired link is refused');
    check(ecw_password_reset_user('x') === null && ecw_password_reset_user(str_repeat('a', 43)) === null, 'nonsense tokens are refused');
    putenv('ECW_BASE_URL=http://evil.example/path');
    check(ecw_public_base_url() === null, 'emailed links need a plain base URL (never from the Host header)');
    putenv('ECW_BASE_URL');

    group('flags');
    $mb = $ins('mailboxes', ['organization_id' => $org, 'connected_by_user_id' => $uid, 'provider' => 'microsoft', 'email_address' => 'flag' . uniqid() . '@acme.test', 'sync_status' => 'connected']);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'inbox' . uniqid(), 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1]);
    $pid = 'p' . bin2hex(random_bytes(6));
    $msg = ['provider_message_id' => $pid, 'internet_message_id' => '<' . $pid . '@x>', 'provider_conversation_id' => 'c' . uniqid(),
        'subject' => 'Flag me', 'from_email' => 'x@other.test', 'from_name' => 'X', 'to' => [], 'cc' => [], 'bcc' => [],
        'received_at' => gmdate('Y-m-d H:i:s'), 'sent_at' => gmdate('Y-m-d H:i:s'), 'is_read' => true, 'has_attachments' => false, 'references' => []];
    $thread = ecw_ingest_message($mb, $inbox, 'inbox', $msg)['thread_id'];
    check(ecw_thread_flag($mb, $thread, true) === 1, 'flagging marks the conversation');
    check(ecw_thread_list($mb, ['folder_id' => $inbox])['threads'][0]['flagged'] === true, 'the list shows the flag');
    check(count(ecw_thread_list($mb, ['query' => 'is:flagged'])['threads']) === 1, 'is:flagged finds it');
    check((int)$pdo->query('SELECT flag_push_pending FROM ecw_email_messages WHERE provider_message_id = ' . $pdo->quote($pid))->fetchColumn() === 1, 'the flag waits to be pushed to Outlook');
    ecw_ingest_message($mb, $inbox, 'inbox', $msg + ['is_flagged' => false, '_source_fields' => ['flag']]);
    check((int)$pdo->query('SELECT is_flagged FROM ecw_email_messages WHERE provider_message_id = ' . $pdo->quote($pid))->fetchColumn() === 1, 'a stale provider update does not undo it');
    check(ecw_thread_flag($mb, $thread, false) === 1 && count(ecw_thread_list($mb, ['query' => 'is:flagged'])['threads']) === 0, 'clearing the flag');
    try { ecw_thread_move($mb, $thread, 'nowhere'); check(false, 'an unknown destination is refused'); }
    catch (InvalidArgumentException $e) { check(true, 'an unknown destination is refused'); }
    try { ecw_thread_move($mb, $thread, '999999999'); check(false, 'a folder of another mailbox is refused'); }
    catch (InvalidArgumentException $e) { check(true, 'a folder of another mailbox is refused'); }
} finally {
    $pdo->rollBack();
}

// A full backup of a real mailbox (gigabytes) made in a throwaway test container brought Docker
// Desktop down (WSL crashed, 2026-10-28). Above 1 GB this check is skipped: the daily automatic
// backup (Settings -> Backups) is the one made at real size.
$dbMb = (int)ecw_db()->query('SELECT SUM(data_length + index_length) / 1048576 FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($dbMb > 1024) {
    echo "SKIP backup file: the database is {$dbMb} MB, too big to back up inside a test\n";
} else {
    group('backup file');
    // A row of its own to find in the dump (committed: the dump reads what is saved), removed
    // after. Without it a fresh, empty database had no user rows and the check failed.
    $pdo->prepare('INSERT INTO `' . ecw_table('users') . '` (name, email, password_hash) VALUES (?, ?, ?)')
        ->execute(['Backup fixture', 'backup-fixture-' . bin2hex(random_bytes(4)) . '@example.test', 'x']);
    $fixtureUser = (int)$pdo->lastInsertId();
    $dir = sys_get_temp_dir() . '/ecw-backup-test-' . bin2hex(random_bytes(3));
    putenv('ECW_BACKUP_DIR=' . $dir);
    try {
    $name = ecw_backup_create('manual');
    $path = ecw_backup_path($name);
    check($path !== null && filesize($path) > 1000, 'a backup zip is written');
    check(ecw_backup_path('../etc/passwd') === null && ecw_backup_path('x.zip') === null, 'only backup names from the folder are accepted');
    $zip = new ZipArchive(); $zip->open($path);
    require_once dirname(__DIR__) . '/lib/security.php';
    if (($zipPassword = ecw_backup_password()) !== null) $zip->setPassword($zipPassword);   // an encrypted backup (backup password set)
    $manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
    check(($manifest['app'] ?? '') === 'Email Case Workspace' && $manifest['tables'] > 20, 'the manifest describes it');
    // Read line by line: with a real mailbox the dump is hundreds of MB.
    $hasCases = $hasUsers = false; $bad = 0; $usersHasCreated = false;
    $stream = $zip->getStream('database.sql');
    while ($stream && ($line = fgets($stream)) !== false) {
        $line = rtrim($line, "\r\n");
        if (!$hasCases && str_contains($line, 'CREATE TABLE `ecw_cases`')) $hasCases = true;
        if (!$hasUsers && str_contains($line, 'INSERT INTO `ecw_users`')) $hasUsers = true;
        // Columns that default to the current time are data too (DEFAULT_GENERATED is not a generated column).
        if (str_starts_with($line, 'INSERT INTO `ecw_users` (')) $usersHasCreated = str_contains(substr($line, 0, strpos($line, ') VALUES')), '`created_at`');
        if ($line !== '' && !str_starts_with($line, '--') && !str_ends_with($line, ';')) $bad++;
    }
    if ($stream) fclose($stream);
    check($hasCases && $hasUsers, 'it holds the tables and their rows');
    check($usersHasCreated, 'it keeps the dates that default to now (created_at…), so a restore does not stamp them with the restore time');
    check($bad === 0, 'every line is one complete statement (safe to restore line by line)');
    $zip->close();
    } finally {
        $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE id = ?')->execute([$fixtureUser]);
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir);
        putenv('ECW_BACKUP_DIR');
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
