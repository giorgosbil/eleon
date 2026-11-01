<?php declare(strict_types=1);
/**
 * Support agent (modules/support_agent): the connection to the support agent and
 * what is sent to it.
 *
 * A conversation goes as its emails in time order: incoming = the customer, outgoing =
 * us. Each email's own text (the quoted history is already split off, so nothing is
 * sent twice), with its files: attached and pasted-in screenshots (signature logos and
 * icons left out by size, a picture repeated in every reply sent once), PDF, Word, Excel
 * and settings / text files (.ini, .xml, .txt …), within limits: at most 20 files and
 * 15 MB per send. The support agent reads them all (text, cells, pictures inside).
 * A case sends every conversation in it that the admin may open, merged into one time line.
 * The agent only stores it and drafts; it learns nothing until an admin approves there.
 */
require_once dirname(__DIR__, 3) . '/lib/db.php';
require_once dirname(__DIR__, 3) . '/lib/schema.php';
require_once dirname(__DIR__, 3) . '/lib/crypto.php';
require_once dirname(__DIR__, 3) . '/lib/access.php';
require_once dirname(__DIR__, 3) . '/lib/sanitize.php';

const ECW_SA_MAX_FILES = 20;
const ECW_SA_MAX_BYTES = 15 * 1024 * 1024;
const ECW_SA_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
const ECW_SA_FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'ini', 'cfg', 'conf', 'config', 'xml', 'xsl', 'xslt', 'txt', 'csv', 'json', 'log', 'properties', 'yaml', 'yml', 'bat', 'cmd', 'reg', 'html', 'htm', 'md'];
/** Pictures smaller than this are logos and icons (signatures, social links), not screenshots. */
const ECW_SA_MIN_IMAGE = ['width' => 120, 'height' => 60, 'pixels' => 25000];

function ecw_sa_raw(int $orgId): array
{
    $st = ecw_db()->prepare('SELECT support_agent_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $st->execute([$orgId]);
    $s = json_decode((string)$st->fetchColumn(), true);
    return is_array($s) ? $s : [];
}

/** What the settings page and the button may know: never the token. */
function ecw_sa_settings(int $orgId): array
{
    $s = ecw_sa_raw($orgId);
    return [
        'configured' => !empty($s['url']) && !empty($s['token_enc']),
        'url'        => (string)($s['url'] ?? ''),
        'has_token'  => !empty($s['token_enc']),
        'last_ok_at' => $s['last_ok_at'] ?? null,
        'last_error' => $s['last_error'] ?? null,
        'suggested_url' => (is_file('/.dockerenv') ? 'http://host.docker.internal' : 'http://localhost') . '/support_agent/public/api.php',
    ];
}

/** Saves the address and token (an empty token keeps the saved one), then checks the agent answers. */
function ecw_sa_save(int $orgId, array $in, int $userId): array
{
    $s = ecw_sa_raw($orgId);
    $url = trim((string)($in['url'] ?? ''));
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['query']) || !str_ends_with((string)($p['path'] ?? ''), '.php')) {
        throw new InvalidArgumentException('Enter the address of the support agent\'s API, ending in api.php, for example http://localhost/support_agent/public/api.php.');
    }
    $token = trim((string)($in['token'] ?? ''));
    if ($token !== '' && !preg_match('/^sat_[a-f0-9]{48}$/', $token)) throw new InvalidArgumentException('That does not look like a support agent token. Create one in the support agent: Settings → API tokens.');
    if ($token === '' && empty($s['token_enc'])) throw new InvalidArgumentException('Paste the token from the support agent (Settings → API tokens).');
    $s['url'] = $url;
    if ($token !== '') $s['token_enc'] = ecw_encrypt($token);
    try {
        $r = ecw_sa_call($orgId, 'GET', 'ping', null, $s);
        $s['last_ok_at'] = gmdate('Y-m-d H:i:s');
        $s['last_error'] = null;
        $s['agent_ai'] = $r['ai'] ?? null;
    } catch (RuntimeException $e) {
        $s['last_error'] = $e->getMessage();
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET support_agent_json = ? WHERE id = ?')->execute([json_encode($s, JSON_UNESCAPED_SLASHES), $orgId]);
    require_once dirname(__DIR__, 3) . '/lib/audit.php';
    ecw_audit('settings.support_agent', null, null, ['url' => $url, 'token_changed' => $token !== ''], $orgId);
    return ecw_sa_settings($orgId);
}

/**
 * One request to the support agent's API. Tests answer through $GLOBALS['ecw_sa_transport'].
 * @throws RuntimeException with a message an admin can act on
 */
function ecw_sa_call(int $orgId, string $method, string $action, ?array $body, ?array $settings = null): array
{
    $s = $settings ?? ecw_sa_raw($orgId);
    $token = ecw_decrypt($s['token_enc'] ?? null);
    if (empty($s['url']) || !$token) throw new RuntimeException('The support agent is not connected. An admin connects it in Settings → Support agent.');
    $url = $s['url'] . '?action=' . rawurlencode($action);
    if (defined('ECW_TESTING') && isset($GLOBALS['ecw_sa_transport'])) {
        [$status, $data] = ($GLOBALS['ecw_sa_transport'])($method, $url, $body, $token);
    } else {
        $h = curl_init($url);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json']]);
        if ($method === 'POST') { curl_setopt($h, CURLOPT_POST, true); curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); }
        $raw = curl_exec($h);
        $status = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $err = curl_error($h);
        curl_close($h);
        if ($raw === false) throw new RuntimeException('The support agent could not be reached at ' . $s['url'] . ' (' . $err . ').');
        $data = json_decode((string)$raw, true);
    }
    if (!is_array($data)) throw new RuntimeException('The support agent answered something unexpected (' . $status . '). Check the address.');
    if ($status === 401) throw new RuntimeException('The support agent refused the token. Create a new one there (Settings → API tokens) and paste it here.');
    if ($status < 200 || $status >= 300 || empty($data['ok'])) throw new RuntimeException((string)($data['error'] ?? 'The support agent could not take it (' . $status . ').'));
    return $data;
}

/** Sends one conversation. The admin must be able to open its mailbox. */
function ecw_sa_send_thread(int $orgId, array $user, int $threadId): array
{
    $st = ecw_db()->prepare('SELECT t.id, t.mailbox_id, t.normalized_subject FROM `' . ecw_table('email_threads') . '` t JOIN `' . ecw_table('mailboxes') . '` m ON m.id = t.mailbox_id WHERE t.id = ? AND m.organization_id = ?');
    $st->execute([$threadId, $orgId]);
    $t = $st->fetch();
    if (!$t || !ecw_access_mailbox($orgId, (int)$user['id'], (int)$t['mailbox_id'], $threadId)) throw new InvalidArgumentException('not found');
    $budget = ['files' => ECW_SA_MAX_FILES, 'bytes' => ECW_SA_MAX_BYTES];
    $messages = ecw_sa_drop_signatures(ecw_sa_messages((int)$t['mailbox_id'], $threadId, $budget), $budget);
    if (!$messages) throw new InvalidArgumentException('This conversation has no emails to send.');
    return ecw_sa_call($orgId, 'POST', 'learn', [
        'external_ref' => 'thread:' . $threadId, 'kind' => 'thread', 'title' => $t['normalized_subject'] !== '' ? $t['normalized_subject'] : '(no subject)',
        'link' => ecw_sa_link('app.php?mailbox=' . (int)$t['mailbox_id'] . '&thread=' . $threadId), 'sent_by' => (string)($user['name'] ?? $user['email'] ?? ''),
        'messages' => $messages,
    ]);
}

/** Sends a whole case: every conversation in it the admin may open, as one time line. */
function ecw_sa_send_case(int $orgId, array $user, int $caseId): array
{
    require_once dirname(__DIR__, 3) . '/lib/timeline.php';
    $case = ecw_case_load($orgId, $caseId);
    if (!$case) throw new InvalidArgumentException('not found');
    $st = ecw_db()->prepare('SELECT ct.email_thread_id, t.mailbox_id FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('email_threads') . '` t ON t.id = ct.email_thread_id WHERE ct.case_id = ? ORDER BY ct.sort_order, ct.id');
    $st->execute([$caseId]);
    $budget = ['files' => ECW_SA_MAX_FILES, 'bytes' => ECW_SA_MAX_BYTES];
    $messages = [];
    foreach ($st->fetchAll() as $r) {
        if (!ecw_access_mailbox($orgId, (int)$user['id'], (int)$r['mailbox_id'], (int)$r['email_thread_id'])) continue;
        array_push($messages, ...ecw_sa_messages((int)$r['mailbox_id'], (int)$r['email_thread_id'], $budget));
    }
    if (!$messages) throw new InvalidArgumentException('This case has no emails you can send.');
    $messages = ecw_sa_drop_signatures($messages, $budget);
    usort($messages, static fn($a, $b) => strcmp($a['date'], $b['date']));
    return ecw_sa_call($orgId, 'POST', 'learn', [
        'external_ref' => 'case:' . $caseId, 'kind' => 'case', 'title' => trim($case['case_number'] . ' ' . $case['name']),
        'link' => ecw_sa_link('case.php?id=' . $caseId), 'sent_by' => (string)($user['name'] ?? $user['email'] ?? ''), 'messages' => $messages,
    ]);
}

/** The emails of one conversation, oldest first, with what files fit the budget. */
function ecw_sa_messages(int $mailboxId, int $threadId, array &$budget): array
{
    $st = ecw_db()->prepare("SELECT id, subject, from_email, from_name, body_html, body_text, body_fetch_state, direction, sent_at, received_at, provider_message_id, has_attachments
        FROM `" . ecw_table('email_messages') . "` WHERE mailbox_id = ? AND email_thread_id = ? AND direction IN ('incoming', 'outgoing') ORDER BY COALESCE(sent_at, received_at), id");
    $st->execute([$mailboxId, $threadId]);
    $out = [];
    foreach ($st->fetchAll() as $m) {
        // A body never opened is fetched now (the same loader as opening it, ecw_store_full_message), so nothing is sent empty.
        if ($m['body_fetch_state'] !== 'fetched') {
            try {
                require_once dirname(__DIR__, 3) . '/lib/sync/messages.php';
                ecw_store_full_message($mailboxId, (int)$m['id'], (string)$m['provider_message_id']);
                $re = ecw_db()->prepare('SELECT body_html, body_text FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
                $re->execute([(int)$m['id']]);
                $m = array_merge($m, (array)$re->fetch());
            } catch (Throwable $e) {
                ecw_log('support agent: body of message ' . (int)$m['id'] . ' not fetched: ' . get_class($e));
            }
        }
        // A picture pasted into the email stays where it was: [[image:<content id>]] in the text,
        // so the agent reads it together with the sentences around it.
        $html = (string)$m['body_html'];
        if (stripos($html, 'cid:') !== false) {
            $html = (string)preg_replace_callback('#<img\b[^>]*?\bsrc\s*=\s*(["\'])cid:([^"\']+)\1[^>]*>#i', static fn($x) => ' [[image:' . trim($x[2], " <>") . ']] ', $html);
            $text = ecw_html_to_text($html);
        } else {
            $text = trim((string)$m['body_text']) !== '' ? (string)$m['body_text'] : ecw_html_to_text($html);
        }
        $out[] = [
            'direction' => $m['direction'], 'from' => (string)$m['from_email'], 'from_name' => (string)$m['from_name'],
            'date' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)($m['sent_at'] ?: $m['received_at'] ?: 'now') . ' UTC')),
            'subject' => (string)$m['subject'], 'body' => mb_substr(trim($text), 0, 50000),
            // Always looked up: an email with only pasted-in pictures is marked "no attachments" by Outlook.
            'attachments' => ecw_sa_attachments($mailboxId, $m, $budget),
        ];
    }
    return $out;
}

/**
 * Signatures: a picture pasted into two or more emails of one send (the company logo under every
 * reply) is a signature, not a screenshot: it is left out everywhere. Then every [[image:…]]
 * marker whose picture is not sent is taken out of the text.
 */
function ecw_sa_drop_signatures(array $messages, array $budget): array
{
    $repeated = $budget['repeated'] ?? [];
    foreach ($messages as &$m) {
        $m['attachments'] = array_values(array_filter($m['attachments'], static fn($a) => empty($a['inline']) || !isset($repeated[$a['sha1']])));
        $kept = array_flip(array_filter(array_column($m['attachments'], 'content_id')));
        $m['body'] = trim((string)preg_replace_callback('/ ?\[\[image:([^\]]*)\]\] ?/', static fn($x) => isset($kept[$x[1]]) ? ' ' . trim($x[0]) . ' ' : ' ', $m['body']));
        foreach ($m['attachments'] as &$a) unset($a['sha1']);
        unset($a);
    }
    unset($m);
    return $messages;
}

/**
 * The files of one email, while the budget lasts: attached files, and pictures pasted into the
 * text (inline). Pictures smaller than a screenshot (logos, icons) and pictures already sent with
 * an earlier email of the same send (a signature repeated in every reply) are left out.
 */
function ecw_sa_attachments(int $mailboxId, array $message, array &$budget): array
{
    require_once dirname(__DIR__, 3) . '/lib/attachment_text.php';
    $st = ecw_db()->prepare('SELECT id, filename, mime_type, size_bytes, is_inline, content_id, local_path, provider_attachment_id FROM `' . ecw_table('email_attachments') . '` WHERE email_message_id = ? ORDER BY is_inline, id');
    $st->execute([(int)$message['id']]);
    $budget['seen'] ??= [];
    $budget['repeated'] ??= [];
    $out = [];
    foreach ($st->fetchAll() as $a) {
        $name = (string)$a['filename'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        // An inline picture may have no name or extension ("image001"): its type decides.
        if ($ext === '' && str_starts_with((string)$a['mime_type'], 'image/')) $ext = substr((string)$a['mime_type'], 6);
        if (!in_array($ext, ECW_SA_FILE_EXTENSIONS, true) || $budget['files'] <= 0 || (int)$a['size_bytes'] > $budget['bytes']) continue;
        $bytes = ecw_attachment_bytes($a + ['mailbox_id' => $mailboxId, 'provider_message_id' => $message['provider_message_id']]);
        if ($bytes === null || $bytes === '' || strlen($bytes) > $budget['bytes']) continue;
        $hash = sha1($bytes);
        if (isset($budget['seen'][$hash])) {
            // The same picture pasted into another email of this send: a signature (removed afterwards, ecw_sa_drop_signatures).
            if ((int)$a['is_inline']) $budget['repeated'][$hash] = true;
            continue;
        }
        if (in_array($ext, ECW_SA_IMAGE_EXTENSIONS, true) || str_starts_with((string)$a['mime_type'], 'image/')) {
            $size = @getimagesizefromstring($bytes);
            if (!$size || $size[0] < ECW_SA_MIN_IMAGE['width'] || $size[1] < ECW_SA_MIN_IMAGE['height'] || $size[0] * $size[1] < ECW_SA_MIN_IMAGE['pixels']) continue;
            if ($name === '' || !str_contains($name, '.')) $name = ($name !== '' ? $name : 'pasted-image-' . (int)$a['id']) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        }
        $budget['seen'][$hash] = true;
        $budget['files']--;
        $budget['bytes'] -= strlen($bytes);
        $out[] = ['filename' => $name, 'data_base64' => base64_encode($bytes), 'inline' => (bool)$a['is_inline'], 'content_id' => trim((string)$a['content_id'], " <>"), 'sha1' => $hash];
    }
    return $out;
}

function ecw_sa_link(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = function_exists('ecw_web_base') ? ecw_web_base() : '/';
    return $scheme . '://' . $host . (str_starts_with($base, '/') ? $base : '/' . $base) . $path;
}
