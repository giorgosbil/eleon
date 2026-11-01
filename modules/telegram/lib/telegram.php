<?php declare(strict_types=1);
/**
 * Telegram module: a person's own Telegram account, in the app.
 *
 * HOW IT WORKS
 * ------------
 * Each person connects their own Telegram account with their phone number,
 * the code Telegram sends them and (when they have one) their 2-step
 * password. A background process (bin/daemon.php, in the "telegram"
 * container) then stays signed in like a second Telegram Desktop: it appears
 * on their phone under Settings → Devices, where it can also be ended.
 *
 * The web never talks to Telegram itself. It writes jobs (tg_jobs: send the
 * code, send a message, load a chat…) and reads what the background process
 * stored (tg_chats, tg_messages, tg_contacts); lib/sync.php is that side.
 *
 * PRIVATE
 * -------
 * Everything is per person: the tab shows only the signed-in person's own
 * Telegram, and every function here takes their account. Linking a chat to a
 * case is the one thing others see (the case history says a chat was linked).
 *
 * WHERE THE SIGN-IN LIVES
 * -----------------------
 * storage/telegram/sessions/<account>/ on this computer, never in backups or
 * Data push (lib/backup.php copies named folders only). tg_accounts.machine
 * says which computer has it; another computer with a copy of the database
 * shows the chats but does not send (ecw_tg_here).
 */

require_once dirname(__DIR__, 3) . '/lib/db.php';
require_once dirname(__DIR__, 3) . '/lib/crypto.php';
require_once dirname(__DIR__, 3) . '/lib/log.php';
require_once dirname(__DIR__, 3) . '/lib/audit.php';
require_once dirname(__DIR__, 3) . '/lib/cases.php';

const ECW_TG_MAX_UPLOAD = 8 * 1024 * 1024;          // php.ini upload_max_filesize
const ECW_TG_MAX_DOWNLOAD = 20 * 1024 * 1024;       // files kept here; larger ones are opened in Telegram
const ECW_TG_DAEMON_STALE = 90;                     // seconds without a heartbeat = not running

final class EcwTelegramNotConnected extends RuntimeException {}

// ------------------------------------------------------------------ storage

/** storage/telegram[/<sub>], created on first use. */
function ecw_tg_dir(string $sub = ''): string
{
    $dir = dirname(__DIR__, 3) . '/storage/telegram' . ($sub !== '' ? '/' . trim($sub, '/') : '');
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

/** This computer's id (storage/telegram/machine-id): which computer holds a sign-in. */
function ecw_tg_machine_id(): string
{
    $file = ecw_tg_dir() . '/machine-id';
    $id = is_file($file) ? trim((string)file_get_contents($file)) : '';
    if (preg_match('/^[a-f0-9]{32}$/', $id)) return $id;
    $f = @fopen($file, 'x');   // exclusive: two processes starting at once agree
    if ($f) { fwrite($f, bin2hex(random_bytes(16))); fclose($f); }
    return trim((string)file_get_contents($file));
}

// ------------------------------------------------------------------ account

function ecw_tg_account(int $userId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('tg_accounts') . '` WHERE user_id = ?');
    $st->execute([$userId]);
    $a = $st->fetch();
    return $a ?: null;
}

/** The connected account of $userId. @throws EcwTelegramNotConnected */
function ecw_tg_ready_account(int $userId): array
{
    $a = ecw_tg_account($userId);
    if (!$a || $a['status'] !== 'ready') throw new EcwTelegramNotConnected('Your Telegram is not connected. Connect it on the Telegram tab.');
    return $a;
}

/** Is the account's sign-in on this computer (or nowhere yet)? */
function ecw_tg_here(array $a): bool
{
    return empty($a['machine']) || hash_equals((string)$a['machine'], ecw_tg_machine_id());
}

/** The API id / hash from the server's settings (ECW_TELEGRAM_API_ID / _HASH), when the admin set them. */
function ecw_tg_env_api(): ?array
{
    $id = (int)getenv('ECW_TELEGRAM_API_ID');
    $hash = (string)getenv('ECW_TELEGRAM_API_HASH');
    return $id > 0 && preg_match('/^[a-f0-9]{32}$/', $hash) ? ['id' => $id, 'hash' => $hash] : null;
}

/** Is the background process running? (It writes a heartbeat every few seconds.) */
function ecw_tg_daemon_ok(): bool
{
    $f = ecw_tg_dir() . '/daemon-heartbeat';
    return is_file($f) && (int)@filemtime($f) > time() - ECW_TG_DAEMON_STALE;
}

/** What the tab may show about the account: no secrets. */
function ecw_tg_status(int $userId): array
{
    $a = ecw_tg_account($userId);
    $pending = $a ? ecw_tg_pending_login($a) : false;
    return [
        'status'    => $a['status'] ?? 'none',
        'phone'     => (string)($a['phone'] ?? ''),
        'name'      => (string)($a['name'] ?? ''),
        'username'  => (string)($a['username'] ?? ''),
        'hint'      => (string)($a['hint'] ?? ''),
        'error'     => $a['last_error'] ?? null,
        'busy'      => $pending,
        'here'      => $a ? ecw_tg_here($a) : true,
        'daemon_ok' => ecw_tg_daemon_ok(),
        'needs_api' => ecw_tg_env_api() === null,
        'has_api'   => !empty($a['api_id']),
        'synced'    => !empty($a['dialogs_synced_at']),
    ];
}

/** Is a sign-in step still waiting for the background process? */
function ecw_tg_pending_login(array $a): bool
{
    $st = ecw_db()->prepare("SELECT 1 FROM `" . ecw_table('tg_jobs') . "` WHERE account_id = ? AND status IN ('queued', 'running') AND type IN ('send_code', 'code', 'password', 'logout') LIMIT 1");
    $st->execute([(int)$a['id']]);
    return (bool)$st->fetchColumn();
}

/** Digits with the country code, as Telegram wants it (+…); null when it cannot be one (it must start with + or 00). */
function ecw_tg_phone(string $in): ?string
{
    $in = trim($in);
    if (!str_starts_with($in, '+') && !str_starts_with($in, '00')) return null;
    $d = preg_replace('/\D/', '', $in);
    if (str_starts_with($in, '00')) $d = substr($d, 2);
    return strlen($d) >= 8 && strlen($d) <= 15 && $d[0] !== '0' ? '+' . $d : null;
}

/** A job for the background process. The payload is stored encrypted (codes, passwords). */
function ecw_tg_queue(int $accountId, string $type, array $payload = []): int
{
    ecw_db()->prepare('INSERT INTO `' . ecw_table('tg_jobs') . '` (account_id, type, payload_enc) VALUES (?, ?, ?)')
        ->execute([$accountId, $type, $payload ? ecw_encrypt(json_encode($payload)) : null]);
    return (int)ecw_db()->lastInsertId();
}

/** Step 1: the phone number (and the API id/hash unless the server has them). Telegram then sends a code. */
function ecw_tg_login_start(int $orgId, int $userId, string $phone, string $apiId = '', string $apiHash = ''): array
{
    $num = ecw_tg_phone($phone);
    if ($num === null) throw new InvalidArgumentException('Enter your phone number with its country code, e.g. +30 69…');
    $a = ecw_tg_account($userId);
    if ($a && $a['status'] === 'ready') throw new InvalidArgumentException('Your Telegram is already connected. Disconnect it first.');
    $apiId = trim($apiId); $apiHash = strtolower(trim($apiHash));
    $useOwn = $apiId !== '' || $apiHash !== '';
    if ($useOwn && (!preg_match('/^\d{3,12}$/', $apiId) || !preg_match('/^[a-f0-9]{32}$/', $apiHash))) {
        throw new InvalidArgumentException('The api_id is a number and the api_hash 32 letters and digits, both from my.telegram.org → API development tools.');
    }
    if (!$useOwn && ecw_tg_env_api() === null && empty($a['api_id'])) throw new InvalidArgumentException('Paste the api_id and api_hash from my.telegram.org first.');
    if ($a && !ecw_tg_here($a)) throw new InvalidArgumentException('Your Telegram is signed in on another computer that runs this app. Connect it there.');
    $pdo = ecw_db();
    if (!$a) {
        $pdo->prepare('INSERT INTO `' . ecw_table('tg_accounts') . '` (organization_id, user_id, status) VALUES (?, ?, \'new\')')->execute([$orgId, $userId]);
        $a = ecw_tg_account($userId);
    }
    // Another number: the chats of the old one are not this account's.
    if ($a['phone'] && $a['phone'] !== $num) {
        $pdo->prepare('DELETE FROM `' . ecw_table('tg_chats') . '` WHERE account_id = ?')->execute([(int)$a['id']]);
        $pdo->prepare('DELETE FROM `' . ecw_table('tg_contacts') . '` WHERE account_id = ?')->execute([(int)$a['id']]);
        $pdo->prepare('UPDATE `' . ecw_table('tg_accounts') . '` SET tg_user_id = NULL, name = NULL, username = NULL, dialogs_synced_at = NULL, contacts_synced_at = NULL WHERE id = ?')->execute([(int)$a['id']]);
    }
    $pdo->prepare("DELETE FROM `" . ecw_table('tg_jobs') . "` WHERE account_id = ? AND status IN ('queued', 'running')")->execute([(int)$a['id']]);
    $set = 'status = \'new\', phone = ?, hint = NULL, last_error = NULL, machine = ?';
    $args = [$num, ecw_tg_machine_id()];
    if ($useOwn) { $set .= ', api_id = ?, api_hash_enc = ?'; $args[] = (int)$apiId; $args[] = ecw_encrypt($apiHash); }
    $pdo->prepare('UPDATE `' . ecw_table('tg_accounts') . "` SET {$set} WHERE id = ?")->execute([...$args, (int)$a['id']]);
    ecw_tg_queue((int)$a['id'], 'send_code', ['phone' => $num]);
    ecw_audit('telegram.login_started', null, null, [], $orgId, $userId);
    return ecw_tg_status($userId);
}

/** Step 2: the code Telegram sent (in the Telegram app, or by SMS). */
function ecw_tg_login_code(int $userId, string $code): array
{
    $a = ecw_tg_account($userId);
    if (!$a || $a['status'] !== 'code') throw new InvalidArgumentException('Ask for a new code first.');
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) < 4 || strlen($code) > 8) throw new InvalidArgumentException('Enter the code Telegram sent you (5 digits).');
    ecw_tg_queue((int)$a['id'], 'code', ['code' => $code]);
    ecw_db()->prepare('UPDATE `' . ecw_table('tg_accounts') . '` SET last_error = NULL WHERE id = ?')->execute([(int)$a['id']]);
    return ecw_tg_status($userId);
}

/** Step 3, only with 2-step verification: the password. Kept encrypted until used, then deleted. */
function ecw_tg_login_password(int $userId, string $password): array
{
    $a = ecw_tg_account($userId);
    if (!$a || $a['status'] !== 'password') throw new InvalidArgumentException('Telegram is not asking for your password.');
    if ($password === '' || strlen($password) > 256) throw new InvalidArgumentException('Enter your Telegram 2-step verification password.');
    ecw_tg_queue((int)$a['id'], 'password', ['password' => $password]);
    ecw_db()->prepare('UPDATE `' . ecw_table('tg_accounts') . '` SET last_error = NULL WHERE id = ?')->execute([(int)$a['id']]);
    return ecw_tg_status($userId);
}

/** Sign this app out of Telegram (the chats stay readable here). */
function ecw_tg_disconnect(int $orgId, int $userId): array
{
    $a = ecw_tg_account($userId);
    if (!$a) return ecw_tg_status($userId);
    ecw_db()->prepare("DELETE FROM `" . ecw_table('tg_jobs') . "` WHERE account_id = ? AND status = 'queued'")->execute([(int)$a['id']]);
    if (ecw_tg_here($a)) ecw_tg_queue((int)$a['id'], 'logout');
    ecw_db()->prepare('UPDATE `' . ecw_table('tg_accounts') . "` SET status = 'logged_out', hint = NULL, last_error = NULL WHERE id = ?")->execute([(int)$a['id']]);
    ecw_audit('telegram.disconnected', null, null, [], $orgId, $userId);
    return ecw_tg_status($userId);
}

/** A job of this person's, for the tab to wait on. */
function ecw_tg_job(int $userId, int $jobId): array
{
    $st = ecw_db()->prepare('SELECT j.status, j.result_json, j.error FROM `' . ecw_table('tg_jobs') . '` j JOIN `' . ecw_table('tg_accounts') . '` a ON a.id = j.account_id
                              WHERE j.id = ? AND a.user_id = ?');
    $st->execute([$jobId, $userId]);
    $j = $st->fetch();
    if (!$j) throw new InvalidArgumentException('That request does not exist.');
    return ['status' => $j['status'], 'result' => json_decode((string)$j['result_json'], true), 'error' => $j['error']];
}

/** Case history (hook case.event_label): how the module's events read. */
function ecw_tg_event_label(string $type, array $m): ?string
{
    $title = isset($m['title']) ? ' (' . $m['title'] . ')' : '';
    return match ($type) {
        'TELEGRAM_LINKED'   => 'linked a Telegram chat' . $title,
        'TELEGRAM_UNLINKED' => 'unlinked a Telegram chat' . $title,
        default => null,
    };
}

// -------------------------------------------------------------------- chats

function ecw_tg_chat(int $accountId, int $chatId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('tg_chats') . '` WHERE id = ? AND account_id = ?');
    $st->execute([$chatId, $accountId]);
    $r = $st->fetch();
    return $r ?: null;
}

function ecw_tg_title(array $chat): string
{
    return (string)($chat['name'] ?: ($chat['username'] ? '@' . $chat['username'] : ($chat['phone'] ? '+' . ltrim((string)$chat['phone'], '+') : 'Telegram user')));
}

/** The chat list, newest first. */
function ecw_tg_chats(int $accountId): array
{
    $st = ecw_db()->prepare('SELECT t.*, c.name AS case_name, c.case_number FROM `' . ecw_table('tg_chats') . '` t
                               LEFT JOIN `' . ecw_table('cases') . '` c ON c.id = t.case_id AND c.deleted_at IS NULL
                              WHERE t.account_id = ? ORDER BY t.last_message_at IS NULL, t.last_message_at DESC, t.id DESC LIMIT 500');
    $st->execute([$accountId]);
    return array_map('ecw_tg_chat_out', $st->fetchAll());
}

function ecw_tg_chat_out(array $r): array
{
    return [
        'id' => (int)$r['id'], 'name' => (string)($r['name'] ?? ''), 'username' => (string)($r['username'] ?? ''),
        'phone' => $r['phone'] ? '+' . ltrim((string)$r['phone'], '+') : '',
        'title' => ecw_tg_title($r),
        'case_id' => $r['case_id'] !== null && ($r['case_name'] ?? null) !== null ? (int)$r['case_id'] : null,
        'case_label' => isset($r['case_name']) && $r['case_name'] !== null ? trim(($r['case_number'] ? '#' . $r['case_number'] . ' ' : '') . $r['case_name']) : null,
        'last_at' => $r['last_message_at'] ? gmdate('c', strtotime($r['last_message_at'] . ' UTC')) : null,
        'preview' => (string)($r['last_preview'] ?? ''), 'unread' => (int)$r['unread'],
        'loading' => (int)$r['history_loaded'] < 2,
    ];
}

/**
 * A chat's messages (oldest first). The first time, its history is asked from
 * Telegram; opening it marks it read, on the phone too.
 */
function ecw_tg_messages(array $account, int $chatId): array
{
    $accountId = (int)$account['id'];
    $chat = ecw_tg_chat($accountId, $chatId);
    if (!$chat) throw new InvalidArgumentException('That Telegram chat does not exist.');
    $here = ecw_tg_here($account) && $account['status'] === 'ready';
    if ((int)$chat['history_loaded'] === 0 && $here) {
        ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET history_loaded = 1 WHERE id = ?')->execute([$chatId]);
        ecw_tg_queue($accountId, 'history', ['chat_id' => $chatId]);
        $chat['history_loaded'] = 1;
    }
    $st = ecw_db()->prepare('SELECT m.*, u.name AS sender FROM `' . ecw_table('tg_messages') . '` m
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = m.sent_by_user_id
                              WHERE m.chat_id = ? ORDER BY m.occurred_at DESC, m.id DESC LIMIT 300');
    $st->execute([$chatId]);
    $rows = array_reverse($st->fetchAll());
    if ((int)$chat['unread'] > 0) {
        ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET unread = 0 WHERE id = ?')->execute([$chatId]);
        $max = 0;
        foreach ($rows as $r) if ($r['direction'] === 'in') $max = max($max, (int)$r['tg_message_id']);
        if ($max > 0 && $here) ecw_tg_queue($accountId, 'read', ['chat_id' => $chatId, 'max_id' => $max]);
    }
    $readUpTo = (int)$chat['read_outbox_max_id'];
    return [
        'chat' => ecw_tg_chat_out($chat + ecw_tg_case_names($chat['case_id'])),
        'messages' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'direction' => $r['direction'], 'kind' => $r['kind'], 'body' => (string)($r['body'] ?? ''),
            'has_media' => !empty($r['file_path']), 'mime' => (string)($r['media_mime'] ?? ''), 'filename' => (string)($r['filename'] ?? ''),
            'size' => $r['file_size'] !== null ? (int)$r['file_size'] : null,
            // Their Telegram tells us how far they have read: ✓✓.
            'status' => $r['direction'] === 'out' && $r['status'] === 'sent' && $r['tg_message_id'] !== null && (int)$r['tg_message_id'] <= $readUpTo ? 'read' : $r['status'],
            'error' => $r['error'],
            'sender' => $r['direction'] === 'out' ? ($r['sender'] ?? 'In Telegram') : null,
            'edited' => $r['edited_at'] !== null,
            'at' => gmdate('c', strtotime($r['occurred_at'] . ' UTC')),
        ], $rows),
    ];
}

/** ['case_name' => …, 'case_number' => …] of a linked case (deleted cases have no name). */
function ecw_tg_case_names($caseId): array
{
    if (!$caseId) return ['case_name' => null, 'case_number' => null];
    $st = ecw_db()->prepare('SELECT name, case_number FROM `' . ecw_table('cases') . '` WHERE id = ? AND deleted_at IS NULL');
    $st->execute([(int)$caseId]);
    $c = $st->fetch();
    return ['case_name' => $c ? (string)$c['name'] : null, 'case_number' => $c ? $c['case_number'] : null];
}

/**
 * Send text and/or a file. Stored at once as "sending"; the background process
 * sends it and marks it sent (or failed, with the reason).
 * $file: ['tmp' => uploaded file, 'name' => …, 'mime' => …, 'size' => …] or null.
 */
function ecw_tg_send(array $account, int $chatId, int $userId, string $text, ?array $file = null): array
{
    $text = trim($text);
    if ($text === '' && $file === null) throw new InvalidArgumentException('Write a message or choose a file.');
    if (mb_strlen($text) > ($file ? 1024 : 4096)) throw new InvalidArgumentException($file ? 'A file\'s caption can have up to 1024 characters.' : 'Telegram messages can have up to 4096 characters.');
    if (!ecw_tg_here($account)) throw new InvalidArgumentException('Your Telegram is signed in on another computer that runs this app; send from there.');
    $chat = ecw_tg_chat((int)$account['id'], $chatId);
    if (!$chat) throw new InvalidArgumentException('That Telegram chat does not exist.');
    $kind = 'text'; $name = null; $mime = null; $size = null;
    if ($file !== null) {
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || !is_file((string)$file['tmp'])) throw new InvalidArgumentException('The file did not arrive. Try again.');
        if ($size > ECW_TG_MAX_UPLOAD) throw new InvalidArgumentException('Files up to 8 MB can be sent from here; send larger ones from Telegram.');
        $name = mb_substr(preg_replace('/[\x00-\x1F\/\\\\]+/u', '_', basename((string)$file['name'])) ?: 'file', 0, 200);
        $mime = (string)(function_exists('mime_content_type') ? (mime_content_type((string)$file['tmp']) ?: '') : '') ?: 'application/octet-stream';
        // A photo is sent as a photo (compressed, shown inline); anything else as a file.
        $kind = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && $size <= 10 * 1024 * 1024 ? 'image' : 'document';
    }
    $now = gmdate('Y-m-d H:i:s');
    $pdo = ecw_db();
    $pdo->prepare('INSERT INTO `' . ecw_table('tg_messages') . '` (chat_id, direction, kind, body, media_mime, filename, file_size, status, sent_by_user_id, occurred_at)
                   VALUES (?, \'out\', ?, ?, ?, ?, ?, \'sending\', ?, ?)')->execute([$chatId, $kind, $text !== '' ? $text : null, $mime, $name, $size, $userId, $now]);
    $id = (int)$pdo->lastInsertId();
    if ($file !== null) {
        $rel = 'out/' . (int)$account['id'] . '/' . $id . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$name);
        ecw_tg_dir(dirname($rel));
        $dest = ecw_tg_dir() . '/' . $rel;
        $moved = is_uploaded_file((string)$file['tmp']) ? move_uploaded_file((string)$file['tmp'], $dest) : copy((string)$file['tmp'], $dest);
        if (!$moved) {
            $pdo->prepare('DELETE FROM `' . ecw_table('tg_messages') . '` WHERE id = ?')->execute([$id]);
            throw new RuntimeException('The file could not be kept for sending.');
        }
        $pdo->prepare('UPDATE `' . ecw_table('tg_messages') . '` SET file_path = ? WHERE id = ?')->execute([$rel, $id]);
    }
    $preview = $file !== null ? ($kind === 'image' ? '📷 Photo' : '📄 ' . $name) . ($text !== '' ? ': ' . $text : '') : $text;
    $pdo->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET last_message_at = ?, last_preview = ? WHERE id = ?')->execute([$now, mb_substr('You: ' . $preview, 0, 250), $chatId]);
    ecw_tg_queue((int)$account['id'], 'send', ['message_id' => $id]);
    return ['id' => $id];
}

// ----------------------------------------------------------------- contacts

/** Telegram contacts matching $q (name, @username or phone), for starting a chat. */
function ecw_tg_contacts(int $accountId, string $q = ''): array
{
    $q = trim($q);
    $sql = 'SELECT peer_id, name, username, phone FROM `' . ecw_table('tg_contacts') . '` WHERE account_id = ?';
    $args = [$accountId];
    if ($q !== '') {
        $like = '%' . addcslashes(ltrim($q, '@'), '%_\\') . '%';
        $digits = preg_replace('/\D/', '', $q);
        $sql .= ' AND (name LIKE ? OR username LIKE ?' . ($digits !== '' ? ' OR phone LIKE ?' : '') . ')';
        array_push($args, $like, $like);
        if ($digits !== '') $args[] = '%' . $digits . '%';
    }
    $st = ecw_db()->prepare($sql . ' ORDER BY name LIMIT 60');
    $st->execute($args);
    return array_map(static fn($c) => ['peer_id' => (string)$c['peer_id'], 'name' => (string)($c['name'] ?? ''), 'username' => (string)($c['username'] ?? ''),
                                       'phone' => $c['phone'] ? '+' . ltrim((string)$c['phone'], '+') : ''], $st->fetchAll());
}

/** Open (or start) the chat with one of the person's contacts. @return int chat id */
function ecw_tg_open_contact(int $accountId, int $peerId): int
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('tg_contacts') . '` WHERE account_id = ? AND peer_id = ?');
    $st->execute([$accountId, $peerId]);
    $c = $st->fetch();
    if (!$c) throw new InvalidArgumentException('That contact does not exist.');
    return (int)ecw_tg_chat_upsert($accountId, $peerId, ['name' => (string)$c['name'], 'username' => (string)$c['username'], 'phone' => (string)$c['phone']])['id'];
}

/** Write to a phone number that is not a contact yet: it is added to the person's Telegram contacts, as the app does. @return int job id */
function ecw_tg_new_number(array $account, string $phone, string $first, string $last = ''): int
{
    $num = ecw_tg_phone($phone);
    if ($num === null) throw new InvalidArgumentException('Enter the phone number with its country code, e.g. +30 69…');
    $first = trim($first);
    if ($first === '' || mb_strlen($first) > 64 || mb_strlen($last) > 64) throw new InvalidArgumentException('Enter the person\'s first name (it is saved in your Telegram contacts).');
    if (!ecw_tg_here($account)) throw new InvalidArgumentException('Your Telegram is signed in on another computer that runs this app; start the chat there.');
    return ecw_tg_queue((int)$account['id'], 'import', ['phone' => $num, 'first' => $first, 'last' => trim($last)]);
}

function ecw_tg_chat_upsert(int $accountId, int $peerId, array $who = []): array
{
    $name = trim((string)($who['name'] ?? ''));
    $username = trim((string)($who['username'] ?? ''));
    $phone = preg_replace('/\D/', '', (string)($who['phone'] ?? ''));
    $pdo = ecw_db();
    $pdo->prepare('INSERT INTO `' . ecw_table('tg_chats') . '` (account_id, peer_id, name, username, phone) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE name = COALESCE(NULLIF(VALUES(name), \'\'), name), username = COALESCE(NULLIF(VALUES(username), \'\'), username), phone = COALESCE(NULLIF(VALUES(phone), \'\'), phone)')
        ->execute([$accountId, $peerId, $name !== '' ? mb_substr($name, 0, 190) : null, $username !== '' ? mb_substr($username, 0, 64) : null, $phone !== '' ? $phone : null]);
    $st = $pdo->prepare('SELECT * FROM `' . ecw_table('tg_chats') . '` WHERE account_id = ? AND peer_id = ?');
    $st->execute([$accountId, $peerId]);
    return $st->fetch();
}

/** A file of a message (theirs or sent from here), kept on this computer. @return array{path:string, mime:string, filename:string} */
function ecw_tg_media(int $accountId, int $messageId): array
{
    $st = ecw_db()->prepare('SELECT m.* FROM `' . ecw_table('tg_messages') . '` m JOIN `' . ecw_table('tg_chats') . '` c ON c.id = m.chat_id
                              WHERE m.id = ? AND c.account_id = ?');
    $st->execute([$messageId, $accountId]);
    $m = $st->fetch();
    $rel = (string)($m['file_path'] ?? '');
    if (!$m || $rel === '' || str_contains($rel, '..')) throw new InvalidArgumentException('That file does not exist.');
    $path = ecw_tg_dir() . '/' . $rel;
    if (!is_file($path)) throw new InvalidArgumentException('That file is not on this computer. Open it in Telegram.');
    $mime = (string)($m['media_mime'] ?: 'application/octet-stream');
    return ['path' => $path, 'mime' => $mime, 'filename' => (string)($m['filename'] ?: 'telegram-' . $messageId . '.' . (pathinfo($rel, PATHINFO_EXTENSION) ?: 'bin'))];
}

/** How many chats have unread messages. */
function ecw_tg_unread(int $accountId): int
{
    $st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('tg_chats') . '` WHERE account_id = ? AND unread > 0');
    $st->execute([$accountId]);
    return (int)$st->fetchColumn();
}
