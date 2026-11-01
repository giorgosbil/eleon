<?php declare(strict_types=1);
/**
 * Sign in with Microsoft: GET sso.php (from the sign-in page) or
 * POST sso.php mode=link / unlink (from Account). Microsoft returns to
 * oauth/callback.php, which hands sign-ins to lib/sso.php.
 */
require_once dirname(__DIR__) . '/lib/sso.php';
require_once dirname(__DIR__) . '/lib/rate_limit.php';

ecw_session_start();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$mode = $method === 'POST' ? (string)($_POST['mode'] ?? '') : 'login';

try {
    ecw_rate_limit_hit('sso_start', 'ip:' . ecw_client_ip(), 30, 300);
} catch (EcwRateLimitExceeded $e) {
    http_response_code(429);
    header('Retry-After: ' . $e->retryAfterSeconds);
    exit('Too many attempts. Try again in a few minutes.');
}

if ($method === 'POST') {
    if (ecw_context() === null || !ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { ecw_redirect(ecw_web_base() . 'account.php'); }
    if ($mode === 'unlink') {
        ecw_sso_unlink((int)ecw_context()['user']['id']);
        ecw_redirect(ecw_web_base() . 'account.php?sso=unlinked#microsoft');
    }
    $mode = 'link';
} elseif (ecw_context() !== null) {
    ecw_redirect(ecw_web_base() . 'app.php');
} elseif (!ecw_sso_available()) {
    ecw_redirect(ecw_web_base() . 'login.php');
}

try {
    header('Cache-Control: no-store');
    ecw_redirect(ecw_sso_begin($mode, $_GET['next'] ?? null));
} catch (Throwable $e) {
    ecw_log('microsoft sign-in could not start: ' . $e->getMessage());
    $_SESSION['ecw_sso_error'] = 'Microsoft sign-in is not available right now.';
    ecw_redirect(ecw_web_base() . ($mode === 'link' ? 'account.php' : 'login.php'));
}
