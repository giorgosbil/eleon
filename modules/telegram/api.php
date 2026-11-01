<?php declare(strict_types=1);
/**
 * The Telegram module's API actions (tg_*). Runs inside web/api/index.php,
 * which has already checked sign-in, CSRF, POST, read-only and the telegram
 * feature (see module.php), with $action, $user, $orgId and $mailbox set.
 * Every action works on the signed-in person's own Telegram account only.
 */
require_once __DIR__ . '/lib/telegram.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$uid = (int)$user['id'];

try {
    switch ($action) {
        case 'tg_status':
            ecw_json(['status' => ecw_tg_status($uid)]);
        case 'tg_login_start':
            ecw_json(['status' => ecw_tg_login_start($orgId, $uid, (string)ecw_param('phone', ''), (string)ecw_param('api_id', ''), (string)ecw_param('api_hash', ''))]);
        case 'tg_login_code':
            ecw_json(['status' => ecw_tg_login_code($uid, (string)ecw_param('code', ''))]);
        case 'tg_login_password':
            ecw_json(['status' => ecw_tg_login_password($uid, (string)ecw_param('password', ''))]);
        case 'tg_disconnect':
            ecw_json(['status' => ecw_tg_disconnect($orgId, $uid)]);
        case 'tg_job':
            ecw_json(ecw_tg_job($uid, (int)ecw_param('id', 0)));
    }

    // The rest reads (or writes to) the chats: they stay readable after disconnecting.
    $account = ecw_tg_account($uid);
    if (!$account) throw new EcwTelegramNotConnected('Your Telegram is not connected. Connect it on the Telegram tab.');
    $accountId = (int)$account['id'];
    // A linked case's name is shown only to people who can see that case.
    $hideCase = static function (array $c) use ($orgId, $uid): array {
        if ($c['case_id'] !== null && !ecw_case_visible($orgId, $uid, (int)$c['case_id'])) { $c['case_id'] = null; $c['case_label'] = 'a case you cannot see'; }
        return $c;
    };

    switch ($action) {
        case 'tg_chats':
            ecw_json(['chats' => array_map($hideCase, ecw_tg_chats($accountId)), 'status' => ecw_tg_status($uid)]);
        case 'tg_messages':
            $r = ecw_tg_messages($account, (int)ecw_param('chat_id', 0));
            $r['chat'] = $hideCase($r['chat']);
            ecw_json($r);
        case 'tg_contacts':
            ecw_json(['contacts' => ecw_tg_contacts($accountId, (string)ecw_param('q', ''))]);
        case 'tg_media':
            $f = ecw_tg_media($accountId, (int)ecw_param('message_id', 0));
            // Shown inline only for images, audio and video; anything else downloads.
            $inline = (bool)preg_match('#^(image/(jpeg|png|webp|gif)|audio/(ogg|mpeg|aac|mp4)|video/mp4)#', $f['mime']);
            header('Content-Type: ' . ($inline ? strtok($f['mime'], ';') : 'application/octet-stream'));
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Cache-Control: private, max-age=3600');
            header('Content-Length: ' . (string)filesize($f['path']));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\- ]/u', '_', $f['filename']) . '"');
            readfile($f['path']);
            exit;
    }

    // Writing needs the account signed in.
    if ($account['status'] !== 'ready') throw new EcwTelegramNotConnected('Your Telegram is not connected. Connect it again on the Telegram tab.');
    switch ($action) {
        case 'tg_send':
            // JSON for text; a form (multipart) when a file comes along.
            $up = $_FILES['file'] ?? null;
            if ($up !== null && (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $err = (int)$up['error'];
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) ecw_fail('Files up to 8 MB can be sent from here; send larger ones from Telegram.');
                if ($err !== UPLOAD_ERR_OK) ecw_fail('The file did not arrive. Try again.');
                $file = ['tmp' => (string)$up['tmp_name'], 'name' => (string)$up['name'], 'size' => (int)$up['size']];
            } else {
                $file = null;
            }
            ecw_json(ecw_tg_send($account, (int)ecw_param('chat_id', 0), $uid, (string)ecw_param('text', ''), $file));
        case 'tg_open_contact':
            ecw_json(['chat_id' => ecw_tg_open_contact($accountId, (int)ecw_param('peer_id', 0))]);
        case 'tg_new_number':
            ecw_json(['job' => ecw_tg_new_number($account, (string)ecw_param('phone', ''), (string)ecw_param('first', ''), (string)ecw_param('last', ''))]);
    }
} catch (InvalidArgumentException $e) {
    ecw_fail($e->getMessage());
} catch (EcwTelegramNotConnected $e) {
    ecw_json(['error' => $e->getMessage(), 'not_connected' => true], 409);
} catch (Throwable $e) {
    ecw_log('telegram ' . $action . ' failed: ' . $e->getMessage());
    ecw_fail('Telegram request failed.', 502);
}
