<?php declare(strict_types=1);
/**
 * The chatlink module's API actions. Runs inside web/api/index.php, which has
 * already checked sign-in, CSRF, POST, read-only, the feature and (for
 * case_scoped actions) that the case is one the person can see (module.php),
 * with $action, $user, $orgId and $mailbox set.
 */
require_once __DIR__ . '/lib/chatlink.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$uid = (int)$user['id'];
$source = (string)ecw_param('source', '');
// Reading a chat needs that chat app's feature too (Teams, WhatsApp, Telegram).
$needsChat = in_array($action, ['chatlink_marks', 'chatlink_link', 'chatlink_suggest'], true);

try {
    if ($needsChat && !ecw_feature_allowed(ecw_chatlink_source_feature($source))) ecw_fail('That chat is not available to you.', 403);
    switch ($action) {
        case 'chatlink_column_update':
            ecw_chatlink_column_update($orgId, $uid, (int)ecw_param('case_id', 0), (string)ecw_param('column', ''), ecw_input());
            ecw_json(['ok' => true]);
        case 'chatlink_column_reply':
            $col = ecw_chatlink_column((int)ecw_param('case_id', 0), (string)ecw_param('column', ''));
            if (!$col) ecw_fail('That chat is not in this case.', 404);
            if (!ecw_feature_allowed(ecw_chatlink_source_feature((string)$col['source']))) ecw_fail('That chat is not available to you.', 403);
            ecw_json(['joined' => ecw_chatlink_reply($orgId, $uid, (int)ecw_param('case_id', 0), (string)ecw_param('column', ''), (string)ecw_param('text', ''))]);
        case 'chatlink_column_unlink':
            ecw_json(['unlinked' => ecw_chatlink_column_unlink($orgId, $uid, (int)ecw_param('case_id', 0), (string)ecw_param('column', ''))]);
        case 'chatlink_marks':
            ecw_json(['marks' => ecw_chatlink_marks($orgId, $uid, $source, (string)ecw_param('chat', ''))]);
        case 'chatlink_link':
            $refs = ecw_param('messages', []);
            ecw_json(ecw_chatlink_link($orgId, $uid, $source, (string)ecw_param('chat', ''), is_array($refs) ? $refs : [], (int)ecw_param('case_id', 0), (string)ecw_param('title', ''),
                !empty(ecw_param('follow', false))));
        case 'chatlink_unlink':
            ecw_chatlink_unlink($orgId, $uid, (int)ecw_param('id', 0));
            ecw_json(['ok' => true]);
        case 'chatlink_suggest':
            // The AI only when it is on, set up, allowed for this person, and not paused.
            require_once dirname(__DIR__, 2) . '/lib/ai.php';
            require_once dirname(__DIR__, 2) . '/lib/rate_limit.php';
            if (!ecw_feature_allowed('ai') || ecw_ai_paused($orgId)) ecw_json(['suggestion' => null, 'ai' => false]);
            $ai = ecw_ai_config($orgId, 'assistant');
            if (!ecw_ai_ready($ai)) ecw_json(['suggestion' => null, 'ai' => false]);
            // Counted only when the AI is really asked (a chat already read is answered from memory).
            $ask = static function (string $system, string $userText) use ($ai, $uid, $orgId): ?array {
                ecw_rate_limit_hit('ai_minute', 'user:' . $uid, ecw_ai_minute_limit($ai), 60);
                ecw_rate_limit_hit('ai_daily', 'org:' . $orgId, (int)$ai['daily_requests'], 86400);
                require_once dirname(__DIR__, 2) . '/lib/agents.php';
                return ecw_agent_json($ai, $system, $userText, 400);
            };
            try {
                ecw_json(['suggestion' => ecw_chatlink_suggest($orgId, $uid, $source, (string)ecw_param('chat', ''), $ai, $ask), 'ai' => true]);
            } catch (EcwRateLimitExceeded $e) {
                ecw_json(['suggestion' => null, 'ai' => true, 'later' => true]);
            }
        case 'chatlink_dismiss':
            ecw_chatlink_dismiss($uid, (int)ecw_param('id', 0));
            ecw_json(['ok' => true]);
    }
} catch (InvalidArgumentException $e) {
    ecw_fail($e->getMessage());
} catch (Throwable $e) {
    // Teams not connected, Microsoft unreachable, the AI failing…
    ecw_log('chatlink ' . $action . ' failed: ' . $e->getMessage());
    ecw_fail($e instanceof RuntimeException && $e->getMessage() !== '' ? $e->getMessage()
        : (function_exists('ecw_teams_error_message') ? ecw_teams_error_message($e) : 'That did not work. Try again.'), 502);
}
