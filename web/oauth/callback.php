<?php declare(strict_types=1);
/**
 * GET /other_things/web/oauth/callback.php?code=...&state=...
 *
 * The redirect URI registered with Azure. Validates state, exchanges the code,
 * attaches the mailbox, and sends the user on. The import runs in the
 * background — the user is not made to watch a sync.
 *
 * Errors are shown as text, not as a redirect carrying the provider's message:
 * that message can contain the code or a token fragment, and a redirect puts it
 * in browser history and referrer headers.
 */

require_once dirname(__DIR__, 2) . '/lib/oauth.php';
require_once dirname(__DIR__, 2) . '/lib/rate_limit.php';
require_once dirname(__DIR__, 2) . '/lib/sso.php';

// "Sign in with Microsoft" returns here too (same registered address): no app session yet.
if (ecw_sso_is_callback($_GET)) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    try {
        ecw_rate_limit_hit('sso_callback', 'ip:' . ecw_client_ip(), 30, 300);
        $r = ecw_sso_callback($_GET);
    } catch (Throwable $e) {
        ecw_log('microsoft sign-in callback failed: ' . $e->getMessage());
        $r = ['ok' => false, 'error' => 'Microsoft sign-in failed. Try again, or use your password.'];
    }
    if ($r['ok']) ecw_redirect($r['redirect']);
    $_SESSION['ecw_sso_error'] = $r['error'];
    ecw_redirect(ecw_web_base() . (ecw_context() !== null ? 'account.php#microsoft' : 'login.php'));
}

$context = ecw_require_login();
$user    = $context['user'];
$orgId   = $context['organization_id'];

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$error = (string)($_GET['error'] ?? '');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

try {
    ecw_rate_limit_hit('oauth_callback', 'user:' . $user['id'], 20, 300);
} catch (EcwRateLimitExceeded $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    http_response_code(429);
    echo "Mailbox not connected: too many attempts, try again shortly.\n";
    exit;
}

// Read before the callback consumes the handshake: an "Enable Teams" round trip returns to the Teams page.
$teamsFlow = !empty($_SESSION['ecw_oauth_handshake']['teams']);
$calendarFlow = !empty($_SESSION['ecw_oauth_handshake']['calendar']);
$settingsFlow = !empty($_SESSION['ecw_oauth_handshake']['settings']);
try {
    $_SESSION['ecw_outlook_notice'] = ecw_oauth_callback_result($context, $_GET);
    if ($settingsFlow) {
        ecw_redirect(ecw_web_base() . 'settings/mailbox.php?auto_reply=' . rawurlencode($_SESSION['ecw_outlook_notice']) . '#auto-reply');
    }
    if ($calendarFlow) {
        unset($_SESSION['ecw_calendar_tokens']);
        ecw_redirect(ecw_web_base() . 'calendar.php?connection=' . rawurlencode($_SESSION['ecw_outlook_notice']));
    }
    if ($teamsFlow && $_SESSION['ecw_outlook_notice'] === 'connected') {
        ecw_redirect(ecw_web_base() . 'teams.php?enabled=1');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo "Mailbox not connected: this link is no longer valid. Start again.\n";
    exit;
} catch (Throwable $e) {
    ecw_log('oauth completion failed: ' . $e->getMessage());
    $_SESSION['ecw_outlook_notice'] = $e->getMessage() === 'shared_no_access' ? 'shared_no_access' : 'error';
}
ecw_redirect(ecw_web_base() . 'settings/mailbox.php');
