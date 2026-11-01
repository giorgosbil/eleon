<?php declare(strict_types=1);
/**
 * WhatsApp (lib/whatsapp.php): connecting, the signed webhook, duplicate
 * deliveries, delivery statuses, the 24-hour window, templates, case links,
 * media downloads.
 *
 *   php modules/whatsapp/tests/whatsapp_test.php
 *
 * Meta is scripted with $GLOBALS['ecw_wa_transport']. Runs in a transaction
 * that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/whatsapp.php';
require_once dirname(__DIR__, 3) . '/lib/features.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }
function throws(callable $f, string $class, string $contains, string $what): void
{
    try { $f(); check(false, $what, 'nothing thrown'); }
    catch (Throwable $e) { check($e instanceof $class && str_contains($e->getMessage(), $contains), $what, get_class($e) . ': ' . $e->getMessage()); }
}

$pdo = ecw_db();
$pdo->beginTransaction();
$calls = [];
$routes = [];
$GLOBALS['ecw_wa_transport'] = static function (string $method, string $url, ?array $json) use (&$calls, &$routes): array {
    $calls[] = [$method, $url, $json];
    foreach ($routes as [$m, $re, $res]) if ($m === $method && preg_match($re, $url)) return is_callable($res) ? $res($json) : $res;
    throw new RuntimeException("no route for {$method} {$url}");
};
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'WhatsApp shop']);
    $other = $ins('organizations', ['name' => 'Someone else']);
    $admin = $ins('users', ['name' => 'Eleni', 'email' => 'eleni' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);
    $handler = $ins('users', ['name' => 'Kostas', 'email' => 'kostas' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $handler, 'role' => 'member']);
    $phoneId = '9' . $org . '0001';
    $secret = str_repeat('ab12', 8);

    group('connecting');
    throws(fn() => ecw_wa_save($org, ['phone_number_id' => '', 'waba_id' => '1', 'access_token' => 't', 'app_secret' => $secret], $admin), InvalidArgumentException::class, 'Phone number ID', 'the phone number id is required');
    throws(fn() => ecw_wa_save($org, ['phone_number_id' => $phoneId, 'waba_id' => '55', 'access_token' => 't', 'app_secret' => 'short'], $admin), InvalidArgumentException::class, '32 letters', 'an app secret that cannot be one is refused');
    $routes = [['GET', '#/' . $phoneId . '\?fields=#', ['status' => 401, 'body' => ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']]]]];
    throws(fn() => ecw_wa_save($org, ['phone_number_id' => $phoneId, 'waba_id' => '55', 'access_token' => 'bad', 'app_secret' => $secret], $admin), InvalidArgumentException::class, 'refused the access token', 'a wrong token is caught when saving, in plain words');
    $routes = [['GET', '#/' . $phoneId . '\?fields=#', ['body' => ['display_phone_number' => '+30 210 555 0100', 'verified_name' => 'Shop']]]];
    $s = ecw_wa_save($org, ['phone_number_id' => " {$phoneId} ", 'waba_id' => '55', 'access_token' => 'EAAG-secret-token', 'app_secret' => $secret], $admin);
    $raw = (string)$pdo->query('SELECT whatsapp_json FROM ecw_organizations WHERE id = ' . $org)->fetchColumn();
    check($s['configured'] && $s['display_phone'] === '+30 210 555 0100' && strlen($s['verify_token']) === 32, 'connected: the number from Meta, a verify token made');
    check(!str_contains($raw, 'EAAG-secret-token') && !str_contains($raw, $secret) && !str_contains(json_encode($s), 'EAAG'), 'the token and the secret are stored encrypted and never returned');
    $s2 = ecw_wa_save($org, ['phone_number_id' => $phoneId, 'waba_id' => '55', 'access_token' => '', 'app_secret' => ''], $admin);
    check($s2['verify_token'] === $s['verify_token'] && ecw_wa_config($org)['token'] === 'EAAG-secret-token', 'saving again without the secrets keeps them and the verify token');
    $routes = [['GET', '#\?fields=#', ['body' => ['display_phone_number' => 'x']]]];
    throws(fn() => ecw_wa_save($other, ['phone_number_id' => $phoneId, 'waba_id' => '1', 'access_token' => 't', 'app_secret' => $secret], $admin), InvalidArgumentException::class, 'another workspace', 'one number belongs to one workspace');

    group('the webhook');
    check(ecw_wa_verify_challenge('subscribe', $s['verify_token'], '1158201444') === '1158201444', "Meta's verification is answered with our verify token");
    check(ecw_wa_verify_challenge('subscribe', 'guess', '1158201444') === null, '…and refused with any other');
    $hook = static fn(array $value) => json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '55', 'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $phoneId]] + $value]]]]]);
    $sign = static fn(string $raw) => 'sha256=' . hash_hmac('sha256', $raw, $secret);
    $now = time();
    $body = $hook(['contacts' => [['profile' => ['name' => 'Maria P'], 'wa_id' => '306912345678']], 'messages' => [['from' => '306912345678', 'id' => 'wamid.IN1', 'timestamp' => (string)$now, 'type' => 'text', 'text' => ['body' => 'Is my pump ready?']]]]);
    check(ecw_wa_webhook_handle($body, 'sha256=' . str_repeat('0', 64)) === ['stored' => 0, 'rejected' => 1], 'a body not signed with our app secret is rejected');
    check(ecw_wa_webhook_handle($body, $sign($body)) === ['stored' => 1, 'rejected' => 0], 'a signed message is stored');
    check(ecw_wa_webhook_handle($body, $sign($body))['stored'] === 0, 'the same message delivered again is stored once');
    $chat = $pdo->query("SELECT * FROM ecw_wa_chats WHERE organization_id = {$org}")->fetch();
    check($chat['name'] === 'Maria P' && (int)$chat['unread'] === 1 && $chat['last_preview'] === 'Is my pump ready?' && ecw_wa_window_open($chat), 'one chat, named, unread, the 24-hour window open');
    $foreign = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], 'messages' => [['from' => '1', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'x']]]]]]]]]);
    check(ecw_wa_webhook_handle($foreign, $sign($foreign))['rejected'] === 1, 'a number no workspace owns is rejected');
    throws(fn() => ecw_wa_webhook_handle('{"object":"page"}', ''), InvalidArgumentException::class, 'not a WhatsApp', 'other Meta webhooks are refused');

    $photo = $hook(['messages' => [['from' => '306912345678', 'id' => 'wamid.IN2', 'timestamp' => (string)($now + 1), 'type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg', 'caption' => 'the label']]]]);
    ecw_wa_webhook_handle($photo, $sign($photo));
    $m = $pdo->query("SELECT * FROM ecw_wa_messages WHERE wamid = 'wamid.IN2'")->fetch();
    check($m['kind'] === 'image' && $m['media_id'] === 'MEDIA1' && $m['body'] === 'the label', 'a photo keeps its file id and caption');
    check(ecw_wa_message_content(['type' => 'location', 'location' => ['latitude' => 37.9, 'longitude' => 23.7, 'name' => 'Shop']])[1] === "Shop\nhttps://maps.google.com/?q=37.9,23.7", 'a location becomes a map link');

    group('replying');
    $chatId = (int)$chat['id'];
    $routes = [['POST', '#/' . $phoneId . '/messages$#', static fn($j) => ['body' => ['messages' => [['id' => 'wamid.OUT' . (++$GLOBALS['n'])]]]]]];
    $GLOBALS['n'] = 0;
    $calls = [];
    $r = ecw_wa_messages($org, $chatId);
    check(count($r['messages']) === 2 && $calls[0][2]['status'] === 'read' && $calls[0][2]['message_id'] === 'wamid.IN2', 'opening the chat shows the messages and sends a read receipt for the latest');
    check((int)$pdo->query("SELECT unread FROM ecw_wa_chats WHERE id = {$chatId}")->fetchColumn() === 0, '…and marks it read here');
    $calls = [];
    $GLOBALS['n'] = 0;   // the read receipt above went through the same route
    ecw_wa_send_text($org, $chatId, $admin, "  It is ready.\nCome by tomorrow. ");
    check($calls[0][2]['to'] === '306912345678' && $calls[0][2]['text']['body'] === "It is ready.\nCome by tomorrow." && $calls[0][2]['type'] === 'text', 'a reply goes to the customer as text');
    $out = $pdo->query("SELECT * FROM ecw_wa_messages WHERE chat_id = {$chatId} AND direction = 'out'")->fetch();
    check($out['status'] === 'sent' && (int)$out['sent_by_user_id'] === $admin && $out['wamid'] === 'wamid.OUT1', 'stored as sent, with who sent it');
    $st = static fn(string $status) => $hook(['statuses' => [['id' => 'wamid.OUT1', 'status' => $status, 'timestamp' => (string)$now, 'recipient_id' => '306912345678']]]);
    foreach (['delivered', 'read', 'delivered'] as $x) { $b = $st($x); ecw_wa_webhook_handle($b, $sign($b)); }
    check($pdo->query("SELECT status FROM ecw_wa_messages WHERE wamid = 'wamid.OUT1'")->fetchColumn() === 'read', 'delivered, then read; a late "delivered" does not step back');
    $b = $hook(['statuses' => [['id' => 'wamid.OUT1', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]]]);
    ecw_wa_webhook_handle($b, $sign($b));
    check($pdo->query("SELECT error FROM ecw_wa_messages WHERE wamid = 'wamid.OUT1'")->fetchColumn() === 'Message undeliverable', 'a failure is kept with its reason');

    group('photos and files');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $tmp = tempnam(sys_get_temp_dir(), 'wa'); file_put_contents($tmp, $png);
    $routes = [['POST', '#/' . $phoneId . '/media$#', ['body' => ['id' => 'UPLOADED1']]],
               ['POST', '#/' . $phoneId . '/messages$#', static fn($j) => ['body' => ['messages' => [['id' => 'wamid.FILE' . (++$GLOBALS['n'])]]]]]];
    $calls = [];
    $r = ecw_wa_send_file($org, $chatId, $admin, ['tmp' => $tmp, 'name' => 'label.png', 'size' => strlen($png)], ' the label ');
    check($calls[0][1] !== '' && ($calls[0][2]['type'] ?? '') === 'image/png' && ($calls[0][2]['file'] ?? null) instanceof CURLFile, 'the photo is uploaded to WhatsApp first');
    check(($calls[1][2]['type'] ?? '') === 'image' && ($calls[1][2]['image'] ?? null) === ['id' => 'UPLOADED1', 'caption' => 'the label'], 'then sent as a photo, with its caption');
    $sent = array_values(array_filter(ecw_wa_messages($org, $chatId)['messages'], static fn($m) => $m['id'] === $r['id']))[0] ?? [];
    check(($sent['has_media'] ?? false) && $sent['kind'] === 'image' && $sent['body'] === 'the label' && $sent['direction'] === 'out', 'the sent photo shows in the chat, as mine, with its caption');
    $calls = [];
    $f = ecw_wa_media($org, $r['id']);
    check($f['bytes'] === $png && $f['mime'] === 'image/png' && $calls === [], 'it is shown from the copy kept here, without asking Meta');
    check(str_starts_with((string)$pdo->query("SELECT last_preview FROM ecw_wa_chats WHERE id = {$chatId}")->fetchColumn(), 'You: 📷 Photo'), 'the chat list says a photo was sent');
    file_put_contents($tmp, '%PDF-1.4 test');
    $calls = [];
    ecw_wa_send_file($org, $chatId, $admin, ['tmp' => $tmp, 'name' => 'offer.pdf', 'size' => 13]);
    check(($calls[1][2]['type'] ?? '') === 'document' && ($calls[1][2]['document']['filename'] ?? '') === 'offer.pdf', 'a PDF goes as a document, with its name');
    $routes = [['GET', '#/MEDIA1$#', ['body' => ['url' => 'https://lookaside.fbsbx.com/whatsapp/m1', 'mime_type' => 'image/jpeg']]],
               ['GET', '#lookaside\.fbsbx\.com#', ['body' => 'JPEGBYTES']]];
    $in = (int)$pdo->query("SELECT id FROM ecw_wa_messages WHERE wamid = 'wamid.IN2'")->fetchColumn();
    $calls = [];
    $first = ecw_wa_media($org, $in);
    $second = ecw_wa_media($org, $in);
    check($first['bytes'] === 'JPEGBYTES' && $second['bytes'] === 'JPEGBYTES' && count($calls) === 2, 'a photo the customer sent is fetched once, then kept (Meta deletes it after 30 days)');
    throws(fn() => ecw_wa_send_file($org, $chatId, $admin, ['tmp' => $tmp, 'name' => 'big.bin', 'size' => 9 * 1024 * 1024]), InvalidArgumentException::class, '8 MB', 'a file over 8 MB is refused in plain words');
    @unlink($tmp);
    foreach (glob(ecw_wa_dir((string)$org) . '/*') ?: [] as $kept) @unlink($kept);
    @rmdir(ecw_wa_dir((string)$org));

    group('the 24-hour window');
    $pdo->exec("UPDATE ecw_wa_chats SET last_inbound_at = UTC_TIMESTAMP() - INTERVAL 25 HOUR WHERE id = {$chatId}");
    $calls = [];
    throws(fn() => ecw_wa_send_text($org, $chatId, $admin, 'Hello?'), InvalidArgumentException::class, 'approved template', 'after 24 hours free text is refused before asking Meta');
    check($calls === [], '…nothing was sent');
    check(!ecw_wa_chat_out($pdo->query("SELECT * FROM ecw_wa_chats WHERE id = {$chatId}")->fetch())['window_open'], 'the chat says its window is closed');
    check(str_contains(ecw_wa_error(['status' => 400, 'body' => ['error' => ['code' => 131047]]]), 'template'), "Meta's own 24-hour error is explained too");

    group('templates');
    $routes = [
        ['GET', '#/55/message_templates#', ['body' => ['data' => [
            ['name' => 'order_ready', 'language' => 'el', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Γεια {{1}}, η παραγγελία {{2}} είναι έτοιμη.']]],
            ['name' => 'draft_one', 'language' => 'en', 'status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'x']]],
            ['name' => 'with_photo', 'language' => 'en', 'status' => 'APPROVED', 'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'y']]],
            ['name' => 'hello', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello from the shop']]],
        ]]]],
        ['POST', '#/' . $phoneId . '/messages$#', ['body' => ['contacts' => [['input' => '+30 697 000 0001', 'wa_id' => '306970000001']], 'messages' => [['id' => 'wamid.TPL1']]]]],
    ];
    $tpls = ecw_wa_templates($org);
    check(array_column($tpls, 'name') === ['hello', 'order_ready'] && $tpls[1]['params'] === 2, 'only approved templates this app can fill in, with how many blanks');
    throws(fn() => ecw_wa_send_template($org, $admin, null, '0697 000 0001', 'order_ready', 'el', ['Maria', 'A-17']), InvalidArgumentException::class, 'country code', 'a number without its country code is refused');
    throws(fn() => ecw_wa_send_template($org, $admin, null, '+30 697 000 0001', 'order_ready', 'el', ['Maria', ' ']), InvalidArgumentException::class, '{{2}}', 'every blank must be filled');
    $calls = [];
    $r = ecw_wa_send_template($org, $admin, null, '+30 697 000 0001', 'order_ready', 'el', ['Maria', 'A-17'], 'Γεια {{1}}, η παραγγελία {{2}} είναι έτοιμη.');
    $sent = $calls[0][2];
    check($sent['type'] === 'template' && $sent['to'] === '306970000001' && $sent['template']['language']['code'] === 'el' && $sent['template']['components'][0]['parameters'][1]['text'] === 'A-17', 'a template starts a chat with a new number, blanks filled in');
    check($pdo->query("SELECT body FROM ecw_wa_messages WHERE wamid = 'wamid.TPL1'")->fetchColumn() === 'Γεια Maria, η παραγγελία A-17 είναι έτοιμη.' && $r['chat_id'] > 0, 'the chat shows the message as the customer sees it');
    check(ecw_wa_phone('0030 697 000 0001') === '306970000001' && ecw_wa_phone('+1 (415) 555-0100') === '14155550100' && ecw_wa_phone('12345') === null, 'phone numbers: 00 or +, spaces and brackets');

    // Linking messages to a case (and telling the case's handler about new ones) is the chatlink module's: modules/chatlink/tests.

    group('files');
    $mid = (int)$pdo->query("SELECT id FROM ecw_wa_messages WHERE wamid = 'wamid.IN2'")->fetchColumn();
    $routes = [['GET', '#/MEDIA1$#', ['body' => ['url' => 'https://evil.example/steal', 'mime_type' => 'image/jpeg']]]];
    throws(fn() => ecw_wa_media($org, $mid), RuntimeException::class, 'unexpected file address', "the token is only sent to Meta's own file hosts");
    $routes = [['GET', '#/MEDIA1$#', ['body' => ['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/jpeg']]], ['GET', '#lookaside#', ['body' => "\xFF\xD8jpeg"]]];
    $f = ecw_wa_media($org, $mid);
    check($f['bytes'] === "\xFF\xD8jpeg" && $f['mime'] === 'image/jpeg' && str_ends_with($f['filename'], '.jpg'), 'a photo is fetched from Meta');
    throws(fn() => ecw_wa_media($other, $mid), InvalidArgumentException::class, 'does not exist', "another workspace cannot fetch it");

    group('accounts');
    $pdo->prepare('UPDATE ecw_organizations SET whatsapp_json = ? WHERE id = ?')->execute([json_encode(['phone_number_id' => '1', 'mailbox_id' => 501]), $org]);
    check(ecw_wa_for_account($org, 501) && !ecw_wa_for_account($org, 502), 'WhatsApp shows in the account its number is linked to only');
    check(ecw_wa_nav_visible($org, 501) && !ecw_wa_nav_visible($org, 502) && ecw_wa_nav_visible($org, null), '…and so does its tab (with no account chosen, everywhere)');
    $pdo->prepare('UPDATE ecw_organizations SET whatsapp_json = ? WHERE id = ?')->execute([json_encode(['phone_number_id' => '1']), $org]);
    check(ecw_wa_for_account($org, 502), 'a number linked to no account shows in every account');

    group('the module in the core (lib/modules.php)');
    check(isset(ecw_modules()['whatsapp']), 'the module is found');
    check(isset(ecw_feature_catalog()['whatsapp']), 'its feature is in the catalogue');
    check(in_array('whatsapp', ecw_packages()['pro']['features'], true) && in_array('whatsapp', ecw_packages()['business']['features'], true) && !in_array('whatsapp', ecw_packages()['free']['features'], true), 'it is in Pro and above, not in Free');
    check(ecw_table('wa_chats') === 'ecw_wa_chats' && ecw_table('wa_messages') === 'ecw_wa_messages', 'its tables resolve');
    check(in_array('20261008_ecw_045_whatsapp.sql', array_map('basename', ecw_migration_files()), true), "its migration runs with the core's");
    $send = ecw_module_api_action('wa_send');
    check(($send['feature'] ?? null) === 'whatsapp' && !empty($send['post']) && is_file($send['file'] ?? ''), 'wa_send is a POST action behind the feature');
    check(empty(ecw_module_api_action('wa_media')['post']) && ecw_module_api_action('case_new') === null, "reading is GET; core actions are not the module's");
    check(ecw_module_page_file('whatsapp', 'webhook') !== null && ecw_module_page_file('whatsapp', '../module') === null && ecw_module_page_file('whatsapp', 'nope') === null, 'only its own pages open');
    check(ecw_module_file('whatsapp', '../../lib/db.php') === null, 'no file outside the module');
    check(str_contains(ecw_wa_webhook_url(), 'module.php?m=whatsapp&p=webhook'), 'the webhook address is the module page');
    check(ecw_hook_first('case.event_label', 'WHATSAPP_LINKED', ['title' => 'Maria']) === 'linked a WhatsApp chat (Maria)'
        && ecw_hook_first('case.event_label', 'SOMETHING_ELSE', []) === null, 'its case events read as words');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    unset($GLOBALS['ecw_wa_transport']);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
