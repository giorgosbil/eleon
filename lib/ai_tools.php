<?php declare(strict_types=1);
/**
 * Tools the email assistant can use (lib/ai_agent.php), the same for every
 * AI provider: Claude and OpenAI-compatible endpoints (Gemma on Ollama…).
 *
 * These tools read, and see only what the asking user may see: their
 * mailboxes, plus the conversations of cases visible to them (the same rule
 * as Contacts). Email text in results is untrusted data for the model.
 *
 *   search_emails   find emails by sender, words, dates, folder, case…
 *   count_emails    totals, per month / year / sender / folder
 *   read_email      one email in full
 *   list_files      attachments of matching emails
 *   find_amounts    money amounts written in matching emails (estimates)
 *   get_case        one case: status, people, latest emails, files
 *   list_cases      cases by status / words / overdue
 * And (lib/ai_actions.php): open an email or a case, make a spreadsheet, list
 * what is left unanswered, and PROPOSE changes the person confirms.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/contacts.php';

/** JSON-schema pieces shared by the email filters. */
function ecw_ai_tool_filter_schema(): array
{
    return [
        'from' => ['type' => 'string', 'description' => 'Sender name, address or domain, e.g. "microsoft" or "nikos@acme.gr".'],
        'to' => ['type' => 'string', 'description' => 'Recipient name, address or domain.'],
        'subject' => ['type' => 'string', 'description' => 'Words that must be in the subject.'],
        'any_words' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8, 'description' => 'Up to 8 single words; an email matches if it contains ANY of them (subject, text, sender, file names). Add synonyms in English and Greek, for example invoice, τιμολόγιο, receipt, απόδειξη.'],
        'all_words' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5, 'description' => 'Up to 5 words that must ALL be in the email.'],
        'after' => ['type' => 'string', 'description' => 'Only emails on or after this date, YYYY-MM-DD.'],
        'before' => ['type' => 'string', 'description' => 'Only emails before this date, YYYY-MM-DD.'],
        'direction' => ['type' => 'string', 'enum' => ['received', 'sent'], 'description' => 'received = sent to us, sent = sent by us.'],
        'has_attachments' => ['type' => 'boolean'],
        'folder' => ['type' => 'string', 'description' => 'Folder name or type, e.g. Inbox, Sent Items, Archive.'],
        'case' => ['type' => 'string', 'description' => 'Case number such as CASE-00002 or "2", or words of the case name.'],
    ];
}

function ecw_ai_tool_defs(): array
{
    $f = ecw_ai_tool_filter_schema();
    return array_merge([
        ['name' => 'search_emails', 'description' => 'Find emails. Combine filters; omit the ones you do not need. Returns the newest matches first with a short snippet and the total number of matches.',
            'schema' => ['type' => 'object', 'properties' => $f + [
                'sort' => ['type' => 'string', 'enum' => ['newest', 'oldest']],
                'limit' => ['type' => 'integer', 'description' => '1-25, default 10.']]]],
        ['name' => 'count_emails', 'description' => 'Count emails matching the filters, optionally grouped. Use this for "how many" questions instead of counting search results.',
            'schema' => ['type' => 'object', 'properties' => $f + [
                'group_by' => ['type' => 'string', 'enum' => ['none', 'month', 'year', 'sender', 'sender_domain', 'folder']]]]],
        ['name' => 'read_email', 'description' => 'Read one email in full (text up to ~5000 characters, recipients, attachments). Use the ref from search results, e.g. "M123".',
            'schema' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string']], 'required' => ['ref']]],
        ['name' => 'read_file', 'description' => 'Read the text inside one attached file (PDF, Word, Excel, PowerPoint, text), e.g. to check an invoice, a quote or a drawing list. Use the file ref from list_files or read_email, e.g. "F42". Scanned images have no text.',
            'schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string']], 'required' => ['file']]],
        ['name' => 'list_files', 'description' => 'List the files (attachments) of emails matching the filters, newest first. The user sees them as download cards.',
            'schema' => ['type' => 'object', 'properties' => $f + [
                'filename' => ['type' => 'string', 'description' => 'Part of the file name or an extension, e.g. ".pdf" or "quote".'],
                'limit' => ['type' => 'integer', 'description' => '1-60, default 30.']]]],
        ['name' => 'find_amounts', 'description' => 'Find money amounts written in the text of matching emails (e.g. invoices, receipts, quotes) and add up the most likely total of each email per currency. Amounts that exist only inside PDF attachments are not seen. Always say these are estimates from the email text.',
            'schema' => ['type' => 'object', 'properties' => $f + [
                'limit' => ['type' => 'integer', 'description' => 'How many emails to scan, 1-80, default 40.']]]],
        ['name' => 'get_case', 'description' => 'One case: number, name, status, owner, due date, its conversations, latest emails, file count and open notes.',
            'schema' => ['type' => 'object', 'properties' => ['case' => ['type' => 'string', 'description' => 'Case number like CASE-00002 or "2".']], 'required' => ['case']]],
        ['name' => 'list_cases', 'description' => 'List cases, newest activity first.',
            'schema' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => ['open', 'waiting', 'closed', 'any']],
                'words' => ['type' => 'string', 'description' => 'Words in the case name or number.'],
                'overdue' => ['type' => 'boolean', 'description' => 'Only cases past their due date.']]]],
    ], array_values(array_filter(ecw_ai_action_tool_defs($f), static fn($t) => ecw_ai_actions_on() || !in_array($t['name'], ECW_AI_ACTING_TOOLS, true))));
}

/** The assistant's tools that make a file or propose a change: Feature access → "AI assistant: actions and files". */
const ECW_AI_ACTING_TOOLS = ['make_spreadsheet', 'create_case', 'add_to_case', 'update_case', 'add_note', 'remind_me', 'reply'];

function ecw_ai_actions_on(): bool
{
    // Without a signed-in person (tests, scripts) the API's own check is the gate.
    return !function_exists('ecw_context') || ecw_context() === null || ecw_feature_allowed('ai_actions');
}

/** The tools that open, make files or propose changes (lib/ai_actions.php). */
function ecw_ai_action_tool_defs(array $f): array
{
    $where = ['type' => 'string', 'enum' => ['here', 'new_tab', 'window'], 'description' => 'here (default), a new browser tab, or a window over the page.'];
    $refs = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20, 'description' => 'Email refs from earlier results, e.g. ["M12","M40"]; each brings its whole conversation.'];
    return [
        ['name' => 'list_unanswered', 'description' => 'Emails sent TO the person (not a copy) that ask something and that nobody here answered yet, newest first. For "what did I miss", "what is waiting for my reply", "what did I leave behind".',
            'schema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'description' => 'Waiting at least this many days, default 1 (0 = today too).']]]],
        ['name' => 'open_email', 'description' => 'Open an email (its conversation) for the person to see now. Use when they ask to open, show or see an email.',
            'schema' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'where' => $where], 'required' => ['ref']]],
        ['name' => 'open_case', 'description' => 'Open a case page for the person.',
            'schema' => ['type' => 'object', 'properties' => ['case' => ['type' => 'string', 'description' => 'Case number or name.'], 'where' => $where], 'required' => ['case']]],
        ['name' => 'make_spreadsheet', 'description' => 'Make an Excel (.xlsx) or CSV file the person downloads. source "emails" (with the email filters), "cases" (status / words / overdue), or "rows" (a table you put together yourself from what you read: give headers and rows, numbers as numbers, dates as YYYY-MM-DD).',
            'schema' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'], 'format' => ['type' => 'string', 'enum' => ['xlsx', 'csv']],
                'source' => ['type' => 'string', 'enum' => ['emails', 'cases', 'rows']],
                'filters' => ['type' => 'object', 'properties' => $f, 'description' => 'For source emails.'],
                'status' => ['type' => 'string', 'enum' => ['open', 'waiting', 'closed', 'any']], 'words' => ['type' => 'string'], 'overdue' => ['type' => 'boolean'],
                'headers' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => ['string', 'number', 'null']]]]], 'required' => ['title', 'source']]],
        ['name' => 'create_case', 'description' => 'PROPOSE a new case (the person confirms it). Give a short name, the emails it is about, and if asked a group, a Responsible (a colleague name or "me"), a due date and a note.',
            'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'emails' => $refs, 'group' => ['type' => 'string'], 'responsible' => ['type' => 'string'],
                'due' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'note' => ['type' => 'string']], 'required' => ['name']]],
        ['name' => 'add_to_case', 'description' => 'PROPOSE adding emails (their conversations) to an existing case.',
            'schema' => ['type' => 'object', 'properties' => ['case' => ['type' => 'string'], 'emails' => $refs], 'required' => ['case', 'emails']]],
        ['name' => 'update_case', 'description' => 'PROPOSE changing a case: status (open, waiting, closed), due date (YYYY-MM-DD, or null for none), Responsible, group, next step.',
            'schema' => ['type' => 'object', 'properties' => ['case' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['open', 'waiting', 'closed']],
                'due' => ['type' => ['string', 'null']], 'responsible' => ['type' => 'string'], 'group' => ['type' => 'string'], 'next_step' => ['type' => 'string']], 'required' => ['case']]],
        ['name' => 'add_note', 'description' => 'PROPOSE an internal note (colleagues see it, the customer never does) on a case or on a conversation.',
            'schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'case' => ['type' => 'string'], 'email' => ['type' => 'string']], 'required' => ['text']]],
        ['name' => 'remind_me', 'description' => 'PROPOSE a reminder for the person at a date and time, optionally about an email or a case, optionally only if nobody replied by then.',
            'schema' => ['type' => 'object', 'properties' => ['when' => ['type' => 'string', 'description' => 'YYYY-MM-DDTHH:MM in the person\'s own time zone (the one in "Today is …").'], 'text' => ['type' => 'string'],
                'email' => ['type' => 'string'], 'case' => ['type' => 'string'], 'only_if_no_reply' => ['type' => 'boolean']], 'required' => ['when']]],
        ['name' => 'reply', 'description' => 'PROPOSE opening a reply to an email in the composer, with a text you wrote for the person to check and send themselves. Nothing is sent.',
            'schema' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'text' => ['type' => 'string'], 'mode' => ['type' => 'string', 'enum' => ['reply', 'reply_all']]], 'required' => ['ref']]],
    ];
}

/** Human description of a tool call, for the "thinking" steps the user sees. */
function ecw_ai_tool_label(string $name, array $a): string
{
    $bits = [];
    foreach (['from' => 'from', 'to' => 'to', 'subject' => 'subject', 'case' => 'case', 'folder' => 'in', 'filename' => 'named'] as $k => $w) {
        if (!empty($a[$k]) && is_string($a[$k])) $bits[] = $w . ' “' . mb_substr($a[$k], 0, 40) . '”';
    }
    foreach (['any_words', 'all_words'] as $k) {
        if (!empty($a[$k]) && is_array($a[$k])) $bits[] = '“' . mb_substr(implode(', ', array_slice(array_map('strval', $a[$k]), 0, 5)), 0, 60) . '”';
    }
    if (!empty($a['after'])) $bits[] = 'since ' . $a['after'];
    if (!empty($a['before'])) $bits[] = 'before ' . $a['before'];
    if (($a['direction'] ?? '') !== '') $bits[] = $a['direction'];
    if (!empty($a['has_attachments'])) $bits[] = 'with attachments';
    $what = $bits ? ' ' . implode(' · ', $bits) : '';
    return match ($name) {
        'search_emails' => 'Searching emails' . $what,
        'count_emails' => 'Counting emails' . $what . (!empty($a['group_by']) && $a['group_by'] !== 'none' ? ' per ' . str_replace('_', ' ', $a['group_by']) : ''),
        'read_email' => 'Reading email ' . (string)($a['ref'] ?? ''),
        'read_file' => 'Reading file ' . (string)($a['file'] ?? ''),
        'list_files' => 'Listing files' . $what,
        'find_amounts' => 'Looking for amounts' . $what,
        'get_case' => 'Opening case ' . (string)($a['case'] ?? ''),
        'list_cases' => 'Listing cases' . (!empty($a['status']) ? ' · ' . $a['status'] : '') . (!empty($a['overdue']) ? ' · overdue' : '') . (!empty($a['words']) ? ' · “' . $a['words'] . '”' : ''),
        'list_unanswered' => 'Looking for emails to you still unanswered',
        'open_email' => 'Opening email ' . (string)($a['ref'] ?? ''),
        'open_case' => 'Opening case ' . (string)($a['case'] ?? ''),
        'make_spreadsheet' => 'Making the ' . (($a['format'] ?? 'xlsx') === 'csv' ? 'CSV' : 'Excel') . ' file “' . mb_substr((string)($a['title'] ?? ''), 0, 50) . '”',
        'create_case' => 'Preparing a new case “' . mb_substr((string)($a['name'] ?? ''), 0, 50) . '”',
        'add_to_case' => 'Preparing to add to ' . (string)($a['case'] ?? 'a case'),
        'update_case' => 'Preparing changes to ' . (string)($a['case'] ?? 'a case'),
        'add_note' => 'Preparing a note',
        'remind_me' => 'Preparing a reminder',
        'reply' => 'Preparing a reply to ' . (string)($a['ref'] ?? ''),
        default => $name,
    };
}

// ------------------------------------------------------------ filters → SQL

function ecw_ai_like(string $v): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%';
}

/** The visible-mail scope plus the filters, for alias m (messages) and mb (mailboxes). */
function ecw_ai_tool_where(int $orgId, int $userId, array $a, array &$params): string
{
    $w = [ecw_contacts_scope_sql($orgId, $userId, $params), 'm.provider_removed_at IS NULL'];
    $str = static fn($k) => isset($a[$k]) && is_scalar($a[$k]) ? mb_substr(trim((string)$a[$k]), 0, 120) : '';
    $wordSql = static function (string $word, array &$p): string {
        $like = ecw_ai_like($word);
        array_push($p, $like, $like, $like, $like, $like, $like, $like);
        // HTML-only emails not yet given a text version: search their HTML. Files: name and the text inside.
        return '(m.subject LIKE ? OR m.body_text LIKE ? OR ((m.body_text IS NULL OR m.body_text = \'\') AND m.body_html LIKE ?) OR m.from_name LIKE ? OR m.from_email LIKE ?'
            . ' OR EXISTS (SELECT 1 FROM `' . ecw_table('email_attachments') . '` wa WHERE wa.email_message_id = m.id AND (wa.filename LIKE ? OR wa.text_content LIKE ?)))';
    };
    if (($v = $str('from')) !== '') { $w[] = '(m.from_email LIKE ? OR m.from_name LIKE ?)'; array_push($params, ecw_ai_like($v), ecw_ai_like($v)); }
    if (($v = $str('to')) !== '') {
        $w[] = 'EXISTS (SELECT 1 FROM `' . ecw_table('message_participants') . "` tp WHERE tp.email_message_id = m.id AND tp.role IN ('to','cc','bcc') AND (tp.email LIKE ? OR tp.name LIKE ?))";
        array_push($params, ecw_ai_like($v), ecw_ai_like($v));
    }
    if (($v = $str('subject')) !== '') { $w[] = 'm.subject LIKE ?'; $params[] = ecw_ai_like($v); }
    // Small models send lists oddly: one string "a, b", or '["a","b"]' jammed into one item. Accept all of it.
    $words = static function ($k) use ($a): array {
        $raw = $a[$k] ?? [];
        $items = [];
        foreach (is_array($raw) ? $raw : [$raw] as $x) {
            if (!is_scalar($x)) continue;
            foreach (preg_split('/\s*(?:\\\\?"\s*,\s*\\\\?"|,|;|\|)\s*/u', (string)$x) ?: [] as $piece) {
                $piece = trim($piece, " \t\n\r\"'\\[]");
                if (mb_strlen($piece) >= 2) $items[] = mb_substr($piece, 0, 60);
            }
        }
        return array_slice(array_values(array_unique($items)), 0, 12);
    };
    if ($any = $words('any_words')) { $or = []; foreach ($any as $x) $or[] = $wordSql($x, $params); $w[] = '(' . implode(' OR ', $or) . ')'; }
    foreach ($words('all_words') as $x) $w[] = $wordSql($x, $params);
    foreach (['after' => '>=', 'before' => '<'] as $k => $op) {
        // The person's days (the same "today" the assistant is told), as UTC times.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str($k))) { require_once __DIR__ . '/timezone.php'; $w[] = "m.occurred_at {$op} ?"; $params[] = ecw_tz_to_utc($str($k) . ' 00:00:00', ecw_tz_for_user($userId, $orgId)) ?? $str($k) . ' 00:00:00'; }
    }
    if ($str('direction') === 'received') $w[] = "m.direction <> 'outgoing'";
    if ($str('direction') === 'sent') $w[] = "m.direction = 'outgoing'";
    if (array_key_exists('has_attachments', $a) && $a['has_attachments'] !== null) $w[] = 'm.has_attachments = ' . (!empty($a['has_attachments']) ? '1' : '0');
    if (($v = $str('folder')) !== '') {
        $w[] = 'm.mail_folder_id IN (SELECT id FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = m.mailbox_id AND (name LIKE ? OR folder_type LIKE ?))';
        array_push($params, ecw_ai_like($v), ecw_ai_like(str_replace(' ', '', $v)));
    } elseif (empty($a['_any_folder'])) {
        // Deleted and junk mail only when asked for by folder.
        $w[] = 'NOT EXISTS (SELECT 1 FROM `' . ecw_table('mail_folders') . "` xf WHERE xf.id = m.mail_folder_id AND (xf.folder_type IN ('deleteditems','junkemail','deleted','junk','trash','spam')))";
    }
    if (($v = $str('case')) !== '') {
        $num = preg_match('/(\d{1,7})\s*$/', $v, $mm) ? (int)$mm[1] : 0;
        $w[] = 'm.email_thread_id IN (SELECT ct.email_thread_id FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` cc ON cc.id = ct.case_id
                 WHERE cc.organization_id = ? AND cc.deleted_at IS NULL AND (' . ($num > 0 ? 'REGEXP_SUBSTR(cc.case_number, \'[0-9]+$\') + 0 = ? OR ' : '') . 'cc.name LIKE ?))';
        $params[] = $orgId;
        if ($num > 0) $params[] = $num;
        $params[] = ecw_ai_like($v);
    }
    return implode(' AND ', $w);
}

function ecw_ai_tool_from_sql(): string
{
    return ' FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id ';
}

/** Short, model-friendly form of an email row; also remembered for the UI's citations. */
function ecw_ai_tool_brief(array $r, array &$ctx, int $snippet = 220): array
{
    $id = (int)$r['id'];
    $from = trim((string)$r['from_name']) !== '' && !str_contains((string)$r['from_name'], '@') ? $r['from_name'] . ' <' . $r['from_email'] . '>' : (string)$r['from_email'];
    $plain = trim((string)($r['body_text'] ?? ''));
    $text = trim((string)preg_replace('/\s+/u', ' ', $plain !== '' ? $plain : ecw_ai_html_text((string)($r['body_html'] ?? ''))));
    $ctx['seen'][$id] = ['id' => $id, 'label' => 'M' . $id, 'thread_id' => (int)$r['email_thread_id'], 'mailbox_id' => (int)$r['mailbox_id'],
        'subject' => (string)$r['subject'], 'date' => (string)$r['occurred_at'],
        'from' => trim((string)$r['from_name']) !== '' && !str_contains((string)$r['from_name'], '@') ? (string)$r['from_name'] : (string)$r['from_email']];
    return ['ref' => 'M' . $id, 'date' => substr((string)$r['occurred_at'], 0, 16), 'direction' => $r['direction'] === 'outgoing' ? 'sent' : 'received',
        'from' => mb_substr($from, 0, 120), 'subject' => mb_substr((string)$r['subject'], 0, 200),
        'snippet' => $text === '' ? '(text not downloaded yet)' : mb_substr($text, 0, $snippet)];
}

// ---------------------------------------------------------------- the tools

/**
 * Run one tool for $ctx = ['org' => , 'user' => , 'seen' => [], 'files' => []].
 * Returns a JSON-able array for the model.
 */
function ecw_ai_tool_run(array &$ctx, string $name, array $a): array
{
    $org = (int)$ctx['org']; $user = (int)$ctx['user'];
    $db = ecw_db();
    switch ($name) {
        case 'search_emails': {
            $params = [];
            $where = ecw_ai_tool_where($org, $user, $a, $params);
            $limit = max(1, min(25, (int)($a['limit'] ?? 10)));
            $order = ($a['sort'] ?? '') === 'oldest' ? 'ASC' : 'DESC';
            $st = $db->prepare('SELECT COUNT(*)' . ecw_ai_tool_from_sql() . 'WHERE ' . $where);
            $st->execute($params);
            $total = (int)$st->fetchColumn();
            $st = $db->prepare('SELECT m.id, m.email_thread_id, m.mailbox_id, m.subject, m.from_name, m.from_email, m.direction, m.occurred_at, m.has_attachments, LEFT(m.body_text, 1500) AS body_text, IF(m.body_text IS NULL OR m.body_text = \'\', LEFT(m.body_html, 40000), NULL) AS body_html'
                . ecw_ai_tool_from_sql() . 'WHERE ' . $where . " ORDER BY m.occurred_at {$order}, m.id {$order} LIMIT {$limit}");
            $st->execute($params);
            $rows = $st->fetchAll();
            $files = ecw_ai_tool_filenames(array_map(static fn($r) => (int)$r['id'], $rows));
            $emails = array_map(static function ($r) use (&$ctx, $files) {
                $b = ecw_ai_tool_brief($r, $ctx);
                if (!empty($files[(int)$r['id']])) $b['attachments'] = $files[(int)$r['id']];
                return $b;
            }, $rows);
            return ['total_matches' => $total, 'shown' => count($emails), 'emails' => $emails];
        }
        case 'count_emails': {
            $params = [];
            $where = ecw_ai_tool_where($org, $user, $a, $params);
            $group = (string)($a['group_by'] ?? 'none');
            $key = match ($group) {
                'month' => "DATE_FORMAT(m.occurred_at, '%Y-%m')",
                'year' => 'YEAR(m.occurred_at)',
                'sender' => 'LOWER(m.from_email)',
                'sender_domain' => "LOWER(SUBSTRING_INDEX(m.from_email, '@', -1))",
                'folder' => '(SELECT f.name FROM `' . ecw_table('mail_folders') . '` f WHERE f.id = m.mail_folder_id)',
                default => null,
            };
            $st = $db->prepare('SELECT COUNT(*), MIN(m.occurred_at), MAX(m.occurred_at)' . ecw_ai_tool_from_sql() . 'WHERE ' . $where);
            $st->execute($params);
            [$total, $first, $last] = $st->fetch(PDO::FETCH_NUM);
            $out = ['total' => (int)$total, 'first' => $first ? substr((string)$first, 0, 10) : null, 'last' => $last ? substr((string)$last, 0, 10) : null];
            if ($key !== null) {
                $st = $db->prepare("SELECT {$key} AS k, COUNT(*) AS n" . ecw_ai_tool_from_sql() . 'WHERE ' . $where . ' GROUP BY k ORDER BY ' . (in_array($group, ['month', 'year'], true) ? 'k DESC' : 'n DESC') . ' LIMIT 24');
                $st->execute($params);
                $out['groups'] = array_map(static fn($r) => ['key' => (string)$r['k'], 'count' => (int)$r['n']], $st->fetchAll());
            }
            return $out;
        }
        case 'read_email': {
            $id = (int)preg_replace('/\D+/', '', (string)($a['ref'] ?? ''));
            $params = [];
            // Reading by ref is allowed in any folder.
            $where = ecw_ai_tool_where($org, $user, ['_any_folder' => true], $params);
            $st = $db->prepare('SELECT m.*' . ecw_ai_tool_from_sql() . 'WHERE m.id = ? AND ' . $where);
            $st->execute(array_merge([$id], $params));
            $r = $st->fetch();
            if (!$r) return ['error' => 'No email ' . ($a['ref'] ?? '') . ' that this user can see.'];
            $b = ecw_ai_tool_brief($r, $ctx, 5000);
            $names = static fn($json) => implode(', ', array_map(static fn($p) => trim(($p['name'] ?? '') . ' <' . ($p['email'] ?? $p['address'] ?? '') . '>'), array_slice(json_decode((string)$json, true) ?: [], 0, 8)));
            $b['to'] = mb_substr($names($r['to_json']), 0, 400);
            if ($r['cc_json']) $b['cc'] = mb_substr($names($r['cc_json']), 0, 300);
            $b['text'] = $b['snippet']; unset($b['snippet']);
            $st = $db->prepare('SELECT id, filename, size_bytes, text_state FROM `' . ecw_table('email_attachments') . '` WHERE email_message_id = ? AND is_inline = 0 LIMIT 20');
            $st->execute([$id]);
            $b['attachments'] = array_map(static fn($f) => ['file' => 'F' . $f['id'], 'name' => $f['filename'], 'size' => ecw_ai_tool_size((int)$f['size_bytes']),
                'readable' => $f['text_state'] === 'done'], $st->fetchAll());
            require_once __DIR__ . '/attachment_text.php';
            $texts = ecw_attachment_texts($id, 2500, 2);
            if ($texts) $b['attachment_text_excerpts'] = array_map(static fn($t) => ['file' => 'F' . $t['id'], 'name' => $t['name'], 'text' => $t['text']], $texts);
            $st = $db->prepare('SELECT c.case_number, c.name FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id WHERE ct.email_thread_id = ? AND c.deleted_at IS NULL LIMIT 3');
            $st->execute([(int)$r['email_thread_id']]);
            if ($cs = $st->fetchAll()) $b['cases'] = array_map(static fn($c) => $c['case_number'] . ' · ' . $c['name'], $cs);
            return $b;
        }
        case 'list_files': {
            $params = [];
            $inner = ecw_ai_tool_where($org, $user, ['has_attachments' => true] + $a, $params);
            // ecw_ai_files() joins the attachments as "a".
            $where = 'm.id IN (SELECT m.id' . ecw_ai_tool_from_sql() . 'WHERE ' . $inner . ')';
            if (($fn = trim((string)($a['filename'] ?? ''))) !== '') { $where .= ' AND a.filename LIKE ?'; $params[] = ecw_ai_like(mb_substr($fn, 0, 80)); }
            $limit = max(1, min(60, (int)($a['limit'] ?? 30)));
            $files = ecw_ai_files($where, $params, $limit);
            foreach ($files as $f) $ctx['files'][$f['id']] = $f;
            return ['count' => count($files), 'files' => array_map(static fn($f) => ['file' => 'F' . $f['id'], 'readable' => $f['readable'] ?? false, 'name' => $f['filename'], 'size' => ecw_ai_tool_size($f['size']),
                'from' => $f['from'], 'direction' => $f['direction'] === 'outgoing' ? 'sent' : 'received', 'date' => substr($f['date'], 0, 10),
                'email' => 'M' . $f['message_id'], 'times_sent' => $f['times']], $files),
                'note' => 'The user already sees these files as download cards; do not repeat the whole list, summarise.'];
        }
        case 'find_amounts': {
            $params = [];
            $where = ecw_ai_tool_where($org, $user, $a, $params);
            $limit = max(1, min(80, (int)($a['limit'] ?? 40)));
            $st = $db->prepare('SELECT m.id, m.email_thread_id, m.mailbox_id, m.subject, m.from_name, m.from_email, m.direction, m.occurred_at, LEFT(m.body_text, 20000) AS body_text, LEFT(m.body_html, 120000) AS body_html'
                . ecw_ai_tool_from_sql() . 'WHERE ' . $where . " ORDER BY m.occurred_at DESC LIMIT {$limit}");
            $st->execute($params);
            $emails = []; $sum = []; $noText = 0; $invoices = []; $dupes = 0;
            foreach ($st->fetchAll() as $r) {
                // Many invoice emails are HTML only: read the visible text of the HTML then.
                $body = trim((string)$r['body_text']) !== '' ? (string)$r['body_text'] : ecw_ai_html_text((string)$r['body_html']);
                // The invoice itself is often a PDF: its text counts too.
                require_once __DIR__ . '/attachment_text.php';
                foreach (ecw_attachment_texts((int)$r['id'], 15000, 3) as $file) $body .= "\n\n" . $file['text'];
                $b = ecw_ai_tool_brief(['body_text' => '', 'body_html' => ''] + $r, $ctx, 0);
                unset($b['snippet']);
                if (trim($body) === '') { $noText++; continue; }
                $found = ecw_ai_find_money((string)$r['subject'] . "\n" . $body);
                if (!$found['amounts']) continue;
                $b['amounts'] = array_slice($found['amounts'], 0, 6);
                $b['likely_total'] = $found['total'];
                // The same invoice number again (reminder, declined card, copy): listed, not added twice.
                $inv = ecw_ai_invoice_number((string)$r['subject'] . "\n" . mb_substr($body, 0, 4000));
                if ($inv !== null) $b['invoice'] = $inv;
                if ($inv !== null && isset($invoices[$inv])) { $b['duplicate_of'] = $invoices[$inv]; $dupes++; }
                elseif ($found['total']) {
                    $sum[$found['total']['currency']] = round(($sum[$found['total']['currency']] ?? 0) + $found['total']['amount'], 2);
                    if ($inv !== null) $invoices[$inv] = $b['ref'];
                }
                $emails[] = $b;
            }
            return ['emails_scanned' => $limit, 'emails_with_amounts' => count($emails), 'emails_without_downloaded_text' => $noText,
                'sum_of_likely_totals' => $sum, 'distinct_invoices' => count($invoices), 'duplicates_not_added' => $dupes, 'emails' => array_slice($emails, 0, 30),
                'note' => 'Estimates from the email text and the text of attached files (PDF, Word, Excel) that have been read. Scanned images are not read. Emails repeating an invoice number already counted were not added again.'];
        }
        case 'read_file': {
            $fid = (int)preg_replace('/\D+/', '', (string)($a['file'] ?? ''));
            $params = [];
            // The file's email must be one this user may see (any folder).
            $where = ecw_ai_tool_where($org, $user, ['_any_folder' => true], $params);
            $st = $db->prepare('SELECT a.id, a.filename, a.size_bytes, a.text_state, a.text_content, m.id AS mid, m.email_thread_id, m.mailbox_id, m.subject, m.from_name, m.from_email, m.direction, m.occurred_at
                                  FROM `' . ecw_table('email_attachments') . '` a JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
                                  JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id WHERE a.id = ? AND a.is_inline = 0 AND ' . $where);
            $st->execute(array_merge([$fid], $params));
            $f = $st->fetch();
            if (!$f) return ['error' => 'No file ' . ($a['file'] ?? '') . ' that this user can see.'];
            ecw_ai_tool_brief(['id' => $f['mid'], 'body_text' => ''] + $f, $ctx, 0);
            $ctx['files'][(int)$f['id']] = ['id' => (int)$f['id'], 'filename' => (string)$f['filename'], 'size' => (int)$f['size_bytes'], 'message_id' => (int)$f['mid'],
                'thread_id' => (int)$f['email_thread_id'], 'mailbox_id' => (int)$f['mailbox_id'], 'from' => (string)($f['from_name'] ?: $f['from_email']),
                'direction' => (string)$f['direction'], 'date' => (string)$f['occurred_at'], 'subject' => (string)$f['subject'], 'times' => 1, 'readable' => $f['text_state'] === 'done'];
            $state = (string)$f['text_state'];
            $why = ['' => 'not read yet (the worker reads new files within minutes)', 'empty' => 'it has no text (probably a scan or a drawing)', 'failed' => 'it could not be read',
                'skipped' => 'this kind of file is not read', 'too_big' => 'it is too large to read'][$state] ?? null;
            if ($state !== 'done') return ['file' => 'F' . $fid, 'name' => $f['filename'], 'email' => 'M' . $f['mid'], 'text' => null, 'why' => $why];
            return ['file' => 'F' . $fid, 'name' => $f['filename'], 'email' => 'M' . $f['mid'], 'text' => mb_substr((string)$f['text_content'], 0, 7000),
                'cut' => mb_strlen((string)$f['text_content']) > 7000];
        }
        case 'get_case': {
            require_once __DIR__ . '/ai.php';
            $ref = (string)($a['case'] ?? '');
            $refs = [];
            if (preg_match('/^\s*([A-Z]{2,10})-0*(\d{1,7})\s*$/i', $ref, $m)) $refs[] = ['prefix' => strtoupper($m[1]), 'n' => (int)$m[2]];
            if (preg_match('/(\d{1,7})\s*$/', $ref, $m)) $refs[] = ['prefix' => null, 'n' => (int)$m[1]];
            $case = $refs ? ecw_ai_find_case($org, $user, $refs) : null;
            if (!$case) return ['error' => 'No case "' . $ref . '" that this user can see.'];
            $cid = (int)$case['id'];
            $st = $db->prepare('SELECT c.due_at, c.created_at, c.updated_at, uo.name AS owner, uh.name AS handler, g.name AS grp FROM `' . ecw_table('cases') . '` c
                                  LEFT JOIN `' . ecw_table('users') . '` uo ON uo.id = c.owner_user_id LEFT JOIN `' . ecw_table('users') . '` uh ON uh.id = c.handler_user_id
                                  LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id WHERE c.id = ?');
            $st->execute([$cid]);
            $more = $st->fetch() ?: [];
            $st = $db->prepare('SELECT m.id, m.email_thread_id, m.mailbox_id, m.subject, m.from_name, m.from_email, m.direction, m.occurred_at, LEFT(m.body_text, 800) AS body_text
                                  FROM `' . ecw_table('email_messages') . '` m WHERE m.email_thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)
                                   AND m.provider_removed_at IS NULL ORDER BY m.occurred_at DESC LIMIT 8');
            $st->execute([$cid]);
            $latest = array_map(static fn($r) => ecw_ai_tool_brief($r, $ctx, 300), $st->fetchAll());
            $count = static function (string $sql, array $p) use ($db): int { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
            $threads = $count('SELECT COUNT(*) FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?', [$cid]);
            $emails = $count('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . '` m WHERE m.provider_removed_at IS NULL AND m.email_thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)', [$cid]);
            $files = $count('SELECT COUNT(*) FROM `' . ecw_table('email_attachments') . '` a JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id WHERE a.is_inline = 0 AND m.email_thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)', [$cid]);
            $st = $db->prepare('SELECT n.body, n.created_at, u.name FROM `' . ecw_table('case_notes') . '` n LEFT JOIN `' . ecw_table('users') . '` u ON u.id = n.user_id
                                 WHERE n.deleted_at IS NULL AND (n.case_id = ? OR n.thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)) ORDER BY n.created_at DESC LIMIT 5');
            $st->execute([$cid, $cid]);
            $notes = array_map(static fn($n) => ['by' => $n['name'], 'date' => substr((string)$n['created_at'], 0, 10),
                'text' => mb_substr(trim((string)preg_replace('/@\[([^\]\n]{1,120})\]\(user:\d+\)/u', '@$1', strip_tags((string)$n['body']))), 0, 300)], $st->fetchAll());
            return ['case' => $case['case_number'], 'name' => $case['name'], 'status' => $case['status'], 'due' => $more['due_at'] ?? null,
                'owner' => $more['owner'] ?? null, 'handled_by' => $more['handler'] ?? null, 'group' => $more['grp'] ?? null,
                'opened' => substr((string)($more['created_at'] ?? ''), 0, 10), 'conversations' => $threads, 'emails' => $emails, 'files' => $files,
                'latest_emails' => $latest, 'internal_notes' => $notes]
                + (($chats = (static function () use ($cid): array {
                    // Chat messages (Teams, WhatsApp, Telegram…) linked to the case by modules.
                    require_once __DIR__ . '/modules.php';
                    return array_map(static fn($c) => ['channel' => $c['channel'], 'from' => $c['from'], 'date' => substr((string)$c['date'], 0, 16), 'text' => mb_substr((string)$c['text'], 0, 300)],
                        ecw_case_chat_material($cid, 10));
                })()) ? ['linked_chat_messages' => $chats] : []);
        }
        case 'list_cases': {
            $params = [$org];
            $where = 'c.organization_id = ? AND c.deleted_at IS NULL';
            $status = (string)($a['status'] ?? 'any');
            if (in_array($status, ['open', 'waiting', 'closed'], true)) { $where .= ' AND c.status = ?'; $params[] = $status; }
            if (($w = trim((string)($a['words'] ?? ''))) !== '') { $where .= ' AND (c.name LIKE ? OR c.case_number LIKE ?)'; array_push($params, ecw_ai_like($w), ecw_ai_like($w)); }
            if (!empty($a['overdue'])) { require_once __DIR__ . '/case_settings.php'; $where .= ' AND ' . ecw_case_overdue_sql($org); }   // Settings → Case settings
            $where .= ' AND ' . ecw_case_visible_sql('c', $user, $params);
            $st = $db->prepare('SELECT c.case_number, c.name, c.status, c.due_at, c.updated_at, u.name AS owner FROM `' . ecw_table('cases') . '` c
                                  LEFT JOIN `' . ecw_table('users') . '` u ON u.id = c.owner_user_id WHERE ' . $where . ' ORDER BY c.updated_at DESC LIMIT 25');
            $st->execute($params);
            return ['cases' => array_map(static fn($c) => ['case' => $c['case_number'], 'name' => $c['name'], 'status' => $c['status'],
                'due' => $c['due_at'], 'owner' => $c['owner'], 'last_activity' => substr((string)$c['updated_at'], 0, 10)], $st->fetchAll())];
        }
    }
    // Open, make a file, or propose a change (lib/ai_actions.php).
    require_once __DIR__ . '/ai_actions.php';
    return ecw_ai_action_tool($ctx, $name, $a);
}

/** The visible text of an HTML email: no styles, scripts or tags; cells and lines kept apart. */
function ecw_ai_html_text(string $html): string
{
    require_once __DIR__ . '/body.php';
    return ecw_html_plain($html);
}

/** Attachment names per message id (non-inline, max 5 each). */
function ecw_ai_tool_filenames(array $ids): array
{
    if (!$ids) return [];
    $out = [];
    foreach (ecw_db()->query('SELECT email_message_id, filename FROM `' . ecw_table('email_attachments') . '` WHERE is_inline = 0 AND email_message_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id') as $r) {
        if (count($out[(int)$r['email_message_id']] ?? []) < 5) $out[(int)$r['email_message_id']][] = (string)$r['filename'];
    }
    return $out;
}

function ecw_ai_tool_size(int $b): string
{
    return $b < 1024 ? $b . ' B' : ($b < 1048576 ? round($b / 1024) . ' KB' : round($b / 1048576, 1) . ' MB');
}

/**
 * Money amounts in a text: "€12.50", "12,50 €", "EUR 1.234,56", "$9.99",
 * "USD 10". The likely total is the amount next to total / amount due /
 * σύνολο / πληρωτέο…, else the largest.
 */
function ecw_ai_find_money(string $text): array
{
    $text = mb_substr($text, 0, 20000);
    $cur = '(€|EUR|\$|USD|£|GBP|CHF|ευρώ|euro)';
    $num = '(\d{1,3}(?:[.,\s]\d{3})*(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)';
    $found = [];
    if (preg_match_all('/' . $cur . '\s?' . $num . '|' . $num . '\s?' . $cur . '/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($all as $m) {
            $sym = $m[1][0] !== '' && $m[1][1] >= 0 ? $m[1][0] : ($m[4][0] ?? '');
            $raw = ($m[2][0] ?? '') !== '' ? $m[2][0] : ($m[3][0] ?? '');
            $amount = ecw_ai_parse_amount($raw);
            if ($amount === null || $amount <= 0 || $amount > 10000000) continue;
            $currency = match (mb_strtolower($sym)) { '€', 'eur', 'ευρώ', 'euro' => 'EUR', '$', 'usd' => 'USD', '£', 'gbp' => 'GBP', 'chf' => 'CHF', default => strtoupper($sym) };
            $at = (int)$m[0][1];
            $before = substr($text, max(0, $at - 80), min(80, $at));
            $context = trim((string)preg_replace('/\s+/u', ' ', mb_convert_encoding(substr($text, max(0, $at - 50), 110), 'UTF-8', 'UTF-8')));
            $isTotal = (bool)preg_match('/(total|amount due|balance due|grand total|charged|you paid|payment of|σύνολο|συνολικ|πληρωτέο|πληρωμή|χρεώθηκε|ποσό)/iu', $before);
            $found[] = ['amount' => $amount, 'currency' => $currency, 'is_total' => $isTotal, 'context' => $context];
        }
    }
    $total = null;
    foreach ($found as $f) if ($f['is_total'] && ($total === null || $f['amount'] > $total['amount'])) $total = $f;
    if ($total === null) foreach ($found as $f) if ($total === null || $f['amount'] > $total['amount']) $total = $f;
    return ['amounts' => array_map(static fn($f) => ['amount' => $f['amount'], 'currency' => $f['currency'], 'context' => $f['context']], $found),
        'total' => $total ? ['amount' => $total['amount'], 'currency' => $total['currency']] : null];
}

/** An invoice / receipt number in the text ("Invoice G187566266", "Τιμολόγιο αρ. 1234", "#INV-0042"), or null. */
function ecw_ai_invoice_number(string $text): ?string
{
    if (preg_match('/(?:invoice|τιμολόγιο|τιμολογίου|receipt|απόδειξη|bill|inv)\s*(?:no\.?|number|nr\.?|#|αρ\.?|αριθμός)?\s*[:#]?\s*([A-Z]{0,5}-?\d{4,}[A-Z0-9-]*)/iu', $text, $m)) return strtoupper($m[1]);
    if (preg_match('/\b(INV-?\d{3,}|[A-Z]\d{8,})\b/', $text, $m)) return strtoupper($m[1]);
    return null;
}

/** "1.234,56" / "1,234.56" / "12,50" / "12.5" → float. */
function ecw_ai_parse_amount(string $raw): ?float
{
    $s = preg_replace('/\s+/', '', $raw);
    if ($s === '' || $s === null) return null;
    $lastDot = strrpos($s, '.'); $lastComma = strrpos($s, ',');
    $decSep = null;
    if ($lastDot !== false && $lastComma !== false) $decSep = $lastDot > $lastComma ? '.' : ',';
    elseif ($lastDot !== false || $lastComma !== false) {
        $sep = $lastDot !== false ? '.' : ',';
        $pos = (int)strrpos($s, $sep);
        // "12,50" or "12.5": decimals. "1.234" / "1,234": thousands.
        $decSep = strlen($s) - $pos - 1 <= 2 ? $sep : null;
    }
    if ($decSep !== null) {
        $thou = $decSep === '.' ? ',' : '.';
        $s = str_replace($thou, '', $s);
        $s = str_replace($decSep, '.', $s);
    } else {
        $s = str_replace([',', '.'], '', $s);
    }
    return is_numeric($s) ? round((float)$s, 2) : null;
}
