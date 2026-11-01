<?php declare(strict_types=1);
/**
 * WhatsApp webhook (Meta → this app), at module.php?m=whatsapp&p=webhook.
 *
 * GET:  Meta's one-time verification (hub.challenge echoed when the verify
 *       token is one a workspace was given in Settings → WhatsApp).
 * POST: messages and delivery statuses. No session: each change is accepted
 *       only for the workspace that owns the number and only when the body is
 *       signed with that workspace's app secret (lib/whatsapp.php).
 */
require_once dirname(__DIR__) . '/lib/whatsapp.php';
require_once dirname(__DIR__, 3) . '/lib/rate_limit.php';
require_once dirname(__DIR__, 3) . '/lib/session.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // PHP turns "hub.mode" into "hub_mode".
    $challenge = ecw_wa_verify_challenge((string)($_GET['hub_mode'] ?? ''), (string)($_GET['hub_verify_token'] ?? ''), (string)($_GET['hub_challenge'] ?? ''));
    if ($challenge === null) {
        try { ecw_rate_limit_hit('wa_verify', 'ip:' . ecw_client_ip(), 30, 300); } catch (Throwable $e) { /* counted */ }
        http_response_code(403);
        exit;
    }
    header('Content-Type: text/plain');
    echo $challenge;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 3 * 1024 * 1024);
try {
    $r = ecw_wa_webhook_handle($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit;
} catch (Throwable $e) {
    // Meta retries on an error, and duplicates are ignored, so nothing is lost.
    ecw_log('whatsapp webhook failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
if ($r['stored'] === 0 && $r['rejected'] > 0) {
    ecw_log('whatsapp webhook: ' . $r['rejected'] . ' change(s) rejected (unknown number or bad signature)');
    http_response_code(401);
    exit;
}
http_response_code(200);
