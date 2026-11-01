<?php declare(strict_types=1);
/**
 * Two-factor sign-in (TOTP, RFC 6238 — Microsoft/Google Authenticator, 1Password…)
 * and password reset links.
 *
 * TWO-FACTOR
 *   The secret is encrypted at rest (lib/crypto.php). A code is accepted for
 *   the current 30-second step or one either side (clock drift), and never twice:
 *   totp_last_step remembers the last step used. Ten one-time recovery codes are
 *   shown once and stored only as password hashes. Attempts are rate-limited.
 *
 * RESET LINKS
 *   A random 32-byte token in the link; only its sha256 is stored. One use,
 *   24 hours, and every older unused link for that person stops working when a
 *   new one is made or used. Using a link turns nothing else off (two-factor
 *   stays on — a reset link is not a way around it).
 *   Links an admin makes are shown to the admin to pass on. A person can also
 *   ask for one on the sign-in page: it is emailed from a mailbox connected by
 *   an admin, and only when ECW_BASE_URL is set — the link is never built from
 *   the request's Host header, which an attacker controls.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/rate_limit.php';

const ECW_TOTP_STEP = 30;
const ECW_TOTP_DIGITS = 6;
const ECW_RESET_HOURS = 24;

// ---------------------------------------------------------------- base32 / TOTP

function ecw_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function ecw_base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text) ?? '');
    $bits = '';
    foreach (str_split($text) as $c) { $i = strpos($alphabet, $c); if ($i === false) continue; $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function ecw_totp_code(string $secretBase32, int $step): string
{
    $key = ecw_base32_decode($secretBase32);
    $hash = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), $key, true);
    $o = ord($hash[19]) & 0x0F;
    $num = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($num % (10 ** ECW_TOTP_DIGITS)), ECW_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/** The matching step for $code (current ±1), newer than $lastStep, or null. */
function ecw_totp_match(string $secretBase32, string $code, ?int $lastStep, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== ECW_TOTP_DIGITS) return null;
    $step = intdiv($now ?? time(), ECW_TOTP_STEP);
    foreach ([0, -1, 1] as $d) {
        $s = $step + $d;
        if ($lastStep !== null && $s <= $lastStep) continue;
        if (hash_equals(ecw_totp_code($secretBase32, $s), $code)) return $s;
    }
    return null;
}

function ecw_totp_new_secret(): string
{
    return ecw_base32_encode(random_bytes(20));
}

function ecw_totp_uri(string $secret, string $email, string $issuer = 'Case Workspace'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=' . ECW_TOTP_DIGITS . '&period=' . ECW_TOTP_STEP;
}

// ---------------------------------------------------------------- per user

function ecw_totp_enabled(int $userId): bool
{
    $st = ecw_db()->prepare('SELECT totp_secret_encrypted FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    return (string)$st->fetchColumn() !== '';
}

/** Turn two-factor on once the person typed a correct code. @return string[] the recovery codes (show once) */
function ecw_totp_enable(int $userId, string $secret, string $code): array
{
    $step = ecw_totp_match($secret, $code, null);
    if ($step === null) throw new InvalidArgumentException('That code is not right. Check the time on your phone and try the newest code.');
    $codes = [];
    for ($i = 0; $i < 10; $i++) { $r = strtoupper(bin2hex(random_bytes(5))); $codes[] = substr($r, 0, 5) . '-' . substr($r, 5); }
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET totp_secret_encrypted = ?, totp_enabled_at = ?, totp_last_step = ?, totp_recovery_json = ? WHERE id = ?')
        ->execute([ecw_encrypt($secret), ecw_now(), $step, json_encode(array_map(static fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes)), $userId]);
    ecw_trusted_forget_all($userId);   // a new authenticator: no old trust carries over
    ecw_log('user ' . $userId . ' turned on two-factor sign-in');
    require_once __DIR__ . '/audit.php';
    ecw_audit('2fa.on', 'user', $userId);
    return $codes;
}

function ecw_totp_disable(int $userId, ?int $byAdmin = null): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET totp_secret_encrypted = NULL, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery_json = NULL WHERE id = ?')
        ->execute([$userId]);
    ecw_trusted_forget_all($userId);
    ecw_log('two-factor sign-in turned off for user ' . $userId . ($byAdmin ? ' by admin ' . $byAdmin : ''));
    require_once __DIR__ . '/audit.php';
    ecw_audit($byAdmin ? 'user.2fa_reset' : '2fa.off', 'user', $userId);
}

/** Check a sign-in code: an authenticator code, or a recovery code (used up). */
function ecw_totp_verify_login(int $userId, string $code): bool
{
    ecw_rate_limit_hit('totp', 'user:' . $userId, 8, 900);
    $st = ecw_db()->prepare('SELECT totp_secret_encrypted, totp_last_step, totp_recovery_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (string)$row['totp_secret_encrypted'] === '') return false;
    $secret = (string)ecw_decrypt($row['totp_secret_encrypted']);
    $step = ecw_totp_match($secret, $code, $row['totp_last_step'] !== null ? (int)$row['totp_last_step'] : null);
    if ($step !== null) {
        ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET totp_last_step = ? WHERE id = ?')->execute([$step, $userId]);
        return true;
    }
    // Recovery code: XXXXX-XXXXX
    $typed = strtoupper(trim($code));
    if (preg_match('/^[0-9A-F]{5}-?[0-9A-F]{5}$/', $typed)) {
        $typed = substr(str_replace('-', '', $typed), 0, 5) . '-' . substr(str_replace('-', '', $typed), 5);
        $hashes = json_decode((string)$row['totp_recovery_json'], true) ?: [];
        foreach ($hashes as $i => $hash) {
            if (password_verify($typed, (string)$hash)) {
                unset($hashes[$i]);
                ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET totp_recovery_json = ? WHERE id = ?')->execute([json_encode(array_values($hashes)), $userId]);
                ecw_log('user ' . $userId . ' signed in with a recovery code (' . count($hashes) . ' left)');
                return true;
            }
        }
    }
    return false;
}

function ecw_totp_recovery_left(int $userId): int
{
    $st = ecw_db()->prepare('SELECT totp_recovery_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    return count(json_decode((string)$st->fetchColumn(), true) ?: []);
}

// ------------------------------------------------------------ trusted computers
// "Trust this computer for 30 days": no authenticator code on this browser for
// that long. A random token in an HttpOnly cookie, only its sha256 stored.

const ECW_TRUST_DAYS = 30;
const ECW_TRUST_COOKIE = 'ecw_trusted';

function ecw_request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

/** A short "Edge on Windows" style name for the list on the account page. */
function ecw_device_label(string $ua): string
{
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => 'unknown system',
    };
    return $browser . ' on ' . $os;
}

/** Remember this browser for $userId: store the hash, set the cookie. @return string the cookie's token */
function ecw_trusted_remember(int $userId): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = time() + ECW_TRUST_DAYS * 86400;
    ecw_db()->prepare('INSERT INTO `' . ecw_table('trusted_devices') . '` (user_id, token_hash, label, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), ecw_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), gmdate('Y-m-d H:i:s', $expires)]);
    if (!headers_sent()) setcookie(ECW_TRUST_COOKIE, $token, ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => ecw_request_is_https()]);
    ecw_log('user ' . $userId . ' trusted a browser for ' . ECW_TRUST_DAYS . ' days');
    return $token;
}

/** Is this browser trusted by $userId? (cookie token matches, not expired) */
function ecw_trusted_check(int $userId): bool
{
    $token = (string)($_COOKIE[ECW_TRUST_COOKIE] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) return false;
    $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('trusted_devices') . '` WHERE token_hash = ? AND user_id = ? AND expires_at > ?');
    $st->execute([hash('sha256', $token), $userId, ecw_now()]);
    $id = $st->fetchColumn();
    if ($id === false) return false;
    ecw_db()->prepare('UPDATE `' . ecw_table('trusted_devices') . '` SET last_used_at = ? WHERE id = ?')->execute([ecw_now(), (int)$id]);
    return true;
}

/** @return array<int,array{id:int,label:string,created_at:string,last_used_at:?string,expires_at:string,current:bool}> */
function ecw_trusted_list(int $userId): array
{
    $st = ecw_db()->prepare('SELECT id, token_hash, label, created_at, last_used_at, expires_at FROM `' . ecw_table('trusted_devices') . '` WHERE user_id = ? AND expires_at > ? ORDER BY COALESCE(last_used_at, created_at) DESC');
    $st->execute([$userId, ecw_now()]);
    $mine = hash('sha256', (string)($_COOKIE[ECW_TRUST_COOKIE] ?? ''));
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'label' => (string)$r['label'], 'created_at' => (string)$r['created_at'],
        'last_used_at' => $r['last_used_at'], 'expires_at' => (string)$r['expires_at'], 'current' => hash_equals((string)$r['token_hash'], $mine)], $st->fetchAll());
}

function ecw_trusted_forget(int $userId, int $id): void
{
    ecw_db()->prepare('DELETE FROM `' . ecw_table('trusted_devices') . '` WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

function ecw_trusted_forget_all(int $userId): void
{
    try { ecw_db()->prepare('DELETE FROM `' . ecw_table('trusted_devices') . '` WHERE user_id = ?')->execute([$userId]); }
    catch (Throwable $e) { ecw_log('forget trusted devices failed: ' . $e->getMessage()); }   // before migration 022
}

// ------------------------------------------------- sign-in code by email
// When the authenticator is not at hand: a 6-digit code to the person's own
// address, sent from a mailbox an admin connected. 10 minutes, kept (hashed)
// in the half-signed-in session, 5 tries, 3 emails per 15 minutes.

const ECW_EMAIL_CODE_MINUTES = 10;

/** A working mailbox connected by an admin of the organization, to send system emails from. */
function ecw_system_mailbox_id(int $organizationId): ?int
{
    $st = ecw_db()->prepare('SELECT mb.id FROM `' . ecw_table('mailboxes') . '` mb JOIN `' . ecw_table('organization_users') . "` ou ON ou.user_id = mb.connected_by_user_id AND ou.organization_id = mb.organization_id
                             WHERE mb.organization_id = ? AND ou.role = 'admin' AND mb.sync_status NOT IN ('disconnected', 'needs_reauth') ORDER BY mb.id LIMIT 1");
    $st->execute([$organizationId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** Whether an email code can be sent to this person at all (for showing the button). */
function ecw_email_code_available(int $userId): bool
{
    $st = ecw_db()->prepare('SELECT organization_id FROM `' . ecw_table('organization_users') . '` WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $org = $st->fetchColumn();
    return $org !== false && ecw_system_mailbox_id((int)$org) !== null;
}

/**
 * Email a sign-in code. @return array{hash:string, expires:int, sent_to:string} to keep in the session
 * @throws EcwRateLimitExceeded|RuntimeException
 */
function ecw_email_code_send(int $userId): array
{
    ecw_rate_limit_hit('email_code', 'user:' . $userId, 3, 900);
    $st = ecw_db()->prepare('SELECT u.id, u.name, u.email, ou.organization_id FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE u.id = ? AND u.disabled_at IS NULL LIMIT 1');
    $st->execute([$userId]);
    $user = $st->fetch();
    if (!$user) throw new RuntimeException('Sign-in failed.');
    $mailboxId = ecw_system_mailbox_id((int)$user['organization_id']);
    if ($mailboxId === null) throw new RuntimeException('No mailbox is connected that could send the code. Use a recovery code, or ask your admin.');

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    require_once __DIR__ . '/mailbox.php';
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    try {
        ecw_provider_for_mailbox($mailboxId)->sendMessage([
            'subject' => 'Your sign-in code: ' . $code,
            'body_html' => '<p>Hello ' . $h($user['name'] ?: $user['email']) . ',</p><p>Your sign-in code is:</p><p style="font-size:24px;font-weight:700;letter-spacing:4px">' . $h(substr($code, 0, 3) . ' ' . substr($code, 3)) . '</p><p>It works for ' . ECW_EMAIL_CODE_MINUTES . ' minutes. If you are not signing in right now, someone has your password: change it.</p>',
            'to' => [['email' => $user['email'], 'name' => $user['name']]], 'cc' => [], 'bcc' => [], 'attachments' => [],
        ]);
    } catch (Throwable $e) {
        ecw_log('sign-in code email could not be sent: ' . $e->getMessage());
        throw new RuntimeException('The email could not be sent. Try again, or use a recovery code.');
    }
    ecw_log('sign-in code emailed to user ' . $userId);
    // Show where it went without spelling the whole address out: p•••@example.com
    [$local, $domain] = array_pad(explode('@', (string)$user['email'], 2), 2, '');
    return ['hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + ECW_EMAIL_CODE_MINUTES * 60, 'sent_to' => mb_substr($local, 0, 1) . '•••@' . $domain, 'tries' => 0];
}

/** Check a typed code against the one kept in the session. */
function ecw_email_code_matches(?array $sent, string $code): bool
{
    if (!$sent || time() > (int)($sent['expires'] ?? 0) || (int)($sent['tries'] ?? 0) >= 5) return false;
    $code = preg_replace('/\D/', '', $code) ?? '';
    return strlen($code) === 6 && password_verify($code, (string)$sent['hash']);
}

// ---------------------------------------------------------------- reset links

/** Make a reset link token for a user (older links stop working). @return string the token for the link */
function ecw_password_reset_create(int $userId, ?int $byAdmin = null, int $hours = ECW_RESET_HOURS): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $t = ecw_table('password_resets');
    ecw_db()->prepare("UPDATE `{$t}` SET used_at = ? WHERE user_id = ? AND used_at IS NULL")->execute([ecw_now(), $userId]);
    ecw_db()->prepare("INSERT INTO `{$t}` (user_id, token_hash, created_by_user_id, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, hash('sha256', $token), $byAdmin, gmdate('Y-m-d H:i:s', time() + $hours * 3600)]);
    ecw_log('password reset link made for user ' . $userId . ($byAdmin ? ' by admin ' . $byAdmin : ' (requested)'));
    require_once __DIR__ . '/audit.php';
    if ($byAdmin) ecw_audit('user.reset_link', 'user', $userId);
    return $token;
}

/**
 * The name system emails may show: the one set in Settings → Branding, or none. Never the
 * workspace's internal name or the product's: the person sees their own company's name, or nothing.
 */
function ecw_email_brand(int $organizationId): string
{
    require_once __DIR__ . '/branding.php';
    try { $name = trim((string)(ecw_branding($organizationId)['name'] ?? '')); } catch (Throwable $e) { return ''; }
    // Never saved in Branding: the product's default name, which emails do not show.
    return $name === ECW_BRAND_DEFAULT_NAME ? '' : $name;
}

/** How long an invitation link (Settings → Users → Add a user) works. */
const ECW_INVITE_HOURS = 72;

/**
 * Email someone added in Settings → Users their link to choose their own password. Sent from a
 * mailbox an admin connected. Not sent (no such mailbox, or it failed): the link comes back for
 * the admin to pass on. Never a password: nobody but the person knows theirs.
 *
 * @return array{sent:bool, link:?string, error:?string}
 */
function ecw_invite_email_send(int $organizationId, int $userId, int $actorUserId): array
{
    require_once __DIR__ . '/auth.php';
    $st = ecw_db()->prepare('SELECT u.name, u.email FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id AND ou.organization_id = ? WHERE u.id = ?');
    $st->execute([$organizationId, $userId]);
    $user = $st->fetch();
    if (!$user) return ['sent' => false, 'link' => null, 'error' => 'That user is not a member of this organization.'];
    $linkBase = ecw_email_link_base();
    if ($linkBase === null) return ['sent' => false, 'link' => null, 'error' => 'First set the address colleagues use to open the app (above): a link to localhost works only on this computer.'];
    $token = ecw_password_reset_create($userId, $actorUserId, ECW_INVITE_HOURS);
    $link = $linkBase . ecw_web_base() . 'reset.php?token=' . $token;
    $who = ecw_db()->prepare('SELECT name FROM `' . ecw_table('users') . '` WHERE id = ?');
    $who->execute([$actorUserId]);
    $actorName = (string)($who->fetchColumn() ?: 'Your administrator');
    $brand = ecw_email_brand($organizationId);
    $mailboxId = ecw_system_mailbox_id($organizationId);
    if ($mailboxId === null) return ['sent' => false, 'link' => $link, 'error' => 'No mailbox connected by an admin can send the invitation.'];
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    try {
        require_once __DIR__ . '/mailbox.php';
        ecw_provider_for_mailbox($mailboxId)->sendMessage([
            'subject' => ($brand !== '' ? $brand . ': ' : '') . $actorName . ' invited you: set your password',
            'body_html' => '<p>Hello ' . $h($user['name'] ?: $user['email']) . ',</p>'
                . '<p>' . $h($actorName) . ' gave you an account' . ($brand !== '' ? ' at <strong>' . $h($brand) . '</strong>' : '') . ' for email and cases.</p>'
                . '<p>Choose your own password to start</p>'
                . '<p><a href="' . $h($link) . '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:#1f6feb;color:#fff;text-decoration:none;font-weight:600">Set my password</a></p>'
                . '<p style="color:#666;font-size:13px">The link works once, for ' . (int)(ECW_INVITE_HOURS / 24) . ' days. Your sign-in name is ' . $h($user['email']) . '. If you did not expect this email, you can ignore it.</p>',
            'to' => [['email' => $user['email'], 'name' => $user['name']]], 'cc' => [], 'bcc' => [], 'attachments' => [],
        ]);
    } catch (Throwable $e) {
        ecw_log('invitation email could not be sent: ' . $e->getMessage());
        return ['sent' => false, 'link' => $link, 'error' => 'The invitation email could not be sent.'];
    }
    require_once __DIR__ . '/audit.php';
    ecw_audit('user.invite_email', 'user', $userId, [], $organizationId, $actorUserId);
    return ['sent' => true, 'link' => null, 'error' => null];
}

/** The user a still-valid token belongs to, or null. */
function ecw_password_reset_user(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{30,60}$/', $token)) return null;
    $st = ecw_db()->prepare(
        'SELECT r.id AS reset_id, u.id, u.name, u.email, u.password_hash = \'' . (defined('ECW_INVITED_HASH') ? ECW_INVITED_HASH : '!invited') . '\' AS invited FROM `' . ecw_table('password_resets') . '` r
           JOIN `' . ecw_table('users') . '` u ON u.id = r.user_id
          WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.disabled_at IS NULL'
    );
    $st->execute([hash('sha256', $token), ecw_now()]);
    return $st->fetch() ?: null;
}

/** @return array{ok:bool, error:?string} */
function ecw_password_reset_use(string $token, string $newPassword): array
{
    require_once __DIR__ . '/auth.php';
    $user = ecw_password_reset_user($token);
    if ($user === null) return ['ok' => false, 'error' => 'This link has expired or was already used. Ask for a new one.'];
    $problem = ecw_password_problem($newPassword);
    if ($problem !== null) return ['ok' => false, 'error' => $problem];
    $pdo = ecw_db();
    $pdo->prepare('UPDATE `' . ecw_table('users') . '` SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
    $pdo->prepare('UPDATE `' . ecw_table('password_resets') . '` SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([ecw_now(), (int)$user['id']]);
    ecw_trusted_forget_all((int)$user['id']);   // whoever had the old password must not keep a trusted browser
    ecw_log('user ' . (int)$user['id'] . ' set a new password with a reset link');
    require_once __DIR__ . '/audit.php';
    ecw_audit('password.reset', 'user', (int)$user['id'], [], ecw_user_organization_id((int)$user['id']), (int)$user['id']);
    return ['ok' => true, 'error' => null];
}

/** Base URL for links in emails: ECW_BASE_URL only (never the Host header). */
function ecw_public_base_url(): ?string
{
    $b = rtrim((string)getenv('ECW_BASE_URL'), '/');
    return preg_match('#^https?://[^/\s]+$#', $b) ? $b : null;
}

/**
 * The address colleagues open the app at (Settings → Users), for links in emails: invitations,
 * password resets. Not ECW_BASE_URL: that is the public address webhooks use (Telegram,
 * WhatsApp), which may open nothing else (Tailscale Funnel on one path). Scheme and host only.
 * Saved as an office-network address (192.168.x.x, 10.x.x.x, 172.16-31.x.x): that means "this
 * PC", so the address follows the network this PC is on now (another office, another router).
 */
function ecw_app_url(): ?string
{
    require_once __DIR__ . '/license.php';
    foreach ([ecw_app_setting('app_url'), getenv('ECW_APP_URL') ?: null] as $u) {
        $u = rtrim(trim((string)$u), '/');
        if ($u !== '' && preg_match('#^https?://[^/\s]+$#', $u)) return ecw_follow_lan_ip($u);
    }
    return null;
}

function ecw_is_lan_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
}

/**
 * This PC's address on the office network now, or null. ECW_LAN_IP sets it (in Docker, the host
 * PC's address, written to .env by scripts/lan-ip.ps1 from Start.ps1; "off" turns it off). Otherwise the address Windows would send from: a UDP
 * "connect" only picks the route, nothing is sent.
 */
function ecw_lan_ip(): ?string
{
    static $ip = false;
    $env = trim((string)getenv('ECW_LAN_IP'));
    if ($env !== '') return ecw_is_lan_ip($env) ? $env : null;
    if ($ip !== false) return $ip;
    $ip = null;
    if (is_file('/.dockerenv')) return null;   // the container's own address opens nothing
    $s = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
    if ($s) {
        $name = (string)stream_socket_get_name($s, false);
        fclose($s);
        $local = substr($name, 0, (int)strrpos($name, ':'));
        if (ecw_is_lan_ip($local)) return $ip = $local;
    }
    foreach (@gethostbynamel((string)gethostname()) ?: [] as $a) {
        if (ecw_is_lan_ip($a)) return $ip = $a;
    }
    return null;
}

/** An address on this PC's office network, with the host swapped for this PC's address now. */
function ecw_follow_lan_ip(string $url): string
{
    $host = (string)parse_url($url, PHP_URL_HOST);
    if (!ecw_is_lan_ip($host) || ($now = ecw_lan_ip()) === null || $now === $host) return $url;
    $port = parse_url($url, PHP_URL_PORT);
    return parse_url($url, PHP_URL_SCHEME) . '://' . $now . ($port ? ':' . $port : '');
}

function ecw_app_url_save(?string $url): void
{
    $url = rtrim(trim((string)$url), '/');
    if ($url !== '' && !preg_match('#^https?://[^/\s]+$#', $url)) {
        throw new InvalidArgumentException('Type it like http://192.168.2.2:8080 or https://cases.yourcompany.com (no path).');
    }
    require_once __DIR__ . '/license.php';
    ecw_app_setting_set('app_url', $url !== '' ? $url : null);
}

/** The address this request came in at (scheme and host). */
function ecw_request_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * Base for links in emails: the colleagues' address when set, else the one in use now. On
 * localhost (works only on this computer): this PC's office-network address, same port; none
 * found, null, and nothing broken is sent.
 */
function ecw_email_link_base(): ?string
{
    if (($u = ecw_app_url()) !== null) return $u;
    $b = ecw_request_base_url();
    $host = strtolower((string)parse_url($b, PHP_URL_HOST));
    if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) return ecw_follow_lan_ip($b);
    if (($ip = ecw_lan_ip()) === null) return null;
    $port = parse_url($b, PHP_URL_PORT);
    return parse_url($b, PHP_URL_SCHEME) . '://' . $ip . ($port ? ':' . $port : '');
}

/** Base URL for links shown on screen to the signed-in admin. */
function ecw_link_base_url(): string
{
    if ($b = ecw_public_base_url()) return $b;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * "Forgot password?": email a link, when the address belongs to someone and a
 * mailbox connected by an admin of their workspace can send it. Always quiet
 * about whether the address exists. @return bool whether an email went out
 */
function ecw_password_reset_request(string $email, string $clientIp): bool
{
    $email = strtolower(trim($email));
    try {
        ecw_rate_limit_hit('reset_ip', 'ip:' . $clientIp, 10, 3600);
        if ($email !== '') ecw_rate_limit_hit('reset_email', 'email:' . $email, 3, 3600);
    } catch (EcwRateLimitExceeded $e) { return false; }
    // The address colleagues use (Settings → Users), else the configured one. Never the address
    // the request came in at: anyone can send a false Host, and the link would lead to their site.
    $base = ecw_app_url() ?? ecw_public_base_url();
    if ($base === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $st = ecw_db()->prepare('SELECT u.id, u.name, u.email, ou.organization_id FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE u.email = ? AND u.disabled_at IS NULL LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch();
    if (!$user) return false;
    $mailboxId = ecw_system_mailbox_id((int)$user['organization_id']);
    if ($mailboxId === null) return false;

    require_once __DIR__ . '/mailbox.php';
    $token = ecw_password_reset_create((int)$user['id']);
    $link = $base . ecw_web_base() . 'reset.php?token=' . $token;
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    try {
        ecw_provider_for_mailbox((int)$mailboxId)->sendMessage([
            'subject' => 'Reset your password',
            'body_html' => '<p>Hello ' . $h($user['name'] ?: $user['email']) . ',</p><p>Someone (hopefully you) asked to reset your password. Open this link within ' . ECW_RESET_HOURS . ' hours to choose a new one:</p><p><a href="' . $h($link) . '">' . $h($link) . '</a></p><p>If you did not ask for this, ignore this email — your password stays the same.</p>',
            'to' => [['email' => $user['email'], 'name' => $user['name']]], 'cc' => [], 'bcc' => [], 'attachments' => [],
        ]);
        return true;
    } catch (Throwable $e) {
        ecw_log('reset email could not be sent: ' . $e->getMessage());
        return false;
    }
}
