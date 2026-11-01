<?php declare(strict_types=1);
/**
 * Web Push: notifications on phones and computers even when the app is closed
 * (the installed app / a browser with the service worker web/sw.js).
 *
 * Standards, implemented with PHP's OpenSSL so no extra library is needed:
 *   RFC 8292 VAPID   — the server signs each request (ES256 JWT) with its own key
 *                      pair, created once and kept in app_settings (so backups
 *                      carry it; a new key would silently orphan every phone).
 *   RFC 8291 / 8188  — the message is encrypted for that one browser (ECDH P-256,
 *                      HKDF-SHA256, AES-128-GCM); the push service cannot read it.
 *
 * Browsers only allow this on https:// or localhost. Subscriptions that the push
 * service reports as gone (404 / 410) are deleted.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/product_brand.php';

function ecw_b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function ecw_b64u_decode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** @return array{public:string, private:string} public = base64url uncompressed point, private = PEM */
function ecw_vapid_keys(): array
{
    $t = ecw_table('app_settings');
    $raw = ecw_db()->query("SELECT value FROM `{$t}` WHERE name = 'vapid'")->fetchColumn();
    if ($raw !== false && ($k = json_decode((string)$raw, true)) && !empty($k['public'])) return $k;
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) throw new RuntimeException('Could not create the push key pair.');
    openssl_pkey_export($key, $pem);
    $d = openssl_pkey_get_details($key)['ec'];
    $k = ['public' => ecw_b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)), 'private' => $pem];
    ecw_db()->prepare("INSERT IGNORE INTO `{$t}` (name, value) VALUES ('vapid', ?)")->execute([json_encode($k)]);
    // Another request may have created one at the same moment: use whatever is stored.
    return json_decode((string)ecw_db()->query("SELECT value FROM `{$t}` WHERE name = 'vapid'")->fetchColumn(), true);
}

function ecw_push_subscribe(int $userId, array $sub, string $userAgent = ''): void
{
    $endpoint = (string)($sub['endpoint'] ?? '');
    $p256dh = (string)($sub['keys']['p256dh'] ?? '');
    $auth = (string)($sub['keys']['auth'] ?? '');
    if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen(ecw_b64u_decode($p256dh)) !== 65 || strlen(ecw_b64u_decode($auth)) !== 16) {
        throw new InvalidArgumentException('That push subscription is not valid.');
    }
    ecw_db()->prepare('INSERT INTO `' . ecw_table('push_subscriptions') . '` (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)')
        ->execute([$userId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, mb_substr($userAgent, 0, 255)]);
}

function ecw_push_unsubscribe(int $userId, string $endpoint): void
{
    ecw_db()->prepare('DELETE FROM `' . ecw_table('push_subscriptions') . '` WHERE user_id = ? AND endpoint_hash = ?')->execute([$userId, hash('sha256', $endpoint)]);
}

/** Encrypt $payload for one subscription (RFC 8291, aes128gcm). */
function ecw_push_encrypt(string $payload, string $p256dhB64, string $authB64): string
{
    $uaPublic = ecw_b64u_decode($p256dhB64);
    $authSecret = ecw_b64u_decode($authB64);
    $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($local)['ec'];
    $asPublic = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    // The browser's public key as PEM (SubjectPublicKeyInfo for P-256).
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPublic;
    $uaKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
    $shared = openssl_pkey_derive($uaKey, $local, 32);
    if ($shared === false) throw new RuntimeException('Push key agreement failed.');
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** ES256 JWT for VAPID; OpenSSL gives DER, JWT wants raw r||s. */
function ecw_vapid_jwt(string $audience, string $privatePem): string
{
    $data = ecw_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . ecw_b64u(json_encode([
        'aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . (getenv('ECW_PUSH_CONTACT') ?: 'admin@localhost'),
    ]));
    openssl_sign($data, $der, $privatePem, OPENSSL_ALGO_SHA256);
    // DER: 30 len 02 lenR R 02 lenS S
    $pos = 2; if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $int = static function () use ($der, &$pos): string { $len = ord($der[$pos + 1]); $v = substr($der, $pos + 2, $len); $pos += 2 + $len; return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT); };
    $r = $int(); $s = $int();
    return $data . '.' . ecw_b64u($r . $s);
}

/** Send one notification to every device of a user. @return int devices reached */
function ecw_push_to_user(int $userId, string $title, string $body, string $url = '', string $tag = '', ?int $badge = null): int
{
    $st = ecw_db()->prepare('SELECT id, endpoint, p256dh, auth FROM `' . ecw_table('push_subscriptions') . '` WHERE user_id = ?');
    $st->execute([$userId]);
    $subs = $st->fetchAll();
    if ($subs === []) return 0;
    $keys = ecw_vapid_keys();
    $payload = json_encode(['product_name' => ecw_product_brand()['name'], 'icon' => ecw_product_brand()['icons'] . 'icon-192.png', 'title' => $title === 'Case Workspace' ? ecw_product_brand()['name'] : $title, 'body' => mb_strimwidth($body, 0, 240, '…'), 'url' => $url, 'tag' => $tag]
        + ($badge !== null ? ['badge' => $badge] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = 0;
    foreach ($subs as $s) {
        try {
            $parts = parse_url((string)$s['endpoint']);
            $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $ch = curl_init((string)$s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_POSTFIELDS => ecw_push_encrypt($payload, (string)$s['p256dh'], (string)$s['auth']),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: normal',
                    'Authorization: vapid t=' . ecw_vapid_jwt($aud, $keys['private']) . ', k=' . $keys['public'],
                ],
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code === 404 || $code === 410) {
                ecw_db()->prepare('DELETE FROM `' . ecw_table('push_subscriptions') . '` WHERE id = ?')->execute([(int)$s['id']]);
            } elseif ($code >= 200 && $code < 300) {
                $ok++;
                ecw_db()->prepare('UPDATE `' . ecw_table('push_subscriptions') . '` SET last_used_at = ? WHERE id = ?')->execute([ecw_now(), (int)$s['id']]);
            } else {
                ecw_log("push to subscription {$s['id']} answered {$code}");
            }
        } catch (Throwable $e) {
            ecw_log('push failed: ' . $e->getMessage());
        }
    }
    return $ok;
}

/**
 * New mail → a push to the mailbox's owner. Called every minute by the daemon.
 * Each mailbox remembers the newest message already announced.
 */
function ecw_push_new_mail(): void
{
    $mbs = ecw_db()->query('SELECT mb.id, mb.organization_id, mb.connected_by_user_id, mb.push_watermark_id FROM `' . ecw_table('mailboxes') . '` mb
                             WHERE mb.connected_by_user_id IS NOT NULL AND mb.sync_status NOT IN (\'disconnected\')
                               AND EXISTS (SELECT 1 FROM `' . ecw_table('push_subscriptions') . '` p WHERE p.user_id = mb.connected_by_user_id)')->fetchAll();
    foreach ($mbs as $mb) {
        $max = (int)ecw_db()->query('SELECT COALESCE(MAX(id), 0) FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id = ' . (int)$mb['id'])->fetchColumn();
        if ($mb['push_watermark_id'] === null) {   // first time: start from now, never announce old mail
            ecw_db()->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET push_watermark_id = ? WHERE id = ?')->execute([$max, (int)$mb['id']]);
            continue;
        }
        if ($max <= (int)$mb['push_watermark_id']) continue;
        $st = ecw_db()->prepare('SELECT m.id, m.email_thread_id, m.from_name, m.from_email, m.subject FROM `' . ecw_table('email_messages') . '` m
                                   JOIN `' . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id AND f.folder_type = 'inbox'
                                  WHERE m.mailbox_id = ? AND m.id > ? AND m.direction = 'incoming' AND m.is_read = 0 AND m.provider_removed_at IS NULL
                                    AND m.occurred_at >= ? ORDER BY m.id DESC LIMIT 20");
        $st->execute([(int)$mb['id'], (int)$mb['push_watermark_id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        $new = $st->fetchAll();
        ecw_db()->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET push_watermark_id = ? WHERE id = ?')->execute([$max, (int)$mb['id']]);
        if ($new === []) continue;
        $first = $new[0];
        $title = count($new) === 1 ? (string)($first['from_name'] ?: $first['from_email']) : count($new) . ' new emails';
        $body = count($new) === 1 ? (string)($first['subject'] ?: '(no subject)') : implode(' · ', array_map(static fn($m) => (string)($m['from_name'] ?: $m['from_email']), array_slice($new, 0, 4)));
        $url = count($new) === 1 ? 'app.php?thread=' . (int)$first['email_thread_id'] . '&mailbox=' . (int)$mb['id'] : 'app.php';
        // With it, the unread count for the app's taskbar icon (the Mail tab's number).
        $badge = null;
        try {
            require_once __DIR__ . '/unread.php';
            require_once __DIR__ . '/appearance.php';
            $mode = ecw_load_appearance((int)$mb['connected_by_user_id'])['badge_mode'];   // number / dot (-1) / off (0)
            $unread = ecw_unread_badges((int)$mb['organization_id'], (int)$mb['connected_by_user_id'])['mail'];
            $badge = $mode === 'off' || $unread === 0 ? 0 : ($mode === 'dot' ? -1 : $unread);
        } catch (Throwable $e) { $badge = null; }
        ecw_push_to_user((int)$mb['connected_by_user_id'], $title, $body, $url, 'mail-' . (int)$mb['id'], $badge);
    }
}
