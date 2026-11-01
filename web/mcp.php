<?php declare(strict_types=1);
/**
 * MCP endpoint (lib/mcp.php) for Microsoft 365 Copilot and other AI apps.
 *
 *   POST /other_things/web/mcp.php   JSON-RPC 2.0 (one message or a batch)
 *   Authorization: Bearer <Microsoft Entra token | ecw_… personal token>
 *
 * No cookies, no session: every request carries its own token.
 */
require_once dirname(__DIR__) . '/lib/mcp.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$send = static function (int $status, $body = null, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $h) header($h);
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' || $method === 'DELETE') {
    // No server-to-client stream and no sessions to end: plain request / response only.
    $send(405, null, ['Allow: POST']);
}
if ($method !== 'POST') $send(405, null, ['Allow: POST']);

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $auth = (string)$v;
}
try {
    $who = ecw_mcp_authenticate($auth);
} catch (EcwMcpAuthError $e) {
    $send(401, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => $e->getMessage()]], ['WWW-Authenticate: Bearer realm="email-case-workspace"']);
} catch (Throwable $e) {
    error_log('[ecw] mcp auth: ' . $e->getMessage());
    $send(500, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Sign-in could not be checked.']]);
}

$raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
$in = json_decode($raw, true);
if (!is_array($in)) $send(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);

$batch = array_is_list($in) && $in !== [];
$out = [];
foreach ($batch ? array_slice($in, 0, 20) : [$in] as $msg) {
    $r = is_array($msg) ? ecw_mcp_handle($msg, $who) : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
    if ($r !== null) $out[] = $r;
}
if (!$out) $send(202);   // only notifications
$send(200, $batch ? $out : $out[0]);
