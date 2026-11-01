<?php declare(strict_types=1);
/**
 * POST /other_things/web/webhook.php — Microsoft Graph change notifications.
 *
 * TWO JOBS, BOTH SMALL
 * --------------------
 * 1. Answer Graph's subscription validation handshake by echoing
 *    validationToken as text/plain. Graph gives this about ten seconds and
 *    silently refuses to create the subscription if the answer is late or
 *    wrapped in anything.
 * 2. For a real notification: verify the echoed clientState, enqueue a delta
 *    job, return 202.
 *
 * NOTHING SYNCS HERE. A handler that paged Graph inline would hold this
 * request open for the length of a sync, and Graph drops subscriptions whose
 * endpoint is slow. The queue exists precisely so this file can be boring.
 *
 * This endpoint is unauthenticated by necessity — Graph cannot carry a session
 * — so the clientState comparison IS the authentication. It is checked against
 * a stored hash, per subscription, before anything is queued.
 */

require_once dirname(__DIR__) . '/lib/sync/subscriptions.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';

// 1. Validation handshake. Must be the raw token, nothing else.
$validationToken = $_GET['validationToken'] ?? $_POST['validationToken'] ?? null;
if ($validationToken !== null) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo (string)$validationToken;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    ecw_log('webhook received a non-JSON body');
    http_response_code(400);
    exit;
}

try {
    $result = ecw_subscription_handle_notifications($payload);
    ecw_log('webhook processed', $result);
} catch (Throwable $e) {
    // Graph retries on a 5xx, which is what we want if the queue write failed.
    ecw_log('webhook failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

// 202: accepted for processing. The delta job decides what actually changed.
http_response_code(202);
