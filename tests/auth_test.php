<?php declare(strict_types=1);
/**
 * Step 04A tests — authentication and application entry.
 *
 *   php tests/auth_test.php
 *
 * Two halves.
 *
 * The first drives the application over REAL HTTP, against PHP's built-in
 * server, with a cookie jar. That is not gold-plating: this step is almost
 * entirely about status codes, Location headers, cookie flags and session
 * identity, and none of those exist when a page is include()d from a test.
 * A redirect loop, a cookie that is never set, a 401 that should have been a
 * 302 — all invisible in-process, all immediately fatal in a browser.
 *
 * The second half calls the auth functions directly, inside a transaction, for
 * the logic that is awkward to reach through HTTP (cross-organization refusals,
 * timing-safe misses, password rules).
 *
 * The lettered groups map to the acceptance tests in the step.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/auth.php';

$pass = 0;
$fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function ok(string $w): void { global $pass; $pass++; echo "  ok    {$w}\n"; }
function bad(string $w, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
function check(bool $c, string $w, string $d = ''): void { $c ? ok($w) : bad($w, $d); }
function checkSame($e, $a, string $w): void
{
    $e === $a ? ok($w) : bad($w, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$root = dirname(__DIR__);
$pdo  = ecw_db();

// ---------------------------------------------------------------- fixtures
// Committed, because the HTTP server is a separate process and cannot see an
// open transaction. Removed in the finally at the bottom.
$tag = bin2hex(random_bytes(3));
$ins = static function (string $table, array $row) use ($pdo): int {
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
};

$adminPassword = 'correct-horse-battery';
$userPassword  = 'another-good-secret';

$orgId   = $ins('organizations', ['name' => 'Auth Test Org ' . $tag]);
$adminId = $ins('users', ['name' => 'Admin Person', 'email' => "admin.{$tag}@example.test",
                          'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]);
$plainId = $ins('users', ['name' => 'Plain Person', 'email' => "plain.{$tag}@example.test",
                          'password_hash' => password_hash($userPassword, PASSWORD_DEFAULT)]);
$ins('organization_users', ['organization_id' => $orgId, 'user_id' => $adminId, 'role' => 'admin']);
$ins('organization_users', ['organization_id' => $orgId, 'user_id' => $plainId, 'role' => 'user']);

$otherOrgId   = $ins('organizations', ['name' => 'Auth Other Org ' . $tag]);
$otherAdminId = $ins('users', ['name' => 'Other Admin', 'email' => "other.{$tag}@example.test",
                               'password_hash' => password_hash('yet-another-secret', PASSWORD_DEFAULT)]);
$ins('organization_users', ['organization_id' => $otherOrgId, 'user_id' => $otherAdminId, 'role' => 'admin']);

// Isolate login throttling from real users and other test runs.
$testIp = '2001:db8::' . substr($tag, 0, 3) . ':' . substr($tag, 3);
$router = sys_get_temp_dir() . '/ecw_auth_router_' . $tag . '.php';
file_put_contents($router, '<?php $_SERVER["REMOTE_ADDR"] = ' . var_export($testIp, true) . '; return false;');

// ------------------------------------------------------------- http server
// Ask the OS for a free port; fixed random ranges can hit a stale test server.
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($listener === false) throw new RuntimeException($errstr);
$port = (int)substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);
$serverLog = sys_get_temp_dir() . '/ecw_auth_server_' . $tag . '.log';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/web', $router],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes,
    // Invitations link to the address colleagues use: here the test server itself (not localhost-only).
    null, getenv() + ['ECW_APP_URL' => 'http://127.0.0.1.nip.test:' . $port]
);

// Wait for it to answer rather than sleeping a fixed amount.
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($probe !== false) { fclose($probe); $ready = true; break; }
    usleep(100000);
}

/**
 * One HTTP request with a persistent cookie jar.
 *
 * @return array{status:int, headers:array, body:string, location:?string}
 */
function http(string $jar, string $path, int $port, array $post = null, array $headers = []): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,   // the redirect IS what we are testing
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw    = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $hlen);
    $body       = substr($raw, $hlen);

    $parsed = [];
    foreach (preg_split('/\R/', $headerText) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))][] = trim($v);
        }
    }

    return [
        'status'   => $status,
        'headers'  => $parsed,
        'body'     => $body,
        'location' => $parsed['location'][0] ?? null,
    ];
}

function sessionCookie(string $jar): ?string
{
    if (!is_file($jar)) {
        return null;
    }
    foreach (preg_split('/\R/', (string)file_get_contents($jar)) ?: [] as $line) {
        if (str_contains($line, 'PHPSESSID')) {
            $parts = preg_split('/\s+/', trim($line));
            return $parts[6] ?? null;
        }
    }
    return null;
}

/** Pull the CSRF token out of a rendered form. */
function csrfFrom(string $html): ?string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : null;
}

$jars = [];
$jar = static function (string $name) use (&$jars, $tag): string {
    $jars[$name] = $jars[$name] ?? tempnam(sys_get_temp_dir(), 'ecwjar_' . $tag . '_');
    return $jars[$name];
};

try {
    check($ready, 'the test HTTP server started');
    if (!$ready) {
        echo "server log:\n" . @file_get_contents($serverLog) . "\n";
        throw new RuntimeException('server did not start');
    }

    // ------------------------------------------------------------------ A
    group('A — entry point');
    $anon = $jar('anon');
    $response = http($anon, '/', $port);
    checkSame(302, $response['status'], 'GET /web/ answers a redirect');
    check(str_contains((string)$response['location'], 'login.php'),
        'and it points at login.php when nobody is signed in', (string)$response['location']);

    $response = http($anon, '/login.php', $port);
    checkSame(200, $response['status'], 'the login page itself renders');
    check(str_contains($response['body'], 'name="password"'), 'with a password field');
    check(str_contains($response['body'], 'name="csrf"'), 'and a CSRF token');

    // A fresh jar, because the cookie is only sent on the response that
    // establishes the session — a second request over the same jar carries no
    // Set-Cookie at all and would make these assertions pass vacuously.
    $cookieProbe = $jar('cookieprobe');
    $probe = http($cookieProbe, '/login.php', $port);
    $setCookie = implode(' ', $probe['headers']['set-cookie'] ?? []);
    check($setCookie !== '', 'a session cookie is issued');
    check(str_contains($setCookie, 'HttpOnly'), 'the session cookie is HttpOnly');
    check(str_contains($setCookie, 'SameSite=Lax'), 'and SameSite=Lax');
    check(!str_contains($setCookie, 'Secure'),
        'Secure is omitted over plain HTTP, so the session is not silently discarded in development');

    // ------------------------------------------------------------------ C
    // Run the failure cases first, while no session exists.
    group('C — failed login');
    $before = sessionCookie($anon);
    $page   = http($anon, '/login.php', $port);
    $csrf   = csrfFrom($page['body']);

    $wrongPassword = http($anon, '/login.php', $port, [
        'csrf' => $csrf, 'email' => "admin.{$tag}@example.test", 'password' => 'not-the-password',
    ]);
    checkSame(401, $wrongPassword['status'], 'a wrong password is refused');
    check(str_contains($wrongPassword['body'], 'Invalid email or password'), 'with a generic message');

    $page    = http($anon, '/login.php', $port);
    $csrf    = csrfFrom($page['body']);
    $unknown = http($anon, '/login.php', $port, [
        'csrf' => $csrf, 'email' => "nobody.{$tag}@example.test", 'password' => 'not-the-password',
    ]);
    checkSame(401, $unknown['status'], 'an unknown address is refused the same way');

    // The two bodies must be indistinguishable, or the form is an account oracle.
    $normalise = static fn(string $html): string => (string)preg_replace('/value="[^"]*"/', '', $html);
    checkSame($normalise($wrongPassword['body']), $normalise($unknown['body']),
        'the two responses are byte-identical, so the form cannot be used to discover accounts');

    $protected = http($anon, '/app.php', $port);
    checkSame(302, $protected['status'], 'no session was created by the failed attempts');

    // ------------------------------------------------------------------ D
    group('D — protected pages without a session');
    foreach (['/app.php', '/case.php?id=1', '/cases.php', '/account.php',
              '/settings/', '/settings/users.php', '/settings/mailbox.php', '/oauth/start.php'] as $path) {
        $response = http($anon, $path, $port);
        check($response['status'] === 302 && str_contains((string)$response['location'], 'login.php'),
            "{$path} redirects to the login page", 'status ' . $response['status']);
    }

    $api = http($anon, '/api/index.php?action=mailboxes', $port);
    checkSame(401, $api['status'], 'the API answers 401 rather than redirecting');
    $json = json_decode($api['body'], true);
    checkSame('unauthenticated', $json['error'] ?? null, 'with the exact body the step specifies');

    // The intended destination survives the detour.
    $response = http($anon, '/settings/users.php', $port);
    check(str_contains((string)$response['location'], 'next='), 'the intended destination is preserved in the redirect');

    // ------------------------------------------------------------------ B
    group('B — successful login');
    $admin = $jar('admin');
    $page  = http($admin, '/login.php', $port);
    $csrf  = csrfFrom($page['body']);
    $sessionBefore = sessionCookie($admin);

    $login = http($admin, '/login.php', $port, [
        'csrf' => $csrf, 'email' => "admin.{$tag}@example.test", 'password' => $adminPassword,
    ]);
    checkSame(302, $login['status'], 'valid credentials are accepted');
    check(str_contains((string)$login['location'], 'app.php'), 'and land on the application');

    $sessionAfter = sessionCookie($admin);
    check($sessionBefore !== null && $sessionAfter !== null && $sessionBefore !== $sessionAfter,
        'the session id is regenerated on login, so a fixed session cannot be reused');

    // First visit with no mailbox connected: the Get started checklist, once per session.
    $first = http($admin, '/app.php', $port);
    check($first['status'] === 302 && str_contains((string)$first['location'], 'get-started.php'), 'on the first day, Mail opens the Get started checklist first');
    $app = http($admin, '/app.php', $port);
    checkSame(200, $app['status'], 'the mail interface is now reachable');
    check(str_contains($app['body'], 'appnav'), 'and carries the shared navigation');
    foreach (['app.php', 'cases.php', 'settings/', 'logout.php'] as $destination) {
        check(str_contains($app['body'], $destination), "navigation reaches {$destination}");
    }

    $entry = http($admin, '/', $port);
    check($entry['status'] === 302 && str_contains((string)$entry['location'], 'app.php'),
        'GET /web/ now goes straight to the application');

    // next= is honoured after signing in
    $deep = $jar('deep');
    $page = http($deep, '/login.php?next=settings/users.php', $port);
    $csrf = csrfFrom($page['body']);
    $login = http($deep, '/login.php', $port, [
        'csrf' => $csrf, 'email' => "admin.{$tag}@example.test",
        'password' => $adminPassword, 'next' => 'settings/users.php',
    ]);
    check(str_contains((string)$login['location'], 'settings/users.php'),
        'after signing in, the user lands where they were originally going');

    // An open redirect must not be possible.
    $evil = $jar('evil');
    $page = http($evil, '/login.php?next=https://evil.test/steal', $port);
    $csrf = csrfFrom($page['body']);
    $login = http($evil, '/login.php', $port, [
        'csrf' => $csrf, 'email' => "admin.{$tag}@example.test",
        'password' => $adminPassword, 'next' => 'https://evil.test/steal',
    ]);
    check(!str_contains((string)$login['location'], 'evil.test'),
        'an off-site next= is refused, so the login page is not an open redirect');

    // Settings opens the overview of its groups, with the search; its links work wherever
    // the app is installed (here web/ is the site root, so there is no "/web/" in the path).
    $settingsHub = http($admin, '/settings/', $port);
    check($settingsHub['location'] === null && str_contains($settingsHub['body'], 'id="settingsSearch"') && str_contains($settingsHub['body'], 'href="/settings/appearance.php"')
        && str_contains($settingsHub['body'], 'For me') && str_contains($settingsHub['body'], 'System'), 'Settings opens its overview and search, at the right addresses');
    $settingsPage = http($admin, '/settings/signatures.php', $port);
    check(str_contains($settingsPage['body'], 'account.php') && !str_contains($settingsPage['body'], '/settings/settings/'), 'Account remains reachable through Settings, and its links are not doubled');

    // A signed-in user has no business on the login page.
    $response = http($admin, '/login.php', $port);
    check($response['status'] === 302 && str_contains((string)$response['location'], 'app.php'),
        'an authenticated user is sent away from the login page');

    // ------------------------------------------------------------------ F
    group('F — admin user creation');
    $usersPage = http($admin, '/settings/users.php', $port);
    checkSame(200, $usersPage['status'], 'an admin can open Settings → Users');
    check(str_contains($usersPage['body'], "plain.{$tag}@example.test"), 'and sees the organization members');
    check(!str_contains($usersPage['body'], "other.{$tag}@example.test"),
        "but not members of another organization");

    $csrf = csrfFrom($usersPage['body']);
    $created = http($admin, '/settings/users.php', $port, [
        'csrf' => $csrf, 'action' => 'create', 'name' => 'Created Person',
        'email' => "created.{$tag}@example.test", 'password' => 'a-brand-new-secret', 'role' => 'user',
    ]);
    check(str_contains($created['body'], 'User added'), 'an admin can add a user (name, email, role)');

    $st = $pdo->prepare(
        'SELECT ou.organization_id FROM `' . ecw_table('users') . '` u
           JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE u.email = ?'
    );
    $st->execute(["created.{$tag}@example.test"]);
    checkSame($orgId, (int)$st->fetchColumn(), 'and the new user lands in the admin\'s own organization');

    // No password was chosen by the admin: a password typed into the form does nothing.
    $fresh = $jar('fresh');
    $page  = http($fresh, '/login.php', $port);
    $login = http($fresh, '/login.php', $port, [
        'csrf' => csrfFrom($page['body']), 'email' => "created.{$tag}@example.test", 'password' => 'a-brand-new-secret',
    ]);
    check($login['status'] !== 302, 'nobody can sign in before the person chooses their own password');
    // This organization has no mailbox to email from: the page gives the admin the link.
    check(preg_match('#reset\.php\?token=([A-Za-z0-9_-]+)#', $created['body'], $tm) === 1, 'without a mailbox to send from, the invitation link is shown to pass on');
    $welcome = http($fresh, '/reset.php?token=' . ($tm[1] ?? ''), $port);
    check(str_contains($welcome['body'], 'Welcome') && str_contains($welcome['body'], 'Only you will know it'), 'the link opens the welcome page');
    $set = http($fresh, '/reset.php', $port, ['csrf' => csrfFrom($welcome['body']), 'token' => $tm[1] ?? '', 'password' => 'my-own-chosen-secret', 'password2' => 'my-own-chosen-secret']);
    checkSame(302, $set['status'], 'they choose their own password');
    $page  = http($fresh, '/login.php', $port);
    $login = http($fresh, '/login.php', $port, [
        'csrf' => csrfFrom($page['body']), 'email' => "created.{$tag}@example.test", 'password' => 'my-own-chosen-secret',
    ]);
    checkSame(302, $login['status'], 'and sign in with it');

    // A non-admin cannot.
    $plain = $jar('plain');
    $page  = http($plain, '/login.php', $port);
    http($plain, '/login.php', $port, [
        'csrf' => csrfFrom($page['body']), 'email' => "plain.{$tag}@example.test", 'password' => $userPassword,
    ]);
    $denied = http($plain, '/settings/users.php', $port);
    checkSame(403, $denied['status'], 'a non-admin is refused the users page');
    check(!str_contains($denied['body'], 'Add a user'), 'and never sees the create form');

    // ------------------------------------------------------------------ G
    group('G — password change');
    $account = http($plain, '/account.php', $port);
    checkSame(200, $account['status'], 'a user can open their account page');
    $csrf = csrfFrom($account['body']);

    $wrongCurrent = http($plain, '/account.php', $port, [
        'csrf' => $csrf, 'action' => 'password',
        'current_password' => 'wrong', 'new_password' => 'a-replacement-secret',
    ]);
    check(str_contains($wrongCurrent['body'], 'current password is not correct'),
        'the current password must be correct');

    $account = http($plain, '/account.php', $port);
    $changed = http($plain, '/account.php', $port, [
        'csrf' => csrfFrom($account['body']), 'action' => 'password',
        'current_password' => $userPassword, 'new_password' => 'a-replacement-secret',
    ]);
    check(str_contains($changed['body'], 'Password changed'), 'a correct current password allows the change');
    $still = http($plain, '/app.php', $port);   // 200, or the first-day checklist — never the login page
    check($still['status'] === 200 || ($still['status'] === 302 && !str_contains((string)$still['location'], 'login')),
        'and the user stays signed in on this device', 'status ' . $still['status'] . ' → ' . ($still['location'] ?? ''));

    $retry = $jar('retry');
    $page  = http($retry, '/login.php', $port);
    $old   = http($retry, '/login.php', $port, [
        'csrf' => csrfFrom($page['body']), 'email' => "plain.{$tag}@example.test", 'password' => $userPassword,
    ]);
    checkSame(401, $old['status'], 'the old password stops working');

    $page = http($retry, '/login.php', $port);
    $new  = http($retry, '/login.php', $port, [
        'csrf' => csrfFrom($page['body']), 'email' => "plain.{$tag}@example.test", 'password' => 'a-replacement-secret',
    ]);
    checkSame(302, $new['status'], 'the new password works');

    // ------------------------------------------------------------------ E
    group('E — logout');
    $sessionBeforeLogout = sessionCookie($admin);
    $logoutPage = http($admin, '/logout.php', $port);
    $csrf = csrfFrom($logoutPage['body']);

    $noToken = http($admin, '/logout.php', $port, ['csrf' => 'wrong']);
    checkSame(419, $noToken['status'], 'logout without a valid CSRF token is refused');
    checkSame(200, http($admin, '/app.php', $port)['status'], 'and the session survives that attempt');

    $logout = http($admin, '/logout.php', $port, ['csrf' => $csrf]);
    checkSame(302, $logout['status'], 'logout redirects');
    check(str_contains((string)$logout['location'], 'login.php'), 'to the login page');

    checkSame(302, http($admin, '/app.php', $port)['status'], 'protected pages are inaccessible afterwards');
    $entry = http($admin, '/', $port);
    check(str_contains((string)$entry['location'], 'login.php'), 'and /web/ shows the login page again');

    // Replaying the pre-logout session id must not work.
    $stolen = tempnam(sys_get_temp_dir(), 'ecwstolen_');
    file_put_contents($stolen, "127.0.0.1\tFALSE\t/\tFALSE\t0\tPHPSESSID\t" . $sessionBeforeLogout . "\n");
    $replay = http($stolen, '/app.php', $port);
    checkSame(302, $replay['status'], 'the destroyed session id cannot be replayed');
    @unlink($stolen);

    // ------------------------------------------------------------------ H
    group('H — developer login');
    check(!is_file($root . '/web/dev_login.php'), 'dev_login.php no longer exists on disk');

    // NOT asserting 404 here: PHP's built-in server falls back to index.php for
    // every unmatched path (even /nothing.txt), so it answers 302 where nginx
    // answers 404. The property that actually matters is that the path cannot
    // authenticate anyone, and that holds on both.
    $devJar   = $jar('devlogin');
    $devLogin = http($devJar, '/dev_login.php?token=anything&user=1', $port);
    check($devLogin['status'] !== 200, 'and the path cannot be opened', 'status ' . $devLogin['status']);
    checkSame(302, http($devJar, '/app.php', $port)['status'],
        'visiting it establishes no session, so there is no token path around the login');

    $grep = shell_exec('grep -rl "dev_login" ' . escapeshellarg($root . '/web') . ' ' . escapeshellarg($root . '/lib') . ' 2>/dev/null');
    checkSame('', trim((string)$grep), 'and nothing in web/ or lib/ still references it');

    // ------------------------------------------------- logic-level checks
    group('organization boundaries');
    // The admin of the OTHER organization, handed our organization's id
    // directly — the exact thing a tampered form field would attempt.
    $attempt = ecw_admin_create_user($otherAdminId, $orgId, [
        'name' => 'Injected', 'email' => "injected.{$tag}@example.test",
        'password' => 'a-long-enough-secret', 'role' => 'admin',
    ]);
    check(!$attempt['ok'], "an admin of another organization cannot create a user in this one");
    $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('users') . '` WHERE email = ?');
    $st->execute(["injected.{$tag}@example.test"]);
    checkSame(0, (int)$st->fetchColumn(), 'and no user row was written');

    $attempt = ecw_admin_create_user($plainId, $orgId, [
        'name' => 'By non-admin', 'email' => "nonadmin.{$tag}@example.test",
        'password' => 'a-long-enough-secret', 'role' => 'user',
    ]);
    check(!$attempt['ok'], 'a plain member of this organization cannot create users');

    $attempt = ecw_admin_set_user_disabled($otherAdminId, $otherOrgId, $plainId, true);
    check(!$attempt['ok'], "an admin cannot disable a user outside their own organization");

    $attempt = ecw_admin_set_user_disabled($adminId, $orgId, $adminId, true);
    check(!$attempt['ok'], 'an admin cannot disable themselves and lock the organization out');

    $attempt = ecw_admin_set_user_role($adminId, $orgId, $adminId, 'user');
    check(!$attempt['ok'], 'nor demote themselves');

    $attempt = ecw_admin_create_user($adminId, $orgId, [
        'name' => 'Dup', 'email' => "plain.{$tag}@example.test",
        'password' => 'a-long-enough-secret', 'role' => 'user',
    ]);
    check(!$attempt['ok'], 'an address already in the organization is refused');

    $attempt = ecw_admin_create_user($adminId, $orgId, [
        'name' => 'Bad role', 'email' => "badrole.{$tag}@example.test",
        'password' => 'a-long-enough-secret', 'role' => 'superuser',
    ]);
    check(!$attempt['ok'], 'an unknown role is refused');

    group('disabled accounts');
    ecw_admin_set_user_disabled($adminId, $orgId, $plainId, true);
    $result = ecw_attempt_login("plain.{$tag}@example.test", 'a-replacement-secret', $testIp);
    check(!$result['ok'], 'a disabled user cannot sign in');
    checkSame('Invalid email or password.', $result['error'],
        'and is told the same thing as a wrong password, so disabling is not detectable');
    ecw_admin_set_user_disabled($adminId, $orgId, $plainId, false);
    check(ecw_attempt_login("plain.{$tag}@example.test", 'a-replacement-secret', $testIp)['ok'],
        're-enabling restores access');

    group('password rules');
    check(ecw_password_problem('short') !== null, 'a short password is refused');
    check(ecw_password_problem(str_repeat('a', ECW_PASSWORD_MIN_LENGTH)) === null, 'a long enough one is accepted');
    $result = ecw_change_password($adminId, $adminPassword, $adminPassword);
    check(!$result['ok'], 'the new password must differ from the current one');

    group('redirect safety');
    foreach (['https://evil.test/x', '//evil.test/x', "/web/app.php\nSet-Cookie: x=1", '../../etc/passwd'] as $hostile) {
        checkSame(null, ecw_safe_next($hostile), 'refused: ' . str_replace("\n", '\\n', $hostile));
    }
    checkSame('settings/users.php', ecw_safe_next('settings/users.php'), 'a relative in-app path is allowed');

} finally {
    if (isset($server) && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    @unlink($serverLog);
    @unlink($router);
    foreach ($jars as $file) {
        @unlink($file);
    }

    $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')->execute(['Auth Test Org ' . $tag]);
    $pdo->prepare('DELETE FROM `' . ecw_table('organizations') . '` WHERE name LIKE ?')->execute(['Auth Other Org ' . $tag]);
    $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(["admin.{$tag}@example.test"]);
    $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(["plain.{$tag}@example.test"]);
    $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(["other.{$tag}@example.test"]);
    $pdo->prepare('DELETE FROM `' . ecw_table('users') . '` WHERE email LIKE ?')->execute(["created.{$tag}@example.test"]);
    $pdo->prepare('DELETE FROM `' . ecw_table('rate_limits') . '` WHERE (scope = ? AND subject = ?) OR (scope = ? AND subject LIKE ?)')->execute(['login_ip', 'ip:' . $testIp, 'login_email', 'email:%.' . $tag . '@example.test']);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
