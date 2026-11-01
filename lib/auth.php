<?php declare(strict_types=1);
/**
 * Email Case Workspace — authentication.
 *
 * Credentials, sessions, password changes and admin user creation. The guards
 * that protect pages live in session.php; this file is about proving who
 * someone is, not about enforcing it per request.
 *
 * NO USER ENUMERATION
 * -------------------
 * A wrong password and an unknown address produce the same message, the same
 * status and — importantly — the same amount of work. If a missing user
 * returned early, the response would come back measurably faster than a real
 * user with a wrong password, and that timing difference is a working account
 * oracle. So a miss still runs password_verify() against a dummy hash.
 *
 * RATE LIMITING IS SHARED WITH STEP 05
 * ------------------------------------
 * Login calls ecw_rate_limit_hit() from lib/rate_limit.php, which step 05 added
 * for exactly this ("rate limiting for auth and send endpoints"). Building a
 * second throttle here would have collided with it. Both the address and the
 * client IP are counted: limiting by address alone lets one attacker spray many
 * accounts, limiting by IP alone lets a botnet grind one account.
 *
 * WHAT GOES IN THE SESSION
 * ------------------------
 * The user id, the resolved organization id, and a CSRF token. Nothing else —
 * no name, no role, no password material. Role and membership are re-read from
 * the database on each request, so revoking someone's access takes effect on
 * their next click rather than whenever their session happens to expire.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/rate_limit.php';

/** Cost of doing nothing, so a missing user takes as long as a wrong password. */
const ECW_DUMMY_HASH = '$2y$12$usesomesillystringfore.KeXqCvoFNXfHpQPSMK4VBeVvIu4/e';

const ECW_PASSWORD_MIN_LENGTH = 10;

/** Per-address and per-IP login limits. */
const ECW_LOGIN_MAX_PER_EMAIL = 10;
const ECW_LOGIN_MAX_PER_IP    = 30;
const ECW_LOGIN_WINDOW        = 900;

/**
 * Verify credentials.
 *
 * @return array{ok:bool, user:?array, error:?string, retry_after:?int}
 */
function ecw_attempt_login(string $email, string $password, ?string $clientIp = null): array
{
    $email = strtolower(trim($email));
    $generic = 'Invalid email or password.';

    try {
        ecw_rate_limit_hit('login_ip', 'ip:' . ($clientIp ?: 'unknown'), ECW_LOGIN_MAX_PER_IP, ECW_LOGIN_WINDOW);
        if ($email !== '') {
            ecw_rate_limit_hit('login_email', 'email:' . $email, ECW_LOGIN_MAX_PER_EMAIL, ECW_LOGIN_WINDOW);
        }
    } catch (EcwRateLimitExceeded $e) {
        ecw_log('login throttled');
        return ['ok' => false, 'user' => null, 'retry_after' => $e->retryAfterSeconds,
                'error' => 'Too many attempts. Try again in a few minutes.'];
    }

    $st = ecw_db()->prepare(
        'SELECT id, name, email, password_hash, disabled_at FROM `' . ecw_table('users') . '` WHERE email = ?'
    );
    $st->execute([$email]);
    $user = $st->fetch();

    // Always hash-compare, even with nobody to compare against.
    $hash     = $user === false ? ECW_DUMMY_HASH : (string)$user['password_hash'];
    $verified = password_verify($password, $hash);

    if ($user === false || !$verified) {
        ecw_log('failed login attempt');
        require_once __DIR__ . '/audit.php';
        $orgOf = $user !== false ? ecw_user_organization_id((int)$user['id']) : null;
        ecw_audit('login.fail', 'user', $user !== false ? (int)$user['id'] : null, ['email' => mb_substr($email, 0, 120)], $orgOf, null);
        return ['ok' => false, 'user' => null, 'error' => $generic, 'retry_after' => null];
    }

    if ($user['disabled_at'] !== null) {
        // Same message as a bad password: whether an address is disabled is
        // still information about whether it exists.
        ecw_log('login refused for a disabled account');
        return ['ok' => false, 'user' => null, 'error' => $generic, 'retry_after' => null];
    }

    if (ecw_user_organization_id((int)$user['id']) === null) {
        ecw_log('login refused: user belongs to no organization');
        return ['ok' => false, 'user' => null, 'error' => $generic, 'retry_after' => null];
    }

    // password_hash's cost can be raised later; rehash on the next successful
    // login rather than forcing a reset.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    }

    unset($user['password_hash']);
    return ['ok' => true, 'user' => $user, 'error' => null, 'retry_after' => null];
}

/**
 * Establish the session for a verified user.
 *
 * session_regenerate_id() is not optional: without it, an attacker who fixed a
 * session id before login (a link with a session parameter, a shared machine,
 * an XSS on any same-site page) still holds a valid id afterwards.
 */
function ecw_login_user(array $user, string $method = 'password'): void
{
    ecw_session_start();
    session_regenerate_id(true);

    $_SESSION['ecw_user_id']         = (int)$user['id'];
    $_SESSION['ecw_organization_id'] = ecw_user_last_organization_id((int)$user['id']) ?? ecw_user_organization_id((int)$user['id']);
    $_SESSION['ecw_csrf']            = bin2hex(random_bytes(32));
    $_SESSION['ecw_logged_in_at']    = time();

    ecw_log('user ' . (int)$user['id'] . ' signed in');
    require_once __DIR__ . '/audit.php';
    ecw_audit('login.ok', 'user', (int)$user['id'], $method !== 'password' ? ['with' => $method] : [], $_SESSION['ecw_organization_id'], (int)$user['id']);
}

/** Tear the session down on the server, not just in the browser. */
function ecw_logout(): void
{
    ecw_session_start();
    $userId = (int)($_SESSION['ecw_user_id'] ?? 0);
    if ($userId > 0) { require_once __DIR__ . '/audit.php'; ecw_audit('logout', 'user', $userId); }

    $_SESSION = [];

    // Clearing $_SESSION alone leaves the session file on disk and the cookie
    // in the browser, so the old id keeps working.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
    if ($userId > 0) {
        ecw_log('user ' . $userId . ' signed out');
    }
}

/** The organization a user belongs to. First membership wins in V1. */
/** The workspace of the account this person worked in last (several workspaces: lib/account.php). */
function ecw_user_last_organization_id(int $userId): ?int
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $account = (int)((json_decode((string)$st->fetchColumn(), true) ?: [])['account_id'] ?? 0);
    if ($account <= 0) return null;
    $st = ecw_db()->prepare('SELECT mb.organization_id FROM `' . ecw_table('mailboxes') . '` mb JOIN `' . ecw_table('organization_users') . '` ou
                              ON ou.organization_id = mb.organization_id AND ou.user_id = ? WHERE mb.id = ?');
    $st->execute([$userId, $account]);
    $org = $st->fetchColumn();
    return $org === false ? null : (int)$org;
}

function ecw_user_organization_id(int $userId): ?int
{
    $st = ecw_db()->prepare(
        'SELECT organization_id FROM `' . ecw_table('organization_users') . '`
          WHERE user_id = ? ORDER BY id LIMIT 1'
    );
    $st->execute([$userId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** This user's role in this organization, re-read every time it is asked for. */
function ecw_user_role(int $userId, int $organizationId): ?string
{
    $st = ecw_db()->prepare(
        'SELECT role FROM `' . ecw_table('organization_users') . '`
          WHERE user_id = ? AND organization_id = ?'
    );
    $st->execute([$userId, $organizationId]);
    $role = $st->fetchColumn();
    return $role === false ? null : (string)$role;
}

// ------------------------------------------------------------------ passwords

/** @return string|null an error message, or null when acceptable */
function ecw_password_problem(string $password): ?string
{
    if (mb_strlen($password) < ECW_PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . ECW_PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (trim($password) === '') {
        return 'Password cannot be blank.';
    }
    return null;
}

/**
 * Change a password, verifying the current one first.
 *
 * The session id is regenerated on success: a password change is the usual
 * response to "someone may have my session", and it would be useless if the
 * old session id kept working afterwards. The CURRENT session survives — the
 * user stays signed in on the device they just used.
 *
 * @return array{ok:bool, error:?string}
 */
function ecw_change_password(int $userId, string $currentPassword, string $newPassword): array
{
    $st = ecw_db()->prepare('SELECT password_hash FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();

    if ($hash === false || !password_verify($currentPassword, (string)$hash)) {
        ecw_log('password change refused: current password did not verify');
        return ['ok' => false, 'error' => 'Your current password is not correct.'];
    }

    $problem = ecw_password_problem($newPassword);
    if ($problem !== null) {
        return ['ok' => false, 'error' => $problem];
    }
    if (password_verify($newPassword, (string)$hash)) {
        return ['ok' => false, 'error' => 'The new password must be different from the current one.'];
    }

    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

    ecw_session_start();
    session_regenerate_id(true);

    ecw_log('user ' . $userId . ' changed their password');
    return ['ok' => true, 'error' => null];
}

function ecw_update_display_name(int $userId, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name cannot be blank.'];
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET name = ? WHERE id = ?')
        ->execute([mb_substr($name, 0, 255), $userId]);
    return ['ok' => true, 'error' => null];
}

// ------------------------------------------------------- admin user creation

/**
 * Create a user inside one organization.
 *
 * $organizationId comes from the ACTOR's session, never from the form, which is
 * what stops an admin of one organization creating users in another. There is
 * no parameter here for "which organization" precisely so that no caller can
 * supply one.
 *
 * @return array{ok:bool, user_id:?int, error:?string}
 */
/**
 * The password of someone invited who has not chosen theirs yet (Settings → Users → Add a user).
 * Not a hash: no password ever matches it, so the account cannot be signed in to until its owner
 * sets a password with the emailed link. Nobody, the admin included, ever knows a password.
 */
const ECW_INVITED_HASH = '!invited';

function ecw_user_is_invited(int $userId): bool
{
    $st = ecw_db()->prepare('SELECT password_hash FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    return $st->fetchColumn() === ECW_INVITED_HASH;
}

/**
 * $data['invite'] = true: no password; the person chooses it with an emailed link
 * (ecw_invite_email_send). Otherwise $data['password'] is the starting password.
 */
function ecw_admin_create_user(int $actorUserId, int $organizationId, array $data): array
{
    if (ecw_user_role($actorUserId, $organizationId) !== 'admin') {
        ecw_log('refused user creation: actor is not an admin of that organization');
        return ['ok' => false, 'user_id' => null, 'error' => 'Only an organization admin can add users.'];
    }

    $name  = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $role  = (string)($data['role'] ?? 'user');
    $password = (string)($data['password'] ?? '');

    if ($name === '') {
        return ['ok' => false, 'user_id' => null, 'error' => 'Name is required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'user_id' => null, 'error' => 'A valid email address is required.'];
    }
    if (!in_array($role, ECW_ROLES, true)) {
        return ['ok' => false, 'user_id' => null, 'error' => 'Unknown role.'];
    }
    $invite = !empty($data['invite']);
    $problem = $invite ? null : ecw_password_problem($password);
    if ($problem !== null) {
        return ['ok' => false, 'user_id' => null, 'error' => $problem];
    }

    $pdo = ecw_db();
    $st  = $pdo->prepare('SELECT id FROM `' . ecw_table('users') . '` WHERE email = ?');
    $st->execute([$email]);
    $existing = $st->fetchColumn();

    if ($existing !== false) {
        // The address exists somewhere. Only say so if it is in THIS
        // organization — otherwise an admin could probe for members of another.
        if (ecw_user_role((int)$existing, $organizationId) !== null) {
            return ['ok' => false, 'user_id' => null, 'error' => 'That address is already a member of this organization.'];
        }
        return ['ok' => false, 'user_id' => null, 'error' => 'That address cannot be added.'];
    }
    require_once __DIR__ . '/license.php';
    if ($limit = ecw_license_user_limit_problem()) {
        return ['ok' => false, 'user_id' => null, 'error' => $limit];
    }

    $own = !$pdo->inTransaction();   // inside a caller's transaction (tests): join it
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO `' . ecw_table('users') . '` (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, $email, $invite ? ECW_INVITED_HASH : password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO `' . ecw_table('organization_users') . '` (organization_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$organizationId, $userId, $role]);

        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }

    ecw_log('admin ' . $actorUserId . ' created user ' . $userId . ' in organization ' . $organizationId);
    return ['ok' => true, 'user_id' => $userId, 'error' => null];
}

/**
 * Remove a person for good (Settings → Users → Delete for good), so the same address can be
 * added again later. Only a disabled member, never yourself. Goes: their sign-in and personal
 * things (rules, signatures, templates, notifications, devices, drafts…), and the mailboxes they
 * connected here with those emails. Stays: cases, notes and case history (without their name),
 * and the audit log. A person who is also in another organization only leaves this one.
 *
 * @return array{ok:bool, error:?string, mailboxes:int, emails:int}
 */
function ecw_admin_delete_user(int $actorUserId, int $organizationId, int $targetUserId): array
{
    $no = static fn(string $e): array => ['ok' => false, 'error' => $e, 'mailboxes' => 0, 'emails' => 0];
    if (ecw_user_role($actorUserId, $organizationId) !== 'admin') return $no('Only an organization admin can delete users.');
    if ($targetUserId === $actorUserId) return $no('You cannot delete yourself.');
    if (ecw_user_role($targetUserId, $organizationId) === null) return $no('That user is not a member of this organization.');
    $pdo = ecw_db();
    $T = static fn(string $t): string => '`' . ecw_table($t) . '`';
    $st = $pdo->prepare("SELECT email, disabled_at FROM {$T('users')} WHERE id = ?");
    $st->execute([$targetUserId]);
    $u = $st->fetch();
    if (!$u) return $no('User not found.');
    if ($u['disabled_at'] === null) return $no('Disable them first (the trash button), then delete them for good.');

    // Their mailboxes here go with them (their mail and folders; cases keep their notes).
    require_once __DIR__ . '/oauth.php';
    $boxes = 0; $emails = 0;
    $mb = $pdo->prepare("SELECT id FROM {$T('mailboxes')} WHERE organization_id = ? AND connected_by_user_id = ?");
    $mb->execute([$organizationId, $targetUserId]);
    foreach ($mb->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $r = ecw_mailbox_remove((int)$id);
        $boxes++; $emails += (int)($r['messages'] ?? 0);
    }
    // Their drafts here (with their files).
    require_once __DIR__ . '/compose.php';
    $dr = $pdo->prepare("SELECT id FROM {$T('drafts')} WHERE organization_id = ? AND user_id = ?");
    $dr->execute([$organizationId, $targetUserId]);
    foreach ($dr->fetchAll(PDO::FETCH_COLUMN) as $id) { try { ecw_draft_delete($organizationId, (int)$id); } catch (Throwable $e) { ecw_log('draft not removed: ' . $e->getMessage()); } }

    $others = $pdo->prepare("SELECT COUNT(*) FROM {$T('organization_users')} WHERE user_id = ? AND organization_id <> ?");
    $others->execute([$targetUserId, $organizationId]);
    $elsewhere = (int)$others->fetchColumn() > 0;
    // Places that name the person without a database link: emptied or cleared, so nothing
    // points at someone who is gone. (Most links clear themselves: ON DELETE CASCADE / SET NULL.)
    $clean = [
        "DELETE FROM {$T('ai_suggestions')} WHERE user_id = ?",
        "DELETE FROM {$T('ai_tokens')} WHERE user_id = ?",
        "DELETE FROM {$T('case_reviews')} WHERE user_id = ?",
        "DELETE FROM {$T('mailbox_members')} WHERE user_id = ?",
        "DELETE FROM {$T('rule_backfills')} WHERE user_id = ?",
        "UPDATE {$T('email_threads')} SET claimed_by_user_id = NULL WHERE claimed_by_user_id = ?",
        "UPDATE {$T('cases')} SET next_user_id = NULL WHERE next_user_id = ?",
        "UPDATE {$T('case_checklist')} SET assignee_user_id = NULL WHERE assignee_user_id = ?",
        "UPDATE {$T('drafts')} SET editing_user_id = NULL WHERE editing_user_id = ?",
        "UPDATE {$T('users')} SET cover_user_id = NULL WHERE cover_user_id = ?",
    ];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach ($clean as $sql) {
            try { $pdo->prepare($sql)->execute([$targetUserId]); }
            catch (PDOException $e) { if (!str_contains($e->getMessage(), '1146')) throw $e; }   // a module's table not installed
        }
        if ($elsewhere) {
            $pdo->prepare("DELETE FROM {$T('organization_users')} WHERE user_id = ? AND organization_id = ?")->execute([$targetUserId, $organizationId]);
        } else {
            $pdo->prepare("DELETE FROM {$T('users')} WHERE id = ?")->execute([$targetUserId]);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
    ecw_log('admin ' . $actorUserId . ' deleted user ' . $targetUserId . ' from organization ' . $organizationId . ($elsewhere ? ' (still in another organization)' : ''));
    return ['ok' => true, 'error' => null, 'mailboxes' => $boxes, 'emails' => $emails];
}

/** Members of one organization, for the admin list. */
function ecw_organization_users(int $organizationId): array
{
    $st = ecw_db()->prepare(
        'SELECT u.id, u.name, u.email, u.created_at, u.disabled_at, ou.role
           FROM `' . ecw_table('users') . '` u
           JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
          WHERE ou.organization_id = ?
          ORDER BY ou.role, u.name'
    );
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/**
 * Disable or re-enable a member.
 *
 * Scoped to the actor's own organization, and an admin cannot disable
 * themselves — locking the last admin out of their own organization would need
 * CLI access to undo.
 */
function ecw_admin_set_user_disabled(int $actorUserId, int $organizationId, int $targetUserId, bool $disabled): array
{
    if (ecw_user_role($actorUserId, $organizationId) !== 'admin') {
        return ['ok' => false, 'error' => 'Only an organization admin can do that.'];
    }
    if ($targetUserId === $actorUserId) {
        return ['ok' => false, 'error' => 'You cannot disable your own account.'];
    }
    if (ecw_user_role($targetUserId, $organizationId) === null) {
        return ['ok' => false, 'error' => 'That user is not a member of this organization.'];
    }
    if (!$disabled) {
        require_once __DIR__ . '/license.php';
        if ($limit = ecw_license_user_limit_problem()) return ['ok' => false, 'error' => $limit];
    }

    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET disabled_at = ? WHERE id = ?')
        ->execute([$disabled ? ecw_now() : null, $targetUserId]);

    ecw_log('admin ' . $actorUserId . ($disabled ? ' disabled ' : ' re-enabled ') . 'user ' . $targetUserId);
    return ['ok' => true, 'error' => null];
}

function ecw_admin_set_user_role(int $actorUserId, int $organizationId, int $targetUserId, string $role): array
{
    if (ecw_user_role($actorUserId, $organizationId) !== 'admin') {
        return ['ok' => false, 'error' => 'Only an organization admin can do that.'];
    }
    if (!in_array($role, ECW_ROLES, true)) {
        return ['ok' => false, 'error' => 'Unknown role.'];
    }
    if ($targetUserId === $actorUserId && $role !== 'admin') {
        return ['ok' => false, 'error' => 'You cannot remove your own admin role.'];
    }
    if (ecw_user_role($targetUserId, $organizationId) === null) {
        return ['ok' => false, 'error' => 'That user is not a member of this organization.'];
    }

    ecw_db()->prepare(
        'UPDATE `' . ecw_table('organization_users') . '` SET role = ? WHERE user_id = ? AND organization_id = ?'
    )->execute([$role, $targetUserId, $organizationId]);

    return ['ok' => true, 'error' => null];
}
