<?php declare(strict_types=1);
/**
 * WhatsApp module, through Meta's WhatsApp Business Cloud API.
 *
 * SETUP (Settings → WhatsApp, admins)
 * -----------------------------------
 * A workspace pastes its phone number id, WhatsApp Business account id, a
 * permanent access token (a System User token with whatsapp_business_messaging
 * and whatsapp_business_management) and its Meta app secret. The token and the
 * secret are stored encrypted and never shown again. Saving asks Meta for the
 * number, so a wrong token or id is caught at once. Meta is then pointed at
 * web/webhook.php with the verify token shown on the page.
 *
 * INCOMING (web/webhook.php)
 * -----------------------------------
 * Meta posts every message and status change. The workspace is found by the
 * phone number id the message was sent to, and the body must carry that
 * workspace's X-Hub-Signature-256 (HMAC of the raw body with the app secret),
 * so nobody else can post messages into a workspace. WhatsApp's message id is
 * unique in wa_messages: a webhook delivered twice is stored once.
 *
 * THE 24-HOUR WINDOW
 * ------------------
 * WhatsApp accepts free text only within 24 hours of the customer's last
 * message. Outside it (and to start a chat) only an approved template may be
 * sent; the API says which applies (ecw_wa_window_open) so the UI can switch.
 *
 * Who sees what: everyone with the WhatsApp feature sees the workspace's
 * WhatsApp chats (it is one shared business number, like a shared mailbox).
 */

require_once dirname(__DIR__, 3) . '/lib/db.php';
require_once dirname(__DIR__, 3) . '/lib/crypto.php';
require_once dirname(__DIR__, 3) . '/lib/log.php';
require_once dirname(__DIR__, 3) . '/lib/audit.php';
require_once dirname(__DIR__, 3) . '/lib/cases.php';
require_once dirname(__DIR__, 3) . '/lib/notifications.php';
require_once dirname(__DIR__, 3) . '/lib/account_security.php';
require_once dirname(__DIR__, 3) . '/lib/modules.php';

const ECW_WA_GRAPH = 'https://graph.facebook.com/v21.0';
const ECW_WA_WINDOW_HOURS = 24;
const ECW_WA_STATUS_RANK = ['sending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

final class EcwWhatsAppNotConnected extends RuntimeException {}

/** The stored settings, secrets still encrypted. */
function ecw_wa_raw(int $orgId): array
{
    $st = ecw_db()->prepare('SELECT whatsapp_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $st->execute([$orgId]);
    $s = json_decode((string)$st->fetchColumn(), true);
    return is_array($s) ? $s : [];
}

/** What the settings page and the API may show: no token, no secret. */
function ecw_wa_settings(int $orgId): array
{
    $s = ecw_wa_raw($orgId);
    return [
        'configured'      => !empty($s['phone_number_id']) && !empty($s['token_enc']),
        'phone_number_id' => (string)($s['phone_number_id'] ?? ''),
        'waba_id'         => (string)($s['waba_id'] ?? ''),
        'display_phone'   => (string)($s['display_phone'] ?? ''),
        'verified_name'   => (string)($s['verified_name'] ?? ''),
        'has_token'       => !empty($s['token_enc']),
        'has_secret'      => !empty($s['app_secret_enc']),
        'verify_token'    => (string)($s['verify_token'] ?? ''),
        'webhook_url'     => ecw_wa_webhook_url(),
        'last_webhook_at' => $s['last_webhook_at'] ?? null,
        'mailbox_id'      => isset($s['mailbox_id']) ? (int)$s['mailbox_id'] : null,
    ];
}

/**
 * Does WhatsApp show in this account (lib/account.php)? The number belongs to
 * the account an admin linked it to; not linked, or not connected yet (so an
 * admin can open the tab and connect it), it shows in every account.
 */
function ecw_wa_for_account(int $orgId, int $mailboxId): bool
{
    try { $s = ecw_wa_raw($orgId); } catch (Throwable $e) { return true; }   // before migration 045
    return empty($s['mailbox_id']) || (int)$s['mailbox_id'] === $mailboxId;
}

/** Navigation (module.php → nav): the tab shows in the account the number is linked to. */
function ecw_wa_nav_visible(int $orgId, ?int $accountId): bool
{
    return $accountId === null || ecw_wa_for_account($orgId, $accountId);
}

/** Case history (hook case.event_label): how the module's events read. */
function ecw_wa_event_label(string $type, array $m): ?string
{
    $title = isset($m['title']) ? ' (' . $m['title'] . ')' : '';
    return match ($type) {
        'WHATSAPP_LINKED'   => 'linked a WhatsApp chat' . $title,
        'WHATSAPP_UNLINKED' => 'unlinked a WhatsApp chat' . $title,
        default => null,
    };
}

function ecw_wa_webhook_url(): string
{
    $base = function_exists('ecw_web_base') ? ecw_web_base() : '/';
    require_once dirname(__DIR__, 3) . '/lib/modules.php';
    return ecw_link_base_url() . ecw_module_page_url($base, 'whatsapp', 'webhook');
}

/** Settings with the secrets decrypted, for calling Meta. @throws EcwWhatsAppNotConnected */
function ecw_wa_config(int $orgId): array
{
    $s = ecw_wa_raw($orgId);
    $token = !empty($s['token_enc']) ? (string)ecw_decrypt((string)$s['token_enc']) : '';
    if (empty($s['phone_number_id']) || $token === '') {
        throw new EcwWhatsAppNotConnected('WhatsApp is not connected yet. An admin can connect it under Settings → WhatsApp.');
    }
    return ['org_id' => $orgId, 'phone_number_id' => (string)$s['phone_number_id'], 'waba_id' => (string)($s['waba_id'] ?? ''), 'token' => $token];
}

/**
 * One call to the Cloud API. Tests set $GLOBALS['ecw_wa_transport']
 * ($method, $url, ?$json) → ['status' => int, 'body' => array|string].
 * @return array{status:int, body:mixed}
 */
function ecw_wa_http(array $cfg, string $method, string $pathOrUrl, ?array $json = null, bool $raw = false, ?array $form = null): array
{
    $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : ECW_WA_GRAPH . $pathOrUrl;
    $t = $GLOBALS['ecw_wa_transport'] ?? null;
    if ($t !== null && PHP_SAPI === 'cli' && defined('ECW_TESTING')) {
        $res = $t($method, $url, $json ?? $form);
        return ['status' => (int)($res['status'] ?? 200), 'body' => $res['body'] ?? []];
    }
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $cfg['token']];
    if ($json !== null) $headers[] = 'Content-Type: application/json';
    // A file upload ($form with a CURLFile): curl writes the multipart body and its Content-Type.
    if ($form !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $raw ? 60 : 25,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $out = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('WhatsApp could not be reached (' . $err . ').');
    return ['status' => $status, 'body' => $raw ? $out : (json_decode((string)$out, true) ?? [])];
}

/** Meta's error as a sentence a person can act on. */
function ecw_wa_error(array $res): string
{
    $e = is_array($res['body']) ? ($res['body']['error'] ?? []) : [];
    $code = (int)($e['code'] ?? 0);
    $detail = trim((string)($e['error_data']['details'] ?? $e['message'] ?? ''));
    return match (true) {
        $code === 190 || $res['status'] === 401 => 'Meta refused the access token. An admin should paste a new one under Settings → WhatsApp.',
        $code === 131047                        => 'More than 24 hours have passed since the customer last wrote. Send an approved template instead.',
        $code === 131026                        => 'This number cannot receive WhatsApp messages (not on WhatsApp, or an old app version).',
        $code === 131056 || $code === 130429 || $res['status'] === 429 => 'WhatsApp is limiting how fast messages can be sent. Try again in a minute.',
        $code === 132001                        => 'That template does not exist in this language, or is not approved yet.',
        $code === 100 && str_contains($detail, 'phone')  => 'WhatsApp did not accept that phone number. Include the country code.',
        default => 'WhatsApp refused the request' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : ' (' . $res['status'] . ').'),
    };
}

/**
 * Save the connection. Empty token / secret keep the stored ones. Meta is asked
 * for the number, so a wrong id or token fails here, not on the first message.
 */
function ecw_wa_save(int $orgId, array $in, int $userId): array
{
    $s = ecw_wa_raw($orgId);
    $phoneId = preg_replace('/\D/', '', (string)($in['phone_number_id'] ?? ''));
    $wabaId = preg_replace('/\D/', '', (string)($in['waba_id'] ?? ''));
    $token = trim((string)($in['access_token'] ?? ''));
    $secret = trim((string)($in['app_secret'] ?? ''));
    if ($phoneId === '' || strlen($phoneId) > 30) throw new InvalidArgumentException('Paste the Phone number ID (digits) from Meta → WhatsApp → API setup.');
    if ($wabaId === '' || strlen($wabaId) > 30) throw new InvalidArgumentException('Paste the WhatsApp Business Account ID (digits) from the same page.');
    if ($token === '' && empty($s['token_enc'])) throw new InvalidArgumentException('Paste the access token.');
    if ($secret === '' && empty($s['app_secret_enc'])) throw new InvalidArgumentException('Paste the App secret (Meta app → App settings → Basic). Without it incoming messages cannot be checked.');
    if ($secret !== '' && !preg_match('/^[a-f0-9]{32}$/i', $secret)) throw new InvalidArgumentException('The App secret is 32 letters and digits (a–f, 0–9).');

    $cfg = ['phone_number_id' => $phoneId, 'token' => $token !== '' ? $token : (string)ecw_decrypt((string)$s['token_enc'])];
    $res = ecw_wa_http($cfg, 'GET', '/' . $phoneId . '?fields=display_phone_number,verified_name');
    if ($res['status'] >= 400) throw new InvalidArgumentException(ecw_wa_error($res));
    $owner = $orgId;
    $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('organizations') . '` WHERE whatsapp_phone_number_id = ? AND id <> ?');
    $st->execute([$phoneId, $orgId]);
    if ($st->fetchColumn()) throw new InvalidArgumentException('This WhatsApp number is already connected to another workspace.');

    $s = array_merge($s, [
        'phone_number_id' => $phoneId,
        'waba_id'         => $wabaId,
        'display_phone'   => (string)($res['body']['display_phone_number'] ?? ''),
        'verified_name'   => (string)($res['body']['verified_name'] ?? ''),
        'verify_token'    => (string)($s['verify_token'] ?? '') !== '' ? $s['verify_token'] : bin2hex(random_bytes(16)),
        'connected_at'    => $s['connected_at'] ?? gmdate('Y-m-d H:i:s'),
    ]);
    // The account the number belongs to (0 = every account), only one of this workspace.
    if (array_key_exists('mailbox_id', $in)) {
        $mb = (int)$in['mailbox_id'];
        $own = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('mailboxes') . '` WHERE id = ? AND organization_id = ?');
        $own->execute([$mb, $orgId]);
        if ($mb > 0 && $own->fetchColumn()) $s['mailbox_id'] = $mb; else unset($s['mailbox_id']);
    }
    if ($token !== '') $s['token_enc'] = ecw_encrypt($token);
    if ($secret !== '') $s['app_secret_enc'] = ecw_encrypt($secret);
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET whatsapp_json = ?, whatsapp_phone_number_id = ? WHERE id = ?')
        ->execute([json_encode($s), $phoneId, $owner]);
    ecw_audit('settings.whatsapp_saved', null, null, ['number' => $s['display_phone'], 'token_changed' => $token !== '', 'secret_changed' => $secret !== ''], $orgId, $userId);
    return ecw_wa_settings($orgId);
}

/** Forget the connection (the chats stay readable). */
function ecw_wa_disconnect(int $orgId, int $userId): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET whatsapp_json = NULL, whatsapp_phone_number_id = NULL WHERE id = ?')->execute([$orgId]);
    ecw_audit('settings.whatsapp_removed', null, null, [], $orgId, $userId);
}

// ------------------------------------------------------------------ webhook

/** Meta's "verify" handshake: the challenge when the token is one of ours, else null. */
function ecw_wa_verify_challenge(string $mode, string $token, string $challenge): ?string
{
    if ($mode !== 'subscribe' || $token === '' || !preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge)) return null;
    $rows = ecw_db()->query('SELECT whatsapp_json FROM `' . ecw_table('organizations') . '` WHERE whatsapp_json IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $j) {
        $vt = (string)(json_decode((string)$j, true)['verify_token'] ?? '');
        if ($vt !== '' && hash_equals($vt, $token)) return $challenge;
    }
    return null;
}

/**
 * A webhook POST. Each change is handled only for the workspace that owns its
 * phone number, and only when the body is signed with that workspace's app
 * secret. @return array{stored:int, rejected:int}
 */
function ecw_wa_webhook_handle(string $raw, string $signature): array
{
    $payload = json_decode($raw, true);
    if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') throw new InvalidArgumentException('not a WhatsApp webhook');
    $stored = 0; $rejected = 0; $verified = [];
    foreach ((array)($payload['entry'] ?? []) as $entry) {
        foreach ((array)($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'messages' || !is_array($change['value'] ?? null)) continue;
            $value = $change['value'];
            $phoneId = (string)($value['metadata']['phone_number_id'] ?? '');
            $st = ecw_db()->prepare('SELECT id, whatsapp_json FROM `' . ecw_table('organizations') . '` WHERE whatsapp_phone_number_id = ? LIMIT 1');
            $st->execute([$phoneId]);
            $org = $phoneId !== '' ? $st->fetch() : false;
            if (!$org) { $rejected++; continue; }
            $orgId = (int)$org['id'];
            if (!isset($verified[$orgId])) {
                $s = json_decode((string)$org['whatsapp_json'], true) ?: [];
                $secret = !empty($s['app_secret_enc']) ? (string)ecw_decrypt((string)$s['app_secret_enc']) : '';
                $verified[$orgId] = $secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), strtolower(trim($signature)));
                if ($verified[$orgId]) {
                    $s['last_webhook_at'] = gmdate('Y-m-d H:i:s');
                    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET whatsapp_json = ? WHERE id = ?')->execute([json_encode($s), $orgId]);
                }
            }
            if (!$verified[$orgId]) { $rejected++; continue; }
            $stored += ecw_wa_ingest($orgId, $value);
        }
    }
    return ['stored' => $stored, 'rejected' => $rejected];
}

/** Store the messages and status updates of one webhook "value". @return int new messages */
function ecw_wa_ingest(int $orgId, array $value): int
{
    $pdo = ecw_db();
    $names = [];
    foreach ((array)($value['contacts'] ?? []) as $c) $names[(string)($c['wa_id'] ?? '')] = (string)($c['profile']['name'] ?? '');
    $new = 0;
    foreach ((array)($value['messages'] ?? []) as $m) {
        $from = preg_replace('/\D/', '', (string)($m['from'] ?? ''));
        $wamid = (string)($m['id'] ?? '');
        if ($from === '' || $wamid === '') continue;
        $at = gmdate('Y-m-d H:i:s', (int)($m['timestamp'] ?? time()) ?: time());
        $chat = ecw_wa_chat_upsert($orgId, $from, $names[$from] ?? '');
        [$kind, $body, $media] = ecw_wa_message_content($m);
        $ins = $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('wa_messages') . '`
            (chat_id, wamid, direction, kind, body, media_id, media_mime, filename, status, occurred_at) VALUES (?, ?, \'in\', ?, ?, ?, ?, ?, \'received\', ?)');
        $ins->execute([$chat['id'], $wamid, $kind, $body, $media['id'] ?? null, $media['mime'] ?? null, $media['filename'] ?? null, $at]);
        if ($ins->rowCount() === 0) continue;   // delivered before
        $new++;
        $pdo->prepare('UPDATE `' . ecw_table('wa_chats') . '` SET unread = unread + 1, last_inbound_at = GREATEST(COALESCE(last_inbound_at, ?), ?),
                        last_message_at = GREATEST(COALESCE(last_message_at, ?), ?), last_preview = ? WHERE id = ?')
            ->execute([$at, $at, $at, $at, mb_substr(ecw_wa_preview($kind, $body), 0, 250), $chat['id']]);
        // Other modules hear about it (e.g. linking chats to cases tells the case's handler).
        ecw_hook_run('chat.message_in', ['source' => 'whatsapp', 'organization_id' => $orgId, 'user_id' => null, 'chat' => (string)$chat['id'],
            'from' => (string)($chat['name'] ?: '+' . $from), 'text' => mb_substr(ecw_wa_preview($kind, $body), 0, 120),
            'link' => 'module.php?m=whatsapp&p=whatsapp&chat=' . (int)$chat['id']]);
    }
    foreach ((array)($value['statuses'] ?? []) as $s) {
        $status = (string)($s['status'] ?? '');
        if (!isset(ECW_WA_STATUS_RANK[$status])) continue;
        $err = $status === 'failed' ? mb_substr(trim((string)(($s['errors'][0]['error_data']['details'] ?? '') ?: ($s['errors'][0]['title'] ?? 'Not delivered'))), 0, 250) : null;
        // Never step back (a late "delivered" after "read").
        $pdo->prepare('UPDATE `' . ecw_table('wa_messages') . "` SET status = ?, error = COALESCE(?, error)
                        WHERE wamid = ? AND direction = 'out' AND FIELD(COALESCE(status, 'sending'), 'sending', 'sent', 'delivered', 'read', 'failed') - 1 < ?")
            ->execute([$status, $err, (string)($s['id'] ?? ''), ECW_WA_STATUS_RANK[$status]]);
    }
    return $new;
}

/** @return array{0:string, 1:?string, 2:array} kind, text, media */
function ecw_wa_message_content(array $m): array
{
    $type = (string)($m['type'] ?? '');
    $media = static fn(string $k): array => ['id' => (string)($m[$k]['id'] ?? ''), 'mime' => (string)($m[$k]['mime_type'] ?? ''), 'filename' => isset($m[$k]['filename']) ? mb_substr((string)$m[$k]['filename'], 0, 250) : null];
    return match ($type) {
        'text'        => ['text', (string)($m['text']['body'] ?? ''), []],
        'image', 'video', 'audio', 'sticker', 'document' => [$type, isset($m[$type]['caption']) ? (string)$m[$type]['caption'] : null, $media($type)],
        'location'    => ['location', trim(implode(' — ', array_filter([(string)($m['location']['name'] ?? ''), (string)($m['location']['address'] ?? '')])) . "\n"
                            . 'https://maps.google.com/?q=' . (float)($m['location']['latitude'] ?? 0) . ',' . (float)($m['location']['longitude'] ?? 0)), []],
        'button'      => ['text', (string)($m['button']['text'] ?? ''), []],
        'interactive' => ['text', (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''), []],
        'reaction'    => ['reaction', (string)($m['reaction']['emoji'] ?? ''), []],
        'contacts'    => ['text', 'Shared a contact: ' . implode(', ', array_map(static fn($c) => trim((string)($c['name']['formatted_name'] ?? '') . ' ' . (string)($c['phones'][0]['phone'] ?? '')), (array)($m['contacts'] ?? []))), []],
        default       => ['other', 'A message this app cannot show (' . ($type ?: 'unknown') . '). Open WhatsApp on the phone to see it.', []],
    };
}

function ecw_wa_preview(string $kind, ?string $body): string
{
    $label = ['image' => '📷 Photo', 'video' => '🎬 Video', 'audio' => '🎤 Voice message', 'sticker' => 'Sticker', 'document' => '📄 Document', 'location' => '📍 Location', 'reaction' => 'Reacted'][$kind] ?? '';
    $body = trim((string)$body);
    return $label !== '' ? $label . ($body !== '' ? ': ' . $body : '') : $body;
}

// -------------------------------------------------------------------- chats

/** Digits only with the country code; null when it cannot be a phone number. */
function ecw_wa_phone(string $in): ?string
{
    $d = preg_replace('/\D/', '', $in);
    if (str_starts_with(trim($in), '00')) $d = substr($d, 2);
    return strlen($d) >= 8 && strlen($d) <= 15 && $d[0] !== '0' ? $d : null;
}

function ecw_wa_chat_upsert(int $orgId, string $waId, string $name = ''): array
{
    $pdo = ecw_db();
    $pdo->prepare('INSERT INTO `' . ecw_table('wa_chats') . '` (organization_id, wa_id, name) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE name = COALESCE(NULLIF(VALUES(name), \'\'), name)')
        ->execute([$orgId, $waId, $name !== '' ? mb_substr($name, 0, 190) : null]);
    $st = $pdo->prepare('SELECT * FROM `' . ecw_table('wa_chats') . '` WHERE organization_id = ? AND wa_id = ?');
    $st->execute([$orgId, $waId]);
    return $st->fetch();
}

function ecw_wa_chat(int $orgId, int $chatId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('wa_chats') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$chatId, $orgId]);
    $r = $st->fetch();
    return $r ?: null;
}

function ecw_wa_window_open(array $chat): bool
{
    return !empty($chat['last_inbound_at']) && strtotime($chat['last_inbound_at'] . ' UTC') > time() - ECW_WA_WINDOW_HOURS * 3600;
}

/** The chat list, newest first; with $caseId only that case's chats. */
function ecw_wa_chats(int $orgId, ?int $caseId = null): array
{
    $sql = 'SELECT w.*, c.name AS case_name, c.case_number FROM `' . ecw_table('wa_chats') . '` w
             LEFT JOIN `' . ecw_table('cases') . '` c ON c.id = w.case_id AND c.deleted_at IS NULL
            WHERE w.organization_id = ?' . ($caseId ? ' AND w.case_id = ?' : '') . '
            ORDER BY w.last_message_at IS NULL, w.last_message_at DESC, w.id DESC LIMIT 300';
    $st = ecw_db()->prepare($sql);
    $st->execute($caseId ? [$orgId, $caseId] : [$orgId]);
    return array_map('ecw_wa_chat_out', $st->fetchAll());
}

function ecw_wa_chat_out(array $r): array
{
    return [
        'id' => (int)$r['id'], 'phone' => '+' . $r['wa_id'], 'name' => (string)($r['name'] ?? ''),
        'title' => (string)($r['name'] ?: '+' . $r['wa_id']),
        'case_id' => $r['case_id'] !== null && ($r['case_name'] ?? null) !== null ? (int)$r['case_id'] : null,
        'case_label' => isset($r['case_name']) && $r['case_name'] !== null ? trim(($r['case_number'] ? '#' . $r['case_number'] . ' ' : '') . $r['case_name']) : null,
        'last_at' => $r['last_message_at'] ? gmdate('c', strtotime($r['last_message_at'] . ' UTC')) : null,
        'preview' => (string)($r['last_preview'] ?? ''), 'unread' => (int)$r['unread'],
        'window_open' => ecw_wa_window_open($r),
        'window_closes' => $r['last_inbound_at'] ? gmdate('c', strtotime($r['last_inbound_at'] . ' UTC') + ECW_WA_WINDOW_HOURS * 3600) : null,
    ];
}

/** Messages of a chat (oldest first) and the chat marked read here and on the customer's phone. */
function ecw_wa_messages(int $orgId, int $chatId): array
{
    $chat = ecw_wa_chat($orgId, $chatId);
    if (!$chat) throw new InvalidArgumentException('That WhatsApp chat does not exist.');
    $st = ecw_db()->prepare('SELECT m.*, u.name AS sender FROM `' . ecw_table('wa_messages') . '` m
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = m.sent_by_user_id
                              WHERE m.chat_id = ? ORDER BY m.occurred_at DESC, m.id DESC LIMIT 200');
    $st->execute([$chatId]);
    $rows = array_reverse($st->fetchAll());
    if ((int)$chat['unread'] > 0) {
        ecw_db()->prepare('UPDATE `' . ecw_table('wa_chats') . '` SET unread = 0 WHERE id = ?')->execute([$chatId]);
        $lastIn = null;
        foreach ($rows as $r) if ($r['direction'] === 'in') $lastIn = $r['wamid'];
        if ($lastIn) {
            try {   // blue ticks on their phone; never fails the read
                $cfg = ecw_wa_config($orgId);
                ecw_wa_http($cfg, 'POST', '/' . $cfg['phone_number_id'] . '/messages', ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $lastIn]);
            } catch (Throwable $e) { ecw_log('whatsapp read receipt failed: ' . $e->getMessage()); }
        }
    }
    return [
        'chat' => ecw_wa_chat_out($chat + ['case_name' => ecw_wa_case_name($chat['case_id']), 'case_number' => ecw_wa_case_number($chat['case_id'])]),
        'messages' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'direction' => $r['direction'], 'kind' => $r['kind'], 'body' => (string)($r['body'] ?? ''),
            'has_media' => !empty($r['media_id']), 'mime' => (string)($r['media_mime'] ?? ''), 'filename' => (string)($r['filename'] ?? ''),
            'status' => $r['status'], 'error' => $r['error'], 'sender' => $r['sender'],
            'at' => gmdate('c', strtotime($r['occurred_at'] . ' UTC')),
        ], $rows),
    ];
}

function ecw_wa_case_name($caseId): ?string
{
    if (!$caseId) return null;
    $st = ecw_db()->prepare('SELECT name FROM `' . ecw_table('cases') . '` WHERE id = ? AND deleted_at IS NULL');
    $st->execute([(int)$caseId]);
    $n = $st->fetchColumn();
    return $n === false ? null : (string)$n;
}

function ecw_wa_case_number($caseId): ?string
{
    if (!$caseId) return null;
    $st = ecw_db()->prepare('SELECT case_number FROM `' . ecw_table('cases') . '` WHERE id = ?');
    $st->execute([(int)$caseId]);
    $n = $st->fetchColumn();
    return $n === false || $n === null ? null : (string)$n;
}

/** Store an outgoing message after Meta accepted it. */
function ecw_wa_store_out(array $chat, int $userId, string $wamid, string $kind, string $body): int
{
    $now = gmdate('Y-m-d H:i:s');
    ecw_db()->prepare('INSERT INTO `' . ecw_table('wa_messages') . '` (chat_id, wamid, direction, kind, body, status, sent_by_user_id, occurred_at)
                       VALUES (?, ?, \'out\', ?, ?, \'sent\', ?, ?)')->execute([(int)$chat['id'], $wamid !== '' ? $wamid : null, $kind, $body, $userId, $now]);
    $id = (int)ecw_db()->lastInsertId();
    ecw_db()->prepare('UPDATE `' . ecw_table('wa_chats') . '` SET last_message_at = ?, last_preview = ? WHERE id = ?')
        ->execute([$now, mb_substr('You: ' . $body, 0, 250), (int)$chat['id']]);
    return $id;
}

/** Free text, inside the 24-hour window. */
function ecw_wa_send_text(int $orgId, int $chatId, int $userId, string $text): array
{
    $text = trim($text);
    if ($text === '' || mb_strlen($text) > 4096) throw new InvalidArgumentException('Write a message (up to 4096 characters).');
    $chat = ecw_wa_chat($orgId, $chatId);
    if (!$chat) throw new InvalidArgumentException('That WhatsApp chat does not exist.');
    if (!ecw_wa_window_open($chat)) throw new InvalidArgumentException('More than 24 hours have passed since the customer last wrote, so WhatsApp only accepts an approved template. Choose one under “Send a template”.');
    $cfg = ecw_wa_config($orgId);
    $res = ecw_wa_http($cfg, 'POST', '/' . $cfg['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $chat['wa_id'],
        'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false],
    ]);
    if ($res['status'] >= 400) throw new RuntimeException(ecw_wa_error($res));
    return ['id' => ecw_wa_store_out($chat, $userId, (string)($res['body']['messages'][0]['id'] ?? ''), 'text', $text)];
}

/** Where WhatsApp files are kept on this computer (sent from here, and received once opened). */
function ecw_wa_dir(string $sub = ''): string
{
    $dir = dirname(__DIR__, 3) . '/storage/whatsapp' . ($sub !== '' ? '/' . trim($sub, '/') : '');
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

/** The kept copy of a message's file: storage/whatsapp/<org>/<message id>.<ext> (or null). */
function ecw_wa_local_file(int $orgId, int $messageId): ?string
{
    foreach (glob(ecw_wa_dir((string)$orgId) . '/' . $messageId . '.*') ?: [] as $f) if (is_file($f)) return $f;
    return null;
}

const ECW_WA_MAX_UPLOAD = 8 * 1024 * 1024;   // what PHP takes (docker/php.ini upload_max_filesize)

/**
 * A photo or file (with an optional caption), inside the 24-hour window. Uploaded to Meta, sent,
 * and kept here so it shows in the chat for good (Meta deletes its copy after 30 days).
 * @param array{tmp:string, name:string, size:int} $file
 */
function ecw_wa_send_file(int $orgId, int $chatId, int $userId, array $file, string $caption = ''): array
{
    $caption = trim($caption);
    if (mb_strlen($caption) > 1024) throw new InvalidArgumentException('A caption can have up to 1024 characters.');
    $chat = ecw_wa_chat($orgId, $chatId);
    if (!$chat) throw new InvalidArgumentException('That WhatsApp chat does not exist.');
    if (!ecw_wa_window_open($chat)) throw new InvalidArgumentException('More than 24 hours have passed since the customer last wrote, so WhatsApp only accepts an approved template. Choose one under “Send a template”.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || !is_file((string)$file['tmp'])) throw new InvalidArgumentException('The file did not arrive. Try again.');
    if ($size > ECW_WA_MAX_UPLOAD) throw new InvalidArgumentException('Files up to 8 MB can be sent from here; send larger ones from WhatsApp on the phone.');
    $name = mb_substr(preg_replace('/[\x00-\x1F\/\\\\"]+/u', '_', basename((string)$file['name'])) ?: 'file', 0, 200);
    $mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp']) ?: 'application/octet-stream');
    // WhatsApp shows JPEG and PNG photos (up to 5 MB) as photos; anything else goes as a document.
    $kind = in_array($mime, ['image/jpeg', 'image/png'], true) && $size <= 5 * 1024 * 1024 ? 'image' : 'document';
    $cfg = ecw_wa_config($orgId);
    $up = ecw_wa_http($cfg, 'POST', '/' . $cfg['phone_number_id'] . '/media', null, false,
        ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => new CURLFile((string)$file['tmp'], $mime, $name)]);
    $mediaId = (string)($up['body']['id'] ?? '');
    if ($up['status'] >= 400 || $mediaId === '') throw new RuntimeException($up['status'] >= 400 ? ecw_wa_error($up) : 'WhatsApp did not take the file. Try again.');
    $media = ['id' => $mediaId] + ($caption !== '' ? ['caption' => $caption] : []) + ($kind === 'document' ? ['filename' => $name] : []);
    $res = ecw_wa_http($cfg, 'POST', '/' . $cfg['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $chat['wa_id'], 'type' => $kind, $kind => $media,
    ]);
    if ($res['status'] >= 400) throw new RuntimeException(ecw_wa_error($res));
    $id = ecw_wa_store_out($chat, $userId, (string)($res['body']['messages'][0]['id'] ?? ''), $kind, $caption);
    ecw_db()->prepare('UPDATE `' . ecw_table('wa_messages') . '` SET media_id = ?, media_mime = ?, filename = ?, body = ? WHERE id = ?')
        ->execute([$mediaId, $mime, $name, $caption !== '' ? $caption : null, $id]);
    $preview = ($kind === 'image' ? '📷 Photo' : '📄 ' . $name) . ($caption !== '' ? ': ' . $caption : '');
    ecw_db()->prepare('UPDATE `' . ecw_table('wa_chats') . '` SET last_preview = ? WHERE id = ?')->execute([mb_substr('You: ' . $preview, 0, 250), (int)$chat['id']]);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? (preg_match('/\.([A-Za-z0-9]{1,8})$/', $name, $m) ? strtolower($m[1]) : 'bin');
    $dest = ecw_wa_dir((string)$orgId) . '/' . $id . '.' . $ext;
    if (!(is_uploaded_file((string)$file['tmp']) ? move_uploaded_file((string)$file['tmp'], $dest) : copy((string)$file['tmp'], $dest))) ecw_log('whatsapp: the sent file could not be kept for message ' . $id);
    return ['id' => $id];
}

/** The approved templates that need only text in their body (what this app can fill in). */
function ecw_wa_templates(int $orgId): array
{
    $cfg = ecw_wa_config($orgId);
    if ($cfg['waba_id'] === '') throw new InvalidArgumentException('The WhatsApp Business Account ID is missing. An admin can add it under Settings → WhatsApp.');
    $res = ecw_wa_http($cfg, 'GET', '/' . $cfg['waba_id'] . '/message_templates?fields=name,language,status,category,components&limit=200');
    if ($res['status'] >= 400) throw new RuntimeException(ecw_wa_error($res));
    $out = [];
    foreach ((array)($res['body']['data'] ?? []) as $t) {
        if (($t['status'] ?? '') !== 'APPROVED') continue;
        $body = ''; $ok = true;
        foreach ((array)($t['components'] ?? []) as $c) {
            $type = strtoupper((string)($c['type'] ?? ''));
            if ($type === 'BODY') $body = (string)($c['text'] ?? '');
            if ($type === 'HEADER' && (strtoupper((string)($c['format'] ?? 'TEXT')) !== 'TEXT' || str_contains((string)($c['text'] ?? ''), '{{'))) $ok = false;
        }
        if (!$ok || $body === '') continue;
        preg_match_all('/\{\{(\d+)\}\}/', $body, $mm);
        $out[] = ['name' => (string)$t['name'], 'language' => (string)$t['language'], 'category' => (string)($t['category'] ?? ''), 'body' => $body, 'params' => $mm[1] ? max(array_map('intval', $mm[1])) : 0];
    }
    usort($out, static fn($a, $b) => [$a['name'], $a['language']] <=> [$b['name'], $b['language']]);
    return $out;
}

/**
 * Send an approved template: to an existing chat ($chatId) or to a new number
 * ($phone), which is how a WhatsApp conversation is started.
 */
function ecw_wa_send_template(int $orgId, int $userId, ?int $chatId, ?string $phone, string $name, string $language, array $params, string $bodyText = ''): array
{
    if (!preg_match('/^[a-z0-9_]{1,512}$/', $name) || !preg_match('/^[a-zA-Z_]{2,10}$/', $language)) throw new InvalidArgumentException('Choose a template.');
    if ($chatId) {
        $chat = ecw_wa_chat($orgId, $chatId);
        if (!$chat) throw new InvalidArgumentException('That WhatsApp chat does not exist.');
    } else {
        $num = ecw_wa_phone((string)$phone);
        if ($num === null) throw new InvalidArgumentException('Enter the phone number with its country code, e.g. +30 69…');
        $chat = null;
    }
    $params = array_values(array_map(static fn($p) => mb_substr(trim((string)$p), 0, 1000), $params));
    foreach ($params as $i => $p) if ($p === '') throw new InvalidArgumentException('Fill in {{' . ($i + 1) . '}}.');
    $cfg = ecw_wa_config($orgId);
    $tpl = ['name' => $name, 'language' => ['code' => $language]];
    if ($params) $tpl['components'] = [['type' => 'body', 'parameters' => array_map(static fn($p) => ['type' => 'text', 'text' => $p], $params)]];
    $res = ecw_wa_http($cfg, 'POST', '/' . $cfg['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $chat['wa_id'] ?? $num, 'type' => 'template', 'template' => $tpl,
    ]);
    if ($res['status'] >= 400) throw new RuntimeException(ecw_wa_error($res));
    if ($chat === null) {
        $waId = preg_replace('/\D/', '', (string)($res['body']['contacts'][0]['wa_id'] ?? '')) ?: $num;
        $chat = ecw_wa_chat_upsert($orgId, $waId);
    }
    $shown = $bodyText !== '' ? preg_replace_callback('/\{\{(\d+)\}\}/', static fn($m) => $params[(int)$m[1] - 1] ?? $m[0], $bodyText) : 'Template: ' . $name;
    ecw_wa_store_out($chat, $userId, (string)($res['body']['messages'][0]['id'] ?? ''), 'template', (string)$shown);
    return ['chat_id' => (int)$chat['id']];
}

/**
 * A photo, voice message or document someone sent: fetched from Meta (its
 * download links need the token and expire). @return array{mime:string, filename:string, bytes:string}
 */
function ecw_wa_media(int $orgId, int $messageId): array
{
    $st = ecw_db()->prepare('SELECT m.* FROM `' . ecw_table('wa_messages') . '` m JOIN `' . ecw_table('wa_chats') . '` c ON c.id = m.chat_id
                              WHERE m.id = ? AND c.organization_id = ?');
    $st->execute([$messageId, $orgId]);
    $m = $st->fetch();
    if (!$m || empty($m['media_id'])) throw new InvalidArgumentException('That file does not exist.');
    $ext = static fn(string $mime): string => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'application/pdf' => 'pdf'][strtok($mime, ';')] ?? 'bin';
    // Kept here already (sent from here, or opened before): no trip to Meta, and still there after its 30 days.
    if (($local = ecw_wa_local_file($orgId, $messageId)) !== null) {
        $mime = (string)($m['media_mime'] ?: 'application/octet-stream');
        return ['mime' => $mime, 'filename' => (string)($m['filename'] ?: 'whatsapp-' . $messageId . '.' . $ext($mime)), 'bytes' => (string)file_get_contents($local)];
    }
    $cfg = ecw_wa_config($orgId);
    $meta = ecw_wa_http($cfg, 'GET', '/' . rawurlencode((string)$m['media_id']));
    if ($meta['status'] >= 400) throw new RuntimeException($meta['status'] === 404 ? 'WhatsApp keeps files for 30 days; this one is no longer available.' : ecw_wa_error($meta));
    $url = (string)($meta['body']['url'] ?? '');
    $host = (string)parse_url($url, PHP_URL_HOST);
    // Only Meta's own file hosts: the token is sent along (no SSRF, no leak).
    if (!str_starts_with($url, 'https://') || !preg_match('/(^|\.)(fbsbx\.com|facebook\.com|whatsapp\.net|fbcdn\.net)$/', $host)) throw new RuntimeException('WhatsApp returned an unexpected file address.');
    $file = ecw_wa_http($cfg, 'GET', $url, null, true);
    if ($file['status'] >= 400 || !is_string($file['body'])) throw new RuntimeException('The file could not be downloaded from WhatsApp.');
    $mime = (string)($meta['body']['mime_type'] ?? $m['media_mime'] ?? 'application/octet-stream');
    // Kept from now on, up to 20 MB.
    if (strlen($file['body']) <= 20 * 1024 * 1024) @file_put_contents(ecw_wa_dir((string)$orgId) . '/' . $messageId . '.' . $ext($mime), $file['body']);
    return ['mime' => $mime, 'filename' => (string)($m['filename'] ?: 'whatsapp-' . $messageId . '.' . $ext($mime)), 'bytes' => $file['body']];
}

/** How many chats have unread messages (nav badge). */
function ecw_wa_unread(int $orgId): int
{
    $st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('wa_chats') . '` WHERE organization_id = ? AND unread > 0');
    $st->execute([$orgId]);
    return (int)$st->fetchColumn();
}
