<?php declare(strict_types=1);
/**
 * "Sign in with Microsoft" for the app itself (not for a mailbox).
 *
 * Uses the same Azure app registration and the same redirect URI as mailbox
 * connection (web/oauth/callback.php sends the browser here when the state
 * belongs to a sign-in), so nothing has to change in Entra. Only who you are is
 * asked for (openid, profile, email, User.Read), never mail access.
 *
 * WHO IS WHO
 * ----------
 * A person is identified by "tenant id:object id" from the validated ID token,
 * stored in users.sso_subject. The first time, an account is matched by
 * address, but only inside the organization whose admin named that tenant in
 * Settings → Security: Entra addresses are controlled by that tenant's admin,
 * so an address from another tenant (or a personal account) is never trusted.
 * Anyone else links their Microsoft account themselves, signed in, from Account.
 *
 * Two-factor: Microsoft's sign-in (and its MFA / conditional access) replaces
 * the app's own password and authenticator code.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/graph_identity.php';
require_once __DIR__ . '/security.php';

/** Is the button shown on the sign-in page? */
function ecw_sso_available(): bool
{
    try {
        if (!ecw_provider_configured('microsoft') || !ecw_security_licensed()) return false;
        foreach (ecw_db()->query('SELECT security_json FROM `' . ecw_table('organizations') . '` WHERE security_json IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $j) {
            if (in_array(json_decode((string)$j, true)['sso'] ?? 'off', ['on', 'required'], true)) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function ecw_sso_provider(): MicrosoftGraphProvider
{
    return new MicrosoftGraphProvider(ecw_graph_config());
}

/**
 * Start: $mode 'login' (from the sign-in page) or 'link' (signed in, from Account).
 * @return string the Microsoft URL to send the browser to
 */
function ecw_sso_begin(string $mode, ?string $next = null): string
{
    ecw_session_start();
    $mode = $mode === 'link' ? 'link' : 'login';
    $userId = null;
    if ($mode === 'link') {
        $ctx = ecw_context();
        if ($ctx === null) throw new RuntimeException('Sign in first.');
        $userId = (int)$ctx['user']['id'];
    }
    $state = bin2hex(random_bytes(32));
    $verifier = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $_SESSION['ecw_sso'] = ['state' => $state, 'verifier' => $verifier, 'nonce' => $nonce, 'at' => time(),
        'mode' => $mode, 'user_id' => $userId, 'next' => ecw_safe_next($next)];
    return ecw_sso_provider()->signInUrl($state, ecw_oauth_redirect_uri_sso(), $verifier, $nonce);
}

function ecw_oauth_redirect_uri_sso(): string
{
    $uri = (string)(ecw_graph_config()['redirect_uri'] ?? '');
    if ($uri === '') throw new RuntimeException('The Microsoft redirect address is not configured.');
    return $uri;
}

/** Does this callback belong to a sign-in (not a mailbox connection)? */
function ecw_sso_is_callback(array $query): bool
{
    ecw_session_start();
    $h = $_SESSION['ecw_sso'] ?? null;
    return is_array($h) && ($query['state'] ?? '') !== '' && hash_equals((string)$h['state'], (string)$query['state']);
}

/**
 * Finish. @return array{ok:bool, redirect?:string, error?:string}
 * $claimsOverride: tests only (skips the token exchange and signature check).
 */
function ecw_sso_callback(array $query, ?array $claimsOverride = null): array
{
    ecw_session_start();
    $h = $_SESSION['ecw_sso'] ?? null;
    unset($_SESSION['ecw_sso']);   // single use
    if (!is_array($h) || !hash_equals((string)$h['state'], (string)($query['state'] ?? '')) || time() - (int)$h['at'] > 900) {
        return ['ok' => false, 'error' => 'This sign-in link has expired. Start again.'];
    }
    if (!empty($query['error'])) {
        return ['ok' => false, 'error' => ($query['error'] === 'access_denied' ? 'Microsoft sign-in was cancelled.' : 'Microsoft did not sign you in. Try again, or use your password.')];
    }
    try {
        if ($claimsOverride !== null) {
            $claims = $claimsOverride;
        } else {
            if (empty($query['code'])) return ['ok' => false, 'error' => 'Incomplete answer from Microsoft. Start again.'];
            $tokens = ecw_sso_provider()->exchangeSignIn((string)$query['code'], ecw_oauth_redirect_uri_sso(), (string)$h['verifier']);
            $claims = ecw_graph_identity((string)$tokens['id_token'], (string)ecw_graph_config()['client_id'], (string)$h['nonce'], !empty(ecw_graph_config()['personal_accounts']));
        }
    } catch (Throwable $e) {
        ecw_log('microsoft sign-in failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Microsoft sign-in could not be checked. Try again.'];
    }
    $tid = strtolower((string)($claims['tid'] ?? ''));
    $oid = strtolower((string)($claims['oid'] ?? ''));
    if ($tid === '' || $oid === '') return ['ok' => false, 'error' => 'Microsoft sign-in could not be checked. Try again.'];
    $subject = $tid . ':' . $oid;
    $addresses = array_values(array_unique(array_filter(array_map(static fn($v) => strtolower(trim((string)$v)),
        [$claims['email'] ?? '', $claims['preferred_username'] ?? '', $claims['upn'] ?? '']), static fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false)));
    $shown = $addresses[0] ?? ($claims['name'] ?? 'this Microsoft account');

    if ($h['mode'] === 'link') return ecw_sso_link((int)$h['user_id'], $subject, (string)$shown);

    $user = ecw_sso_find_user($subject, $tid, $addresses, (string)($claims['name'] ?? ''));
    if (isset($user['error'])) {
        require_once __DIR__ . '/audit.php';
        ecw_audit('sso.fail', 'user', null, ['account' => (string)$shown, 'why' => $user['why'] ?? ''], $user['org'] ?? null, null);
        return ['ok' => false, 'error' => $user['error']];
    }
    ecw_login_user($user, 'microsoft');
    $next = $h['next'] ?? null;
    return ['ok' => true, 'redirect' => $next !== null ? (str_starts_with($next, '/') ? $next : ecw_web_base() . $next) : ecw_web_base() . 'app.php'];
}

/** @return array the user row, or ['error' => …] */
function ecw_sso_find_user(string $subject, string $tid, array $addresses, string $name): array
{
    $pdo = ecw_db();
    $users = ecw_table('users');
    $refused = ['error' => 'There is no account in Email Case Workspace for this Microsoft account. Ask your admin to add you, or sign in with your password and link Microsoft under Account.'];

    $st = $pdo->prepare("SELECT id, name, email, disabled_at FROM `{$users}` WHERE sso_subject = ?");
    $st->execute([$subject]);
    $u = $st->fetch();
    if ($u) {
        $org = ecw_user_organization_id((int)$u['id']);
        if ($u['disabled_at'] !== null || $org === null) return $refused + ['why' => 'disabled', 'org' => $org];
        if (ecw_security_settings($org)['sso'] === 'off') return ['error' => 'Microsoft sign-in is turned off for your organization. Use your password.', 'why' => 'off', 'org' => $org];
        unset($u['disabled_at']);
        return $u;
    }

    // First time: only in the organization that trusts this tenant.
    if (!ecw_security_licensed()) return $refused + ['why' => 'not licensed'];
    $org = null;
    foreach ($pdo->query('SELECT id, security_json FROM `' . ecw_table('organizations') . '` WHERE security_json IS NOT NULL')->fetchAll() as $o) {
        $s = json_decode((string)$o['security_json'], true) ?: [];
        if (in_array($s['sso'] ?? 'off', ['on', 'required'], true) && strcasecmp((string)($s['sso_tenant'] ?? ''), $tid) === 0) { $org = (int)$o['id']; break; }
    }
    if ($org === null) return $refused + ['why' => 'unknown tenant'];
    $s = ecw_security_settings($org);

    foreach ($addresses as $addr) {
        $st = $pdo->prepare("SELECT u.id, u.name, u.email, u.disabled_at, u.sso_subject FROM `{$users}` u
                               JOIN `" . ecw_table('organization_users') . "` ou ON ou.user_id = u.id AND ou.organization_id = ?
                              WHERE u.email = ?");
        $st->execute([$org, $addr]);
        $u = $st->fetch();
        if (!$u) continue;
        if ($u['disabled_at'] !== null) return $refused + ['why' => 'disabled', 'org' => $org];
        if ((string)$u['sso_subject'] !== '') return ['error' => 'This account is linked to a different Microsoft account.', 'why' => 'linked elsewhere', 'org' => $org];
        $pdo->prepare("UPDATE `{$users}` SET sso_subject = ? WHERE id = ?")->execute([$subject, (int)$u['id']]);
        require_once __DIR__ . '/audit.php';
        ecw_audit('sso.link', 'user', (int)$u['id'], ['account' => $addr, 'how' => 'first sign-in'], $org, (int)$u['id']);
        return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email']];
    }

    if (!$s['sso_create'] || $addresses === []) return $refused + ['why' => 'no account', 'org' => $org];
    // The address must not belong to anyone else on this server.
    $st = $pdo->prepare("SELECT 1 FROM `{$users}` WHERE email = ?");
    $st->execute([$addresses[0]]);
    if ($st->fetchColumn()) return $refused + ['why' => 'address taken', 'org' => $org];
    require_once __DIR__ . '/license.php';
    if ($limit = ecw_license_user_limit_problem()) return ['error' => $limit, 'why' => 'license', 'org' => $org];

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        // No usable password: a random one nobody knows (they can set one by "Forgot password").
        $pdo->prepare("INSERT INTO `{$users}` (name, email, password_hash, sso_subject) VALUES (?, ?, ?, ?)")
            ->execute([mb_substr(trim($name) !== '' ? trim($name) : $addresses[0], 0, 255), $addresses[0], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $subject]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO `' . ecw_table('organization_users') . '` (organization_id, user_id, role) VALUES (?, ?, ?)')->execute([$org, $id, $s['sso_role']]);
        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns) $pdo->rollBack();
        throw $e;
    }
    require_once __DIR__ . '/audit.php';
    ecw_audit('user.sso_create', 'user', $id, ['email' => $addresses[0], 'role' => $s['sso_role']], $org, $id);
    return ['id' => $id, 'name' => $name, 'email' => $addresses[0]];
}

/** Account → "Link Microsoft account". */
function ecw_sso_link(int $userId, string $subject, string $shown): array
{
    $back = ecw_web_base() . 'account.php';
    if ($userId <= 0 || ecw_context() === null || (int)ecw_context()['user']['id'] !== $userId) {
        return ['ok' => false, 'error' => 'Sign in again, then link your Microsoft account.'];
    }
    $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('users') . '` WHERE sso_subject = ? AND id <> ?');
    $st->execute([$subject, $userId]);
    if ($st->fetchColumn()) return ['ok' => true, 'redirect' => $back . '?sso=taken#microsoft'];
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET sso_subject = ? WHERE id = ?')->execute([$subject, $userId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('sso.link', 'user', $userId, ['account' => $shown, 'how' => 'from Account']);
    $_SESSION['ecw_sso_linked_as'] = $shown;
    return ['ok' => true, 'redirect' => $back . '?sso=linked#microsoft'];
}

function ecw_sso_unlink(int $userId): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET sso_subject = NULL WHERE id = ?')->execute([$userId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('sso.unlink', 'user', $userId);
}

function ecw_sso_linked(int $userId): bool
{
    try {
        $st = ecw_db()->prepare('SELECT sso_subject FROM `' . ecw_table('users') . '` WHERE id = ?');
        $st->execute([$userId]);
        return (string)$st->fetchColumn() !== '';
    } catch (Throwable $e) { return false; }
}
