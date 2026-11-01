<?php declare(strict_types=1);
/**
 * Email Case Workspace — outgoing webhooks for other systems (CRM, ERP, Zapier).
 *
 * An admin sets one HTTPS URL per organization and picks events. Each POST is
 * JSON, signed with HMAC-SHA256 of the raw body in X-ECW-Signature
 * ("sha256=<hex>") using a secret generated here and shown once.
 *
 * SSRF: the URL is user-supplied and fetched by the server. Only https, only
 * hosts that resolve to public addresses, and curl is pinned to the address
 * that was checked (CURLOPT_RESOLVE) so DNS cannot be switched afterwards.
 * Redirects are never followed.
 *
 * Payloads carry identifiers and short text only, never full email bodies.
 * Members-only cases are not sent.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log.php';

const ECW_WEBHOOK_EVENTS = [
    'case.created'   => 'A case is created',
    'case.updated'   => 'A case changes status or assignment',
    'note.added'     => 'An internal note is added',
    'email.received' => 'A new email arrives on a case',
    'rule.matched'   => 'An automation rule with “Send to the webhook” matched an email',
];

/** @return array{0:string,1:string,2:int}|null [host, ip, port] when the URL is allowed */
function ecw_webhook_target(string $url): ?array
{
    $p = parse_url($url);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    $host = strtolower($p['host']);
    $port = (int)($p['port'] ?? 443);
    if (in_array($host, ['localhost'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return null;
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if ($ips === []) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;   // any private/reserved answer rejects the host
        }
    }
    return [$host, $ips[0], $port];
}

function ecw_webhook_settings(int $orgId): array
{
    $st = ecw_db()->prepare('SELECT webhook_url_encrypted, webhook_events_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $st->execute([$orgId]);
    $row = $st->fetch() ?: [];
    $url = ecw_decrypt($row['webhook_url_encrypted'] ?? null);
    $events = json_decode((string)($row['webhook_events_json'] ?? ''), true);
    return [
        'configured' => (bool)$url,
        'url_hint'   => $url ? preg_replace('~^(https://[^/]+).*$~', '$1/…', $url) : null,
        'events'     => is_array($events) ? array_values(array_intersect(array_keys(ECW_WEBHOOK_EVENTS), $events)) : array_keys(ECW_WEBHOOK_EVENTS),
        'labels'     => ECW_WEBHOOK_EVENTS,
    ];
}

/**
 * Save settings. A new URL generates a new secret, returned ONCE.
 *
 * @param ?string $url null keeps the stored URL, '' removes it
 */
function ecw_webhook_save(int $orgId, ?string $url, array $events): array
{
    $events = array_values(array_intersect(array_keys(ECW_WEBHOOK_EVENTS), array_map('strval', $events)));
    $sql = 'UPDATE `' . ecw_table('organizations') . '` SET webhook_events_json = ?';
    $params = [json_encode($events)];
    $secret = null;
    if ($url !== null) {
        $url = trim($url);
        if ($url !== '' && ecw_webhook_target($url) === null) {
            throw new InvalidArgumentException('Use a public https:// address.');
        }
        $secret = $url === '' ? null : bin2hex(random_bytes(24));
        $sql .= ', webhook_url_encrypted = ?, webhook_secret_encrypted = ?';
        array_push($params, $url === '' ? null : ecw_encrypt($url), $secret === null ? null : ecw_encrypt($secret));
    }
    $params[] = $orgId;
    ecw_db()->prepare($sql . ' WHERE id = ?')->execute($params);
    return ecw_webhook_settings($orgId) + ($secret !== null ? ['secret' => $secret] : []);
}

/** Send one event. Best effort, short timeouts; never throws. */
function ecw_webhook_emit(int $orgId, string $event, array $data): bool
{
    try {
        $st = ecw_db()->prepare('SELECT webhook_url_encrypted, webhook_secret_encrypted, webhook_events_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
        $st->execute([$orgId]);
        $row = $st->fetch();
        $url = $row ? ecw_decrypt($row['webhook_url_encrypted'] ?? null) : null;
        if (!$url) {
            return false;
        }
        $events = json_decode((string)($row['webhook_events_json'] ?? ''), true);
        // A rule's "Send to the webhook" action is its own opt-in.
        if (!in_array($event, ['test', 'rule.matched'], true) && is_array($events) && !in_array($event, $events, true)) {
            return false;
        }
        $body = json_encode(['event' => $event, 'occurred_at' => gmdate('c'), 'organization_id' => $orgId, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', (string)$body, (string)ecw_decrypt($row['webhook_secret_encrypted'] ?? null));

        if (PHP_SAPI === 'cli' && defined('ECW_TESTING') && isset($GLOBALS['ecw_webhook_test_sink'])) {
            $GLOBALS['ecw_webhook_test_sink'][] = ['body' => json_decode((string)$body, true), 'signature' => $signature];
            return true;
        }

        $target = ecw_webhook_target($url);
        if ($target === null) {
            ecw_log("webhook for organization {$orgId} skipped: target no longer allowed");
            return false;
        }
        [$host, $ip, $port] = $target;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-ECW-Event: ' . $event, 'X-ECW-Signature: ' . $signature],
            CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            ecw_log("webhook {$event} for organization {$orgId} failed (HTTP {$status})");
            return false;
        }
        return true;
    } catch (Throwable $e) {
        ecw_log('webhook emit failed: ' . get_class($e));
        return false;
    }
}

/** Public facts about a case for a payload, or null for members-only cases. */
function ecw_webhook_case(int $caseId): ?array
{
    $st = ecw_db()->prepare(
        'SELECT c.id, c.case_number, c.name, c.status, c.visibility, g.name AS group_name, o.email AS owner_email, h.email AS handler_email
           FROM `' . ecw_table('cases') . '` c
           LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
           LEFT JOIN `' . ecw_table('users') . '` o ON o.id = c.owner_user_id
           LEFT JOIN `' . ecw_table('users') . '` h ON h.id = c.handler_user_id
          WHERE c.id = ?'
    );
    $st->execute([$caseId]);
    $c = $st->fetch();
    if ($c === false || $c['visibility'] !== 'organization') {
        return null;
    }
    return ['id' => (int)$c['id'], 'number' => $c['case_number'], 'name' => $c['name'], 'status' => $c['status'],
        'group' => $c['group_name'], 'responsible' => $c['owner_email'], 'handled_by' => $c['handler_email']];
}
