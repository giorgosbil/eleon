<?php declare(strict_types=1);
/**
 * GET /other_things/web/oauth/start.php — begin connecting a mailbox.
 *
 * Requires a signed-in user whose organization the mailbox will belong to.
 * Nothing about the provider leaks into this file: it mints state and redirects.
 *
 * Reached from Settings -> Mailbox. Every response here is for a browser doing a
 * top-level navigation, so errors are plain text rather than JSON.
 */

require_once dirname(__DIR__, 2) . '/lib/oauth.php';
require_once dirname(__DIR__, 2) . '/lib/rate_limit.php';

// A browser gets redirected to the login page; only an XHR gets JSON. This is
// a top-level navigation, so it must never answer with a bare 401 body.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$context = ecw_require_login();
$user    = $context['user'];
$orgId   = $context['organization_id'];

try {
    ecw_rate_limit_hit('oauth_start', 'user:' . $user['id'], 10, 300);
} catch (EcwRateLimitExceeded $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Too many mailbox connection attempts. Try again in a few minutes.\n";
    exit;
}

try {
    // ?provider=google connects Gmail; ?teams=1 also asks for Teams chat access (Microsoft only).
    $provider = ($_GET['provider'] ?? '') === 'google' ? 'google' : 'microsoft';
    $shared = isset($_GET['shared']) && is_string($_GET['shared']) && trim($_GET['shared']) !== '' ? (string)$_GET['shared'] : null;
    try {
        $url = ecw_oauth_begin(($_GET['teams'] ?? '') === '1', $provider, ($_GET['calendar'] ?? '') === '1', ($_GET['settings'] ?? '') === '1', $shared);
    } catch (InvalidArgumentException $e) {
        $_SESSION['ecw_outlook_shared_error'] = $e->getMessage();
        ecw_redirect(ecw_web_base() . 'settings/mailbox.php#shared');
    }
    if ($provider === 'microsoft' && ($_GET['action'] ?? '') === 'approval') {
        $_SESSION['ecw_oauth_handshake']['approval'] = true;
        $config = ecw_graph_config();
        // From an account's settings: the approval is for that account's company (its tenant).
        $authority = 'organizations';
        if (($mbId = (int)($_GET['mailbox_id'] ?? 0)) > 0) {
            $mbRow = ecw_mailbox($mbId);
            $tenant = (string)($mbRow['provider_tenant_id'] ?? '');
            if ($mbRow !== null && (int)$mbRow['organization_id'] === (int)$orgId && preg_match('/^[0-9a-f-]{36}$/i', $tenant)
                && strcasecmp($tenant, MicrosoftGraphProvider::PERSONAL_TENANT) !== 0) $authority = $tenant;
        }
        $url = GraphHttp::LOGIN_BASE . '/' . $authority . '/v2.0/adminconsent?' . http_build_query([
            'client_id' => $config['client_id'], 'redirect_uri' => ecw_oauth_redirect_uri(),
            // Everything the app can ask for, so that after this one approval nobody in the
            // company sees a permission screen: mail, calendar, automatic replies, Teams chats,
            // and the mailboxes shared with them (docs/MICROSOFT_ADMIN_APPROVAL.md).
            'scope' => implode(' ', array_values(array_unique(array_filter(array_merge(
                (new MicrosoftGraphProvider($config + ['shared_address' => 'x']))->scopes(),
                MicrosoftGraphProvider::TEAMS_SCOPES,
                ['https://graph.microsoft.com/Calendars.ReadWrite', 'https://graph.microsoft.com/MailboxSettings.ReadWrite']),
                static fn($scope) => str_starts_with($scope, 'https://graph.microsoft.com/'))))),
            'state' => $_SESSION['ecw_oauth_state'],
        ]);
    }
} catch (Throwable $e) {
    ecw_log('oauth start failed');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This mail provider is not configured on this server. Contact the system administrator.\n";
    exit;
}

header('Location: ' . $url, true, 302);
exit;
