<?php declare(strict_types=1);
/**
 * The background process's side of Telegram (bin/daemon.php): it does the jobs
 * the web queued (lib/telegram.php) and stores what Telegram reports, through
 * an EcwTgClient (lib/client.php). Private chats only: a peer > 0 is a person.
 *
 * Signed in, it first stores the latest 100 chats (their last message) and the
 * contacts; a chat's history is loaded when it is first opened. New messages,
 * edits and "read" reports arrive as updates. Photos, voice messages and files
 * up to 20 MB are downloaded right away and kept under storage/telegram/media.
 */

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/client.php';
require_once dirname(__DIR__, 3) . '/lib/notifications.php';
require_once dirname(__DIR__, 3) . '/lib/modules.php';

const ECW_TG_DIALOGS = 100;
const ECW_TG_HISTORY = 60;
const ECW_TG_CONTACTS_EVERY = 6 * 3600;

/** Telegram's error as a sentence a person can act on. */
function ecw_tg_sync_error(Throwable $e): string
{
    $m = $e->getMessage();
    $rpc = property_exists($e, 'rpc') ? (string)$e->rpc : $m;
    return match (true) {
        str_contains($rpc, 'PHONE_CODE_INVALID') => 'That code is not right. Check it and try again.',
        str_contains($rpc, 'PHONE_CODE_EXPIRED') => 'That code has expired. Ask for a new one.',
        str_contains($rpc, 'PASSWORD_HASH_INVALID') => 'That password is not right.',
        str_contains($rpc, 'PHONE_NUMBER_INVALID') => 'Telegram does not know this phone number. Check it, with the country code.',
        str_contains($rpc, 'PHONE_NUMBER_BANNED') => 'Telegram has banned this phone number.',
        str_contains($rpc, 'API_ID_INVALID') || str_contains($rpc, 'API_ID_PUBLISHED_FLOOD') => 'Telegram refused the api_id / api_hash. Check them at my.telegram.org.',
        str_contains($rpc, 'FLOOD_WAIT') || str_contains($rpc, 'FLOOD') => 'Telegram asks to wait before trying again' . (preg_match('/(\d+)/', $rpc, $mm) ? ' (' . ceil((int)$mm[1] / 60) . ' min).' : '.'),
        str_contains($rpc, 'USER_PRIVACY_RESTRICTED') || str_contains($rpc, 'PRIVACY') => 'This person\'s privacy settings do not allow it.',
        str_contains($rpc, 'PEER_FLOOD') => 'Telegram limits this account from writing to people who have not written to it first. Try again later.',
        str_contains($rpc, 'USER_IS_BLOCKED') || str_contains($rpc, 'YOU_BLOCKED_USER') => 'This chat is blocked.',
        str_contains($rpc, 'INPUT_USER_DEACTIVATED') || str_contains($rpc, 'USER_DEACTIVATED') => 'This Telegram account has been deleted.',
        str_contains($rpc, 'AUTH_KEY_UNREGISTERED') || str_contains($rpc, 'SESSION_REVOKED') || str_contains($rpc, 'AUTH_KEY_DUPLICATED') => 'Telegram ended this sign-in. Connect again.',
        default => 'Telegram: ' . mb_substr($m, 0, 200),
    };
}

/** Did Telegram end this sign-in (signed out from the phone, or the session is gone)? */
function ecw_tg_sync_signed_out(Throwable $e): bool
{
    $s = (property_exists($e, 'rpc') ? (string)$e->rpc : '') . ' ' . $e->getMessage();
    return (bool)preg_match('/AUTH_KEY_UNREGISTERED|SESSION_REVOKED|AUTH_KEY_DUPLICATED|USER_DEACTIVATED_BAN|logged out/i', $s);
}

function ecw_tg_sync_account_set(int $accountId, array $fields): void
{
    $set = implode(', ', array_map(static fn($k) => "`{$k}` = ?", array_keys($fields)));
    ecw_db()->prepare('UPDATE `' . ecw_table('tg_accounts') . "` SET {$set} WHERE id = ?")->execute([...array_values($fields), $accountId]);
}

/** The next queued job of an account, claimed (running), or null. */
function ecw_tg_sync_next_job(int $accountId): ?array
{
    $pdo = ecw_db();
    $st = $pdo->prepare("SELECT * FROM `" . ecw_table('tg_jobs') . "` WHERE account_id = ? AND status = 'queued' ORDER BY id LIMIT 1");
    $st->execute([$accountId]);
    $j = $st->fetch();
    if (!$j) return null;
    $claim = $pdo->prepare("UPDATE `" . ecw_table('tg_jobs') . "` SET status = 'running' WHERE id = ? AND status = 'queued'");
    $claim->execute([(int)$j['id']]);
    if ($claim->rowCount() === 0) return null;
    $j['payload'] = $j['payload_enc'] ? (json_decode((string)ecw_decrypt((string)$j['payload_enc']), true) ?: []) : [];
    return $j;
}

/** Done (or failed). The payload is wiped: a code or password is not kept. */
function ecw_tg_sync_finish(array $job, ?string $error = null, ?array $result = null): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('tg_jobs') . '` SET status = ?, error = ?, result_json = ?, payload_enc = NULL, done_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$error === null ? 'done' : 'failed', $error !== null ? mb_substr($error, 0, 250) : null, $result !== null ? json_encode($result) : null, (int)$job['id']]);
}

/**
 * Do one job. Login jobs move the account's status on; everything else needs
 * it signed in. Never throws: a failure is kept on the job (and the account,
 * for login steps).
 */
function ecw_tg_sync_job(EcwTgClient $c, array $account, array $job): void
{
    $id = (int)$account['id'];
    $p = $job['payload'];
    try {
        switch ($job['type']) {
            case 'send_code':
                $where = $c->sendCode((string)$p['phone']);
                ecw_tg_sync_account_set($id, ['status' => 'code', 'hint' => 'Telegram sent the code ' . $where . '.', 'last_error' => null]);
                break;
            case 'code':
                $hint = $c->signIn((string)$p['code']);
                if ($hint !== null) { ecw_tg_sync_account_set($id, ['status' => 'password', 'hint' => $hint, 'last_error' => null]); break; }
                ecw_tg_sync_signed_in($c, $account);
                break;
            case 'password':
                $c->checkPassword((string)$p['password']);
                ecw_tg_sync_signed_in($c, $account);
                break;
            case 'logout':
                try { $c->logout(); } catch (Throwable $e) { /* already ended */ }
                ecw_tg_sync_account_set($id, ['status' => 'logged_out', 'hint' => null]);
                break;
            case 'send':
                ecw_tg_sync_send($c, $account, (int)$p['message_id']);
                break;
            case 'history':
                ecw_tg_sync_history($c, $account, (int)$p['chat_id']);
                break;
            case 'read':
                $chat = ecw_tg_chat($id, (int)$p['chat_id']);
                if ($chat) $c->read((int)$chat['peer_id'], (int)$p['max_id']);
                break;
            case 'import':
                $u = $c->importContact((string)$p['phone'], (string)$p['first'], (string)($p['last'] ?? ''));
                if ($u === null || empty($u['id'])) { ecw_tg_sync_finish($job, 'This number is not on Telegram (or does not let others find it by number).'); return; }
                ecw_tg_sync_contact_store($id, $u);
                ecw_tg_sync_finish($job, null, ['chat_id' => (int)ecw_tg_chat_upsert($id, (int)$u['id'], ecw_tg_sync_who($u))['id']]);
                return;
        }
        ecw_tg_sync_finish($job);
    } catch (Throwable $e) {
        $msg = ecw_tg_sync_error($e);
        ecw_tg_sync_finish($job, $msg);
        if (in_array($job['type'], ['send_code', 'code', 'password'], true)) {
            // A wrong code or password: try again. Anything else: start over.
            $again = (bool)preg_match('/not right/', $msg);
            ecw_tg_sync_account_set($id, ['status' => $again ? ($job['type'] === 'password' ? 'password' : 'code') : 'error', 'last_error' => $msg]);
        } elseif (ecw_tg_sync_signed_out($e)) {
            ecw_tg_sync_account_set($id, ['status' => 'logged_out', 'last_error' => $msg]);
        }
        if ($job['type'] === 'send') ecw_tg_sync_mark_failed((int)$p['message_id'], $msg);
        if (!in_array($job['type'], ['code', 'password'], true)) ecw_log('telegram ' . $job['type'] . ' failed (account ' . $id . '): ' . $e->getMessage());
    }
}

/** Signed in: remember who, then store the latest chats and the contacts. */
function ecw_tg_sync_signed_in(EcwTgClient $c, array $account): void
{
    $me = $c->me();
    $id = (int)$account['id'];
    ecw_tg_sync_account_set($id, ['status' => 'ready', 'hint' => null, 'last_error' => null, 'tg_user_id' => (int)($me['id'] ?? 0) ?: null,
        'name' => mb_substr(trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? '')), 0, 190) ?: null,
        'username' => ($me['username'] ?? '') !== '' ? (string)$me['username'] : null, 'connected_at' => gmdate('Y-m-d H:i:s')]);
    ecw_audit('telegram.connected', null, null, [], (int)$account['organization_id'], (int)$account['user_id']);
    try { ecw_tg_sync_dialogs($c, $id); } catch (Throwable $e) { ecw_log('telegram first sync failed (account ' . $id . '): ' . $e->getMessage()); }
    try { ecw_tg_sync_contacts($c, $id); } catch (Throwable $e) { ecw_log('telegram contacts failed (account ' . $id . '): ' . $e->getMessage()); }
}

/** name / username / phone of a Telegram user array. */
function ecw_tg_sync_who(array $u): array
{
    return ['name' => trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? '')), 'username' => (string)($u['username'] ?? ''), 'phone' => (string)($u['phone'] ?? '')];
}

/** @param list<array> $users @return array<int, array> by id */
function ecw_tg_sync_users(array $users): array
{
    $out = [];
    foreach ($users as $u) if (is_array($u) && isset($u['id'])) $out[(int)$u['id']] = $u;
    return $out;
}

/** The latest chats with their last message and unread count. */
function ecw_tg_sync_dialogs(EcwTgClient $c, int $accountId): void
{
    $d = $c->dialogs(ECW_TG_DIALOGS);
    $users = ecw_tg_sync_users($d['users']);
    $tops = [];
    foreach ($d['messages'] as $m) if (is_array($m) && isset($m['peer_id'], $m['id'])) $tops[(int)$m['peer_id'] . ':' . (int)$m['id']] = $m;
    foreach ($d['dialogs'] as $dlg) {
        $peer = (int)($dlg['peer'] ?? 0);
        if ($peer <= 0 || !empty($users[$peer]['deleted'])) continue;   // groups and channels are not shown
        $chat = ecw_tg_chat_upsert($accountId, $peer, ecw_tg_sync_who($users[$peer] ?? []));
        $top = $tops[$peer . ':' . (int)($dlg['top_message'] ?? 0)] ?? null;
        if ($top) ecw_tg_sync_store($c, $accountId, $top, $users, false);
        ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET unread = ?, read_outbox_max_id = GREATEST(read_outbox_max_id, ?) WHERE id = ?')
            ->execute([max(0, (int)($dlg['unread_count'] ?? 0)), (int)($dlg['read_outbox_max_id'] ?? 0), (int)$chat['id']]);
    }
    ecw_tg_sync_account_set($accountId, ['dialogs_synced_at' => gmdate('Y-m-d H:i:s')]);
}

function ecw_tg_sync_contact_store(int $accountId, array $u): void
{
    $w = ecw_tg_sync_who($u);
    ecw_db()->prepare('INSERT INTO `' . ecw_table('tg_contacts') . '` (account_id, peer_id, name, username, phone) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE name = VALUES(name), username = VALUES(username), phone = VALUES(phone)')
        ->execute([$accountId, (int)$u['id'], mb_substr($w['name'], 0, 190) ?: null, $w['username'] ?: null, preg_replace('/\D/', '', $w['phone']) ?: null]);
}

/** The person's Telegram contacts, replaced. */
function ecw_tg_sync_contacts(EcwTgClient $c, int $accountId): void
{
    $users = $c->contacts();
    ecw_db()->prepare('DELETE FROM `' . ecw_table('tg_contacts') . '` WHERE account_id = ?')->execute([$accountId]);
    foreach ($users as $u) if (is_array($u) && (int)($u['id'] ?? 0) > 0 && empty($u['deleted'])) ecw_tg_sync_contact_store($accountId, $u);
    ecw_tg_sync_account_set($accountId, ['contacts_synced_at' => gmdate('Y-m-d H:i:s')]);
}

/** A chat's latest messages, when it is first opened. */
function ecw_tg_sync_history(EcwTgClient $c, array $account, int $chatId): void
{
    $chat = ecw_tg_chat((int)$account['id'], $chatId);
    if (!$chat) return;
    $h = $c->history((int)$chat['peer_id'], ECW_TG_HISTORY);
    $users = ecw_tg_sync_users($h['users']);
    foreach (array_reverse($h['messages']) as $m) if (is_array($m)) ecw_tg_sync_store($c, (int)$account['id'], $m, $users, false);
    ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET history_loaded = 2 WHERE id = ?')->execute([$chatId]);
}

/** Send a message written here; it gets its Telegram id, or fails with the reason. */
function ecw_tg_sync_send(EcwTgClient $c, array $account, int $messageId): void
{
    $st = ecw_db()->prepare("SELECT m.*, c.peer_id FROM `" . ecw_table('tg_messages') . "` m JOIN `" . ecw_table('tg_chats') . "` c ON c.id = m.chat_id
                              WHERE m.id = ? AND c.account_id = ? AND m.status = 'sending'");
    $st->execute([$messageId, (int)$account['id']]);
    $m = $st->fetch();
    if (!$m) return;
    $peer = (int)$m['peer_id'];
    $text = (string)($m['body'] ?? '');
    if ($m['file_path']) {
        $path = ecw_tg_dir() . '/' . $m['file_path'];
        if (!is_file($path)) throw new RuntimeException('The file to send is gone.');
        $tgId = $c->sendFile($peer, $path, (string)$m['filename'], (string)$m['media_mime'], $text, $m['kind'] === 'image');
    } else {
        $tgId = $c->sendText($peer, $text);
    }
    // Telegram reports our own message back as an update; the id makes it one row.
    ecw_db()->prepare("UPDATE `" . ecw_table('tg_messages') . "` SET tg_message_id = ?, status = 'sent', error = NULL WHERE id = ?")->execute([$tgId ?: null, $messageId]);
}

function ecw_tg_sync_mark_failed(int $messageId, string $error): void
{
    ecw_db()->prepare("UPDATE `" . ecw_table('tg_messages') . "` SET status = 'failed', error = ? WHERE id = ? AND status = 'sending'")->execute([mb_substr($error, 0, 250), $messageId]);
}

/** One update from Telegram. */
function ecw_tg_sync_update(EcwTgClient $c, array $account, array $u): void
{
    $id = (int)$account['id'];
    switch ($u['_'] ?? '') {
        case 'updateNewMessage':
            if (is_array($u['message'] ?? null)) ecw_tg_sync_store($c, $id, $u['message'], [], true);
            break;
        case 'updateEditMessage':
            $m = (array)($u['message'] ?? []);
            $peer = (int)($m['peer_id'] ?? 0);
            if ($peer <= 0 || !isset($m['id'])) break;
            [, $body] = ecw_tg_sync_content($m);
            ecw_db()->prepare('UPDATE `' . ecw_table('tg_messages') . '` m JOIN `' . ecw_table('tg_chats') . '` c ON c.id = m.chat_id
                                  SET m.body = ?, m.edited_at = UTC_TIMESTAMP() WHERE c.account_id = ? AND c.peer_id = ? AND m.tg_message_id = ?')
                ->execute([$body, $id, $peer, (int)$m['id']]);
            break;
        case 'updateReadHistoryOutbox':   // they read our messages: ✓✓
            ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET read_outbox_max_id = GREATEST(read_outbox_max_id, ?) WHERE account_id = ? AND peer_id = ?')
                ->execute([(int)($u['max_id'] ?? 0), $id, (int)($u['peer'] ?? 0)]);
            break;
        case 'updateReadHistoryInbox':    // read on the phone: read here too
            if ((int)($u['still_unread_count'] ?? 0) === 0) {
                ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET unread = 0 WHERE account_id = ? AND peer_id = ?')->execute([$id, (int)($u['peer'] ?? 0)]);
            }
            break;
        case 'updateUserName':
            $peer = (int)($u['user_id'] ?? 0);
            $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
            if ($peer > 0 && $name !== '') ecw_db()->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET name = ? WHERE account_id = ? AND peer_id = ?')->execute([mb_substr($name, 0, 190), $id, $peer]);
            break;
    }
}

/**
 * Store one message of a private chat (once), with its file downloaded.
 * $live: it just arrived (counts as unread, tells the case's handler); else
 * it comes from loading a chat. @return bool stored now
 */
function ecw_tg_sync_store(EcwTgClient $c, int $accountId, array $m, array $users, bool $live): bool
{
    if (($m['_'] ?? '') !== 'message') return false;   // service messages (joined, call…) are not shown
    $peer = (int)($m['peer_id'] ?? 0);
    if ($peer <= 0 || !isset($m['id'])) return false;
    $pdo = ecw_db();
    $st = $pdo->prepare('SELECT * FROM `' . ecw_table('tg_chats') . '` WHERE account_id = ? AND peer_id = ?');
    $st->execute([$accountId, $peer]);
    $chat = $st->fetch();
    if (!$chat) {
        $u = $users[$peer] ?? $c->user($peer);
        $chat = ecw_tg_chat_upsert($accountId, $peer, $u ? ecw_tg_sync_who($u) : []);
        // A chat that starts now has no history to load.
        if ($live) { $pdo->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET history_loaded = 2 WHERE id = ?')->execute([(int)$chat['id']]); $chat['history_loaded'] = 2; }
    }
    $out = !empty($m['out']);
    [$kind, $body, $media] = ecw_tg_sync_content($m);
    $at = gmdate('Y-m-d H:i:s', (int)($m['date'] ?? 0) ?: time());
    $ins = $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('tg_messages') . '`
        (chat_id, tg_message_id, direction, kind, body, media_mime, filename, file_size, status, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([(int)$chat['id'], (int)$m['id'], $out ? 'out' : 'in', $kind, $body, $media['mime'] ?? null, $media['filename'] ?? null, $media['size'] ?? null, $out ? 'sent' : 'received', $at]);
    if ($ins->rowCount() === 0) return false;   // known already (or sent from here)
    $rowId = (int)$pdo->lastInsertId();
    if ($media) ecw_tg_sync_download($c, $accountId, $rowId, $m['media'], $media);
    $preview = ecw_tg_preview($kind, $body);
    $newer = $chat['last_message_at'] === null || $at >= $chat['last_message_at'];
    if ($newer) {
        $pdo->prepare('UPDATE `' . ecw_table('tg_chats') . '` SET last_message_at = ?, last_preview = ?' . ($out ? ', unread = 0' : ($live ? ', unread = unread + 1' : '')) . ' WHERE id = ?')
            ->execute([$at, mb_substr(($out ? 'You: ' : '') . $preview, 0, 250), (int)$chat['id']]);
    }
    if ($live && !$out) {
        // Other modules hear about it (e.g. linking chats to cases). user_id: the chat is this person's alone.
        $st = $pdo->prepare('SELECT organization_id, user_id FROM `' . ecw_table('tg_accounts') . '` WHERE id = ?');
        $st->execute([$accountId]);
        $a = $st->fetch();
        if ($a) ecw_hook_run('chat.message_in', ['source' => 'telegram', 'organization_id' => (int)$a['organization_id'], 'user_id' => (int)$a['user_id'],
            'chat' => (string)$chat['id'], 'from' => ecw_tg_title($chat), 'text' => mb_substr($preview, 0, 120), 'link' => 'module.php?m=telegram&p=telegram&chat=' . (int)$chat['id']]);
    }
    return true;
}

/** Download a message's file (up to 20 MB) into storage/telegram/media/<account>/. */
function ecw_tg_sync_download(EcwTgClient $c, int $accountId, int $rowId, array $tlMedia, array $media): void
{
    if (($media['size'] ?? 0) > ECW_TG_MAX_DOWNLOAD) return;   // shown as "open in Telegram"
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'application/pdf' => 'pdf'][$media['mime'] ?? ''] ?? null;
    $ext ??= preg_match('/\.([A-Za-z0-9]{1,8})$/', (string)($media['filename'] ?? ''), $mm) ? strtolower($mm[1]) : 'bin';
    $rel = 'media/' . $accountId . '/' . $rowId . '.' . $ext;
    ecw_tg_dir(dirname($rel));
    try {
        $c->download($tlMedia, ecw_tg_dir() . '/' . $rel);
        ecw_db()->prepare('UPDATE `' . ecw_table('tg_messages') . '` SET file_path = ? WHERE id = ?')->execute([$rel, $rowId]);
    } catch (Throwable $e) {
        ecw_log('telegram download failed (message ' . $rowId . '): ' . $e->getMessage());
    }
}

/**
 * What a Telegram message holds. @return array{0:string, 1:?string, 2:array}
 * kind, text, file (mime, filename, size; empty when there is none to download)
 */
function ecw_tg_sync_content(array $m): array
{
    $text = (string)($m['message'] ?? '');
    $media = is_array($m['media'] ?? null) ? $m['media'] : [];
    $caption = $text !== '' ? $text : null;
    $map = static fn(array $g): string => 'https://maps.google.com/?q=' . (float)($g['lat'] ?? 0) . ',' . (float)($g['long'] ?? 0);
    switch ($media['_'] ?? '') {
        case '':
        case 'messageMediaEmpty':
        case 'messageMediaWebPage':
            return ['text', $text, []];
        case 'messageMediaPhoto':
            return isset($media['photo']['id']) ? ['image', $caption, ['mime' => 'image/jpeg', 'filename' => null, 'size' => null]] : ['other', 'A photo that has expired.', []];
        case 'messageMediaDocument':
            $d = (array)($media['document'] ?? []);
            if (!isset($d['id'])) return ['other', 'A file that has expired.', []];
            $mime = (string)($d['mime_type'] ?? 'application/octet-stream');
            $file = ['mime' => $mime, 'filename' => null, 'size' => (int)($d['size'] ?? 0)];
            $kind = 'document';
            foreach ((array)($d['attributes'] ?? []) as $a) {
                switch ($a['_'] ?? '') {
                    case 'documentAttributeFilename': $file['filename'] = mb_substr((string)$a['file_name'], 0, 250); break;
                    case 'documentAttributeAudio': $kind = 'audio'; break;
                    case 'documentAttributeVideo': $kind = 'video'; break;
                    case 'documentAttributeAnimated': $kind = 'video'; break;
                    case 'documentAttributeSticker':
                        if ($mime !== 'image/webp') return ['text', 'Sticker ' . (string)($a['alt'] ?? ''), []];
                        $kind = 'sticker';
                        break;
                }
            }
            if ($kind === 'video' && $mime !== 'video/mp4' && $mime !== 'image/gif') $kind = 'document';
            if ($file['size'] > ECW_TG_MAX_DOWNLOAD) {
                $mb = round($file['size'] / 1048576, 1);
                return [$kind === 'document' ? 'document' : 'other', trim(($caption ?? '') . "\n" . '📎 ' . ($file['filename'] ?? 'A file') . " ({$mb} MB): open it in Telegram."), []];
            }
            return [$kind, $caption, $file];
        case 'messageMediaGeo':
        case 'messageMediaGeoLive':
            return ['location', $map((array)($media['geo'] ?? [])), []];
        case 'messageMediaVenue':
            return ['location', implode(' — ', array_filter([trim((string)($media['title'] ?? '')), trim((string)($media['address'] ?? ''))])) . "\n" . $map((array)($media['geo'] ?? [])), []];
        case 'messageMediaContact':
            return ['text', 'Shared a contact: ' . trim((string)($media['first_name'] ?? '') . ' ' . (string)($media['last_name'] ?? '') . ' ' . (string)($media['phone_number'] ?? '')), []];
        case 'messageMediaPoll':
            $q = $media['poll']['question'] ?? '';
            return ['text', 'Poll: ' . (is_array($q) ? (string)($q['text'] ?? '') : (string)$q), []];
        case 'messageMediaDice':
            return ['text', (string)($media['emoticon'] ?? '🎲') . ' ' . (int)($media['value'] ?? 0), []];
        default:
            return ['other', trim(($caption ?? '') . "\n" . 'Something this app cannot show. Open Telegram to see it.'), []];
    }
}

function ecw_tg_preview(string $kind, ?string $body): string
{
    $label = ['image' => '📷 Photo', 'video' => '🎬 Video', 'audio' => '🎤 Voice / audio', 'sticker' => 'Sticker', 'document' => '📄 File', 'location' => '📍 Location'][$kind] ?? '';
    $body = trim((string)$body);
    return $label !== '' ? $label . ($body !== '' ? ': ' . $body : '') : $body;
}

/** Keep the contacts fresh (every 6 hours). */
function ecw_tg_sync_periodic(EcwTgClient $c, array $account): void
{
    $last = $account['contacts_synced_at'] ? strtotime($account['contacts_synced_at'] . ' UTC') : 0;
    if ($last < time() - ECW_TG_CONTACTS_EVERY) ecw_tg_sync_contacts($c, (int)$account['id']);
    if (empty($account['dialogs_synced_at'])) ecw_tg_sync_dialogs($c, (int)$account['id']);
}
