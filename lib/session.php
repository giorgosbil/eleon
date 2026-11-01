<?php declare(strict_types=1);
/**
 * Email Case Workspace — session, request context and access guards.
 *
 * ONE GUARD, TWO ANSWERS
 * ----------------------
 * The same "you are not signed in" condition has to produce a redirect for a
 * browser and a 401 JSON body for fetch(). Two separate guards would drift, and
 * the drift is dangerous in a specific way: a page that answered with JSON
 * would show a blank screen instead of a login form, and an API route that
 * answered with a 302 would hand the browser's fetch() a login PAGE with status
 * 200, which client code happily parses as a successful — empty — response.
 *
 * So there is one guard, ecw_require_login(), and it decides how to answer from
 * the request itself.
 *
 * ORGANIZATION IS NEVER TAKEN FROM THE BROWSER
 * --------------------------------------------
 * The active organization is resolved from the database against the session's
 * user id on every request. The session caches the choice, but membership is
 * re-checked, so access revoked mid-session stops working on the next click
 * rather than whenever the session happens to expire.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/timezone.php';   // ecw_tz_local(): times in the viewer's zone on every page

function ecw_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Secure only when the request actually is HTTPS: setting it unconditionally
    // on a plain-HTTP development host makes the browser discard the cookie and
    // the session silently never persists.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    // Signed in stays signed in: closing the browser or a long weekend away does not ask for the
    // password again. Only "Sign out" (or ECW_SESSION_DAYS of not using the app) ends it.
    // Before: a browser-only cookie, and PHP's default of 24 idle minutes on the server.
    $lifetime = ecw_session_lifetime();
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'httponly' => true,     // JavaScript must never read the session id
        'samesite' => 'Lax',    // blocks cross-site POSTs; the OAuth return is a top-level GET
        'secure'   => $https,
        'path'     => '/',
    ]);
    session_start();
    // The cookie's end date moves on with use (once a day is enough), like the session file's.
    if (!headers_sent() && session_id() !== '' && (int)($_SESSION['ecw_cookie_renewed'] ?? 0) < time() - 86400) {
        setcookie(session_name(), session_id(), ['expires' => time() + $lifetime, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
        $_SESSION['ecw_cookie_renewed'] = time();
    }
}

/** How long a sign-in lasts without using the app, in seconds (.env ECW_SESSION_DAYS, default 90). */
function ecw_session_lifetime(): int
{
    $days = (int)(getenv('ECW_SESSION_DAYS') ?: 90);
    return max(1, min(400, $days)) * 86400;
}

// ----------------------------------------------------------------- identity

function ecw_current_user(): ?array
{
    ecw_session_start();
    $userId = (int)($_SESSION['ecw_user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $st = ecw_db()->prepare(
        'SELECT id, name, email, disabled_at FROM `' . ecw_table('users') . '` WHERE id = ?'
    );
    $st->execute([$userId]);
    $row = $st->fetch();

    if ($row === false || $row['disabled_at'] !== null) {
        // Deleted or disabled while signed in. The session is no longer valid.
        return null;
    }
    return $row;
}

function ecw_current_organization_id(): ?int
{
    ecw_session_start();
    $user = ecw_current_user();
    if ($user === null) {
        return null;
    }

    $cached = (int)($_SESSION['ecw_organization_id'] ?? 0);
    $st = ecw_db()->prepare(
        'SELECT organization_id FROM `' . ecw_table('organization_users') . '`
          WHERE user_id = ?' . ($cached > 0 ? ' AND organization_id = ?' : '') . '
          ORDER BY id LIMIT 1'
    );
    $st->execute($cached > 0 ? [(int)$user['id'], $cached] : [(int)$user['id']]);
    $orgId = $st->fetchColumn();

    if ($orgId === false && $cached > 0) {
        // The cached organization is no longer one this user belongs to. Fall
        // back to any current membership rather than leaving them stuck.
        $st = ecw_db()->prepare(
            'SELECT organization_id FROM `' . ecw_table('organization_users') . '`
              WHERE user_id = ? ORDER BY id LIMIT 1'
        );
        $st->execute([(int)$user['id']]);
        $orgId = $st->fetchColumn();
    }

    if ($orgId === false) {
        return null;
    }

    $_SESSION['ecw_organization_id'] = (int)$orgId;
    return (int)$orgId;
}

/**
 * Everything an authenticated request is allowed to assume.
 *
 * @return array{user:array, organization_id:int, role:string}|null
 */
function ecw_context(): ?array
{
    $user = ecw_current_user();
    if ($user === null) {
        return null;
    }
    $orgId = ecw_current_organization_id();
    if ($orgId === null) {
        return null;
    }

    require_once __DIR__ . '/auth.php';
    $role = ecw_user_role((int)$user['id'], $orgId);
    if ($role === null) {
        return null;
    }

    return ['user' => $user, 'organization_id' => $orgId, 'role' => $role];
}

function ecw_is_admin(): bool
{
    $context = ecw_context();
    return $context !== null && $context['role'] === 'admin';
}

// -------------------------------------------------------------------- CSRF

function ecw_csrf_token(): string
{
    ecw_session_start();
    if (empty($_SESSION['ecw_csrf'])) {
        $_SESSION['ecw_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['ecw_csrf'];
}

/** Constant-time comparison. Accepts the form field or the API header. */
function ecw_csrf_valid(?string $candidate): bool
{
    ecw_session_start();
    $expected = (string)($_SESSION['ecw_csrf'] ?? '');
    if ($expected === '' || $candidate === null || $candidate === '') {
        return false;
    }
    return hash_equals($expected, $candidate);
}

/**
 * The visitor's IP, for rate limits and logs. Behind the optional HTTPS proxy
 * (compose profile "https") every request comes from the proxy, so its
 * X-Forwarded-For is used — but only when the request also carries the shared
 * ECW_PROXY_SECRET, which a visitor talking to the app directly cannot know.
 */
function ecw_client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $secret = (string)getenv('ECW_PROXY_SECRET');
    $sent   = (string)($_SERVER['HTTP_X_ECW_PROXY'] ?? '');
    if ($secret !== '' && $sent !== '' && hash_equals($secret, $sent)) {
        $hops = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        $client = end($hops);
        if ($client !== false && filter_var($client, FILTER_VALIDATE_IP)) return $client;
    }
    return $remote;
}

// --------------------------------------------------------------- the guard

/**
 * Does this caller want JSON rather than a page?
 *
 * A JSON endpoint DECLARES itself by defining ECW_JSON_ENDPOINT before the
 * guard runs. That is checked first because the alternatives are all guesses:
 * SCRIPT_NAME is empty under a CLI include (which is how these endpoints are
 * tested), and Accept headers are whatever the caller felt like sending. An
 * endpoint knows what it serves; the guard should not have to infer it.
 */
function ecw_request_wants_json(): bool
{
    if (defined('ECW_JSON_ENDPOINT')) {
        return true;
    }
    if (str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/')) {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

/** The application's base path, e.g. /other_things/web/ */
function ecw_web_base(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/other_things/web/index.php');
    $marker = '/web/';
    $at     = strpos($script, $marker);
    if ($at !== false) {
        return substr($script, 0, $at + strlen($marker));
    }
    // The web folder is the site's root (https://cases.example.com/): the base is
    // the request path minus where the script sits inside web/. Without this,
    // /settings/index.php took "/settings/" as the base and every link from a
    // settings page pointed at /settings/settings/….
    $webDir = str_replace('\\', '/', (string)realpath(dirname(__DIR__) . '/web'));
    $file   = str_replace('\\', '/', (string)realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
    if ($webDir !== '' && $file !== '' && str_starts_with($file, $webDir . '/')) {
        $inside = substr($file, strlen($webDir));               // "/settings/index.php"
        if (str_ends_with($script, $inside)) {
            return substr($script, 0, strlen($script) - strlen($inside)) . '/';
        }
    }
    return rtrim(str_replace('\\', '/', dirname($script)), '/') . '/';
}

/**
 * Only same-application relative destinations survive.
 *
 * Without this, ?next=https://evil.test turns the login page into an open
 * redirect that borrows our domain's credibility for a phishing link.
 */
function ecw_safe_next(?string $next): ?string
{
    $next = trim((string)$next);
    if ($next === '' || str_starts_with($next, '//') || str_contains($next, '://') || str_contains($next, "\n")) {
        return null;
    }
    if (str_starts_with($next, '/')) {
        // Absolute paths must still be inside this application.
        return str_starts_with($next, ecw_web_base()) ? $next : null;
    }
    // A bare relative path such as "settings/users.php".
    return preg_match('#^[A-Za-z0-9_\-./]+$#', $next) && !str_contains($next, '..') ? $next : null;
}

function ecw_redirect(string $location): never
{
    header('Location: ' . $location, true, 302);
    exit;
}

/**
 * Require an authenticated user with a resolved organization.
 *
 * Browsers are redirected to the login page with their intended destination
 * preserved; JSON callers get 401 with a machine-readable body.
 *
 * @return array{user:array, organization_id:int, role:string}
 */
function ecw_require_login(): array
{
    $context = ecw_context();
    if ($context !== null) {
        return $context;
    }

    if (ecw_request_wants_json()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }

    $requested = (string)($_SERVER['REQUEST_URI'] ?? '');
    $next      = ecw_safe_next($requested);
    ecw_redirect(ecw_web_base() . 'login.php' . ($next !== null ? '?next=' . rawurlencode($next) : ''));
}

/** Pages only an organization admin may open. */
function ecw_require_admin(): array
{
    $context = ecw_require_login();
    if ($context['role'] !== 'admin') {
        if (ecw_request_wants_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p style="font:14px system-ui;padding:40px">This page is for organization admins.</p>';
        echo '<p style="font:14px system-ui;padding:0 40px"><a href="' . htmlspecialchars(ecw_web_base(), ENT_QUOTES) . 'app.php">Back to mail</a></p>';
        exit;
    }
    return $context;
}

/**
 * The JSON-only guard, kept for the API front controller.
 *
 * Delegates to the same context resolution, so there is one definition of
 * "signed in" even though there are two ways to be told about it.
 */
function ecw_require_user(): array
{
    $context = ecw_context();
    if ($context === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }
    return $context['user'];
}

/**
 * Authorization, not authentication: does this user's organization own this
 * mailbox? 404 rather than 403 — a wrong-organization id must not confirm that
 * the mailbox exists.
 */
function ecw_require_mailbox_access(int $mailboxId, ?int $sharedThreadId = null): array
{
    $context = ecw_request_wants_json() ? ['user' => ecw_require_user()] : ecw_require_login();
    $orgId   = ecw_current_organization_id();

    $mailbox = ecw_access_mailbox((int)$orgId, (int)$context['user']['id'], $mailboxId, $sharedThreadId);

    if ($mailbox === null) {
        if (ecw_request_wants_json()) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'not found']);
            exit;
        }
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    return $mailbox;
}

// ------------------------------------------------------------ feature access

/** May the signed-in user (or $context) use $feature? See lib/features.php. */
function ecw_feature_allowed(string $feature, ?array $context = null): bool
{
    $context = $context ?? ecw_context();
    return $context !== null && ecw_feature_allowed_for($feature, $context);
}

/** Page guard: a friendly 403 page when the feature is switched off for this user. */
function ecw_require_feature(string $feature): void
{
    if (ecw_feature_allowed($feature)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $context = ecw_context();
    $label = htmlspecialchars(ecw_feature_catalog()[$feature][0] ?? 'This feature', ENT_QUOTES);
    $why = !ecw_package_has($feature)
        ? ' is part of the ' . ecw_packages()[ecw_package_needed($feature)]['name'] . ' package. ' . (($context['role'] ?? '') === 'admin' ? 'See Settings → License.' : 'Ask an administrator if you need it.')
        : ($context !== null && ecw_feature_available_for($feature, $context)
            ? ' is hidden by your view. Turn it on in Settings → My view.'
            : ' is not available for your account. Ask an administrator if you need it.');
    echo '<!doctype html><meta charset="utf-8"><title>Not available</title>'
        . '<p style="font:15px system-ui;padding:40px">' . $label . htmlspecialchars($why, ENT_QUOTES) . '</p>'
        . '<p style="font:15px system-ui;padding:0 40px"><a href="' . htmlspecialchars(ecw_web_base(), ENT_QUOTES) . 'app.php">Back to mail</a></p>';
    exit;
}
