<?php declare(strict_types=1);
/**
 * Email Case Workspace — the mail UI's API.
 *
 * ONE FRONT CONTROLLER, NOT A FILE PER ENDPOINT
 * ---------------------------------------------
 * The step's security list requires server-side authorization on every mail and
 * case endpoint. With a file per endpoint that is a rule someone has to
 * remember on the day they add the fifteenth file. Here authentication,
 * organization scoping, CSRF and mailbox ownership all run before the switch,
 * so a new action is covered by construction and cannot opt out by forgetting
 * an include.
 *
 * MAILBOX OWNERSHIP IS CHECKED ONCE, CENTRALLY
 * --------------------------------------------
 * Any action carrying mailbox_id goes through ecw_require_mailbox_access(),
 * which matches the mailbox against the caller's organization and answers 404
 * — not 403 — when it does not match. A 403 would confirm that the mailbox
 * exists, which is exactly what someone probing for other tenants wants to know.
 *
 * CSRF
 * ----
 * Session cookies are SameSite=Lax, which already stops cross-site POSTs, but
 * that is one browser default away from not being true. Mutating actions also
 * require the X-ECW-CSRF header to match the session token. Reads do not, so
 * the UI can link and prefetch freely.
 *
 * The token itself comes from lib/session.php, so the API header and the
 * browser form fields validate against one value rather than two that could
 * drift apart.
 */

// Everything this file emits is JSON, including the guard's 401/403/404.
// Declared before the guard loads so it never has to infer that from the path.
define('ECW_JSON_ENDPOINT', true);

require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/mail.php';
require_once dirname(__DIR__, 2) . '/lib/compose.php';
require_once dirname(__DIR__, 2) . '/lib/signature.php';
require_once dirname(__DIR__, 2) . '/lib/cases.php';
require_once dirname(__DIR__, 2) . '/lib/timeline.php';
require_once dirname(__DIR__, 2) . '/lib/sync/messages.php';
require_once dirname(__DIR__, 2) . '/lib/rate_limit.php';
require_once dirname(__DIR__, 2) . '/lib/ai.php';
require_once dirname(__DIR__, 2) . '/lib/teams.php';
require_once dirname(__DIR__, 2) . '/lib/notes.php';
require_once dirname(__DIR__, 2) . '/lib/notifications.php';
require_once dirname(__DIR__, 2) . '/lib/followups.php';
require_once dirname(__DIR__, 2) . '/lib/templates.php';
require_once dirname(__DIR__, 2) . '/lib/rules.php';
require_once dirname(__DIR__, 2) . '/lib/presence.php';
require_once dirname(__DIR__, 2) . '/lib/webhooks.php';
require_once dirname(__DIR__, 2) . '/lib/reports.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';
require_once dirname(__DIR__, 2) . '/lib/unread.php';
require_once dirname(__DIR__, 2) . '/lib/mail_actions.php';
require_once dirname(__DIR__, 2) . '/lib/drafts_sync.php';
require_once dirname(__DIR__, 2) . '/lib/draft_share.php';
require_once dirname(__DIR__, 2) . '/lib/modules.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function ecw_json($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ecw_fail(string $message, int $status = 400): never
{
    ecw_json(['error' => $message], $status);
}

/** JSON body for POSTs, falling back to form encoding. */
function ecw_input(): array
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $input = is_array($decoded) ? $decoded : $_POST;
    return $input;
}

function ecw_param(string $key, $default = null)
{
    $input = ecw_input();
    return $_GET[$key] ?? $input[$key] ?? $default;
}

$user   = ecw_require_user();
$orgId  = ecw_current_organization_id();
if ($orgId === null) {
    ecw_fail('no organization for this user', 403);
}

$action  = (string)($_GET['action'] ?? '');
$isWrite = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
// An action of an optional module (lib/modules.php): its options say POST, feature, read-only and case scope.
$moduleAction = ecw_module_api_action($action);
if (!empty($moduleAction['post']) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ecw_fail('POST required', 405);
}
if (in_array($action, ['ai_action_run','timezone_save','spell_check','spell_learn','draft_forget','rule_backfill_start','rule_backfill_cancel','account_switch','mailbox_sync','message_translate','case_new','case_group_set','case_checklist_add','case_checklist_toggle','case_checklist_update','case_checklist_delete','case_checklist_reorder','case_template_apply','thread_claim','mark_read','signature_save','case_link','case_unlink','prefs_save','case_update','case_thread_update','case_compose','ai_assist','ai_agent','suggestion_act','suggestion_dismiss','rule_from_text','signature_sanitize','signature_profiles_save','draft_save','draft_attach','draft_detach','draft_discard','send','case_rename','case_delete','case_hold','case_restore','case_sharing_save','case_group_save','case_group_delete','teams_send','teams_chat_with','teams_notify_save','teams_notify_test',
    'case_note_add','case_note_delete','notifications_read','case_followup_add','followup_add','followup_cancel','thread_snooze',
    'template_save','template_delete','rule_save','rule_delete','presence','case_ai_summary','case_review','case_review_apply',
    'case_solution_save','case_solution_write','case_similar_check','case_similar_decide','webhook_save','webhook_test','features_save',
    'thread_note_add','thread_note_delete','thread_note_resolve','thread_note_seen','case_note_resolve','case_note_seen','calendar_save','calendar_delete','teams_meeting_create','case_mark_read','thread_move','messages_restore','thread_flag','threads_bulk','send_cancel','meeting_respond','sender_block','sender_unblock','thread_junk','auto_reply_save','case_tags_save','case_merge','draft_import','draft_share','draft_lock','draft_unlock','contact_company_save','contact_person_save','folder_create','folder_rename','folder_delete','folder_empty','case_deals_ai','case_deal_update','case_deal_add','case_deal_read','push_subscribe','push_unsubscribe','push_test','saved_filter_save','saved_filter_delete'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ecw_fail('POST required', 405);
}

if ($isWrite && !ecw_csrf_valid((string)($_SERVER['HTTP_X_ECW_CSRF'] ?? ''))) {
    ecw_fail('invalid csrf token', 419);
}

// "Read only" (Settings → Users): look at everything they may see, change nothing.
// Only their own settings and reading-related actions are allowed.
const ECW_READ_ONLY_ALLOWED = ['prefs_save', 'presence', 'notifications_read', 'case_note_seen', 'thread_note_seen', 'suggestion_dismiss',
    'ai_assist', 'ai_agent', 'message_translate', 'push_subscribe', 'push_unsubscribe', 'session', 'client_error', 'account_switch', 'spell_check', 'spell_learn', 'saved_filter_save', 'saved_filter_delete'];
if ($isWrite && !in_array($action, ECW_READ_ONLY_ALLOWED, true) && empty($moduleAction['read_only']) && ecw_user_role((int)$user['id'], (int)$orgId) === 'read_only') {
    ecw_fail('Your account is read only: you can look, but not change anything. Ask an admin if you need more.', 403);
}

// Drafts: a draft id alone is no authority. Its author may use it; a shared
// draft also the colleagues who can see its conversation or case
// (lib/draft_share.php). Everyone else gets 404.
$draftRow = null; $draftRole = null;
if (in_array($action, ['draft_get','draft_save','draft_discard','draft_detach','draft_attach','draft_file','send','draft_share','draft_lock','draft_unlock'], true) && (int)ecw_param('draft_id', 0) > 0) {
    $draftRow = ecw_draft_load($orgId, (int)ecw_param('draft_id', 0));
    $draftRole = $draftRow ? ecw_draft_role($orgId, (int)$user['id'], $draftRow) : null;
    // Autosave of a draft sent or discarded in another window: it is saved as a new draft (below).
    if ($draftRow === null && $action === 'draft_save') { unset($_GET['draft_id']); $draftRole = 'new'; }
    elseif ($draftRole === null) ecw_fail('not found', 404);
    if ($draftRow !== null && (int)ecw_param('mailbox_id', 0) > 0 && (int)$draftRow['mailbox_id'] !== (int)ecw_param('mailbox_id', 0)) ecw_fail('not found', 404);
    if ($draftRole === 'owner' && ecw_private_mailboxes() && !ecw_access_mailbox($orgId, (int)$user['id'], (int)$draftRow['mailbox_id'])) ecw_fail('not found', 404);
}

/** Resolve and authorize the mailbox for actions that name one. */
$mailbox = null;
$mailboxId = (int)(ecw_param('mailbox_id', 0));
if ($mailboxId > 0 && $draftRole === 'editor') {
    // A shared draft: seeing its conversation or case is the authority (checked above).
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mailboxes') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$mailboxId, $orgId]);
    $mailbox = $st->fetch() ?: null;
    if ($mailbox === null) ecw_fail('not found', 404);
} elseif ($mailboxId > 0) {
    $sharedThread = null;
    if ($action === 'thread') $sharedThread = (int)ecw_param('id', 0);
    if (in_array($action, ['presence', 'thread_notes', 'thread_note_add', 'thread_note_delete', 'thread_note_resolve', 'thread_note_seen'], true)) $sharedThread = (int)ecw_param('thread_id', 0);
    if ($action === 'body') {
        $scope = ecw_db()->prepare('SELECT email_thread_id FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id=? AND id=?');
        $scope->execute([$mailboxId,(int)ecw_param('message_id',0)]); $sharedThread = (int)$scope->fetchColumn();
    }
    $mailbox = ecw_require_mailbox_access($mailboxId, $sharedThread);

}

// Only the author discards; only someone who may send from the mailbox sends.
if ($draftRow !== null && $action === 'draft_discard' && $draftRole !== 'owner') ecw_fail('Only the author can discard this draft.', 403);
if ($draftRow !== null && $action === 'send' && !ecw_draft_can_send($orgId, (int)$user['id'], $draftRow)) ecw_fail('Only the owner of this mailbox can send it. Your changes are saved — they will see them.', 403);
// Someone else is editing a shared draft right now: no saving over them.
if ($draftRow !== null && in_array($action, ['draft_save', 'draft_attach', 'draft_detach'], true) && ecw_draft_locked_by_other($draftRow, (int)$user['id'])) {
    ecw_json(['error' => 'Someone else is editing this draft right now.', 'locked' => ecw_draft_share_state((int)$draftRow['id'], (int)$user['id'])], 409);
}

// Feature access (Settings → Feature access): actions behind a feature an
// admin switched off for normal members answer 403 for them.
$featureOf = [
    'teams_status' => 'teams', 'teams_chats' => 'teams', 'teams_messages' => 'teams', 'teams_send' => 'teams',
    'teams_chat_with' => 'teams', 'teams_new_chat' => 'teams', 'teams_people' => 'teams',
    'reports' => 'reports', 'reports_csv' => 'reports',
    'case_checklist' => 'checklists', 'case_checklist_add' => 'checklists', 'case_checklist_toggle' => 'checklists', 'case_checklist_update' => 'checklists',
    'case_checklist_delete' => 'checklists', 'case_checklist_reorder' => 'checklists', 'case_template_apply' => 'case_templates',
    'case_next_save' => 'next_actions', 'case_next_undo' => 'next_actions', 'case_ref_add' => 'next_actions', 'case_ref_delete' => 'next_actions',
    'case_export' => 'export',
    'case_deals' => 'deals', 'case_deals_ai' => 'deals', 'case_deal_update' => 'deals', 'case_deal_add' => 'deals', 'case_deal_read' => 'deals',
    'calendar_events' => 'calendar', 'calendar_save' => 'calendar', 'calendar_delete' => 'calendar', 'teams_meeting_create' => 'calendar',
    'ai_assist' => 'ai', 'ai_agent' => 'ai', 'case_ai_summary' => 'ai', 'case_review' => 'ai', 'case_review_apply' => 'next_actions',
    'message_translate' => 'ai', 'case_memory' => 'ai', 'case_solution_save' => 'ai', 'case_solution_write' => 'ai', 'case_similar_check' => 'ai', 'case_similar_decide' => 'ai',
    'ai_action_run' => 'ai_actions', 'ai_download' => 'ai_actions', 'spell_vocab' => 'spelling', 'spell_check' => 'spelling', 'spell_learn' => 'spelling',
    'suggestions' => 'ai', 'suggestion_act' => 'ai', 'suggestion_dismiss' => 'ai', 'case_living_summary' => 'ai', 'rule_from_text' => 'ai',
    'templates' => 'templates', 'template_save' => 'templates', 'template_delete' => 'templates',
    'case_group_save' => 'case_groups', 'case_group_delete' => 'case_groups',
    'case_tags_save' => 'case_extras', 'case_merge' => 'case_extras',
    'auto_reply_get' => 'auto_reply', 'auto_reply_save' => 'auto_reply',
    'contacts_companies' => 'contacts', 'contacts_people' => 'contacts', 'contact_company' => 'contacts', 'contact_person' => 'contacts',
    'contact_company_save' => 'contacts', 'contact_person_save' => 'contacts',
    'draft_share' => 'shared_drafts',
    'teams_notify_save' => 'teams', 'teams_notify_test' => 'teams',
];
$actionFeature = $featureOf[$action] ?? ($moduleAction['feature'] ?? null);
if ($actionFeature !== null && !ecw_feature_allowed($actionFeature)) {
    ecw_json(['error' => 'This feature is not available for your account.', 'feature_disabled' => $actionFeature], 403);
}

// Case visibility, checked once for every action that names a case: a
// members-only case answers 404 to anyone outside it, same as a missing one.
$caseScoped = ['case_group_set', 'case_checklist', 'case_checklist_add', 'case_checklist_toggle', 'case_checklist_update', 'case_checklist_delete', 'case_checklist_reorder', 'case_template_apply',
               'case_timeline', 'case_update', 'case_rename', 'case_delete', 'case_hold', 'case_restore', 'case_tags_save', 'case_merge', 'case_history',
               'case_thread_update', 'case_compose', 'case_sharing_save', 'case_next_save', 'case_next_undo', 'case_ref_add', 'case_ref_delete', 'case_export', 'case_preview',
               'case_notes', 'case_note_add', 'case_note_delete', 'case_note_resolve', 'case_note_seen', 'case_followups', 'case_followup_add',
               'case_ai_summary', 'case_review', 'case_review_apply', 'case_deals', 'case_deals_ai', 'case_deal_update', 'case_deal_add', 'case_deal_read', 'case_neighbors', 'case_memory', 'case_solution_save', 'case_solution_write', 'case_similar_check', 'case_similar_decide',
               'case_mark_read'];
if ((in_array($action, $caseScoped, true) || !empty($moduleAction['case_scoped']))
    && !ecw_case_visible($orgId, (int)$user['id'], (int)ecw_param('case_id', 0), $action === 'case_restore')) {
    ecw_fail('not found', 404);
}
// A draft may carry a case_id that is linked on send: it must be a case this user can see.
if (in_array($action, ['draft_save', 'case_link'], true) && (int)(ecw_input()['case_id'] ?? ecw_param('case_id', 0)) > 0
    && !ecw_case_visible($orgId, (int)$user['id'], (int)(ecw_input()['case_id'] ?? ecw_param('case_id', 0)))) {
    ecw_fail('case not found', 404);
}

// The module answers its own action, with $action, $user, $orgId and $mailbox set.
if ($moduleAction !== null) {
    require $moduleAction['file'];
    ecw_fail('unknown action', 404);
}

switch ($action) {

    case 'calendar_events':
    case 'calendar_save':
    case 'calendar_delete':
    case 'teams_meeting_create':
        header('Cache-Control: no-store');
        try {
            $calendarHttp = ecw_calendar_client($orgId, (int)$user['id']);
            // The composer's "Teams meeting": a meeting in the calendar, its link for the email.
            if ($action === 'teams_meeting_create') ecw_json(['meeting' => ecw_calendar_teams_meeting($calendarHttp, ecw_input())]);
            if ($action === 'calendar_events') ecw_json(['events' => ecw_calendar_events($calendarHttp, (string)ecw_param('start', ''), (string)ecw_param('end', ''))]);
            if ($action === 'calendar_save') ecw_json(['event' => ecw_calendar_save($calendarHttp, ecw_input())]);
            ecw_calendar_delete($calendarHttp, (string)ecw_param('id', ''));
            ecw_json(['ok' => true]);
        } catch (EcwCalendarConsentRequired $e) {
            ecw_json(['error' => $e->getMessage(), 'needs_consent' => true], 403);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (GraphException $e) {
            if (in_array($e->status, [401,403], true)) {
                unset($_SESSION['ecw_calendar_tokens']);
                ecw_json(['error' => 'Microsoft refused calendar access. Enable calendar access or ask your administrator to approve Calendars.ReadWrite.', 'needs_consent' => true], 403);
            }
            ecw_fail($e->status === 404 ? 'This event no longer exists. Refresh the calendar.' : 'Outlook calendar is unavailable. Try again shortly.', $e->status === 404 ? 404 : 502);
        } catch (RuntimeException $e) {
            if ($action !== 'teams_meeting_create') { ecw_log('calendar request failed: ' . get_class($e)); ecw_fail('Calendar could not be loaded. Try again shortly.', 502); }
            ecw_fail($e->getMessage(), 409);
        } catch (Throwable $e) {
            ecw_log('calendar request failed: ' . get_class($e));
            ecw_fail('Calendar could not be loaded. Try again shortly.', 502);
        }

    // ------------------------------------------------- export (lib/export.php)
    case 'case_export':
        // One case as a .zip: emails as .eml with their files, case.html, CSVs.
        require_once dirname(__DIR__, 2) . '/lib/export.php';
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(600);
        try { $zipPath = ecw_export_case_zip($orgId, (int)ecw_param('case_id', 0), (int)$user['id']); }
        catch (Throwable $e) { ecw_fail($e->getMessage(), 500); }
        $c = ecw_case_summary($orgId, (int)ecw_param('case_id', 0));
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string)filesize($zipPath));
        header('Content-Disposition: ' . ecw_content_disposition('attachment', ($c ? $c['number'] . ' ' . mb_substr((string)$c['name'], 0, 60) : 'case') . '.zip'));
        header('X-Content-Type-Options: nosniff');
        readfile($zipPath);
        @unlink($zipPath);
        exit;

    case 'export_start':
        if (!ecw_is_admin()) ecw_fail('Only admins can export everything.', 403);
        require_once dirname(__DIR__, 2) . '/lib/export.php';
        ecw_json(['export' => ecw_export_workspace_start($orgId, (int)$user['id'], (bool)(ecw_input()['files'] ?? true))]);

    case 'export_status':
        if (!ecw_is_admin()) ecw_fail('Only admins can export everything.', 403);
        require_once dirname(__DIR__, 2) . '/lib/export.php';
        $s = ecw_export_state($orgId);
        unset($s['file']);   // where it lies on the server is nobody's business
        ecw_json(['export' => $s]);

    case 'export_download':
        if (!ecw_is_admin()) ecw_fail('Only admins can export everything.', 403);
        require_once dirname(__DIR__, 2) . '/lib/export.php';
        $s = ecw_export_state($orgId);
        if (($s['state'] ?? '') !== 'ready') ecw_fail('There is no finished export. Start one in Settings → Backups.', 404);
        require_once dirname(__DIR__, 2) . '/lib/audit.php';
        ecw_audit('workspace.export_download', null, null, ['size' => $s['size']]);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(0);
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string)filesize($s['file']));
        header('Content-Disposition: ' . ecw_content_disposition('attachment', 'Case Workspace export ' . substr((string)$s['at'], 0, 10) . '.zip'));
        header('X-Content-Type-Options: nosniff');
        readfile($s['file']);
        exit;

    // A script error on someone's screen (partials/nav.php): recorded for System health → Problems.
    case 'client_error':
        try { ecw_rate_limit_hit('client_error', 'user:' . (int)$user['id'], 30, 3600); }
        catch (Throwable $e) { ecw_json(['ok' => false]); }   // a page in an error loop: enough is recorded
        $in = ecw_input();
        $msg = trim((string)($in['message'] ?? ''));
        if ($msg !== '') {
            ecw_error_report('browser', mb_substr((string)($in['kind'] ?? 'Error'), 0, 60) ?: 'Error', $msg,
                mb_substr((string)($in['where'] ?? ''), 0, 200), mb_substr((string)($in['stack'] ?? ''), 0, 4000)
                    . "\nBrowser: " . mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), mb_substr((string)($in['page'] ?? ''), 0, 255));
        }
        ecw_json(['ok' => true]);

    case 'ai_status':
        ecw_json(ecw_ai_status($orgId));

    case 'ai_assist':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try { ecw_json(ecw_ai_run($orgId, (int)$user['id'], $mailboxId, ecw_input())); }
        catch (EcwRateLimitExceeded $e) { ecw_fail('Assistant usage limit reached. Please try again later.', 429); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage(), 400); }
        catch (RuntimeException $e) { ecw_fail($e instanceof PDOException ? 'The assistant is temporarily unavailable.' : $e->getMessage(), 503); }
        catch (Throwable $e) { ecw_fail('The assistant is temporarily unavailable. Please try again.', 503); }

    case 'ai_agent':
        // The assistant with tools (lib/ai_agent.php). Answers as NDJSON lines:
        // live {"type":"step"} events, then {"type":"done","result":…} or {"type":"error"}.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/ai_agent.php';
        @set_time_limit(600);
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) ob_end_flush();
        $emit = static function (array $e): void {
            echo json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
            @flush();
        };
        $emit(['type' => 'start']);
        try {
            $emit(['type' => 'done', 'result' => ecw_ai_agent($orgId, (int)$user['id'], $mailboxId, ecw_input(), $emit)]);
        } catch (EcwRateLimitExceeded $e) { $emit(['type' => 'error', 'error' => 'Assistant usage limit reached. Please try again later.']); }
        catch (InvalidArgumentException $e) { $emit(['type' => 'error', 'error' => $e->getMessage()]); }
        catch (RuntimeException $e) { $emit(['type' => 'error', 'error' => $e instanceof PDOException ? 'The assistant is temporarily unavailable.' : $e->getMessage()]); }
        catch (Throwable $e) { error_log('[ecw] ai_agent: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); $emit(['type' => 'error', 'error' => 'The assistant is temporarily unavailable. Please try again.']); }
        exit;

    // ---------------------------------------------- AI agents' suggestions (lib/agents.php)
    case 'suggestions':
        require_once dirname(__DIR__, 2) . '/lib/agents.php';
        ecw_json(['suggestions' => ecw_suggestions_for($orgId, (int)$user['id'], (int)ecw_param('thread_id', 0) ?: null, (int)ecw_param('case_id', 0) ?: null)]);

    case 'suggestion_act':
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/agents.php';
        try { ecw_json(ecw_suggestion_act($orgId, (int)$user['id'], (int)ecw_param('id', 0), (int)ecw_param('action_index', -1))); }
        catch (EcwRateLimitExceeded $e) { ecw_fail('The AI is busy. Try again in a minute.', 429); }
        catch (InvalidArgumentException | RuntimeException $e) { ecw_fail($e->getMessage(), $e instanceof InvalidArgumentException ? 400 : 502); }

    case 'suggestion_dismiss':
        require_once dirname(__DIR__, 2) . '/lib/agents.php';
        ecw_json(['dismissed' => ecw_suggestion_dismiss($orgId, (int)$user['id'], (int)ecw_param('id', 0))]);

    case 'case_living_summary':
        require_once dirname(__DIR__, 2) . '/lib/agents.php';
        $cid = (int)ecw_param('case_id', 0);
        if (!ecw_case_visible($orgId, (int)$user['id'], $cid)) ecw_fail('not found', 404);
        ecw_json(['summary' => ecw_case_ai_summary_get($cid)]);

    case 'mailbox_folders':
        // Settings → Mailbox → Folders to sync, live: appears while the first folder
        // scan runs, then each folder with its progress against the provider's own count.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        require_once dirname(__DIR__, 2) . '/lib/sync/activity.php';
        $sync = ecw_mailbox_sync_activity((int)$mailboxId);
        $fq = ecw_db()->prepare("SELECT f.id, f.name, f.folder_type, f.is_selected_for_sync, f.total_count,
                                        (SELECT COUNT(*) FROM `" . ecw_table('email_messages') . "` m WHERE m.mail_folder_id = f.id AND m.provider_removed_at IS NULL) AS n
                                   FROM `" . ecw_table('mail_folders') . "` f WHERE f.mailbox_id = ? AND f.folder_type <> 'system'
                                  ORDER BY FIELD(f.folder_type, 'inbox', 'sent', 'custom', 'archive', 'snoozed', 'junk', 'deleted', 'drafts'), f.name");
        $fq->execute([(int)$mailboxId]);
        $rows = $fq->fetchAll();
        $st = ecw_db()->prepare("SELECT COUNT(*) FROM `" . ecw_table('sync_queue') . "` WHERE mailbox_id = ? AND job_type = 'folders'
                                  AND ((status = 'queued' AND available_at <= ?) OR (status = 'running' AND locked_at >= ?))");
        $st->execute([(int)$mailboxId, gmdate('Y-m-d H:i:s', time() + 60), gmdate('Y-m-d H:i:s', time() - 900)]);
        $busy = array_flip($sync['folders']);
        // Folders whose last two years are in (lib/sync/messages.php), and which of them import older emails now.
        $recentRows = ecw_db()->prepare("SELECT mail_folder_id, (cursor_encrypted IS NULL AND last_success_at IS NOT NULL) AS complete, recent_done_at IS NOT NULL AS recent
                                           FROM `" . ecw_table('mailbox_sync_state') . "` WHERE mailbox_id = ? AND sync_type = 'backfill'");
        $recentRows->execute([(int)$mailboxId]);
        $recentOf = [];
        foreach ($recentRows->fetchAll() as $r) if ((int)$r['recent'] === 1 && (int)$r['complete'] === 0) $recentOf[(int)$r['mail_folder_id']] = true;
        $olderBusy = ecw_db()->prepare("SELECT DISTINCT mail_folder_id FROM `" . ecw_table('sync_queue') . "` WHERE mailbox_id = ? AND job_type = 'backfill' AND reason = 'older' AND status IN ('queued','running')");
        $olderBusy->execute([(int)$mailboxId]);
        $olderBusy = array_flip(array_map('intval', $olderBusy->fetchAll(PDO::FETCH_COLUMN)));
        ecw_json([
            'discovering' => $rows === [] || (int)$st->fetchColumn() > 0,
            'sync' => $sync,
            'folders' => array_map(static fn($f) => [
                'id' => (int)$f['id'], 'name' => (string)$f['name'], 'type' => (string)$f['folder_type'],
                'selected' => (int)$f['is_selected_for_sync'] === 1, 'fixed' => in_array($f['folder_type'], ['inbox', 'sent'], true),
                'synced' => (int)$f['n'], 'total' => $f['total_count'] !== null ? (int)$f['total_count'] : null,
                'busy' => isset($busy[(int)$f['id']]),
                'recent' => isset($recentOf[(int)$f['id']]), 'older_busy' => isset($olderBusy[(int)$f['id']]),
            ], $rows),
        ]);

    case 'mailbox_sync':
    case 'mailbox_activity':
        // The Sync button (Mail sidebar, Settings → Mailbox) and its spinner.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        require_once dirname(__DIR__, 2) . '/lib/sync/activity.php';
        if ($action === 'mailbox_sync' && !ecw_mailbox_sync_now((int)$mailboxId)) {
            ecw_fail('This mailbox cannot sync right now. Check Settings → Mailbox.', 409);
        }
        ecw_json(['sync' => ecw_mailbox_sync_activity((int)$mailboxId)]);

    case 'message_translate':
        // "Translate" on an email (assets/translate.js, lib/translate.php).
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/translate.php';
        try {
            ecw_json(ecw_translate_message($orgId, (int)$user['id'], (int)ecw_param('message_id', 0), (string)ecw_param('to', 'en')));
        } catch (EcwRateLimitExceeded $e) { ecw_fail('That is a lot of translating in an hour. Try again a little later.', 429); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        catch (RuntimeException $e) { ecw_log('translate failed: ' . $e->getMessage()); ecw_fail($e->getMessage(), 502); }

    // ------------------------------------------------ Solutions memory (lib/case_memory.php)
    case 'case_memory':
        require_once dirname(__DIR__, 2) . '/lib/case_memory.php';
        ecw_json(ecw_memory_get($orgId, (int)ecw_param('case_id', 0), (int)$user['id']));

    case 'case_solution_save':
        require_once dirname(__DIR__, 2) . '/lib/case_memory.php';
        try {
            ecw_memory_save($orgId, (int)ecw_param('case_id', 0), (int)$user['id'], (string)ecw_param('problem', ''), (string)ecw_param('solution', ''), (bool)ecw_param('solved', true));
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(ecw_memory_get($orgId, (int)ecw_param('case_id', 0), (int)$user['id']));

    case 'case_solution_write':
    case 'case_similar_check':
        // On request, now: the same work the Solutions memory agent does in the background.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/agents.php';
        $cid = (int)ecw_param('case_id', 0);
        $ai = ecw_ai_config($orgId, 'agents');
        if (!ecw_agents_allowed($orgId) || !ecw_ai_ready($ai)) ecw_fail('The AI is not available. Check Settings → AI (or Pause all AI).', 409);
        try {
            ecw_rate_limit_hit('ai_memory', 'user:' . (int)$user['id'], 40, 3600);
            if ($action === 'case_solution_write') {
                ecw_memory_on_close($orgId, $cid);
                ecw_db()->prepare('UPDATE `' . ecw_table('case_resolutions') . '` SET written_by = \'ai\' WHERE case_id = ?')->execute([$cid]);   // "write it again" replaces a person's text on purpose
                if (!ecw_memory_write($orgId, $cid, $ai)) ecw_fail('The AI could not write a summary from this case. Write it yourself, or try again.', 502);
                ecw_json(ecw_memory_get($orgId, $cid, (int)$user['id']));
            }
            $found = ecw_memory_check_similar($orgId, $cid, $ai, false);
            ecw_json(['found' => $found] + ecw_memory_get($orgId, $cid, (int)$user['id']));
        } catch (EcwRateLimitExceeded $e) { ecw_fail('The AI is busy. Try again in a minute.', 429); }
        catch (RuntimeException $e) { ecw_fail($e->getMessage(), 502); }

    case 'case_similar_decide':
        require_once dirname(__DIR__, 2) . '/lib/case_memory.php';
        $cid = (int)ecw_param('case_id', 0);
        $other = (int)ecw_param('similar_case_id', 0);
        if (!ecw_case_visible($orgId, (int)$user['id'], $other)) ecw_fail('not found', 404);
        try { ecw_memory_decide($orgId, $cid, $other, (string)ecw_param('answer', ''), (int)$user['id']); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(ecw_memory_get($orgId, $cid, (int)$user['id']));

    case 'rule_from_text':
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/agents.php';
        try { ecw_json(['rule' => ecw_agent_rule_from_text($orgId, (int)$user['id'], ecw_is_admin(), (string)ecw_param('text', ''))]); }
        catch (EcwRateLimitExceeded $e) { ecw_fail('The AI is busy. Try again in a minute.', 429); }
        catch (InvalidArgumentException | RuntimeException $e) { ecw_fail($e->getMessage(), 400); }

    case 'session':
        ecw_json([
            'user'       => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']],
            'csrf'       => ecw_csrf_token(),
            'preferences' => ecw_user_preferences((int)$user['id']),
        ]);

    // One account at a time (lib/account.php): the picker at the top of every page.
    case 'account_switch':
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        if (!ecw_account_switch($orgId, (int)$user['id'], (int)ecw_param('account_id', 0))) { ecw_fail('not found', 404); }
        // Maybe another workspace now (an account of another company).
        $newOrg = (int)($_SESSION['ecw_organization_id'] ?? $orgId);
        ecw_json(['account' => ecw_active_account($newOrg, (int)$user['id']), 'workspace_changed' => $newOrg !== (int)$orgId]);

    case 'mailboxes':
        require_once dirname(__DIR__, 2) . '/lib/shared_mailboxes.php';
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        $mbParams = [$orgId];
        $st = ecw_db()->prepare(
            'SELECT mb.id, mb.email_address, mb.display_name, mb.sync_status, mb.status_message,
                    mb.last_sync_at, mb.initial_sync_completed_at, mb.historical_sync_complete, mb.is_shared, mb.shared_address,
                    (mb.connected_by_user_id = ' . (int)$user['id'] . ') AS mine
               FROM `' . ecw_table('mailboxes') . '` mb WHERE mb.organization_id = ?' . (ecw_private_mailboxes() ? ' AND ' . ecw_mailbox_visible_sql('mb', (int)$user['id'], $mbParams) : '') . ' ORDER BY mb.is_shared, mb.id'
        );
        $st->execute($mbParams);
        ecw_json(['mailboxes' => array_map(static fn($m) => ['is_shared' => (bool)(int)$m['is_shared'], 'mine' => (bool)(int)$m['mine']] + $m, $st->fetchAll()),
                  'active_id' => ecw_active_account_id($orgId, (int)$user['id'])]);

    case 'folders':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        ecw_json(['folders' => ecw_folder_tree($mailboxId)]);

    case 'folder_create':
    case 'folder_rename':
    case 'folder_delete':
    case 'folder_empty':
        // Changes the mailbox itself: its owner (or anyone, without private mailboxes).
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        require_once dirname(__DIR__, 2) . '/lib/shared_mailboxes.php';
        if (ecw_private_mailboxes() && !ecw_mailbox_can_manage($orgId, (int)$user['id'], $mailbox)) { ecw_fail('Only the owner of this mailbox (or an admin, for a shared one) can change its folders.', 403); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/folder_actions.php';
        try {
            if ($action === 'folder_create') {
                $parent = (int)ecw_param('parent_id', 0);
                $row = ecw_folder_create($mailboxId, (string)ecw_param('name', ''), $parent > 0 ? $parent : null);
                ecw_json(['folder' => $row, 'folders' => ecw_folder_tree($mailboxId)]);
            }
            if ($action === 'folder_rename') {
                ecw_folder_rename($mailboxId, (int)ecw_param('folder_id', 0), (string)ecw_param('name', ''));
                ecw_json(['folders' => ecw_folder_tree($mailboxId)]);
            }
            if ($action === 'folder_empty') {
                ecw_json(ecw_folder_empty($mailboxId, (int)ecw_param('folder_id', 0)));
            }
            $n = ecw_folder_delete($mailboxId, (int)ecw_param('folder_id', 0));
            ecw_json(['deleted' => true, 'emails' => $n, 'folders' => ecw_folder_tree($mailboxId)]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (Throwable $e) {
            ecw_log('folder change failed: ' . $e->getMessage());
            ecw_fail('The mailbox did not accept the change. Try again in a moment.', 502);
        }

    case 'threads':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        ecw_json(ecw_thread_list($mailboxId, [
            'folder_id'   => ecw_param('folder_id'),
            'folder_type' => ecw_param('folder_type'),
            'query'       => (string)ecw_param('q', ''),
            'cursor'      => (string)ecw_param('cursor', ''),
            'limit'       => (int)ecw_param('limit', ECW_THREAD_PAGE_SIZE),
            'snoozed'     => (string)ecw_param('snoozed', '') === '1',
            'claim'       => (string)ecw_param('claim', ''),
            'user_id'     => (int)$user['id'],
            'organization_id' => $orgId,
            'no_colors'   => !ecw_feature_allowed('rules'),
        ]));

    case 'thread':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $prefs  = ecw_user_preferences((int)$user['id']);
        $order  = (string)ecw_param('order', $prefs['thread_order'] ?? 'asc');
        $detail = ecw_thread_detail($mailboxId, (int)ecw_param('id', 0), $order, (bool)ecw_param('images', false), ['quoted' => false]);
        if ($detail === null) { ecw_fail('not found', 404); }
        // Internal notes on this conversation: the 💬 on each email and the notes panel.
        $detail['notes'] = ecw_thread_notes((int)$detail['id'], (int)$user['id']);
        $detail['note_groups'] = ecw_note_groups($detail['notes']);
        // Replies being written together (shared drafts), shown above the emails.
        $detail['shared_drafts'] = ecw_shared_drafts_for_thread($orgId, (int)$user['id'], (int)$detail['id']);
        // Saved draft replies, shown in the conversation like Outlook (Draft · Edit · Delete).
        require_once dirname(__DIR__, 2) . '/lib/compose.php';
        $detail['drafts'] = ecw_thread_drafts($orgId, (int)$user['id'], (int)$detail['id']);
        // And emails scheduled in Outlook for this conversation ("Scheduled in Outlook", sent from there).
        require_once dirname(__DIR__, 2) . '/lib/drafts_sync.php';
        try { $detail['drafts'] = array_merge($detail['drafts'], ecw_thread_mailbox_scheduled($orgId, (int)$user['id'], (int)$detail['id'])); }
        catch (Throwable $e) { ecw_log('scheduled Outlook emails not read: ' . $e->getMessage()); }        // Opened from a search: the emails and files that match, so the reader lands on them.
        if (trim((string)ecw_param('q', '')) !== '') {
            $hit = ecw_search_in_thread((int)$detail['id'], (string)ecw_param('q', ''));
            $detail['search'] = ['message_ids' => $hit['message_ids'], 'attachments' => (object)$hit['attachments']];
        }

        // A shared mailbox (info@): who handles this conversation, and who it can be given to.
        if ((int)($mailbox['is_shared'] ?? 0) === 1) {
            require_once dirname(__DIR__, 2) . '/lib/shared_mailboxes.php';
            $detail['shared_mailbox'] = true;
            $detail['claimed'] = ecw_thread_claim_info((int)$detail['id']);
            $detail['claim_people'] = ecw_mailbox_people($mailboxId);
        }
        ecw_json($detail);

    case 'thread_claim':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        require_once dirname(__DIR__, 2) . '/lib/shared_mailboxes.php';
        $for = ecw_param('release', false) ? null : ((int)ecw_param('user_id', 0) ?: (int)$user['id']);
        try { ecw_json(ecw_thread_claim($orgId, (int)$user['id'], $mailboxId, (int)ecw_param('thread_id', 0), $for)); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage(), 400); }

    case 'body':
        // Lazy body loading: fetch from the provider only when a reader has
        // actually opened a message whose body was never synced.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $messageId = (int)ecw_param('message_id', 0);
        $st = ecw_db()->prepare(
            'SELECT id, email_thread_id, provider_message_id, body_html, quoted_html, body_fetch_state FROM `' . ecw_table('email_messages') . '`
              WHERE id = ? AND mailbox_id = ?'
        );
        $st->execute([$messageId, $mailboxId]);
        $row = $st->fetch();
        if ($row === false) { ecw_fail('not found', 404); }

        $inlineMissing = ecw_inline_images_missing($messageId, (string)$row['body_html'] . (string)$row['quoted_html']);
        if ($row['body_fetch_state'] !== 'fetched' || $inlineMissing) {
            try {
                ecw_sync_single_body($mailboxId, $messageId);
            } catch (Throwable $e) {
                ecw_log('on-demand body fetch failed: ' . $e->getMessage());
                ecw_fail('Message content could not be loaded. Please try again.', 502);
            }
        }
        // This one email (with its quoted history), not the whole conversation again.
        $detail = ecw_thread_detail($mailboxId, (int)$row['email_thread_id'], 'asc', (bool)ecw_param('images', false), ['message_id' => $messageId]);
        ecw_json($detail ?? []);

    case 'updates':
        // The live-update heartbeat. Deliberately the cheapest question that can
        // still answer "has anything changed?": three aggregates over
        // ecw_email_threads, which the thread-list cache keeps current, and no
        // message body or JSON column is touched.
        //
        // The browser polls this every few seconds and only refetches the real
        // list when the signature moves, so an idle mailbox costs one indexed
        // aggregate per poll instead of a full page of threads.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }

        $st = ecw_db()->prepare(
            'SELECT COUNT(*) AS threads,
                    COALESCE(SUM(unread_count), 0) AS unread,
                    MAX(latest_message_at) AS latest,
                    SUM(snoozed_until IS NOT NULL AND snoozed_until > ?) AS snoozed
               FROM `' . ecw_table('email_threads') . '` WHERE mailbox_id = ?'
        );
        $st->execute([ecw_now(), $mailboxId]);
        $signal = $st->fetch() ?: [];

        $threadSignal = null;
        $openThreadId = (int)ecw_param('thread_id', 0);
        if ($openThreadId > 0) {
            $st = ecw_db()->prepare(
                'SELECT message_count, latest_message_at FROM `' . ecw_table('email_threads') . '`
                  WHERE id = ? AND mailbox_id = ?'
            );
            $st->execute([$openThreadId, $mailboxId]);
            $row = $st->fetch();
            if ($row !== false) {
                $threadSignal = $row['message_count'] . ':' . (string)$row['latest_message_at'];
            }
        }

        $st = ecw_db()->prepare(
            'SELECT sync_status, status_message, last_sync_at, historical_sync_complete
               FROM `' . ecw_table('mailboxes') . '` WHERE id = ?'
        );
        $st->execute([$mailboxId]);
        $mailboxRow = $st->fetch() ?: [];

        ecw_json([
            // One opaque string: the client compares, it does not interpret.
            'signature' => ($signal['threads'] ?? 0) . ':' . ($signal['unread'] ?? 0) . ':' . (string)($signal['latest'] ?? '') . ':' . (int)($signal['snoozed'] ?? 0),
            'unread'    => (int)($signal['unread'] ?? 0),
            'thread'    => $threadSignal,
            // "Maria is replying": other people with a reply open on this thread.
            'replying'  => $openThreadId > 0 ? ecw_presence_others($openThreadId, (int)$user['id']) : [],
            // What is synchronizing (Sync button, folder spinners, import bar: lib/sync/activity.php).
            'sync' => (static function () use ($mailboxId) { require_once dirname(__DIR__, 2) . '/lib/sync/activity.php'; return ecw_mailbox_sync_activity((int)$mailboxId); })(),
            // "AI is reading": new emails the triage agent has not read yet (assets/ai-working.js).
            'ai_reading' => ecw_feature_allowed('ai') ? (static function () use ($orgId, $mailboxId) { require_once dirname(__DIR__, 2) . '/lib/agents.php'; return ecw_agents_reading($orgId, (int)$mailboxId); })() : null,
            'mailbox'   => [
                'sync_status'    => $mailboxRow['sync_status'] ?? null,
                'status_message' => $mailboxRow['status_message'] ?? null,
                'last_sync_at'   => $mailboxRow['last_sync_at'] ?? null,
                'history_done'   => (int)($mailboxRow['historical_sync_complete'] ?? 0) === 1,
            ],
        ]);

    case 'mark_read':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $changed = ecw_thread_mark_read($mailboxId, (int)ecw_param('thread_id', 0), (bool)ecw_param('is_read', true));
        ecw_json(['updated' => $changed]);

    case 'push_key':
    case 'push_subscribe':
    case 'push_unsubscribe':
    case 'push_test':
        // Phone / computer notifications (Web Push, lib/push.php).
        require_once dirname(__DIR__, 2) . '/lib/push.php';
        try {
            if ($action === 'push_key') ecw_json(['key' => ecw_vapid_keys()['public']]);
            if ($action === 'push_subscribe') { ecw_push_subscribe((int)$user['id'], (array)(ecw_input()['subscription'] ?? []), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')); ecw_json(['ok' => true]); }
            if ($action === 'push_unsubscribe') { ecw_push_unsubscribe((int)$user['id'], (string)(ecw_input()['endpoint'] ?? '')); ecw_json(['ok' => true]); }
            $n = ecw_push_to_user((int)$user['id'], 'Case Workspace', 'Notifications work on this device.', 'app.php', 'test');
            ecw_json(['devices' => $n]);
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }

    case 'auto_reply_get':
    case 'auto_reply_save':
        // Automatic replies (out of office) of a mailbox you connected.
        if ($mailbox === null || (int)$mailbox['connected_by_user_id'] !== (int)$user['id']) { ecw_fail('not found', 404); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $isGoogle = ($mailbox['provider'] ?? '') === 'google';
        try {
            $provider = ecw_provider_for_mailbox($mailboxId);
            if ($action === 'auto_reply_save') {
                $in = ecw_input();
                $status = in_array($in['status'] ?? '', ['off', 'on', 'scheduled'], true) ? $in['status'] : 'off';
                $start = $end = null;
                if ($status === 'scheduled') {
                    $a = strtotime((string)($in['start'] ?? '')); $b = strtotime((string)($in['end'] ?? ''));
                    if ($a === false || $b === false || $b <= $a) ecw_fail('Choose a start and an end time (the end after the start).');
                    $start = gmdate('Y-m-d H:i:s', $a); $end = gmdate('Y-m-d H:i:s', $b);
                }
                $provider->setAutoReply([
                    'status' => $status, 'start' => $start, 'end' => $end,
                    'internal' => ecw_sanitize_compose_html((string)($in['internal'] ?? '')),
                    'external' => ecw_sanitize_compose_html((string)($in['external'] ?? '')),
                    'audience' => in_array($in['audience'] ?? '', ['none', 'contacts', 'all'], true) ? $in['audience'] : 'all',
                    'subject' => mb_substr(trim((string)($in['subject'] ?? '')), 0, 200) ?: 'Automatic reply',
                ]);
            }
            $ar = $provider->getAutoReply();
            // Kept for the top bar's "Away" badge (partials/nav.php), so no page waits for Outlook.
            require_once dirname(__DIR__, 2) . '/lib/license.php';
            ecw_app_setting_set('ooo_' . $mailboxId, json_encode(['status' => $ar['status'], 'start' => $ar['start'], 'end' => $ar['end'], 'at' => time()]));
            ecw_json(['auto_reply' => $ar, 'provider' => $isGoogle ? 'google' : 'microsoft']);
        } catch (GraphException $e) {
            if (in_array($e->status, [401, 403], true)) {
                ecw_json(['error' => 'The app needs permission to change automatic replies. Allow it once (you sign in again).', 'needs_consent' => true,
                    'connect_url' => ecw_web_base() . 'oauth/start.php?settings=1' . ($isGoogle ? '&provider=google' : '')], 403);
            }
            ecw_fail('The mailbox did not accept that. Try again in a moment.', 502);
        }

    case 'thread_junk':
        // Report as junk (block=1: Block sender too) / Not junk (not=1).
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once dirname(__DIR__, 2) . '/lib/junk.php';
        try {
            $tid = (int)ecw_param('thread_id', 0);
            ecw_json(ecw_param('not') ? ecw_thread_not_junk($mailboxId, $tid) : ecw_thread_junk($mailboxId, $tid, (bool)ecw_param('block', false), (int)$user['id']));
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        catch (RuntimeException $e) { ecw_fail($e->getMessage(), 502); }

    case 'blocked_senders':
    case 'sender_block':
    case 'sender_unblock':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        require_once dirname(__DIR__, 2) . '/lib/junk.php';
        try {
            if ($action === 'sender_block') ecw_sender_block($mailboxId, (string)ecw_param('pattern', ''), (int)$user['id']);
            if ($action === 'sender_unblock') ecw_sender_unblock($mailboxId, (string)ecw_param('pattern', ''));
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(['blocked' => ecw_blocked_senders($mailboxId)]);

    case 'meeting_info':
    case 'meeting_respond':
        // Meeting invitations: details + Accept / Tentative / Decline (Outlook).
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $st = ecw_db()->prepare('SELECT provider_message_id FROM `' . ecw_table('email_messages') . '` WHERE id = ? AND mailbox_id = ?');
        $st->execute([(int)ecw_param('message_id', 0), $mailboxId]);
        $pid = $st->fetchColumn();
        if ($pid === false) { ecw_fail('not found', 404); }
        $do = (string)ecw_param('response', '');
        if ($action === 'meeting_respond' && !in_array($do, ['accept', 'tentative', 'decline', 'remove'], true)) ecw_fail('Unknown response.');
        $sharedBox = (string)($mailbox['shared_address'] ?? '') !== '';
        try {
            $provider = ecw_provider_for_mailbox($mailboxId);
            try {
                $info = $provider->meetingInfo((string)$pid);
            } catch (GraphException $e) {
                // A mailbox connected before calendar access was allowed: the calendar's own
                // sign-in (lib/calendar.php) carries it. It caches its token in the session.
                if ($e->status !== 403 || $sharedBox || ($mailbox['provider'] ?? 'microsoft') !== 'microsoft') throw $e;
                $provider = new MicrosoftGraphProvider(ecw_graph_config(), static fn(): string => ecw_calendar_token($mailbox));
                $info = $provider->meetingInfo((string)$pid);
            }
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if ($action === 'meeting_respond') {
                $provider->respondMeeting($info['event_id'], $do, mb_substr(trim((string)ecw_param('comment', '')), 0, 2000));
                $info = array_merge($info, ['response' => ['accept' => 'accepted', 'tentative' => 'tentativelyAccepted', 'decline' => 'declined', 'remove' => 'removed'][$do]]);
            }
            unset($info['event_id']);
            ecw_json(['meeting' => $info]);
        } catch (EcwCalendarConsentRequired $e) {
            ecw_json(['error' => 'Answering invitations needs access to your Outlook calendar.', 'consent' => true], 403);
        } catch (GraphException $e) {
            if ($e->status === 403 && $sharedBox) ecw_fail('Answer the invitations of a shared mailbox in Outlook.', 403);
            if (in_array($e->status, [401, 403], true)) ecw_json(['error' => 'Answering invitations needs access to your Outlook calendar.', 'consent' => true], 403);
            ecw_fail('Outlook did not accept that. Try again in a moment.', 502);
        } catch (RuntimeException $e) {
            ecw_fail($e->getMessage(), 409);
        }

    case 'thread_move':
        // Delete / Archive / Move to folder. The answer carries what Undo needs.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try { ecw_json(ecw_thread_move($mailboxId, (int)ecw_param('thread_id', 0), (string)ecw_param('target', ''))); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        catch (RuntimeException $e) { ecw_fail($e->getMessage(), 502); }

    case 'messages_restore':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $moves = ecw_input()['moves'] ?? [];
        ecw_json(['restored' => ecw_messages_restore($mailboxId, is_array($moves) ? $moves : [])]);

    case 'threads_bulk':
        // Several selected conversations at once: read / unread / flag / unflag / move / junk.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $ids = array_slice(array_values(array_unique(array_map('intval', (array)(ecw_input()['thread_ids'] ?? [])))), 0, 200);
        $op = (string)ecw_param('op', '');
        $target = (string)ecw_param('target', '');
        if ($ids === [] || !in_array($op, ['read', 'unread', 'flag', 'unflag', 'move', 'junk'], true)) { ecw_fail('Nothing to do.'); }
        // Only conversations of this mailbox.
        $in = implode(',', $ids);
        $ids = array_map('intval', ecw_db()->query('SELECT id FROM `' . ecw_table('email_threads') . '` WHERE mailbox_id = ' . $mailboxId . ' AND id IN (' . $in . ')')->fetchAll(PDO::FETCH_COLUMN));
        $moved = []; $failed = 0; $done = 0; $toName = null; $toId = null; $deleted = 0;
        if ($op === 'junk') {
            require_once dirname(__DIR__, 2) . '/lib/junk.php';
            $junk = ecw_junk_folder($mailboxId);
            if ($junk === null) ecw_fail('This mailbox has no Junk folder the app can see.');
            $op = 'move'; $target = (string)$junk['id'];
        }
        foreach ($ids as $tid) {
            try {
                if ($op === 'read' || $op === 'unread') { $done += ecw_thread_mark_read($mailboxId, $tid, $op === 'read') > 0 ? 1 : 0; }
                elseif ($op === 'flag' || $op === 'unflag') { $done += ecw_thread_flag($mailboxId, $tid, $op === 'flag') > 0 ? 1 : 0; }
                else {
                    $r = ecw_thread_move($mailboxId, $tid, $target);
                    $moved = array_merge($moved, $r['moved']); $failed += $r['failed']; $toName = $r['to_name']; $toId = $r['to'];
                    $deleted += (int)($r['deleted'] ?? 0);
                    if ($r['moved'] || !empty($r['deleted'])) $done++;
                }
            } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
            catch (Throwable $e) { $failed++; ecw_log('bulk ' . $op . ' failed for thread ' . $tid . ': ' . $e->getMessage()); }
        }
        ecw_json(['done' => $done, 'threads' => $ids, 'moved' => $moved, 'deleted' => $deleted, 'failed' => $failed, 'to' => $toId, 'to_name' => $toName]);

    case 'thread_flag':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        ecw_json(['changed' => ecw_thread_flag($mailboxId, (int)ecw_param('thread_id', 0), (bool)ecw_param('flagged', true))]);

    case 'case_mark_read':
        $markThread = (int)ecw_param('thread_id', 0);
        ecw_json(['threads' => ecw_case_mark_read($orgId, (int)$user['id'], (int)ecw_param('case_id', 0),
            $markThread > 0 ? $markThread : null, (bool)ecw_param('is_read', true))]);

    case 'nav_badges':
        // Polled by every page for the dots on the top navigation.
        header('Cache-Control: no-store');
        ecw_json(ecw_unread_badges($orgId, (int)$user['id']));

    case 'compose':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $messageId = ecw_param('message_id');
        ecw_json(ecw_compose_context(
            $mailboxId,
            $messageId === null ? null : (int)$messageId,
            (string)ecw_param('mode', 'new'),
            (int)$user['id']
        ));

    case 'draft_save':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        // The composer always sends the whole draft as JSON. A body that did not arrive whole
        // (a save cut off as the page closed) read as empty, and saved over the draft every
        // field it had: subject, recipients, text, and its schedule (2026-10-28).
        $whole = json_decode((string)(file_get_contents('php://input') ?: ''), true);
        if (!is_array($whole) || !array_key_exists('body_html', $whole)) {
            ecw_fail('The draft did not arrive whole, so nothing was saved. It is kept as it was.', 400);
        }
        $draftId = ecw_param('draft_id');
        // A shared draft changed by someone else since this window loaded it: never save over that.
        $base = ecw_param('base_version');
        if ($draftRow !== null && $draftRow['shared_at'] !== null && $base !== null && $base !== ''
            && (int)$base !== (int)$draftRow['version'] && (int)($draftRow['last_edited_by_user_id'] ?? 0) !== (int)$user['id']) {
            ecw_json(['error' => 'Someone else changed this draft in the meantime. It was reloaded.', 'stale' => true], 409);
        }
        $id = ecw_draft_save($orgId, $mailboxId, (int)$user['id'], ecw_input(), $draftId ? (int)$draftId : null);
        ecw_drafts_push_soon($mailboxId);   // copy it to the mailbox's Drafts folder shortly
        $ver = ecw_db()->prepare('SELECT version FROM `' . ecw_table('drafts') . '` WHERE id = ?');
        $ver->execute([$id]);
        ecw_json(['draft_id' => $id, 'version' => (int)$ver->fetchColumn()]);

    case 'draft_share':
        try { ecw_draft_share($orgId, (int)$user['id'], $draftRow ?? [], (bool)ecw_param('shared', true)); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(ecw_draft_share_state((int)$draftRow['id'], (int)$user['id']));

    case 'draft_lock':
        // Heartbeat from an open composer on a shared draft (every few seconds).
        if ($draftRow === null) { ecw_fail('not found', 404); }
        ecw_json(ecw_draft_lock($orgId, (int)$user['id'], $draftRow, (bool)ecw_param('take', false)));

    case 'draft_unlock':
        if ($draftRow !== null) ecw_draft_unlock((int)$user['id'], (int)$draftRow['id']);
        ecw_json(['ok' => true]);

    case 'draft_import':
        // A draft started in Outlook / Gmail, opened here: imported and kept linked.
        if ($mailbox === null || (int)$mailbox['connected_by_user_id'] !== (int)$user['id']) { ecw_fail('not found', 404); }
        try { ecw_json(['draft_id' => ecw_draft_import($orgId, (int)$user['id'], $mailboxId, (string)ecw_param('provider_id', ''))]); }
        catch (Throwable $e) {
            ecw_log('draft import failed: ' . $e->getMessage());
            // Gone in the mailbox (sent or deleted there): drop it from the list so it does not come back.
            require_once dirname(__DIR__, 2) . '/lib/drafts_sync.php';
            if ($e instanceof GraphException && $e->status === 404 || str_contains($e->getMessage(), '404')) {
                ecw_mailbox_draft_forget((string)ecw_param('provider_id', ''));
                ecw_json(['error' => 'That draft is no longer in the mailbox (it was sent or deleted). The list is up to date now.', 'gone' => true], 410);
            }
            ecw_fail('The draft could not be brought in from the mailbox. Try again in a moment.', 502);
        }

    // Greek and English spelling and word suggestions in the composer (lib/spelling.php, assets/spelling.js).
    case 'spell_vocab':
    case 'spell_check':
    case 'spell_learn':
        require_once dirname(__DIR__, 2) . '/lib/spelling.php';
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $spellBox = $mailbox !== null ? (int)$mailbox['id'] : (int)ecw_active_account_id($orgId, (int)$user['id']);
        try {
            if ($action === 'spell_learn') { ecw_spell_learn((int)$user['id'], (string)ecw_param('word', '')); ecw_json(['ok' => true]); }
            $vocab = $spellBox > 0 ? ecw_spell_vocab((int)$user['id'], $spellBox) : ecw_spell_own_words((int)$user['id']);
            if ($action === 'spell_vocab') { header('Cache-Control: private, max-age=3600'); ecw_json(['words' => $vocab]); }
            $words = array_values(array_filter((array)(ecw_input()['words'] ?? []), 'is_string'));
            // Never marked wrong: the person's names and brands (capitalised in their mail) and the words they added.
            $known = array_merge(ecw_spell_own_words((int)$user['id']), array_values(array_filter($vocab, static fn($w) => mb_strtolower($w) !== $w)));
            ecw_json(['wrong' => (object)ecw_spell_check($words, $known)]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (Throwable $e) {
            ecw_log('spelling: ' . $e->getMessage());
            ecw_json(['wrong' => (object)[], 'unavailable' => true]);
        }

    // The assistant's proposals, confirmed by the person (lib/ai_actions.php), and its spreadsheets.
    // Travelling (assets/timezone.js): the home time zone and the calendar's second zone.
    case 'timezone_save':
        $prefs = ecw_user_preferences((int)$user['id']);
        $in = ecw_input();
        if (isset($in['home_tz'])) {
            try { $prefs['home_tz'] = (new DateTimeZone((string)$in['home_tz']))->getName(); } catch (Throwable $e) { ecw_fail('Unknown time zone.'); }
        }
        if (array_key_exists('show_home_zone', $in)) $prefs['show_home_zone'] = !empty($in['show_home_zone']);
        ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), (int)$user['id']]);
        ecw_json(['ok' => true]);

    case 'ai_action_run':
        require_once dirname(__DIR__, 2) . '/lib/ai_actions.php';
        try {
            $r = ecw_ai_action_run($orgId, (int)$user['id'], (array)(ecw_input()['action'] ?? []));
            ecw_json($r);
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        catch (Throwable $e) { ecw_log('assistant action failed: ' . $e->getMessage()); ecw_fail('That could not be done. Try again in a moment.', 500); }

    case 'ai_download':
        require_once dirname(__DIR__, 2) . '/lib/ai_actions.php';
        $dl = ecw_ai_download($orgId, (int)$user['id'], (string)ecw_param('token', ''));
        if ($dl === null) { ecw_fail('That file is no longer available (files are kept for a day). Ask again.', 404); }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        header('Content-Type: ' . $dl['mime']);
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($dl['name']));
        header('Content-Length: ' . filesize($dl['path']));
        header('X-Content-Type-Options: nosniff');
        readfile($dl['path']);
        exit;

    case 'draft_forget':
        // "Remove from the list": a mailbox draft this person does not want to see here.
        require_once dirname(__DIR__, 2) . '/lib/drafts_sync.php';
        ecw_mailbox_draft_forget((string)ecw_param('provider_id', ''));
        ecw_json(['ok' => true]);

    case 'address_suggest':
        ecw_json(['suggestions' => ecw_address_suggest($orgId, (int)$user['id'], (string)ecw_param('q', ''))]);

    case 'recipient_name':
        // For {{first_name}} / {{name}} in templates.
        $email = (string)ecw_param('email', '');
        $full = ecw_recipient_name($orgId, (int)$user['id'], $email);
        ecw_json(['name' => $full, 'first_name' => ecw_first_name($full, $email)]);

    case 'drafts':
        // The Drafts list: this user's unsent drafts across their mailboxes,
        // plus drafts started in Outlook / Gmail that are not here yet.
        $list = ecw_drafts_list($orgId, (int)$user['id']);
        $remote = ecw_mailbox_drafts($orgId, (int)$user['id']);
        $all = array_merge($list, $remote);
        usort($all, static fn($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
        ecw_json(['drafts' => $all]);

    case 'draft_get':
        // Its author, or (shared) a colleague who sees its conversation / case — checked above.
        $draft = $draftRow;
        if ($draft === null) { ecw_fail('not found', 404); }
        $draft['attachments'] = array_map(static function (array $a): array {
            unset($a['path']);   // a server path is never the client's business
            return $a;
        }, json_decode((string)($draft['attachments_json'] ?? '[]'), true) ?: []);
        unset($draft['attachments_json'], $draft['editing_user_id'], $draft['editing_at']);
        $draftThread = ecw_draft_thread_id($draftRow);
        $latest = null;
        if ($draftThread !== null) {
            $lt = ecw_db()->prepare('SELECT MAX(id) FROM `' . ecw_table('email_messages') . '` WHERE email_thread_id = ?');
            $lt->execute([$draftThread]);
            $latest = (int)$lt->fetchColumn() ?: null;
        }
        ecw_json($draft + [
            'role' => $draftRole,
            'thread_id' => $draftThread,
            'thread_latest_message_id' => $latest,
            'can_send' => ecw_draft_can_send($orgId, (int)$user['id'], $draftRow),
            'share' => ecw_draft_share_state((int)$draftRow['id'], (int)$user['id']),
        ]);

    case 'draft_discard':
        // A scheduled email Outlook holds is taken back there first; Outlook out of reach: say so.
        try { ecw_draft_delete($orgId, (int)ecw_param('draft_id', 0)); }
        catch (RuntimeException $e) { ecw_fail(preg_replace('/^ecw:\s*/', '', $e->getMessage()) ?? 'Try again in a moment.', 502); }
        ecw_json(['discarded' => true]);

    case 'draft_detach':
        try { ecw_draft_detach($orgId, (int)ecw_param('draft_id', 0), (string)ecw_param('attachment_id', '')); }
        catch (Throwable $e) { ecw_fail('Attachment could not be removed.', 404); }
        if ($d = ecw_draft_load($orgId, (int)ecw_param('draft_id', 0))) ecw_drafts_push_soon((int)$d['mailbox_id']);
        ecw_json(['removed' => true]);

    case 'draft_file':
        // A file attached to a draft (the draft cards in Mail and on a case): its preview
        // (?inline=1) or download. Its author, or a colleague of a shared draft (checked above).
        if ($draftRow === null) { ecw_attachment_fail('not found', 404, 'This draft is not here any more.'); }
        $file = null;
        foreach (json_decode((string)($draftRow['attachments_json'] ?? '[]'), true) ?: [] as $a) {
            if ((string)($a['id'] ?? '') === (string)ecw_param('file', '')) { $file = $a; break; }
        }
        if ($file === null || empty($file['path']) || !is_file((string)$file['path'])) {
            ecw_attachment_fail('not found', 404, 'This file is not in the draft any more.');
        }
        // Shown in place only when the bytes really are a picture or a PDF (as for email files).
        $detected = (string)((new finfo(FILEINFO_MIME_TYPE))->file((string)$file['path']) ?: 'application/octet-stream');
        $inline = (string)ecw_param('inline', '') === '1' && ecw_inline_preview_allowed($detected);
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($inline ? $detected : 'application/octet-stream'));
        header('Content-Disposition: ' . ecw_content_disposition($inline ? 'inline' : 'attachment', (string)$file['filename']));
        header('Content-Length: ' . filesize((string)$file['path']));
        header('Cache-Control: private, no-store');
        readfile((string)$file['path']);
        exit;

    case 'draft_attach':
        $draftId = (int)ecw_param('draft_id', 0);
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            ecw_fail('no file uploaded');
        }
        if ((int)$_FILES['file']['size'] > ECW_MAX_ATTACHMENT_BYTES) {
            ecw_fail('This file is larger than ' . (int)(ECW_MAX_ATTACHMENT_BYTES / 1024 / 1024) . ' MB, more than an email can carry. Share it as a link instead.', 413);
        }
        try {
            $entry = ecw_draft_attach(
                $orgId,
                $draftId,
                (string)$_FILES['file']['tmp_name'],
                (string)$_FILES['file']['name'],
                (string)($_FILES['file']['type'] ?? '')
            );
        } catch (Throwable $e) {
            ecw_fail($e->getMessage(), 400);
        }
        if ($d = ecw_draft_load($orgId, $draftId)) ecw_drafts_push_soon((int)$d['mailbox_id']);
        ecw_json($entry);

    case 'send':
        try {
            ecw_rate_limit_hit('send', 'user:' . $user['id'], 20, 600);
        } catch (EcwRateLimitExceeded $e) {
            header('Retry-After: ' . $e->retryAfterSeconds);
            ecw_fail('too many messages sent, try again shortly', 429);
        }
        // Send = queue it a few seconds ahead (Undo works until then); Send later = a chosen time.
        // The sync daemon sends it (lib/compose.php ecw_drafts_send_due).
        $sendAt = null;
        if (($raw = (string)ecw_param('send_at', '')) !== '') {
            $ts = strtotime($raw);
            if ($ts === false || $ts < time() - 60) ecw_fail('Choose a time in the future.');
            $sendAt = gmdate('Y-m-d H:i:s', max($ts, time() + ECW_UNDO_SEND_SECONDS));
        }
        // Someone answered (or wrote) in this conversation since the writer last looked: ask first.
        if ($draftRow !== null && !ecw_param('force', false)
            && ($collision = ecw_reply_collision($draftRow, (int)ecw_param('seen_message_id', 0))) !== null) {
            ecw_json(['error' => 'Something new arrived in this conversation.', 'collision' => $collision], 409);
        }
        try {
            $at = ecw_draft_schedule($orgId, (int)ecw_param('draft_id', 0), $sendAt);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (Throwable $e) {
            ecw_fail($e->getMessage(), 404);
        }
        require_once dirname(__DIR__, 2) . '/lib/audit.php';
        ecw_audit('email.send', 'draft', (int)ecw_param('draft_id', 0), ['mailbox' => $mailboxId ?: null, 'later' => $sendAt !== null]);
        // A reply from a shared mailbox: an unclaimed conversation becomes the sender's.
        if ($draftRow !== null && !empty($draftRow['reply_to_local_message_id'])) {
            require_once dirname(__DIR__, 2) . '/lib/shared_mailboxes.php';
            $tq = ecw_db()->prepare('SELECT email_thread_id FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
            $tq->execute([(int)$draftRow['reply_to_local_message_id']]);
            if ($tid = (int)$tq->fetchColumn()) ecw_thread_claim_on_reply($tid, (int)$user['id']);
        }
        $held = $sendAt !== null && !empty((ecw_draft_load($orgId, (int)ecw_param('draft_id', 0)) ?? [])['outlook_internet_id']);
        ecw_json(['scheduled' => true, 'send_at' => $at, 'undo_seconds' => $sendAt === null ? ECW_UNDO_SEND_SECONDS : null, 'held_in_outlook' => $held]);

    case 'send_cancel':
        // Undo send, or cancel a scheduled one. The draft stays in Drafts.
        if (ecw_private_mailboxes()) {
            $own = ecw_draft_load($orgId, (int)ecw_param('draft_id', 0));
            if (!$own || (int)$own['user_id'] !== (int)$user['id']) ecw_fail('not found', 404);
        }
        try { ecw_json(['cancelled' => ecw_draft_unschedule($orgId, (int)ecw_param('draft_id', 0))]); }
        catch (Throwable $e) { ecw_log('send_cancel: ' . $e->getMessage()); ecw_fail('Outlook could not be reached to cancel it. Try again in a moment.', 502); }

    case 'signature_sanitize':
        $html = (string)ecw_param('html', '');
        if (strlen($html) > 4000000) ecw_fail('What you pasted is too large for a signature (over 4 MB).');
        ecw_json(['html'=>ecw_sanitize_signature_html($html)]);

    // Each account has its own default signature (lib/account.php).
    case 'signature_profiles_get':
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        ecw_json(ecw_signature_profiles((int)$user['id'], ecw_active_account_id($orgId, (int)$user['id'])));

    case 'signature_profiles_save':
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        try { ecw_json(ecw_signature_profiles_save((int)$user['id'], ecw_input(), ecw_active_account_id($orgId, (int)$user['id']))); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }

    case 'signature_get':
        ecw_json(ecw_signature_resolve((int)$user['id'], $mailboxId > 0 ? $mailboxId : null) ?? []);

    case 'signature_save':
        $id = ecw_signature_save($orgId, (int)$user['id'], $mailboxId > 0 ? $mailboxId : null, ecw_input());
        ecw_json(['signature_id' => $id]);

    case 'cases':
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        // The picker behind "Add to existing Case": by number, name, or a
        // participant address, which is what the product spec asks for.
        $q  = trim((string)ecw_param('q', ''));
        $pickerStatus = (string)ecw_param('status', 'all');
        if (!in_array($pickerStatus, ['open', 'waiting', 'closed', 'all'], true)) $pickerStatus = 'all';
        $like = '%' . $q . '%';
        $params = [$orgId, $pickerStatus, $pickerStatus, $q, $like, $like, $like];
        $st = ecw_db()->prepare(
            'SELECT DISTINCT c.id, c.case_number, c.name, c.status, c.updated_at
               FROM `' . ecw_table('cases') . '` c
               LEFT JOIN `' . ecw_table('case_threads') . '` ct ON ct.case_id = c.id
               LEFT JOIN `' . ecw_table('email_messages') . '` m ON m.email_thread_id = ct.email_thread_id
               LEFT JOIN `' . ecw_table('message_participants') . '` p ON p.email_message_id = m.id
              WHERE c.organization_id = ? AND c.deleted_at IS NULL
                AND (? = "all" OR c.status = ?)
                AND (? = "" OR c.case_number LIKE ? OR c.name LIKE ? OR p.email LIKE ?)
                AND ' . ecw_case_visible_sql('c', (int)$user['id'], $params) . '
                AND ' . ecw_case_account_sql('c', ecw_active_account_id($orgId, (int)$user['id']), $params, (int)$user['id']) . '
              ORDER BY c.updated_at DESC LIMIT 25'
        );
        $st->execute($params);
        ecw_json(['cases' => $st->fetchAll()]);

    case 'case_neighbors':
        // ‹ › on a case opened from outside the Cases list: the case numbers either side you can see.
        $cid = (int)ecw_param('case_id', 0);
        $out = [];
        foreach (['prev' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $k => [$op, $dir]) {
            $p = [$orgId];
            $vis = ecw_case_visible_sql('c', (int)$user['id'], $p);
            require_once dirname(__DIR__, 2) . '/lib/account.php';
            $vis .= ' AND ' . ecw_case_account_sql('c', ecw_active_account_id($orgId, (int)$user['id']), $p, (int)$user['id']);
            $p[] = $cid;
            $st = ecw_db()->prepare('SELECT c.id, c.case_number, c.name FROM `' . ecw_table('cases') . '` c
                                      WHERE c.organization_id = ? AND c.deleted_at IS NULL AND c.merged_into_case_id IS NULL AND ' . $vis . ' AND c.id ' . $op . ' ?
                                      ORDER BY c.id ' . $dir . ' LIMIT 1');
            $st->execute($p);
            $row = $st->fetch();
            $out[$k] = $row ? ['id' => (int)$row['id'], 'case_number' => $row['case_number'], 'name' => $row['name']] : null;
        }
        ecw_json($out);

    case 'case_list':
        // web/cases.php's filter bar, progressively enhanced: same
        // ecw_case_list() the SSR page calls, so a fetch() never disagrees
        // with the page it is updating.
        require_once dirname(__DIR__, 2) . '/lib/account.php';
        $caseAccount = ecw_active_account_id($orgId, (int)$user['id']);
        $caseList = ecw_case_list($orgId, ['user_id' => (int)$user['id'], 'account_id' => $caseAccount] + ecw_case_list_opts_from_get());
        if (!ecw_feature_allowed('sla')) foreach ($caseList['cases'] as &$row) { $row['sla'] = null; }   // a feature off is off in the data too
        unset($row);
        if (!ecw_feature_allowed('next_actions')) foreach ($caseList['cases'] as &$row) { $row['next'] = null; }
        unset($row);
        $caseList['views'] = ecw_case_view_counts($orgId, (int)$user['id'], $caseAccount);
        ecw_json($caseList);

    case 'case_new':
        // Cases → New case: for work that did not start with an email (a call, a visit, a task).
        $in = ecw_input();
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) ecw_fail('Give the case a name (up to 120 characters).');
        try {
            // People, date and group first, then the template, so its steps go to the people chosen here.
            $templateId = (int)($in['template_id'] ?? 0);
            $template = null;
            if ($templateId > 0 && ecw_feature_allowed('case_templates')) {
                require_once dirname(__DIR__, 2) . '/lib/checklists.php';
                $template = ecw_case_template($orgId, $templateId);
                if ($template === null || $template['archived']) ecw_fail('That template is no longer available.');
                $name = ecw_case_template_name($template, $name);
            }
            $caseId = ecw_case_create($orgId, $name, (int)$user['id']);
            $changes = [];
            if (array_key_exists('owner_user_id', $in)) $changes['owner_user_id'] = $in['owner_user_id'] ? (int)$in['owner_user_id'] : null;
            if (!empty($in['handler_user_id'])) $changes['handler_user_id'] = (int)$in['handler_user_id'];
            if (!empty($in['due_at']) && ecw_feature_allowed('case_extras')) $changes['due_at'] = (string)$in['due_at'];
            if ($changes && !ecw_case_update($orgId, $caseId, $changes, (int)$user['id'])) { ecw_case_soft_delete($orgId, $caseId, (int)$user['id']); ecw_fail('Check the person and the date.'); }
            if (!empty($in['group_id'])) ecw_case_set_group($orgId, $caseId, (int)$in['group_id'], (int)$user['id']);
            if ($template !== null) ecw_case_apply_template($orgId, $caseId, $templateId, (int)$user['id']);
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        $newCase = ecw_case_summary($orgId, $caseId);
        ecw_teams_notify($orgId, 'case_created', 'New case ' . $newCase['number'] . ': ' . $newCase['name'],
            ['Created by' => (string)($user['name'] ?: $user['email'])], ecw_teams_case_link($caseId));
        if (($public = ecw_webhook_case($caseId)) !== null) ecw_webhook_emit($orgId, 'case.created', ['case' => $public, 'source' => 'user', 'by' => $user['email']]);
        foreach (['owner_user_id' => 'Responsible', 'handler_user_id' => 'Handled by'] as $field => $role) {
            if (!empty($newCase[$field]) && (int)$newCase[$field] !== (int)$user['id']) {
                ecw_notify($orgId, (int)$newCase[$field], (int)$user['id'], 'assigned', ($user['name'] ?: $user['email']) . ' made you ' . $role . ' on ' . $newCase['number'] . ' ' . $newCase['name'], 'case.php?id=' . $caseId, $caseId);
            }
        }
        ecw_json(['case' => $newCase, 'url' => 'case.php?id=' . $caseId]);

    case 'case_group_set':
        try {
            if (!ecw_case_set_group($orgId, (int)ecw_param('case_id', 0), ((int)ecw_param('group_id', 0)) ?: null, (int)$user['id'])) ecw_fail('not found', 404);
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(['summary' => ecw_case_summary($orgId, (int)ecw_param('case_id', 0))]);

    case 'case_link':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $threadId = (int)ecw_param('thread_id', 0);
        $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
        $st->execute([$threadId, $mailboxId]);
        if ($st->fetchColumn() === false) { ecw_fail('thread not found', 404); }

        $caseId = (int)ecw_param('case_id', 0);
        try {
            if ($caseId <= 0) {
                $name = trim((string)ecw_param('name', ''));
                if ($name === '') { ecw_fail('a new case needs a name'); }
                $templateId = (int)ecw_param('template_id', 0);
                if ($templateId > 0 && ecw_feature_allowed('case_templates')) {
                    // A ready-made case: its steps, tags, due date and group come with it (lib/checklists.php).
                    require_once dirname(__DIR__, 2) . '/lib/checklists.php';
                    $caseId = ecw_case_create_from_template($orgId, (int)$user['id'], $name, $templateId);
                } else {
                    $caseId = ecw_case_create($orgId, $name, (int)$user['id']);
                }
                // The group chosen in "Add to case" (it may also bring its automatic Responsible).
                if (($groupId = (int)ecw_param('group_id', 0)) > 0) {
                    try { ecw_case_set_group($orgId, $caseId, $groupId, (int)$user['id']); } catch (InvalidArgumentException $e) { /* a group removed meanwhile: no group */ }
                }
                $newCase = ecw_case_summary($orgId, $caseId);
                ecw_teams_notify($orgId, 'case_created', 'New case ' . $newCase['number'] . ': ' . $newCase['name'],
                    ['Created by' => (string)($user['name'] ?: $user['email'])], ecw_teams_case_link($caseId));
                if (($public = ecw_webhook_case($caseId)) !== null) {
                    ecw_webhook_emit($orgId, 'case.created', ['case' => $public, 'source' => 'user', 'by' => $user['email']]);
                }
            } else {
                $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
                $st->execute([$caseId, $orgId]);
                if ($st->fetchColumn() === false) { ecw_fail('case not found', 404); }
            }
            ecw_case_link_thread($caseId, $threadId, (string)ecw_param('display_name', '') ?: null, (int)$user['id']);
        } catch (RuntimeException $e) {
            ecw_fail($e->getMessage(), 409);
        }

        $st = ecw_db()->prepare('SELECT id, case_number, name, status FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $st->execute([$caseId]);
        ecw_json(['case' => $st->fetch()]);

    case 'case_unlink':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $threadId = (int)ecw_param('thread_id', 0);
        $st = ecw_db()->prepare(
            'SELECT ct.id, ct.case_id FROM `' . ecw_table('case_threads') . '` ct
               JOIN `' . ecw_table('email_threads') . '` t ON t.id = ct.email_thread_id
              WHERE ct.email_thread_id = ? AND t.mailbox_id = ?'
        );
        $st->execute([$threadId, $mailboxId]);
        $link = $st->fetch();
        if ($link === false || !ecw_case_visible($orgId, (int)$user['id'], (int)$link['case_id'], true)) { ecw_fail('not linked', 404); }
        require_once dirname(__DIR__, 2) . '/lib/security.php';
        if (ecw_case_held((int)$link['case_id'])) { ecw_fail('The case is on legal hold: its emails cannot be taken out of it.', 409); }

        ecw_db()->prepare('DELETE FROM `' . ecw_table('case_threads') . '` WHERE id = ?')->execute([(int)$link['id']]);
        ecw_case_log_event((int)$link['case_id'], 'THREAD_UNLINKED', (int)$user['id'], 'email_thread', $threadId);
        require_once dirname(__DIR__, 2) . '/lib/categories.php';
        ecw_categories_mark_threads([$threadId]);   // its case categories come off in Outlook
        ecw_json(['unlinked' => true]);

    case 'prefs_save':
        $prefs = ecw_user_preferences((int)$user['id']);
        // The case list's layout (assets/case-preview.js): the plain list, or list + preview.
        if (in_array(ecw_param('cases_layout'), ['list', 'split'], true)) $prefs['cases_layout'] = ecw_param('cases_layout');
        foreach (['thread_order', 'show_remote_images'] as $key) {
            $value = ecw_param($key);
            if ($value !== null) {
                $prefs[$key] = $value;
            }
        }
        // The folder panel's order (folder-order.js): which items sit in which list, in order.
        $layout = ecw_input()['nav_layout'] ?? null;
        if ($layout === false || $layout === 'reset') {
            unset($prefs['nav_layout']);
        } elseif (is_array($layout)) {
            $clean = [];
            foreach (['system', 'user'] as $list) {
                $clean[$list] = array_values(array_slice(array_filter((array)($layout[$list] ?? []),
                    static fn($k) => is_string($k) && preg_match('/^(all|snoozed|drafts|f:\d{1,18})$/', $k)), 0, 500));
            }
            $prefs['nav_layout'] = $clean;
        }
        ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')
            ->execute([json_encode($prefs), (int)$user['id']]);
        ecw_json(['preferences' => $prefs]);

    // ------------------------------------------- the case list's preview (assets/case-preview.js)
    case 'case_preview':
        $cid = (int)ecw_param('case_id', 0);
        $sum = ecw_case_summary($orgId, $cid);
        if ($sum === null) ecw_fail('not found', 404);
        $q = static function (string $sql, array $p): array { $s = ecw_db()->prepare($sql); $s->execute($p); return $s->fetchAll(); };
        $mails = $q('SELECT m.id, m.direction, m.from_name, m.from_email, m.occurred_at, m.subject, m.has_attachments, LEFT(m.body_text, 600) AS body
                       FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('case_threads') . "` ct ON ct.email_thread_id = m.email_thread_id
                      WHERE ct.case_id = ? AND m.provider_removed_at IS NULL AND m.direction IN ('incoming', 'outgoing')
                      ORDER BY m.occurred_at DESC, m.id DESC LIMIT 6", [$cid]);
        $counts = $q('SELECT COUNT(DISTINCT m.id) AS emails, COUNT(a.id) AS files FROM `' . ecw_table('email_messages') . '` m
                        JOIN `' . ecw_table('case_threads') . '` ct ON ct.email_thread_id = m.email_thread_id
                        LEFT JOIN `' . ecw_table('email_attachments') . '` a ON a.email_message_id = m.id AND a.is_inline = 0
                       WHERE ct.case_id = ? AND m.provider_removed_at IS NULL', [$cid])[0];
        $notes = $q('SELECT n.body, n.created_at, u.name FROM `' . ecw_table('case_notes') . '` n LEFT JOIN `' . ecw_table('users') . '` u ON u.id = n.user_id
                      WHERE n.deleted_at IS NULL AND n.parent_note_id IS NULL AND (n.case_id = ? OR n.thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?))
                      ORDER BY n.created_at DESC LIMIT 2', [$cid, $cid]);
        $steps = [];
        if (ecw_feature_allowed('checklists')) {
            require_once dirname(__DIR__, 2) . '/lib/checklists.php';
            $steps = array_values(array_filter(ecw_checklist($cid), static fn($s) => empty($s['done_at'])));
        }
        require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
        $snippet = static fn(string $s): string => mb_substr(trim((string)preg_replace('/\s+/u', ' ', $s)), 0, 220);
        ecw_json([
            'case' => $sum,
            'next' => ecw_feature_allowed('next_actions') ? ecw_case_next_get($cid) : null,
            'sla' => ecw_feature_allowed('sla') ? ($sum['sla'] ?? null) : null,
            'emails' => array_map(static fn($m) => ['id' => (int)$m['id'], 'ours' => $m['direction'] === 'outgoing', 'from' => (string)($m['from_name'] ?: $m['from_email']),
                'at' => $m['occurred_at'], 'subject' => (string)$m['subject'], 'snippet' => $snippet((string)$m['body']), 'files' => (bool)$m['has_attachments']], $mails),
            'counts' => ['emails' => (int)$counts['emails'], 'files' => (int)$counts['files'], 'conversations' => (int)$sum['thread_count']],
            'steps' => array_slice($steps, 0, 6), 'steps_open' => count($steps),
            'notes' => array_map(static fn($n) => ['by' => $n['name'], 'at' => $n['created_at'], 'text' => $snippet(html_entity_decode(strip_tags((string)$n['body']), ENT_QUOTES, 'UTF-8'))], $notes),
        ]);

    // ------------------------------------------------------ case timeline

    case 'case_timeline':
        $onlyThread = ecw_param('thread_id');
        $timeline = ecw_case_timeline(
            $orgId,
            (int)ecw_param('case_id', 0),
            (bool)ecw_param('images', false),
            // A thread id, or a module column's key ("chat:12") on the narrow-screen view.
            $onlyThread ? (is_numeric($onlyThread) ? (int)$onlyThread : mb_substr((string)$onlyThread, 0, 100)) : null,
            (int)$user['id'],
            false   // quoted history comes when a bubble's "..." is clicked (api body)
        );
        if ($timeline === null) { ecw_fail('not found', 404); }
        // The draft replies and scheduled emails of each conversation, under its column as in
        // Mail (ecw_thread_drafts), and those scheduled in Outlook itself (ecw_thread_mailbox_scheduled).
        require_once dirname(__DIR__, 2) . '/lib/compose.php';
        require_once dirname(__DIR__, 2) . '/lib/drafts_sync.php';
        foreach ($timeline['columns'] as &$column) {
            if (($column['kind'] ?? 'email') !== 'email') continue;   // module columns say themselves what may be done
            $column['can_write'] = ecw_access_mailbox($orgId, (int)$user['id'], (int)$column['mailbox_id']) !== null;
            $column['drafts'] = ecw_thread_drafts($orgId, (int)$user['id'], (int)$column['thread_id']);
            try { $column['drafts'] = array_merge($column['drafts'], ecw_thread_mailbox_scheduled($orgId, (int)$user['id'], (int)$column['thread_id'])); }
            catch (Throwable $e) { ecw_log('scheduled Outlook emails not read: ' . $e->getMessage()); }
        }
        unset($column);
        if (!ecw_feature_allowed('sla') && isset($timeline['summary'])) $timeline['summary']['sla'] = null;
        // What happens next, references, conversation roles (lib/next_actions.php).
        if (isset($timeline['summary'])) {
            require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
            $timeline['summary']['next'] = ecw_feature_allowed('next_actions') ? ecw_case_next_get((int)ecw_param('case_id', 0)) : null;
        }
        if (!ecw_feature_allowed('next_actions')) foreach ($timeline['columns'] as &$column) { $column['party_role'] = null; }
        unset($column);
        ecw_json($timeline);

    // ------------------------------------------- next step (lib/next_actions.php)
    case 'case_next_save':
        require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
        $in = array_intersect_key(ecw_input(), array_flip(['next_action', 'next_user_id', 'waiting_on', 'review_on']));
        try {
            if (!ecw_case_next_set($orgId, (int)ecw_param('case_id', 0), $in, (int)$user['id'])) ecw_fail('not found', 404);
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        // Given the next step by someone else: worth a bell.
        if (!empty($in['next_user_id']) && (int)$in['next_user_id'] !== (int)$user['id']) {
            $c = ecw_case_summary($orgId, (int)ecw_param('case_id', 0));
            $nextText = ecw_case_next_get((int)ecw_param('case_id', 0))['next_action'];
            if ($c) ecw_notify($orgId, (int)$in['next_user_id'], (int)$user['id'], 'assigned',
                ($user['name'] ?: $user['email']) . ' gave you the next step on ' . $c['number'] . ' ' . $c['name'] . ($nextText ? ': ' . $nextText : ''),
                'case.php?id=' . (int)$c['id'], (int)$c['id']);
        }
        ecw_json(['next' => ecw_case_next_get((int)ecw_param('case_id', 0))]);

    case 'case_next_undo':
        require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
        if (!ecw_case_next_undo($orgId, (int)ecw_param('case_id', 0), (int)$user['id'])) ecw_fail('There is nothing of the AI’s to undo.');
        ecw_json(['next' => ecw_case_next_get((int)ecw_param('case_id', 0))]);

    case 'case_ref_add':
        require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
        try {
            ecw_case_ref_add((int)ecw_param('case_id', 0), (string)(ecw_input()['kind'] ?? 'other'), (string)(ecw_input()['value'] ?? ''), (int)$user['id']);
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(['refs' => ecw_case_refs((int)ecw_param('case_id', 0))]);

    case 'case_ref_delete':
        require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
        ecw_case_ref_delete((int)ecw_param('case_id', 0), (int)(ecw_input()['ref_id'] ?? 0), (int)$user['id']);
        ecw_json(['refs' => ecw_case_refs((int)ecw_param('case_id', 0))]);

    case 'case_update':
        $before = ecw_case_summary($orgId, (int)ecw_param('case_id', 0));
        $caseChanges = ecw_input();
        if (array_key_exists('due_at', $caseChanges) && !ecw_feature_allowed('case_extras')) {
            ecw_json(['error' => 'Due dates are not available for your account.', 'feature_disabled' => 'case_extras'], 403);
        }
        if (!ecw_case_update($orgId, (int)ecw_param('case_id', 0), $caseChanges, (int)$user['id'])) {
            ecw_fail('not found', 404);
        }
        $after = ecw_case_summary($orgId, (int)ecw_param('case_id', 0));
        ecw_case_update_notify($orgId, $before, $after, $user);
        if ($before !== null && $after !== null
            && [$before['status'], $before['owner_user_id'], $before['handler_user_id'] ?? null] !== [$after['status'], $after['owner_user_id'], $after['handler_user_id'] ?? null]
            && ($public = ecw_webhook_case((int)$after['id'])) !== null) {
            ecw_webhook_emit($orgId, 'case.updated', ['case' => $public, 'previous_status' => $before['status'], 'by' => $user['email']]);
        }
        // Being made Responsible / Handled by is worth a bell notification.
        foreach (['owner_user_id' => 'Responsible', 'handler_user_id' => 'Handled by'] as $field => $role) {
            $newId = $after[$field] ?? null;
            if ($newId !== null && $newId !== ($before[$field] ?? null)) {
                ecw_notify($orgId, (int)$newId, (int)$user['id'], 'assigned',
                    ($user['name'] ?: $user['email']) . ' made you ' . $role . ' on ' . $after['number'] . ' ' . $after['name'],
                    'case.php?id=' . (int)$after['id'], (int)$after['id']);
            }
        }
        ecw_json(['summary' => $after]);

    // ----------------------------------------------------- case settings (task 07)

    case 'case_rename':
        $result = ecw_case_rename($orgId, (int)ecw_param('case_id', 0), (string)ecw_param('name', ''), (int)$user['id']);
        if (!$result['ok']) {
            ecw_fail($result['error'], $result['status']);
        }
        ecw_json(['name' => $result['name']]);

    case 'case_tags_save':
        require_once dirname(__DIR__, 2) . '/lib/case_extras.php';
        $tags = ecw_input()['tags'] ?? [];
        ecw_json(['tags' => ecw_case_tags_save((int)ecw_param('case_id', 0), is_array($tags) ? $tags : [], (int)$user['id'])]);

    // Saved filters of the Cases list (lib/saved_filters.php): one's own and the shared ones.
    case 'saved_filters':
    case 'saved_filter_save':
    case 'saved_filter_delete':
        require_once dirname(__DIR__, 2) . '/lib/saved_filters.php';
        $savedId = 0;
        try {
            if ($action === 'saved_filter_save') {
                $in = ecw_input();
                $savedId = ecw_saved_filter_save($orgId, (int)$user['id'], (string)($in['name'] ?? ''), (string)($in['query'] ?? ''), !empty($in['shared']), (int)($in['id'] ?? 0), ecw_is_admin());
            } elseif ($action === 'saved_filter_delete') {
                ecw_saved_filter_delete($orgId, (int)$user['id'], (int)(ecw_input()['id'] ?? 0), ecw_is_admin());
            }
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['filters' => ecw_saved_filters($orgId, (int)$user['id']), 'admin' => ecw_is_admin(), 'id' => $savedId]);

    case 'case_tag_suggestions':
        require_once dirname(__DIR__, 2) . '/lib/case_extras.php';
        ecw_json(['tags' => ecw_org_tags($orgId)]);

    case 'case_history':
        require_once dirname(__DIR__, 2) . '/lib/case_extras.php';
        ecw_json(['history' => ecw_case_history((int)ecw_param('case_id', 0))]);

    case 'case_merge':
        // This case (case_id) into another one (into): the user must see both.
        require_once dirname(__DIR__, 2) . '/lib/case_extras.php';
        $into = (int)ecw_param('into', 0);
        if (!ecw_case_visible($orgId, (int)$user['id'], $into)) { ecw_fail('That case was not found.', 404); }
        try { ecw_json(['case_id' => ecw_case_merge($orgId, (int)ecw_param('case_id', 0), $into, (int)$user['id'])]); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }

    // ---------------------------------------------- checklists and templates (lib/checklists.php)
    case 'case_checklist':
        require_once dirname(__DIR__, 2) . '/lib/checklists.php';
        ecw_json(['items' => ecw_checklist((int)ecw_param('case_id', 0))]);

    case 'case_checklist_add':
    case 'case_checklist_toggle':
    case 'case_checklist_update':
    case 'case_checklist_delete':
    case 'case_checklist_reorder':
        require_once dirname(__DIR__, 2) . '/lib/checklists.php';
        $cid = (int)ecw_param('case_id', 0);
        $itemId = (int)ecw_param('item_id', 0);
        if ($itemId > 0) {
            $item = ecw_checklist_item($orgId, $itemId);
            if ($item === null || (int)$item['case_id'] !== $cid) ecw_fail('not found', 404);
        }
        try {
            $in = ecw_input();
            if ($action === 'case_checklist_add') ecw_checklist_add($orgId, $cid, (int)$user['id'], (string)($in['text'] ?? ''), isset($in['assignee_id']) ? (int)$in['assignee_id'] : null, $in['due_date'] ?? null);
            elseif ($action === 'case_checklist_toggle') ecw_checklist_toggle($orgId, $itemId, (int)$user['id'], !empty($in['done']));
            elseif ($action === 'case_checklist_update') ecw_checklist_update($orgId, $itemId, (int)$user['id'], array_intersect_key($in, ['text' => 1, 'assignee_id' => 1, 'due_date' => 1]));
            elseif ($action === 'case_checklist_delete') ecw_checklist_delete($orgId, $itemId, (int)$user['id']);
            else ecw_checklist_reorder($orgId, $cid, (array)($in['ids'] ?? []));
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage(), 400); }
        ecw_json(['items' => ecw_checklist($cid)]);

    case 'case_templates':
        require_once dirname(__DIR__, 2) . '/lib/checklists.php';
        ecw_json(['templates' => ecw_feature_allowed('case_templates') ? array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'steps' => count($t['steps'])], ecw_case_templates($orgId)) : []]);

    case 'case_template_apply':
        require_once dirname(__DIR__, 2) . '/lib/checklists.php';
        if (!ecw_feature_allowed('case_templates')) ecw_fail('Case templates are not available.', 403);
        try { $r = ecw_case_apply_template($orgId, (int)ecw_param('case_id', 0), (int)ecw_param('template_id', 0), (int)$user['id']); }
        catch (InvalidArgumentException $e) { ecw_fail($e->getMessage(), 400); }
        ecw_json(['applied' => $r, 'items' => ecw_checklist((int)ecw_param('case_id', 0))]);

    case 'case_hold':
        require_once dirname(__DIR__, 2) . '/lib/security.php';
        $r = ecw_case_hold_set($orgId, (int)ecw_param('case_id', 0), (bool)ecw_param('on', false), (string)ecw_param('note', ''), (int)$user['id']);
        if (!$r['ok']) ecw_fail($r['error'], 403);
        ecw_json(['ok' => true, 'held' => ecw_case_held((int)ecw_param('case_id', 0))]);

    case 'case_delete':
        // Soft delete only — see lib/timeline.php:ecw_case_soft_delete(). Never
        // touches case_threads, so the emails it groups stay exactly where they are.
        try {
            if (!ecw_case_soft_delete($orgId, (int)ecw_param('case_id', 0), (int)$user['id'])) {
                ecw_fail('not found', 404);
            }
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage(), 409); }
        ecw_json(['deleted' => true]);

    case 'case_restore':
        if (!ecw_case_restore($orgId, (int)ecw_param('case_id', 0), (int)$user['id'])) {
            ecw_fail('not found', 404);
        }
        ecw_json(['restored' => true]);

    case 'case_thread_update':
        // Whose conversation it is (customer / supplier / internal): part of next steps.
        if (array_key_exists('party_role', ecw_input())) {
            if (!ecw_feature_allowed('next_actions')) ecw_json(['error' => 'This feature is not available for your account.', 'feature_disabled' => 'next_actions'], 403);
            require_once dirname(__DIR__, 2) . '/lib/next_actions.php';
            $role = (string)(ecw_input()['party_role'] ?? '');
            try { ecw_case_thread_role_set((int)ecw_param('case_id', 0), (int)ecw_param('thread_id', 0), $role === '' ? null : $role, (int)$user['id']); }
            catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        }
        $ok = ecw_case_thread_update(
            $orgId,
            (int)ecw_param('case_id', 0),
            (int)ecw_param('thread_id', 0),
            ecw_input()
        );
        if (!$ok) { ecw_fail('not found', 404); }
        $columns = ecw_case_columns((int)ecw_param('case_id', 0));
        foreach ($columns as &$column) {
            $column['can_write'] = ecw_access_mailbox($orgId, (int)$user['id'], (int)$column['mailbox_id']) !== null;
        }
        unset($column);
        ecw_json(['columns' => $columns]);

    case 'case_linkable_threads':
        ecw_json(['threads' => ecw_case_linkable_threads($orgId, (string)ecw_param('q', ''), 25, (int)$user['id'])]);

    case 'case_compose':
        // "Start new email conversation" from inside a case. The subject is
        // tagged so that a reply which loses every other signal can still be
        // matched back — the step's fourth-choice matching rule, not the only one.
        $case = ecw_case_load($orgId, (int)ecw_param('case_id', 0));
        if ($case === null) { ecw_fail('not found', 404); }
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }

        $context = ecw_compose_context($mailboxId, null, 'new', (int)$user['id']);
        $context['case_id']      = (int)$case['id'];
        $context['case_number']  = $case['case_number'];
        $context['subject']      = ecw_case_subject_tag((string)$case['case_number'], '');
        ecw_json($context);

    // ----------------------------------------------- case notes and @mentions

    case 'case_notes':
        $caseNotes = ecw_case_notes((int)ecw_param('case_id', 0), (int)$user['id']);
        ecw_json(['notes' => $caseNotes, 'groups' => ecw_note_groups($caseNotes)]);

    case 'case_note_add':
        $caseId = (int)ecw_param('case_id', 0);
        try {
            $about = (int)ecw_param('message_id', 0);
            $parent = (int)ecw_param('parent_id', 0);
            $added = ecw_case_note_add($orgId, $caseId, (int)$user['id'], (string)ecw_param('body', ''), $about > 0 ? $about : null, $parent > 0 ? $parent : null);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        if (($public = ecw_webhook_case($caseId)) !== null) {
            ecw_webhook_emit($orgId, 'note.added', ['case' => $public, 'author' => $user['email'],
                'text' => mb_strimwidth(ecw_note_plain((string)ecw_param('body', '')), 0, 500, '…')]);
        }
        $caseNotes = ecw_case_notes($caseId, (int)$user['id']);
        ecw_json($added + ['notes' => $caseNotes, 'groups' => ecw_note_groups($caseNotes)]);

    case 'case_note_delete':
    case 'case_note_resolve':
    case 'case_note_seen':
        $caseId = (int)ecw_param('case_id', 0);
        $noteId = (int)ecw_param('note_id', 0);
        $ok = match ($action) {
            'case_note_delete'  => ecw_case_note_delete($caseId, $noteId, (int)$user['id'], ecw_is_admin()),
            'case_note_resolve' => ecw_note_resolve($noteId, (int)$user['id'], (bool)ecw_param('resolved', true), $caseId, null),
            default             => ecw_note_seen($noteId, (int)$user['id'], $caseId, null),
        };
        if (!$ok) { ecw_fail('not found', 404); }
        $caseNotes = ecw_case_notes($caseId, (int)$user['id']);
        ecw_json(['notes' => $caseNotes, 'groups' => ecw_note_groups($caseNotes)]);

    // ------------------------------------------- notes on email conversations

    case 'thread_notes':
    case 'thread_note_add':
    case 'thread_note_delete':
    case 'thread_note_resolve':
    case 'thread_note_seen':
        // Access to the conversation was checked centrally: mailbox_id plus the
        // shared-thread grant (thread_id), so a case member can note on a
        // conversation shared with them.
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $threadId = (int)ecw_param('thread_id', 0);
        $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
        $chk->execute([$threadId, $mailboxId]);
        if (!$chk->fetchColumn()) { ecw_fail('conversation not found', 404); }
        try {
            if ($action === 'thread_note_add') {
                $about = (int)ecw_param('message_id', 0);
                $parent = (int)ecw_param('parent_id', 0);
                $added = ecw_note_add($orgId, (int)$user['id'], (string)ecw_param('body', ''), null, $threadId, $about > 0 ? $about : null, $parent > 0 ? $parent : null);
                if ($added['case_id'] !== null && ($public = ecw_webhook_case($added['case_id'])) !== null) {
                    ecw_webhook_emit($orgId, 'note.added', ['case' => $public, 'author' => $user['email'],
                        'text' => mb_strimwidth(ecw_note_plain((string)ecw_param('body', '')), 0, 500, '…')]);
                }
                $threadNotes = ecw_thread_notes($threadId, (int)$user['id']);
                ecw_json($added + ['notes' => $threadNotes, 'groups' => ecw_note_groups($threadNotes)]);
            }
            $noteId = (int)ecw_param('note_id', 0);
            $ok = match ($action) {
                'thread_note_delete'  => ecw_note_delete($noteId, (int)$user['id'], ecw_is_admin(), null, $threadId),
                'thread_note_resolve' => ecw_note_resolve($noteId, (int)$user['id'], (bool)ecw_param('resolved', true), null, $threadId),
                'thread_note_seen'    => ecw_note_seen($noteId, (int)$user['id'], null, $threadId),
                default               => true,
            };
            if (!$ok) { ecw_fail('not found', 404); }
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        $threadNotes = ecw_thread_notes($threadId, (int)$user['id']);
        ecw_json(['notes' => $threadNotes, 'groups' => ecw_note_groups($threadNotes)]);

    // ---------------------------------------------------------- notifications

    // This workspace's; of the person's other workspaces only how many are unread (their links
    // open there). quiet: the person's quiet hours now, no desktop pop-ups (nav-badges.js).
    case 'notifications':
        $uid = (int)$user['id'];
        $here = ecw_notifications_unread($uid, (int)$orgId);
        ecw_json(['unread' => $here, 'important' => ecw_notifications_unread_important($uid, (int)$orgId),
            'elsewhere' => ecw_notifications_unread($uid) - $here, 'quiet' => ecw_notify_quiet_now($uid),
            'items' => ecw_notifications_list($uid, 40, (int)$orgId)]);

    case 'notifications_read':
        $nid = (int)ecw_param('id', 0);
        $uid = (int)$user['id'];
        ecw_notifications_mark_read($uid, $nid > 0 ? $nid : null, (int)$orgId);
        ecw_json(['unread' => ecw_notifications_unread($uid, (int)$orgId), 'important' => ecw_notifications_unread_important($uid, (int)$orgId)]);

    // ------------------------------------------------- follow-ups and snooze

    case 'case_followups':
        ecw_json(['followups' => ecw_followups_for_case((int)ecw_param('case_id', 0))]);

    case 'case_followup_add':
    case 'followup_add':
        $caseId = $action === 'case_followup_add' ? (int)ecw_param('case_id', 0) : null;
        $threadId = null;
        if ($caseId === null) {
            if ($mailbox === null) { ecw_fail('mailbox_id required'); }
            $threadId = (int)ecw_param('thread_id', 0);
            $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
            $chk->execute([$threadId, $mailboxId]);
            if (!$chk->fetchColumn()) { ecw_fail('thread not found', 404); }
        }
        try {
            ecw_followup_add($orgId, (int)$user['id'], $caseId, $threadId, (string)ecw_param('due', ''),
                (string)ecw_param('note', ''), (bool)ecw_param('if_no_reply', false));
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['followups' => $caseId !== null ? ecw_followups_for_case($caseId) : ecw_followups_for_thread($threadId, (int)$user['id'])]);

    case 'thread_followups':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        ecw_json(['followups' => ecw_followups_for_thread((int)ecw_param('thread_id', 0), (int)$user['id'])]);

    case 'followup_cancel':
        if (!ecw_followup_cancel((int)ecw_param('id', 0), (int)$user['id'])) { ecw_fail('not found', 404); }
        ecw_json(['cancelled' => true]);

    case 'thread_snooze':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $until = (string)ecw_param('until', '');
        try {
            $untilUtc = $until === '' ? null : ecw_followup_parse_due($until);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        if (!ecw_thread_snooze($mailboxId, (int)ecw_param('thread_id', 0), $untilUtc)) { ecw_fail('thread not found', 404); }
        ecw_json(['snoozed_until' => $untilUtc]);

    // -------------------------------------------------------------- templates

    case 'templates':
        ecw_json(['templates' => ecw_templates_list($orgId, (int)$user['id']), 'placeholders' => ECW_TEMPLATE_PLACEHOLDERS]);

    case 'template_save':
        try {
            $tid = ecw_template_save($orgId, (int)$user['id'], ecw_is_admin(), ecw_input());
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['id' => $tid, 'templates' => ecw_templates_list($orgId, (int)$user['id'])]);

    case 'template_delete':
        if (!ecw_template_delete($orgId, (int)$user['id'], ecw_is_admin(), (int)ecw_param('id', 0))) { ecw_fail('not found', 404); }
        ecw_json(['templates' => ecw_templates_list($orgId, (int)$user['id'])]);

    // ------------------------------------------------------------------ rules

    case 'rules':
        // Colour rules follow the "Mail colour rules" switch, automation the "Automation rules" one.
        $canColor = ecw_feature_allowed('rules');
        $canRoute = ecw_is_admin() && ecw_feature_allowed('automation');
        ecw_json([
            'color' => $canColor ? ecw_rules_list($orgId, 'color', (int)$user['id']) : [],
            'route' => $canRoute ? ecw_rules_list($orgId, 'route', null) : [],
            'can_color' => $canColor,
            'can_route' => $canRoute,
            'fields' => ECW_RULE_FIELDS, 'ops' => ECW_RULE_OPS, 'colors' => ECW_RULE_COLORS,
            'field_ops' => ECW_RULE_FIELD_OPS, 'actions' => ECW_RULE_ACTIONS, 'max_actions' => ECW_RULE_MAX_ACTIONS,
        ]);

    case 'rule_save':
        if (!ecw_feature_allowed((ecw_param('kind', '') === 'route') ? 'automation' : 'rules')) {
            ecw_json(['error' => 'This feature is not available for your account.'], 403);
        }
        try {
            $rid = ecw_rule_save($orgId, (int)$user['id'], ecw_is_admin(), ecw_input());
            require_once dirname(__DIR__, 2) . '/lib/audit.php';
            ecw_audit('rule.save', 'rule', $rid, ['kind' => (string)ecw_param('kind', '')]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['id' => $rid]);

    // Settings → Rules → Run on earlier emails (lib/rule_backfill.php): admins, automation.
    case 'rule_backfill_preview':
    case 'rule_backfill_start':
    case 'rule_backfill_status':
    case 'rule_backfill_cancel':
        if (!ecw_is_admin() || !ecw_feature_allowed('automation')) { ecw_fail('Only admins can run automation rules.', 403); }
        require_once dirname(__DIR__, 2) . '/lib/rule_backfill.php';
        $rid = (int)ecw_param('rule_id', 0);
        try {
            if ($action === 'rule_backfill_preview') ecw_json(ecw_rule_backfill_preview($orgId, $rid, (string)ecw_param('from', ''), (string)ecw_param('to', '')));
            if ($action === 'rule_backfill_start') {
                ecw_rule_backfill_start($orgId, (int)$user['id'], $rid, (string)ecw_param('from', ''), (string)ecw_param('to', ''), !empty(ecw_input()['close_cases']));
            }
            if ($action === 'rule_backfill_cancel') ecw_rule_backfill_cancel($orgId, $rid);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['run' => ecw_rule_backfill_status($orgId, $rid)]);

    case 'rule_delete':
        if (!ecw_rule_delete($orgId, (int)$user['id'], ecw_is_admin(), (int)ecw_param('id', 0))) { ecw_fail('not found', 404); }
        require_once dirname(__DIR__, 2) . '/lib/audit.php';
        ecw_audit('rule.delete', 'rule', (int)ecw_param('id', 0));
        ecw_json(['deleted' => true]);

    // --------------------------------------------- presence and suggestions

    case 'presence':
        // Heartbeat while a reply is open. Access to the thread was checked
        // centrally (mailbox_id + the shared-thread grant).
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $threadId = (int)ecw_param('thread_id', 0);
        $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
        $chk->execute([$threadId, $mailboxId]);
        if (!$chk->fetchColumn()) { ecw_fail('thread not found', 404); }
        ecw_param('active', true) ? ecw_presence_beat((int)$user['id'], $threadId) : ecw_presence_clear((int)$user['id'], $threadId);
        ecw_json(['others' => ecw_presence_others($threadId, (int)$user['id'])]);

    case 'case_suggest':
        if ($mailbox === null) { ecw_fail('mailbox_id required'); }
        $own = ecw_db()->prepare('SELECT email_address FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ?');
        $own->execute([$orgId]);
        // Only the AI's confident pick (lib/case_match.php); no AI: no suggestion.
        require_once dirname(__DIR__, 2) . '/lib/case_match.php';
        $suggestThread = (int)ecw_param('thread_id', 0);
        $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
        $chk->execute([$suggestThread, $mailboxId]);
        if (!$chk->fetchColumn()) { ecw_fail('thread not found', 404); }
        $ownAddresses = $own->fetchAll(PDO::FETCH_COLUMN);
        require_once dirname(__DIR__, 2) . '/lib/ai.php';
        $aiOn = ecw_feature_allowed('ai') && !ecw_ai_paused($orgId);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // the AI may take a moment
        ecw_json(['cases' => $aiOn ? ecw_case_match_ai($orgId, (int)$user['id'], $suggestThread, $ownAddresses) : []]);

    // ---------------------------------------------------------- AI and reports

    case 'case_review':
    case 'case_review_apply':
        // The ✨ next to Next step (lib/case_review.php): the case reviewed for support and sales.
        require_once dirname(__DIR__, 2) . '/lib/case_review.php';
        try {
            if ($action === 'case_review_apply') {
                ecw_case_review_apply($orgId, (int)$user['id'], (int)ecw_param('case_id', 0), ecw_input());
                ecw_json(['next' => ecw_case_next_get((int)ecw_param('case_id', 0))]);
            }
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            ecw_json(['review' => ecw_case_review($orgId, (int)$user['id'], (int)ecw_param('case_id', 0), (string)ecw_param('fresh', '') === '1')]);
        } catch (EcwRateLimitExceeded $e) {
            ecw_fail('Too many AI requests. Wait a moment and try again.', 429);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (RuntimeException $e) {
            ecw_fail($e->getMessage(), 502);
        }

    case 'case_deals':
    case 'case_deals_ai':
    case 'case_deal_update':
    case 'case_deal_add':
    case 'case_deal_read':
        // Deals tab (lib/deals.php): RFQ → offer revisions → PO → confirmation → delivery → invoice, selling and buying.
        require_once dirname(__DIR__, 2) . '/lib/deals.php';
        require_once dirname(__DIR__, 2) . '/lib/ai.php';
        $dealCase = (int)ecw_param('case_id', 0);
        try {
            if ($action === 'case_deal_update') {
                ecw_deal_doc_update($orgId, $dealCase, (int)ecw_param('doc_id', 0), (int)$user['id'], (array)(ecw_input()['fields'] ?? []) + (array_key_exists('dismissed', ecw_input()) ? ['dismissed' => (bool)ecw_input()['dismissed']] : []));
            } elseif ($action === 'case_deal_add') {
                ecw_deal_doc_add($orgId, $dealCase, (int)ecw_param('message_id', 0), (int)$user['id'], (string)ecw_param('stage', ''), (string)ecw_param('side', '') ?: null);
            } elseif ($action === 'case_deal_read') {
                // "Choose the email": the clicked email, as the stage chosen, or read by the AI (the rules when it is off).
                $useAi = ecw_feature_allowed('ai') && !ecw_ai_paused($orgId);
                if ($useAi && session_status() === PHP_SESSION_ACTIVE) session_write_close();
                $picked = ecw_deal_doc_read($orgId, $dealCase, (int)ecw_param('message_id', 0), (int)$user['id'], (string)ecw_param('stage', ''), (string)ecw_param('side', '') ?: null, $useAi);
            } elseif ($action === 'case_deals_ai') {
                if (!ecw_feature_allowed('ai')) ecw_fail('The AI assistant is not available for your account.', 403);
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                $read = ecw_deals_ai($orgId, (int)$user['id'], $dealCase, (string)ecw_param('fresh', '') === '1');
            }
            $view = ecw_deals_view($orgId, $dealCase);
            // For "Add a document": the case's emails, newest first (date, who, subject).
            $view['emails'] = array_map(static fn($m) => ['id' => (int)$m['id'], 'date' => $m['occurred_at'], 'direction' => $m['direction'],
                'from' => trim((string)$m['from_name']) ?: (string)$m['from_email'], 'subject' => mb_strimwidth((string)$m['subject'], 0, 90, '…')],
                array_reverse(array_slice(ecw_deal_case_messages($dealCase, 80), -80)));
            $view['ai']['available'] = ecw_feature_allowed('ai') && !ecw_ai_paused($orgId);
            $view['ai']['paused'] = ecw_ai_paused($orgId);
            if (isset($read)) $view['ai']['read'] = $read;
            if (isset($picked)) $view['picked'] = $picked;
            ecw_json($view);
        } catch (EcwRateLimitExceeded $e) {
            ecw_fail('Too many AI requests. Wait a moment and try again.', 429);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (RuntimeException $e) {
            ecw_fail($e->getMessage(), 502);
        }

    case 'case_ai_summary':
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            ecw_json(ecw_ai_case_summary($orgId, (int)$user['id'], (int)ecw_param('case_id', 0)));
        } catch (EcwRateLimitExceeded $e) {
            ecw_fail('Too many assistant requests. Wait a moment and try again.', 429);
        } catch (InvalidArgumentException | RuntimeException $e) {
            ecw_fail($e->getMessage(), 502);
        }

    case 'reports':
        $report = ecw_reports($orgId, (int)$user['id'], $_GET);
        if (!ecw_feature_allowed('sla')) $report['sla'] = ['enabled' => false];
        ecw_json($report);

    case 'reports_csv':
        // The filtered cases as a spreadsheet-ready CSV download.
        $rows = ecw_reports_csv($orgId, (int)$user['id'], $_GET);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cases-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM, so Excel reads UTF-8 (Greek names) correctly
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        exit;

    // --------------------------------------------------------------- webhooks

    case 'webhook_settings':
        if (!ecw_is_admin()) { ecw_fail('admins only', 403); }
        ecw_json(ecw_webhook_settings($orgId));

    case 'webhook_save':
        if (!ecw_is_admin()) { ecw_fail('admins only', 403); }
        $url = ecw_input()['url'] ?? null;
        try {
            ecw_json(ecw_webhook_save($orgId, $url === null ? null : (string)$url, (array)(ecw_input()['events'] ?? [])));
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }

    case 'webhook_test':
        if (!ecw_is_admin()) { ecw_fail('admins only', 403); }
        ecw_webhook_emit($orgId, 'test', ['message' => 'Case Workspace webhook test', 'by' => $user['email']])
            ? ecw_json(['sent' => true])
            : ecw_fail('The test could not be delivered. The address must answer with a 2xx status.', 502);

    // ------------------------------------------------------------ Teams

    case 'teams_status':
        ecw_json(ecw_teams_status((int)$user['id']) + ['notify' => ecw_is_admin() ? ecw_teams_notify_settings($orgId) : null]);

    case 'teams_image':
        // A picture in a Teams message (lib/teams.php ecw_teams_image_urls points <img> here).
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            $img = ecw_teams_image((int)$user['id'], (string)ecw_param('chat_id', ''), (string)ecw_param('message_id', ''), (string)ecw_param('hc', ''));
        } catch (Throwable $e) {
            if (!$e instanceof InvalidArgumentException) ecw_log('teams_image failed: ' . $e->getMessage());
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Picture not available';
            exit;
        }
        header('Content-Type: ' . $img['mime']);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . strlen($img['bytes']));
        header('Content-Disposition: inline; filename="teams-picture.' . (explode('/', $img['mime'])[1] ?? 'png') . '"');
        echo $img['bytes'];
        exit;

    case 'teams_chats':
    case 'teams_messages':
    case 'teams_send':
    case 'teams_chat_with':
    case 'teams_new_chat':
    case 'teams_people':
        // Teams calls run on the user's own token; their Teams permissions decide what they see.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            $uid = (int)$user['id'];
            ecw_json(match ($action) {
                'teams_chats'     => ['chats' => ecw_teams_chats($uid)],
                'teams_messages'  => ['messages' => ecw_teams_messages($uid, (string)ecw_param('chat_id', ''))],
                'teams_send'      => ecw_teams_send($uid, (string)ecw_param('chat_id', ''), (string)ecw_param('text', '')),
                'teams_chat_with' => ['chat' => ecw_teams_chat_with($uid, (string)ecw_param('email', ''))],
                'teams_new_chat'  => ['chat' => ecw_teams_new_chat($uid, (array)(ecw_input()['emails'] ?? []), (string)(ecw_input()['topic'] ?? ''), (string)(ecw_input()['message'] ?? ''))],
                'teams_people'    => ['people' => ecw_teams_people($orgId, $uid)],
            });
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (EcwTeamsNotEnabled $e) {
            ecw_json(['error' => $e->getMessage(), 'teams_disabled' => true], 409);
        } catch (EcwTeamsNoAccess $e) {
            ecw_json(['error' => $e->getMessage(), 'no_access' => true], 403);
        } catch (EcwTeamsOpenInTeams $e) {
            ecw_json(['error' => $e->getMessage(), 'open_in_teams' => $e->url], 409);
        } catch (Throwable $e) {
            ecw_log('teams ' . $action . ' failed: ' . $e->getMessage());
            ecw_fail(ecw_teams_error_message($e), 502);
        }

    case 'teams_notify_save':
        if (!ecw_is_admin()) { ecw_fail('Only admins can change Teams notifications.', 403); }
        $url = ecw_input()['webhook_url'] ?? null;
        try {
            ecw_json(['notify' => ecw_teams_notify_save($orgId, $url === null ? null : (string)$url, (array)(ecw_input()['events'] ?? []))]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }

    case 'teams_notify_test':
        if (!ecw_is_admin()) { ecw_fail('Only admins can change Teams notifications.', 403); }
        $sent = ecw_teams_notify($orgId, 'test', 'Case Workspace is connected to this channel',
            ['Sent by' => (string)($user['name'] ?: $user['email'])]);
        $sent ? ecw_json(['sent' => true]) : ecw_fail('The test message could not be delivered. Check the webhook address.', 502);

    case 'features_save':
        if (!ecw_is_admin()) { ecw_fail('Only admins can change feature access.', 403); }
        ecw_json(['features' => ecw_features_save($orgId, (array)(ecw_input()['enabled'] ?? []))]);

    // ------------------------------------------------- contacts and companies

    case 'contacts_companies':
        require_once dirname(__DIR__, 2) . '/lib/contacts.php';
        ecw_json(['companies' => ecw_companies_list($orgId, (int)$user['id'], (string)ecw_param('q', ''))]);

    case 'contacts_people':
        require_once dirname(__DIR__, 2) . '/lib/contacts.php';
        ecw_json(['people' => ecw_people_list($orgId, (int)$user['id'], (string)ecw_param('q', ''))]);

    case 'contact_company':
        require_once dirname(__DIR__, 2) . '/lib/contacts.php';
        $detail = ecw_company_detail($orgId, (int)$user['id'], (string)ecw_param('domain', ''));
        if ($detail === null) { ecw_fail('not found', 404); }
        ecw_json($detail);

    case 'contact_person':
        require_once dirname(__DIR__, 2) . '/lib/contacts.php';
        $detail = ecw_person_detail($orgId, (int)$user['id'], (string)ecw_param('email', ''));
        if ($detail === null) { ecw_fail('not found', 404); }
        ecw_json($detail);

    case 'contact_company_save':
    case 'contact_person_save':
        require_once dirname(__DIR__, 2) . '/lib/contacts.php';
        try {
            // Only someone who can see mail with them edits their card.
            if ($action === 'contact_company_save') {
                if (ecw_company_detail($orgId, (int)$user['id'], (string)ecw_param('domain', '')) === null) ecw_fail('not found', 404);
                ecw_company_save($orgId, (int)$user['id'], (string)ecw_param('domain', ''), ecw_input());
            } else {
                if (ecw_person_detail($orgId, (int)$user['id'], (string)ecw_param('email', '')) === null) ecw_fail('not found', 404);
                ecw_person_save($orgId, (int)$user['id'], (string)ecw_param('email', ''), ecw_input());
            }
        } catch (InvalidArgumentException $e) { ecw_fail($e->getMessage()); }
        ecw_json(['saved' => true]);

    case 'org_users':
        // Active members only, each with avatar_url (their Outlook photo) or null.
        ecw_json(['users' => ecw_org_users($orgId)]);

    // ------------------------------------------------- case groups and sharing

    case 'case_groups':
        // With the workspace's accounts, for "only emails arriving in" of a group's rules.
        $acc = ecw_db()->prepare('SELECT id, email_address FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ? ORDER BY email_address');
        $acc->execute([$orgId]);
        require_once dirname(__DIR__, 2) . '/lib/group_rules.php';
        // With what each group's AI has learned (Settings → Case groups shows it).
        $groups = array_map(static function (array $g): array { $g['learned'] = ecw_group_rules_learned((int)$g['id']); unset($g['learned']['examples']); return $g; }, ecw_case_groups($orgId, (int)$user['id']));
        ecw_json(['groups' => $groups,
            'accounts' => array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => (string)$r['email_address']], $acc->fetchAll())]);

    case 'case_group_save':
        try {
            $groupId = ecw_case_group_save($orgId, (int)$user['id'], ecw_is_admin(), ecw_input());
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['group_id' => $groupId, 'groups' => ecw_case_groups($orgId, (int)$user['id'])]);

    case 'case_group_delete':
        try {
            ecw_case_group_delete($orgId, (int)$user['id'], ecw_is_admin(), (int)ecw_param('group_id', 0));
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['groups' => ecw_case_groups($orgId, (int)$user['id'])]);

    case 'case_sharing_save':
        try {
            $sharing = ecw_case_sharing_save($orgId, (int)ecw_param('case_id', 0), (int)$user['id'], ecw_input());
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        }
        ecw_json(['sharing' => $sharing]);

    case 'attachment':
        ecw_serve_attachment($orgId, (int)ecw_param('id', 0), (string)ecw_param('inline', '') === '1');

    case 'attachments_zip':
        // "Download all": every (non-inline) attachment of one email, in one .zip.
        ecw_serve_attachments_zip($orgId, (int)$user['id'], (int)ecw_param('message_id', 0));

    default:
        ecw_fail('unknown action', 404);
}

// --------------------------------------------------------------- helpers

function ecw_user_preferences(int $userId): array
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $raw = $st->fetchColumn();
    $prefs = $raw ? json_decode((string)$raw, true) : [];
    return is_array($prefs) ? $prefs + ['thread_order' => 'asc'] : ['thread_order' => 'asc'];
}

/** Fetch one body on demand and store it split into body/quoted. */
/**
 * On-demand body load for a message a reader just opened.
 *
 * Delegates to ecw_store_full_message() rather than repeating the UPDATE: this
 * was a second copy of that statement, and when bulk sync stopped requesting
 * message headers, only one of the two copies would have been taught to store
 * them.
 */
function ecw_sync_single_body(int $mailboxId, int $messageId): void
{
    $st = ecw_db()->prepare(
        'SELECT provider_message_id FROM `' . ecw_table('email_messages') . '` WHERE id = ? AND mailbox_id = ?'
    );
    $st->execute([$messageId, $mailboxId]);
    $providerId = $st->fetchColumn();
    if ($providerId === false) {
        return;
    }

    ecw_store_full_message($mailboxId, $messageId, (string)$providerId);
}

/**
 * Stream an attachment, downloading it from the provider the first time.
 *
 * Cached on disk afterwards, because a reading pane with six inline images
 * would otherwise make six Graph calls every time someone scrolls past it.
 *
 * $inlinePreview is task 04's ?inline=1: same auth/ownership query as a
 * normal download, but if (and only if) finfo confirms the bytes are one of
 * ecw_inline_preview_allowed()'s types, it's served with an inline
 * Content-Disposition and a sandboxed CSP so the overlay can render it
 * in-page. Anything else falls through to today's ordinary attachment
 * response, unchanged.
 */
/**
 * A file that cannot be served. A link opened in the browser (a download) gets a short
 * page in words, the viewer and the scripts the JSON they read.
 */
function ecw_attachment_fail(string $code, int $status, string $text): never
{
    if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') ecw_fail($code, $status);
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>File not available</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f8fa;color:#0d0d0d;'
        . 'font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}main{max-width:420px;margin:16px;padding:24px;'
        . 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;text-align:center}h1{font-size:18px;margin:0 0 8px}p{margin:0 0 16px;color:#4b5563}'
        . 'a{color:#0057ff}@media(prefers-color-scheme:dark){body{background:#111318;color:#e8eaed}main{background:#1b1e24;border-color:#2c313a}p{color:#a5acb8}a{color:#7aa7ff}}</style></head>'
        . '<body><main><h1>File not available</h1><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<a href="javascript:history.back()">Go back</a></main></body></html>';
    exit;
}

function ecw_serve_attachment(int $organizationId, int $attachmentId, bool $inlinePreview = false): never
{
    $st = ecw_db()->prepare(
        'SELECT a.*, m.provider_message_id, m.mailbox_id
           FROM `' . ecw_table('email_attachments') . '` a
           JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
          WHERE a.id = ? AND mb.organization_id = ?'
    );
    $st->execute([$attachmentId, $organizationId]);
    $attachment = $st->fetch();
    if ($attachment === false || !ecw_access_message($organizationId, (int)ecw_require_user()['id'], (int)$attachment['email_message_id'])) {
        ecw_attachment_fail('not found', 404, 'This file is not here any more, or you cannot open it.');
    }
    if (!$inlinePreview && (int)($attachment['is_inline'] ?? 0) === 0) {
        require_once dirname(__DIR__, 2) . '/lib/audit.php';
        ecw_audit_view('attachment.download', 'attachment', $attachmentId, ['file' => (string)$attachment['filename'], 'email' => (int)$attachment['email_message_id']]);
    }

    $path = (string)($attachment['local_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        try {
            $bytes = ecw_download_attachment($attachment);
        } catch (Throwable $e) {
            ecw_log('attachment download failed: ' . $e->getMessage());
            ecw_attachment_fail('attachment unavailable', 502, 'This file could not be fetched from the mailbox. It may have been deleted in Outlook or Gmail. Try again in a minute.');
        }

        $dir  = dirname(__DIR__, 2) . '/storage/attachments';
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
        $path = $dir . '/' . (int)$attachment['id'] . '_' . bin2hex(random_bytes(4));
        if (is_dir($dir) && is_writable($dir) && @file_put_contents($path, $bytes) !== false) {
            ecw_db()->prepare('UPDATE `' . ecw_table('email_attachments') . "`
                SET local_path = ?, provider_download_state = 'downloaded' WHERE id = ?")
                ->execute([$path, (int)$attachment['id']]);
        } else {
            // Serve it anyway; only the cache failed.
            if ($inlinePreview) {
                $detected = (string)((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream');
                if (ecw_inline_preview_allowed($detected)) {
                    header('Content-Type: ' . $detected);
                    header('Content-Length: ' . (string)strlen($bytes));
                    header('Content-Disposition: ' . ecw_content_disposition('inline', (string)$attachment['filename']));
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Security-Policy: sandbox');
                    echo $bytes;
                    exit;
                }
            }
            $type = (string)$attachment['mime_type'];
            $image = preg_match('#^image/(png|jpeg|gif|webp|avif)$#iD', $type) === 1;
            header('Content-Type: ' . ($image ? $type : 'application/octet-stream'));
            header('Content-Length: ' . (string)strlen($bytes));
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: ' . ecw_content_disposition($image && (int)$attachment['is_inline'] === 1 ? 'inline' : 'attachment', (string)$attachment['filename']));
            echo $bytes;
            exit;
        }
    }

    if ($inlinePreview) {
        $detected = (string)((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream');
        if (ecw_inline_preview_allowed($detected)) {
            header('Content-Type: ' . $detected);
            header('Content-Length: ' . (string)filesize($path));
            header('Content-Disposition: ' . ecw_content_disposition('inline', (string)$attachment['filename']));
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: sandbox');
            readfile($path);
            exit;
        }
    }

    // Content-Disposition attachment, and a neutered content type for anything
    // scriptable: an attachment is untrusted bytes from the internet, and
    // serving it inline from our own origin would run it in our session.
    $mime = (string)($attachment['mime_type'] ?: 'application/octet-stream');
    if (preg_match('#^(text/html|image/svg|application/xhtml)#i', $mime)) {
        $mime = 'application/octet-stream';
    }
    $inline = (int)$attachment['is_inline'] === 1 && str_starts_with($mime, 'image/');

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: ' . ecw_content_disposition($inline ? 'inline' : 'attachment', (string)$attachment['filename']));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/** A local file holding the attachment's bytes: the cache, or a fresh download into it. */
function ecw_attachment_file(array $attachment): string
{
    $path = (string)($attachment['local_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        return $path;
    }
    $bytes = ecw_download_attachment($attachment);
    $dir = dirname(__DIR__, 2) . '/storage/attachments';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    $path = $dir . '/' . (int)$attachment['id'] . '_' . bin2hex(random_bytes(4));
    if (@file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('attachment cache is not writable');
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('email_attachments') . "` SET local_path = ?, provider_download_state = 'downloaded' WHERE id = ?")
        ->execute([$path, (int)$attachment['id']]);
    return $path;
}

function ecw_serve_attachments_zip(int $organizationId, int $userId, int $messageId): never
{
    if (!class_exists('ZipArchive')) { ecw_fail('Download all is not available on this server.', 501); }
    if ($messageId <= 0 || !ecw_access_message($organizationId, $userId, $messageId)) { ecw_fail('not found', 404); }
    require_once dirname(__DIR__, 2) . '/lib/audit.php';
    ecw_audit('attachments.zip', 'email', $messageId);
    $st = ecw_db()->prepare(
        'SELECT a.*, m.provider_message_id, m.mailbox_id, m.subject
           FROM `' . ecw_table('email_attachments') . '` a
           JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
          WHERE a.email_message_id = ? AND mb.organization_id = ? AND a.is_inline = 0
          ORDER BY a.id'
    );
    $st->execute([$messageId, $organizationId]);
    $rows = $st->fetchAll();
    if ($rows === []) { ecw_fail('This email has no attachments.', 404); }
    if (array_sum(array_map(static fn($r) => (int)$r['size_bytes'], $rows)) > 300 * 1024 * 1024) {
        ecw_fail('These attachments are too large to download together. Download them one by one.', 413);
    }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    @set_time_limit(300);

    $zipPath = tempnam(sys_get_temp_dir(), 'ecwzip');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { ecw_fail('The zip file could not be created.', 500); }
    $used = [];
    $failed = 0;
    foreach ($rows as $row) {
        // Plain, unique names inside the archive: no folders, no "../".
        $name = trim(preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', (string)$row['filename']) ?? '', ' .') ?: 'attachment';
        $base = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION);
        for ($n = 2; isset($used[mb_strtolower($name)]); $n++) { $name = $base . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : ''); }
        $used[mb_strtolower($name)] = true;
        try { $zip->addFile(ecw_attachment_file($row), $name); }
        catch (Throwable $e) { $failed++; ecw_log('zip: attachment ' . $row['id'] . ' unavailable: ' . $e->getMessage()); }
    }
    if ($failed > 0) { $zip->addFromString('NOT INCLUDED.txt', $failed . " attachment(s) could not be downloaded from the mailbox. Try them one by one.\r\n"); }
    $zip->close();

    $subject = rtrim(trim((string)preg_replace('/^\s*((re|fw|fwd|aw|σχετ)\s*:\s*)+/iu', '', (string)($rows[0]['subject'] ?? ''))), ' .') ?: 'email';
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Content-Disposition: ' . ecw_content_disposition('attachment', 'Attachments - ' . mb_substr($subject, 0, 80) . '.zip'));
    header('X-Content-Type-Options: nosniff');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

/**
 * Content-Disposition with the real file name, so a download is saved as
 * "Offer.xlsx" and never as "index.php". An ASCII fallback for old clients plus
 * RFC 5987 filename* (UTF-8) for Greek and other non-ASCII names. Quotes,
 * slashes and control characters are removed: the name comes from email.
 */
function ecw_content_disposition(string $type, string $filename): string
{
    // Also what Windows cannot have in a file name (: * ? < > |).
    $name = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', $filename) ?? '');
    if ($name === '') { $name = 'attachment'; }
    $ascii = preg_replace('/[^\x20-\x7E]+/', '_', $name);
    if (trim((string)$ascii, '_ .') === '') {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ascii = 'attachment' . (preg_match('/^[A-Za-z0-9]{1,10}$/', $ext) ? '.' . $ext : '');
    }
    return $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}
