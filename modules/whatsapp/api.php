<?php declare(strict_types=1);
/**
 * The WhatsApp module's API actions (wa_*). Runs inside web/api/index.php,
 * which has already checked sign-in, CSRF, POST, read-only and the whatsapp
 * feature (see module.php), with $action, $user, $orgId and $mailbox set.
 */
require_once __DIR__ . '/lib/whatsapp.php';

switch ($action) {

    case 'wa_chats':
    case 'wa_messages':
    case 'wa_send':
    case 'wa_templates':
    case 'wa_send_template':
        // One shared business number: everyone with the WhatsApp feature works its chats.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $uid = (int)$user['id'];
        // A linked case's name is shown only to people who can see that case.
        $hideCase = static function (array $c) use ($orgId, $uid): array {
            if ($c['case_id'] !== null && !ecw_case_visible($orgId, $uid, (int)$c['case_id'])) { $c['case_id'] = null; $c['case_label'] = 'a case you cannot see'; }
            return $c;
        };
        try {
            switch ($action) {
                case 'wa_chats':
                    ecw_json(['chats' => array_map($hideCase, ecw_wa_chats($orgId)), 'settings' => ['configured' => ecw_wa_settings($orgId)['configured'], 'number' => ecw_wa_settings($orgId)['display_phone']]]);
                case 'wa_messages':
                    $r = ecw_wa_messages($orgId, (int)ecw_param('chat_id', 0));
                    $r['chat'] = $hideCase($r['chat']);
                    ecw_json($r);
                case 'wa_send':
                    // JSON for text; a form (multipart) when a photo or file comes along.
                    $up = $_FILES['file'] ?? null;
                    if (is_array($up)) {
                        if ((int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) ecw_fail('The file did not arrive. Try again.');
                        ecw_json(ecw_wa_send_file($orgId, (int)ecw_param('chat_id', 0), $uid, ['tmp' => (string)$up['tmp_name'], 'name' => (string)$up['name'], 'size' => (int)$up['size']], (string)ecw_param('text', '')));
                    }
                    ecw_json(ecw_wa_send_text($orgId, (int)ecw_param('chat_id', 0), $uid, (string)ecw_param('text', '')));
                case 'wa_templates':
                    ecw_json(['templates' => ecw_wa_templates($orgId)]);
                case 'wa_send_template':
                    $in = ecw_input();
                    ecw_json(ecw_wa_send_template($orgId, $uid, !empty($in['chat_id']) ? (int)$in['chat_id'] : null, isset($in['phone']) ? (string)$in['phone'] : null,
                        (string)($in['name'] ?? ''), (string)($in['language'] ?? ''), (array)($in['params'] ?? []), (string)($in['body'] ?? '')));
            }
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (EcwWhatsAppNotConnected $e) {
            ecw_json(['error' => $e->getMessage(), 'not_connected' => true], 409);
        } catch (Throwable $e) {
            ecw_log('whatsapp ' . $action . ' failed: ' . $e->getMessage());
            ecw_fail($e instanceof RuntimeException ? $e->getMessage() : 'WhatsApp request failed.', 502);
        }

    case 'wa_media':
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            $f = ecw_wa_media($orgId, (int)ecw_param('message_id', 0));
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage(), 404);
        } catch (Throwable $e) {
            ecw_fail($e->getMessage(), 502);
        }
        // Shown inline only for images, audio and video; anything else downloads.
        $inline = (bool)preg_match('#^(image/(jpeg|png|webp)|audio/(ogg|mpeg|aac|mp4|amr)|video/mp4)#', $f['mime']);
        header('Content-Type: ' . ($inline ? strtok($f['mime'], ';') : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\- ]/u', '_', $f['filename']) . '"');
        echo $f['bytes'];
        exit;

    case 'wa_settings_save':
    case 'wa_disconnect':
        if (!ecw_is_admin()) { ecw_fail('Only admins can change the WhatsApp connection.', 403); }
        if ($action === 'wa_disconnect') { ecw_wa_disconnect($orgId, (int)$user['id']); ecw_json(['settings' => ecw_wa_settings($orgId)]); }
        try {
            ecw_json(['settings' => ecw_wa_save($orgId, ecw_input(), (int)$user['id'])]);
        } catch (InvalidArgumentException $e) {
            ecw_fail($e->getMessage());
        } catch (Throwable $e) {
            ecw_log('whatsapp settings failed: ' . $e->getMessage());
            ecw_fail('Meta could not be reached to check the connection. Try again.', 502);
        }
}
