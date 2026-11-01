<?php declare(strict_types=1);
/**
 * The support agent module's API actions. Runs inside web/api/index.php, which has
 * already checked sign-in, CSRF, POST, the feature and (sa_send_case) that the case
 * is one the person can see (module.php), with $action, $user, $orgId and $mailbox set.
 * Everything here is for admins only.
 */
require_once __DIR__ . '/lib/support_agent.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$isAdmin = ecw_user_role((int)$user['id'], (int)$orgId) === 'admin';

switch ($action) {
    case 'sa_state':
        // The button asks first: nothing at all is shown to anyone else.
        $s = ecw_sa_settings($orgId);
        ecw_json(['admin' => $isAdmin, 'configured' => $isAdmin && $s['configured']]);

    case 'sa_settings_save':
        if (!$isAdmin) ecw_fail('Only admins can connect the support agent.', 403);
        try {
            ecw_json(['settings' => ecw_sa_save($orgId, ecw_input(), (int)$user['id'])]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }

    case 'sa_send_thread':
    case 'sa_send_case':
        if (!$isAdmin) ecw_fail('Only admins can send to the support agent.', 403);
        try {
            $r = $action === 'sa_send_case'
                ? ecw_sa_send_case($orgId, $user, (int)ecw_param('case_id', 0))
                : ecw_sa_send_thread($orgId, $user, (int)ecw_param('thread_id', 0));
            // From Docker the agent is host.docker.internal; the person's browser knows it as localhost.
            $link = str_replace('://host.docker.internal', '://localhost', (string)($r['url'] ?? ''));
            ecw_json(['ok' => true, 'message' => (string)($r['message'] ?? 'Sent.'), 'url' => $link, 'already_learned' => $r['already_learned'] ?? null]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage() === 'not found' ? 'not found' : $e->getMessage(), $e->getMessage() === 'not found' ? 404 : 400);
        } catch (RuntimeException $e) {
            ecw_fail($e->getMessage(), 502);
        }
}
