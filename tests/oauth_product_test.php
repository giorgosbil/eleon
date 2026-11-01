<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/oauth.php';
require_once __DIR__ . '/support/FakeGraph.php';
require_once __DIR__ . '/support/GraphIdentity.php';
session_save_path(sys_get_temp_dir());
ecw_session_start();
ob_start();
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['ecw_test_pdo'] = $pdo;
$GLOBALS['ecw_test_encryption_key'] = random_bytes(32);
foreach ([
    'organizations' => 'id INTEGER PRIMARY KEY, name TEXT, features_json TEXT, default_view_profile TEXT, timezone TEXT, sla_json TEXT, ai_json TEXT, copilot_json TEXT',
    'users' => 'id INTEGER PRIMARY KEY, name TEXT, email TEXT, disabled_at TEXT, preferences_json TEXT, avatar_hash TEXT, avatar_updated_at TEXT, view_profile TEXT, view_features_json TEXT, language TEXT, sso_subject TEXT, away_from TEXT, away_until TEXT, cover_user_id INT, password_hash TEXT, created_at TEXT',
    'organization_users' => 'id INTEGER PRIMARY KEY, user_id INT, organization_id INT, role TEXT',
    'mailboxes' => 'id INTEGER PRIMARY KEY, organization_id INT, connected_by_user_id INT, provider TEXT, provider_account_id TEXT, provider_tenant_id TEXT, email_address TEXT, display_name TEXT, sync_status TEXT, status_message TEXT, status_changed_at TEXT, access_token_encrypted TEXT, refresh_token_encrypted TEXT, token_expires_at TEXT, granted_scopes TEXT, last_sync_at TEXT, initial_sync_completed_at TEXT, snooze_in_mailbox INT DEFAULT 0, is_shared INT DEFAULT 0, shared_address TEXT',
    'mailbox_members' => 'mailbox_id INT, user_id INT, created_at TEXT',
    'audit_log' => 'id INTEGER PRIMARY KEY, organization_id INT, user_id INT, action TEXT, target_type TEXT, target_id INT, detail TEXT, via TEXT, ip TEXT, user_agent TEXT, created_at TEXT',
    'sync_queue' => 'id INTEGER PRIMARY KEY, mailbox_id INT, mail_folder_id INT, job_type TEXT, reason TEXT, status TEXT, available_at TEXT, locked_by TEXT, locked_at TEXT, attempts INT DEFAULT 0, last_error TEXT',
    'graph_subscriptions' => 'id INTEGER PRIMARY KEY, mailbox_id INT, subscription_id TEXT, status TEXT',
    'mail_folders' => 'id INTEGER PRIMARY KEY, mailbox_id INT, provider_folder_id TEXT, parent_provider_folder_id TEXT, name TEXT, folder_type TEXT, is_system INT DEFAULT 0, is_selected_for_sync INT DEFAULT 1, is_case_intake INT DEFAULT 0, delta_link_encrypted TEXT, last_sync_at TEXT',
    'email_messages' => 'id INTEGER PRIMARY KEY, mailbox_id INT, subject TEXT, mail_folder_id INT, provider_removed_at TEXT',
    'cases' => 'id INTEGER PRIMARY KEY, organization_id INT, subject TEXT',
    'rate_limits' => 'id INTEGER PRIMARY KEY, scope TEXT, subject TEXT, window_start TEXT, hits INT',
] as $name => $columns) { $pdo->exec('CREATE TABLE `' . ecw_table($name) . '` (' . $columns . ')'); }
// MySQL's FIELD(), used to order lists (settings/mailbox.php's folders).
@$pdo->sqliteCreateFunction('FIELD', static function ($value, ...$list) { $i = array_search($value, $list, true); return $i === false ? 0 : $i + 1; });
$pdo->exec("INSERT INTO ecw_organizations (id, name) VALUES (1,'A'),(2,'B'); INSERT INTO ecw_users (id, name, email) VALUES (1,'User','user@example.test'); INSERT INTO ecw_organization_users VALUES (1,1,1,'admin')");
$fail = 0; $pass = 0;
function check(bool $ok, string $label): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; }
function rejected(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }
$config = ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'redirect_uri' => 'https://example.test/other_things/web/oauth/callback.php'];
ecw_set_graph_config_override($config);
check(GraphProductConfig::isConfigured(), 'complete product config');
foreach (array_keys($config) as $key) { $bad = $config; unset($bad[$key]); check(!GraphProductConfig::isConfigured($bad), 'missing ' . $key); }
check(!GraphProductConfig::isConfigured(array_replace($config, ['redirect_uri' => 'http://example.test/callback'])), 'HTTPS required');
check(rejected(fn() => ecw_oauth_begin()), 'unauthenticated start refused');
$_SESSION['ecw_user_id'] = 1; $_SESSION['ecw_organization_id'] = 1;
$url = ecw_oauth_begin(); parse_str(parse_url($url, PHP_URL_QUERY), $query);
$handshake = $_SESSION['ecw_oauth_handshake'];
check(str_contains($url, '/organizations/oauth2/v2.0/authorize?'), 'organizational multi-tenant endpoint');
check(strlen($query['state']) === 64, '256-bit random state');
check($query['code_challenge_method'] === 'S256' && $query['code_challenge'] === rtrim(strtr(base64_encode(hash('sha256', $handshake['verifier'], true)), '+/', '-_'), '='), 'PKCE challenge matches session verifier');
check($query['nonce'] === $handshake['nonce'] && $handshake['organization_id'] === 1 && $handshake['user_id'] === 1, 'nonce and application identity bound to session');
$minimum = ['openid','profile','offline_access','https://graph.microsoft.com/User.Read','https://graph.microsoft.com/Mail.ReadWrite','https://graph.microsoft.com/Mail.Send'];
// One permission screen: the calendar and automatic replies come with the mailbox when the
// person has those features; Teams chats never (personal accounts have none: the sign-in would fail).
$asked = explode(' ', $query['scope']);
check(array_slice($asked, 0, 6) === $minimum && in_array('https://graph.microsoft.com/Calendars.ReadWrite', $asked, true)
    && in_array('https://graph.microsoft.com/MailboxSettings.ReadWrite', $asked, true) && !in_array('https://graph.microsoft.com/Chat.ReadWrite', $asked, true),
    'one screen: mail with the calendar and automatic replies, not Teams chats');
ecw_user_view_save(1, 'simple');   // a person without calendar and automatic replies
ecw_user_view(1, 1, true);          // (cached for the request)
$simple = ecw_oauth_begin(); parse_str(parse_url($simple, PHP_URL_QUERY), $sq);
check(explode(' ', $sq['scope']) === $minimum, 'without those features: exact minimum scope set');
ecw_user_view_save(1, null);
ecw_user_view(1, 1, true);
$url = ecw_oauth_begin(); parse_str(parse_url($url, PHP_URL_QUERY), $query);
check(!str_contains($url, 'test-secret'), 'no client secret in browser URL');
check(ecw_oauth_validate_state($query['state']), 'valid state accepted');
check(!ecw_oauth_validate_state($query['state']), 'state cannot be replayed');
ecw_oauth_begin(); check(!ecw_oauth_validate_state('wrong'), 'incorrect state refused');
ecw_oauth_begin(); $_SESSION['ecw_oauth_state_at'] = time() - 901; check(!ecw_oauth_validate_state($_SESSION['ecw_oauth_state']), 'expired state refused');
check(ecw_oauth_error_state('access_denied', 'Canceled') === 'denied', 'denial handled separately');
check(ecw_oauth_error_state('access_denied', 'AADSTS90094: administrator approval needed') === 'approval_required', 'admin approval classified');
check(ecw_oauth_error_state('access_denied', 'AADSTS65004') === 'consent_incomplete', 'approval-request return does not claim approval or denial');
$identity = ecw_test_identity(['nonce' => $handshake['nonce']]);
$fake = new FakeGraph();
$fake->on('POST', '#/oauth2/v2.0/token#', ['body' => ['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'id_token' => $identity['jwt'], 'expires_in' => 3600]]);
$fake->on('GET', '#/discovery/v2.0/keys#', ['body' => ['keys' => $identity['keys']]]);
$fake->on('GET', '#/me\?#', ['body' => ['id' => 'oid-fresh', 'mail' => 'mail@example.test', 'displayName' => 'Mailbox']]);
$fake->install();
check(ecw_graph_identity($identity['jwt'], 'test-client', $handshake['nonce'])['tid'] === '11111111-2222-3333-4444-555555555555', 'signed Microsoft tenant resolved');
check(rejected(fn() => ecw_graph_identity($identity['jwt'], 'wrong-client', $handshake['nonce'])), 'wrong audience refused');
check(rejected(fn() => ecw_graph_identity($identity['jwt'], 'test-client', 'wrong-nonce')), 'wrong nonce refused');
$parts = explode('.', $identity['jwt']); $parts[2] = str_repeat('A', strlen($parts[2]));
check(rejected(fn() => ecw_graph_identity(implode('.', $parts), 'test-client', $handshake['nonce'])), 'forged signature refused');
$id = ecw_oauth_complete(1, 1, 'code', $handshake); $mb = ecw_mailbox($id);
check($mb['organization_id'] === 1 && $mb['connected_by_user_id'] === 1, 'mailbox assigned to application organization and user');
check($mb['provider_account_id'] === 'oid-fresh' && $mb['provider_tenant_id'] === '11111111-2222-3333-4444-555555555555', 'account and tenant stored');
check(ecw_decrypt($mb['access_token_encrypted']) === 'access-secret' && ecw_decrypt($mb['refresh_token_encrypted']) === 'refresh-secret' && !str_contains($mb['access_token_encrypted'], 'access-secret'), 'both credentials encrypted');
check($pdo->query("SELECT COUNT(*) FROM ecw_sync_queue WHERE job_type='folders' AND status='queued'")->fetchColumn() == 1, 'folder discovery queued');
check($fake->callsMatching('#/oauth2/v2.0/token#')[0]['json']['code_verifier'] === $handshake['verifier'], 'code exchange supplies PKCE verifier');
// Actual callback processor: state validation occurs before denial or approval handling.
$context = ecw_context();
ecw_oauth_begin(); $state = $_SESSION['ecw_oauth_state'];
check(ecw_oauth_callback_result($context, ['state' => $state, 'error' => 'access_denied']) === 'denied', 'callback handles valid-state denial');
check(rejected(fn() => ecw_oauth_callback_result($context, ['state' => $state, 'error' => 'access_denied'])), 'denial callback consumes state');
ecw_oauth_begin(); $state = $_SESSION['ecw_oauth_state'];
check(ecw_oauth_callback_result($context, ['state' => $state, 'error' => 'access_denied', 'error_description' => 'AADSTS90094']) === 'approval_required', 'callback records administrator approval state');
ecw_oauth_begin(); $state = $_SESSION['ecw_oauth_state']; $_SESSION['ecw_oauth_handshake']['approval'] = true;
check(ecw_oauth_callback_result($context, ['state' => $state, 'admin_consent' => 'True']) === 'approval_return' && $pdo->query('SELECT COUNT(*) FROM ecw_mailboxes')->fetchColumn() == 1, 'admin consent return never connects mailbox');
ecw_oauth_begin(); $state = $_SESSION['ecw_oauth_state'];
check(rejected(fn() => ecw_oauth_callback_result(array_replace($context, ['organization_id' => 2]), ['state' => $state, 'code' => 'x'])), 'callback cannot switch application organizations');
ecw_oauth_begin(); $state = $_SESSION['ecw_oauth_state'];
check(rejected(fn() => ecw_oauth_callback_result(array_replace($context, ['user' => ['id' => 2]]), ['state' => $state, 'code' => 'x'])), 'callback cannot switch application users');
ecw_oauth_begin(); $state = $_SESSION['ecw_oauth_state'];
$_SESSION['ecw_oauth_handshake']['nonce'] = $handshake['nonce'];
check(ecw_oauth_callback_result($context, ['state' => $state, 'code' => 'ok']) === 'connected', 'valid-state callback connects and queues mailbox');
check(empty($_SESSION['ecw_outlook_approval'][1]), 'successful authorization clears approval state');
check(ecw_oauth_complete(1, 1, 'code2', $handshake) === $id, 'reconnect reuses same mailbox');
$otherId = ecw_oauth_complete(2, 1, 'code3', $handshake);
check($id !== $otherId, 'same Microsoft account remains isolated across app organizations');
check(ecw_require_mailbox_access($id)['id'] === $id, 'own mailbox access succeeds');
// Exercise an exit-based route/guard in a fork, including HTTP status.
function response(callable $fn): array {
    $path = tempnam(sys_get_temp_dir(), 'ecw-response-');
    $pid = pcntl_fork();
    if ($pid === 0) {
        while (ob_get_level()) { ob_end_clean(); } ob_start();
        register_shutdown_function(static function () use ($path): void {
            file_put_contents($path, json_encode(['status' => http_response_code(), 'body' => ob_get_clean()]));
        });
        $fn(); exit;
    }
    pcntl_waitpid($pid, $status);
    $out = file_get_contents($path); unlink($path);
    return json_decode($out, true);
}
check(response(fn() => ecw_require_mailbox_access($otherId))['status'] === 404, 'Org A cannot access Org B mailbox');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/other_things/web/settings/mailbox.php';
$_POST = ['csrf' => ecw_csrf_token(), 'mailbox_id' => $otherId, 'action' => 'disconnect', 'confirm' => 'yes'];
check(response(fn() => require dirname(__DIR__) . '/web/settings/mailbox.php')['status'] === 404, 'Org A cannot disconnect Org B mailbox');
$_POST['csrf'] = 'invalid'; $_POST['mailbox_id'] = $id;
check(response(fn() => require dirname(__DIR__) . '/web/settings/mailbox.php')['status'] === 419, 'disconnect requires CSRF');
$pdo->exec("INSERT INTO ecw_email_messages (id, mailbox_id, subject) VALUES (1,$id,'History'); INSERT INTO ecw_cases VALUES (1,1,'Case history')");
$pdo->exec("INSERT INTO ecw_graph_subscriptions VALUES (1,$id,'sub-1','active')");
$fake->on('DELETE', '#/subscriptions/sub-1#', ['status' => 503]);
ecw_mailbox_disconnect($id); $mb = ecw_mailbox($id);
check($mb['sync_status'] === 'disconnected' && $mb['access_token_encrypted'] === null && $mb['refresh_token_encrypted'] === null, 'disconnect removes credentials and marks disconnected');
check(ecw_queue_enqueue($id, null, 'folders') === null, 'disconnected mailbox cannot enqueue sync');
check($pdo->query('SELECT status FROM ecw_graph_subscriptions WHERE id=1')->fetchColumn() === 'deleted', 'subscription state removed even when remote cleanup fails');
check($pdo->query("SELECT COUNT(*) FROM ecw_sync_queue WHERE mailbox_id=$id AND status='queued'")->fetchColumn() == 0, 'pending jobs stopped');
check(rejected(fn() => ecw_mailbox_access_token($id)), 'disconnected mailbox cannot fetch token');
ecw_mailbox_set_status($id, 'connected');
check(ecw_mailbox($id)['sync_status'] === 'disconnected', 'finishing worker cannot revive disconnected mailbox');
check($pdo->query('SELECT COUNT(*) FROM ecw_email_messages')->fetchColumn() == 1 && $pdo->query('SELECT COUNT(*) FROM ecw_cases')->fetchColumn() == 1, 'message and Case history retained');
check(ecw_oauth_complete(1, 1, 'code4', $handshake) === $id && ecw_mailbox($id)['sync_status'] === 'syncing', 'explicit reconnect resumes same mailbox');
$pdo->exec("UPDATE ecw_mailboxes SET token_expires_at='2000-01-01 00:00:00' WHERE id=$id");
$fake = new FakeGraph(); $fake->on('POST', '#/oauth2/v2.0/token#', ['status' => 400, 'body' => ['error' => 'invalid_grant', 'error_description' => 'never log refresh-secret']]); $fake->install();
check(rejected(fn() => ecw_mailbox_access_token($id)) && ecw_mailbox($id)['sync_status'] === 'needs_reauth', 'revoked refresh grant produces reconnect state');
check(ecw_queue_enqueue($id, null, 'folders') === null, 'reauth mailbox cannot enqueue work');
ecw_mailbox_set_status($id, 'temp_error');
check(ecw_mailbox($id)['sync_status'] === 'needs_reauth', 'late worker failure preserves reauthentication state');
$_SERVER['REQUEST_METHOD'] = 'GET';
ecw_set_graph_config_override([]);
$page = response(fn() => require dirname(__DIR__) . '/web/settings/mailbox.php')['body'];
check(str_contains($page, 'Contact the system administrator.') && !str_contains($page, 'config.local.php') && !str_contains($page, 'client_secret'), 'unconfigured UI contains no infrastructure onboarding');
ecw_set_graph_config_override($config);
$page = response(fn() => require dirname(__DIR__) . '/web/settings/mailbox.php')['body'];
check(str_contains($page, 'Reconnect Microsoft Outlook') && !str_contains($page, 'refresh-secret') && !str_contains($page, 'test-secret'), 'reauthentication UI has reconnect action and no credentials');
// Personal Outlook.com / Hotmail accounts: only when allowed, and refreshed through "consumers".
$msa = '9188040d-6c67-4c5b-b112-36a304b66dad';
$personalId = ecw_test_identity(['tid' => $msa, 'iss' => 'https://login.microsoftonline.com/' . $msa . '/v2.0', 'oid' => '00000000-0000-0000-4ad3-c0e1b2a39f07']);
$fake = new FakeGraph(); $fake->on('GET', '#/discovery/v2.0/keys#', ['body' => ['keys' => $personalId['keys']]]); $fake->install();
check(rejected(fn() => ecw_graph_identity($personalId['jwt'], 'test-client', null)), 'personal account refused unless allowed');
check(ecw_graph_identity($personalId['jwt'], 'test-client', null, true)['tid'] === $msa, 'personal account accepted when allowed');
check(str_contains($fake->callsMatching('#/discovery/v2.0/keys#')[0]['url'] ?? '', '/common/discovery'), 'personal accounts check keys at "common"');
check(ecw_graph_same_account('4ad3c0e1b2a39f07', '00000000-0000-0000-4ad3-c0e1b2a39f07') && ecw_graph_same_account('oid-fresh', 'oid-fresh'), 'personal /me id matches the token oid');
check(!ecw_graph_same_account('4ad3c0e1b2a39f08', '00000000-0000-0000-4ad3-c0e1b2a39f07') && !ecw_graph_same_account('0', '00000000-0000-0000-0000-000000000000'), 'a different account never matches');
ecw_set_graph_config_override($config + ['personal_accounts' => true]);
check(str_contains(ecw_oauth_begin(), '/common/oauth2/v2.0/authorize?'), 'with personal accounts on, sign-in offers work and personal accounts');
$p = new MicrosoftGraphProvider($config + ['account_tenant' => $msa]);
check(str_contains($p->authorizationUrl('s', 'https://x.test/cb'), '/consumers/oauth2/'), 'a personal mailbox signs in and refreshes through "consumers"');
$p = new MicrosoftGraphProvider($config + ['account_tenant' => '11111111-2222-3333-4444-555555555555', 'personal_accounts' => true]);
check(str_contains($p->authorizationUrl('s', 'https://x.test/cb'), '/11111111-2222-3333-4444-555555555555/oauth2/'), 'a work mailbox keeps its own tenant');
ecw_set_graph_config_override($config);
check(str_contains(ecw_oauth_begin(), '/organizations/oauth2/v2.0/authorize?'), 'without it, work and school accounts only (unchanged)');

echo "$pass passed; $fail failed\n";
ob_end_flush(); exit($fail > 0 ? 1 : 0);
