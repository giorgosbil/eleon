<?php declare(strict_types=1);
/**
 * Chats in cases (modules/chatlink): linking chosen WhatsApp and Telegram
 * messages to a case, who may read and link what, the case's Chats tab, the
 * AI reading linked messages, telling the case when a linked chat gets a new
 * message, and the AI's suggestions (with a scripted AI).
 *
 *   php modules/chatlink/tests/chatlink_test.php
 *
 * Runs in a transaction that is rolled back. (Teams is read live from
 * Microsoft, so only its refusal of a chat id is tested here.)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/chatlink.php';
require_once dirname(__DIR__, 3) . '/lib/features.php';
require_once dirname(__DIR__, 3) . '/modules/whatsapp/lib/whatsapp.php';
require_once dirname(__DIR__, 3) . '/lib/timeline.php';

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
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Chat shop']);
    $other = $ins('organizations', ['name' => 'Someone else']);
    $me = $ins('users', ['name' => 'Giorgos', 'email' => 'g' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $handler = $ins('users', ['name' => 'Kostas', 'email' => 'k' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $handler, 'role' => 'admin']);
    $case = $ins('cases', ['organization_id' => $org, 'case_number' => 'C-' . $org, 'name' => 'Pump repair', 'status' => 'open', 'owner_user_id' => $me, 'handler_user_id' => $handler, 'created_by_user_id' => $me]);
    $case2 = $ins('cases', ['organization_id' => $org, 'case_number' => 'D-' . $org, 'name' => 'Boiler offer', 'status' => 'open', 'owner_user_id' => $me, 'created_by_user_id' => $me]);
    $foreignCase = $ins('cases', ['organization_id' => $other, 'case_number' => 'X-' . $org, 'name' => 'Not yours', 'status' => 'open', 'created_by_user_id' => $me]);
    $now = time();
    $at = static fn(int $s) => gmdate('Y-m-d H:i:s', $now + $s);

    // A WhatsApp chat (the workspace's) and a Telegram chat (Giorgos' own).
    $wa = $ins('wa_chats', ['organization_id' => $org, 'wa_id' => '306912345678', 'name' => 'Maria P', 'last_inbound_at' => $at(-60)]);
    $w1 = $ins('wa_messages', ['chat_id' => $wa, 'wamid' => 'w1' . $org, 'direction' => 'in', 'kind' => 'text', 'body' => 'Hello, about my pump', 'status' => 'received', 'occurred_at' => $at(-300)]);
    $w2 = $ins('wa_messages', ['chat_id' => $wa, 'wamid' => 'w2' . $org, 'direction' => 'in', 'kind' => 'image', 'body' => 'the label', 'status' => 'received', 'occurred_at' => $at(-200)]);
    $w3 = $ins('wa_messages', ['chat_id' => $wa, 'wamid' => 'w3' . $org, 'direction' => 'out', 'kind' => 'text', 'body' => 'We will check it', 'status' => 'sent', 'sent_by_user_id' => $handler, 'occurred_at' => $at(-100)]);
    $tgAcc = $ins('tg_accounts', ['organization_id' => $org, 'user_id' => $me, 'status' => 'ready', 'name' => 'Giorgos']);
    $tgChat = $ins('tg_chats', ['account_id' => $tgAcc, 'peer_id' => 5550001, 'name' => 'Nikos']);
    $t1 = $ins('tg_messages', ['chat_id' => $tgChat, 'tg_message_id' => 10, 'direction' => 'in', 'kind' => 'text', 'body' => 'The boiler price?', 'status' => 'received', 'occurred_at' => $at(-50)]);

    group('linking WhatsApp messages');
    throws(fn() => ecw_chatlink_link($org, $me, 'whatsapp', (string)$wa, [], $case), InvalidArgumentException::class, 'Choose', 'at least one message');
    throws(fn() => ecw_chatlink_link($org, $me, 'whatsapp', (string)$wa, [(string)$w1], $foreignCase), InvalidArgumentException::class, 'does not exist', "not into another workspace's case");
    throws(fn() => ecw_chatlink_link($org, $me, 'whatsapp', (string)$wa, [(string)$w1, '999999'], $case), InvalidArgumentException::class, 'not in this chat', 'a message of another chat (or made up) is refused');
    throws(fn() => ecw_chatlink_link($other, $me, 'whatsapp', (string)$wa, [(string)$w1], $foreignCase), InvalidArgumentException::class, 'does not exist', "another workspace cannot read this chat");
    throws(fn() => ecw_chatlink_link($org, $me, 'fax', '1', ['1'], $case), InvalidArgumentException::class, 'Unknown chat', 'only known chat apps');
    $r = ecw_chatlink_link($org, $me, 'whatsapp', (string)$wa, [(string)$w1, (string)$w2, (string)$w3], $case, 'ignored title');
    check($r['linked'] === 3 && $r['case_label'] === '#C-' . $org . ' Pump repair', 'three chosen messages are linked to the case');
    $rows = $pdo->query("SELECT * FROM ecw_chat_links WHERE case_id = {$case} ORDER BY sent_at")->fetchAll();
    check($rows[0]['body_text'] === 'Hello, about my pump' && $rows[0]['sender'] === 'Maria P' && $rows[1]['body_text'] === '📷 Photo: the label' && $rows[2]['sender'] === 'Kostas' && $rows[2]['direction'] === 'out',
        'their text is read on the server (never taken from the browser), with who wrote it');
    check($rows[0]['chat_title'] === 'Maria P', 'the chat is named by its app, not by the browser');
    check(ecw_chatlink_link($org, $me, 'whatsapp', (string)$wa, [(string)$w1], $case)['linked'] === 0, 'linking the same message again adds nothing');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_events WHERE case_id = {$case} AND event_type = 'CHAT_LINKED'")->fetchColumn() === 1, 'the case history says it once');
    check(ecw_chatlink_event_label('CHAT_LINKED', ['channel' => 'WhatsApp', 'count' => 3, 'title' => 'Maria P']) === 'linked 3 WhatsApp messages (Maria P)', '…in words');
    check(ecw_hook_first('case.event_label', 'CHAT_UNLINKED', ['channel' => 'Telegram', 'count' => 1]) === 'took out 1 Telegram message', '…through the core hook');
    $marks = ecw_chatlink_marks($org, $me, 'whatsapp', (string)$wa);
    check(count($marks) === 3 && $marks[(string)$w1][0]['case_id'] === $case, 'the chat knows which of its messages are in which case');

    group('Telegram is private');
    throws(fn() => ecw_chatlink_link($org, $handler, 'telegram', (string)$tgChat, [(string)$t1], $case2), InvalidArgumentException::class, 'does not exist', "a colleague cannot link someone else's Telegram messages");
    $r = ecw_chatlink_link($org, $me, 'telegram', (string)$tgChat, [(string)$t1], $case2);
    check($r['linked'] === 1 && (int)$pdo->query("SELECT owner_user_id FROM ecw_chat_links WHERE case_id = {$case2}")->fetchColumn() === $me, "the owner links their own; the copy remembers whose chat it is");
    // Who may open the chat itself: its owner only (the case's people read the copy).
    $mine = ecw_chatlink_columns($org, $case2, $me);
    $theirs = ecw_chatlink_columns($org, $case2, $handler);
    check($mine[0]['open_url'] === 'module.php?m=telegram&p=telegram&chat=' . $tgChat && $theirs[0]['open_url'] === null && str_contains($theirs[0]['subtitle'], 'Giorgos’s own Telegram'),
        'everyone with the case sees the chat column; only the owner gets "Open the chat"');
    $ev = ecw_chatlink_events($org, $case2, [$theirs[0]['thread_id']], $handler);
    check(count($ev) === 1 && $ev[0]['item']['text'] === 'The boiler price?', '…and reads the linked message');

    group('the case: a column per chat, on the time axis');
    $tl = ecw_case_timeline($org, $case, false, null, $me);
    $chatCols = array_values(array_filter($tl['columns'], static fn($c) => $c['kind'] === 'chat'));
    check(count($chatCols) === 1 && $chatCols[0]['label'] === 'Maria P' && $chatCols[0]['message_count'] === 3 && $chatCols[0]['icon'] === 'fa-brands fa-whatsapp'
        && preg_match('/^chat:\d+$/', $chatCols[0]['thread_id']), 'the WhatsApp chat is a column of the case, named, with its count');
    $key = $chatCols[0]['thread_id'];
    $chatRows = array_values(array_filter($tl['events'], static fn($e) => $e['type'] === 'chat'));
    check(count($chatRows) === 1 && count($chatRows[0]['items']) === 3 && $chatRows[0]['items'][2]['direction'] === 'out', 'three messages within minutes, nothing between: one row');
    $mat = ecw_case_chat_material($case, 30);
    check(count($mat) === 3 && $mat[0]['ref'] === 'C' . $rows[0]['id'] && $mat[2]['direction'] === 'sent by our team' && $mat[0]['channel'] === 'WhatsApp', 'the AI reads them with the emails (core hook case.chat_material)');
    check(count(ecw_case_chat_material($case, 2)) === 2 && ecw_case_chat_material($case, 2)[1]['text'] === 'We will check it', '…the newest when there are many');
    ecw_chatlink_unlink($org, $me, (int)$rows[1]['id']);
    $items = array_values(array_filter(ecw_case_timeline($org, $case, false, null, $me)['events'], static fn($e) => $e['type'] === 'chat'))[0]['items'];
    check(count($items) === 2 && $items[0]['skipped_before'] === null && $items[1]['skipped_before'] === 1, 'a message taken out leaves "1 message not in this case" where it was');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_events WHERE case_id = {$case} AND event_type = 'CHAT_UNLINKED'")->fetchColumn() === 1, '…and the history says so');
    throws(fn() => ecw_chatlink_unlink($other, $me, (int)$rows[0]['id']), InvalidArgumentException::class, 'not linked', 'not from another workspace');
    // An email between the two chat messages: they are two rows again, the email between them.
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-cl' . $org, 'email_address' => 'g' . $org . '@shop.test', 'sync_status' => 'connected', 'connected_by_user_id' => $me]);
    $folder = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'IN-' . $mb, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $thread = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'Pump']);
    $ins('case_threads', ['case_id' => $case, 'email_thread_id' => $thread]);
    $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread, 'mail_folder_id' => $folder, 'provider_message_id' => 'CL' . $org, 'subject' => 'Pump', 'from_email' => 'maria@x.test',
        'to_json' => '[]', 'cc_json' => '[]', 'body_text' => 'Photo attached', 'body_fetch_state' => 'fetched', 'direction' => 'incoming', 'received_at' => $at(-150), 'sent_at' => $at(-150)]);
    $order = array_map(static fn($e) => $e['type'] . ($e['type'] === 'chat' ? count($e['items']) : ''), ecw_case_timeline($org, $case, false, null, $me)['events']);
    check($order === ['chat1', 'email', 'chat1'], 'with an email between them, the chat messages sit above and below it, in time order');
    $alone = ecw_case_timeline($org, $case, false, $key, $me)['events'];
    check(count($alone) === 1 && count($alone[0]['items']) === 2, 'the narrow-screen view can show the chat column alone (no email between: one row again)');

    group('the chat column: rename, hide, whose, unlink');
    ecw_chatlink_column_update($org, $me, $case, $key, ['display_name' => '  Maria on WhatsApp ', 'party_role' => 'customer']);
    $col = array_values(array_filter(ecw_case_timeline($org, $case, false, null, $me)['columns'], static fn($c) => $c['thread_id'] === $key))[0];
    check($col['label'] === 'Maria on WhatsApp' && $col['renamed'] && $col['party_role'] === 'customer', 'renamed, and marked as the customer\'s');
    throws(fn() => ecw_chatlink_column_update($org, $me, $case, $key, ['party_role' => 'boss']), InvalidArgumentException::class, 'customer', 'only known roles');
    throws(fn() => ecw_chatlink_column_update($org, $me, $case2, $key, ['is_hidden' => true]), InvalidArgumentException::class, 'not in this case', "not another case's column");
    ecw_chatlink_column_update($org, $me, $case, $key, ['is_hidden' => true]);
    $hidden = ecw_case_timeline($org, $case, false, null, $me);
    check(!array_filter($hidden['events'], static fn($e) => $e['type'] === 'chat') && count(array_filter($hidden['columns'], static fn($c) => $c['thread_id'] === $key && $c['is_hidden'])) === 1,
        'hidden: its rows leave the axis, the column can be shown again');
    ecw_chatlink_column_update($org, $me, $case, $key, ['is_hidden' => false]);

    group('a linked chat gets a new message');
    $before = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$handler}")->fetchColumn();
    // Through the real WhatsApp webhook: it announces the message (hook chat.message_in).
    $pdo->prepare('UPDATE ecw_organizations SET whatsapp_json = ?, whatsapp_phone_number_id = ? WHERE id = ?')->execute([json_encode(['phone_number_id' => 'P' . $org, 'app_secret_enc' => ecw_encrypt('s3cret')]), 'P' . $org, $org]);
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'P' . $org],
        'messages' => [['from' => '306912345678', 'id' => 'wnew' . $org, 'timestamp' => (string)$now, 'type' => 'text', 'text' => ['body' => 'Any news?']]]]]]]]]);
    ecw_wa_webhook_handle($body, 'sha256=' . hash_hmac('sha256', $body, 's3cret'));
    $n = $pdo->query("SELECT * FROM ecw_notifications WHERE user_id = {$handler} ORDER BY id DESC LIMIT 1")->fetch();
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$handler}")->fetchColumn() === $before + 1 && str_contains($n['text'], 'Any news?') && (int)$n['case_id'] === $case,
        "WhatsApp: the case's handler is told, with the case");
    $beforeMe = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$me}")->fetchColumn();
    $beforeH = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$handler}")->fetchColumn();
    ecw_chatlink_message_in(['source' => 'telegram', 'organization_id' => $org, 'user_id' => $me, 'chat' => (string)$tgChat, 'from' => 'Nikos', 'text' => 'And delivery?', 'link' => 'x']);
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$me}")->fetchColumn() === $beforeMe + 1
        && (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$handler}")->fetchColumn() === $beforeH, 'Telegram: only the chat\'s owner is told (it is their private chat)');
    $quiet = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications")->fetchColumn();
    ecw_chatlink_message_in(['source' => 'whatsapp', 'organization_id' => $org, 'user_id' => null, 'chat' => '424242', 'from' => 'x', 'text' => 'x', 'link' => 'x']);
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications")->fetchColumn() === $quiet, 'a chat in no case tells nobody');

    group('AI suggestions');
    $asked = 0;
    $ai = ['provider' => 'test'];
    // The newest message ("Any news?") is in no case; the AI is asked, sees the cases this chat feeds, and picks.
    $ask = static function (string $system, string $user) use (&$asked): ?array {
        $asked++;
        $u = json_decode($user, true);
        $GLOBALS['seen'] = $u;
        $k = null;
        foreach ($u['cases'] as $c) if (!empty($c['this_chat_already_in_it'])) $k = $c['id'];
        $last = end($u['messages'])['id'];
        return ['case' => $k, 'messages' => [$last, 'm999'], 'reason' => 'Same pump.', 'confidence' => 88];
    };
    $s = ecw_chatlink_suggest($org, $me, 'whatsapp', (string)$wa, $ai, $ask);
    $newest = (string)$pdo->query("SELECT id FROM ecw_wa_messages WHERE wamid = 'wnew{$org}'")->fetchColumn();
    check($s !== null && $s['case_id'] === $case && $s['refs'] === [$newest] && $s['reason'] === 'Same pump.', 'it suggests the case and the new message (made-up message ids are dropped)');
    check(!empty($GLOBALS['seen']['messages'][0]['already_in_a_case']) && $GLOBALS['seen']['cases'][0]['this_chat_already_in_it'] === true, 'the AI is told which messages are filed already, and which cases this chat feeds');
    check(ecw_chatlink_suggest($org, $me, 'whatsapp', (string)$wa, $ai, $ask)['id'] === $s['id'] && $asked === 1, 'opening the chat again does not ask the AI again');
    ecw_chatlink_dismiss($me, $s['id']);
    check(ecw_chatlink_suggest($org, $me, 'whatsapp', (string)$wa, $ai, $ask) === null && $asked === 1, '"Not this" keeps it away');
    $low = static fn() => ['case' => 'K1', 'messages' => ['m1'], 'reason' => 'maybe', 'confidence' => 40];
    check(ecw_chatlink_suggest($org, $me, 'telegram', (string)$tgChat, $ai, static fn() => ['case' => 'K1', 'messages' => ['m1'], 'confidence' => 95]) === null, 'nothing new in a chat (all filed): nothing to suggest');
    $t2 = $ins('tg_messages', ['chat_id' => $tgChat, 'tg_message_id' => 11, 'direction' => 'in', 'kind' => 'text', 'body' => 'hi', 'status' => 'received', 'occurred_at' => $at(10)]);
    check(ecw_chatlink_suggest($org, $me, 'telegram', (string)$tgChat, $ai, $low) === null, 'an unsure AI suggests nothing');
    throws(fn() => ecw_chatlink_suggest($org, $handler, 'telegram', (string)$tgChat, $ai, $low), InvalidArgumentException::class, 'does not exist', "the AI is never given someone else's Telegram");
    $t3 = $ins('tg_messages', ['chat_id' => $tgChat, 'tg_message_id' => 12, 'direction' => 'in', 'kind' => 'text', 'body' => 'boiler delivery Friday?', 'status' => 'received', 'occurred_at' => $at(20)]);
    $s2 = ecw_chatlink_suggest($org, $me, 'telegram', (string)$tgChat, $ai, static fn() => ['case' => 'K1', 'messages' => ['m3'], 'reason' => 'Boiler.', 'confidence' => 90]);
    check($s2 !== null && $s2['case_id'] === $case2, 'a newer message is a new question');
    ecw_chatlink_link($org, $me, 'telegram', (string)$tgChat, $s2['refs'], $s2['case_id']);
    check($pdo->query("SELECT state FROM ecw_chat_link_suggestions WHERE id = {$s2['id']}")->fetchColumn() === 'used', 'linking it uses the suggestion up');

    group('taking a whole chat out of the case');
    $key = array_values(array_filter(ecw_case_timeline($org, $case, false, null, $me)['columns'], static fn($c) => ($c['kind'] ?? '') === 'chat'))[0]['thread_id'];
    check(ecw_chatlink_column_unlink($org, $me, $case, $key) === 2, 'its messages leave the case (the chat itself is untouched)');
    check(!array_filter(ecw_case_timeline($org, $case, false, null, $me)['columns'], static fn($c) => ($c['kind'] ?? '') === 'chat')
        && (int)$pdo->query("SELECT COUNT(*) FROM ecw_wa_messages WHERE chat_id = {$wa}")->fetchColumn() > 0, '…and so does its column');

    group('following a chat');
    $r = ecw_chatlink_link($org, $me, 'whatsapp', (string)$wa, [(string)$w1], $case, '', true);
    $fcol = $pdo->query("SELECT * FROM ecw_chat_link_columns WHERE case_id = {$case} AND source = 'whatsapp'")->fetch();
    check($r['linked'] === 1 && (int)$fcol['follow'] === 1 && (int)$fcol['follow_user_id'] === $me && $fcol['follow_from'] !== null, 'link with "also add new messages": the column follows the chat');
    $before = (int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_links WHERE case_id = {$case}")->fetchColumn();
    $later = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'P' . $org],
        'messages' => [['from' => '306912345678', 'id' => 'wfollow' . $org, 'timestamp' => (string)($now + 30), 'type' => 'text', 'text' => ['body' => 'Delivered today?']]]]]]]]]);
    ecw_wa_webhook_handle($later, 'sha256=' . hash_hmac('sha256', $later, 's3cret'));
    $texts = $pdo->query("SELECT body_text FROM ecw_chat_links WHERE case_id = {$case} ORDER BY sent_at")->fetchAll(PDO::FETCH_COLUMN);
    check(count($texts) > $before && end($texts) === 'Delivered today?', 'a new WhatsApp message joins the case the moment it arrives');
    check(!in_array('📷 Photo: the label', $texts, true), '…but older messages that were not chosen stay out');
    $hist = $pdo->query("SELECT event_type FROM ecw_case_events WHERE case_id = {$case} ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('CHAT_FOLLOWED', $hist, true) && ecw_chatlink_event_label('CHAT_FOLLOWED', ['channel' => 'WhatsApp']) === 'follows the WhatsApp chat: its new messages are added', 'the case history says it follows the chat');
    // Telegram: followed by its owner; a message arrives; the minute tick adds it.
    throws(fn() => ecw_chatlink_follow_set($org, $handler, $case2, 'telegram', (string)$tgChat, true), InvalidArgumentException::class, 'does not exist', "only the owner can make a case follow their Telegram");
    $tgKey = ecw_chatlink_columns($org, $case2, $me)[0]['thread_id'];
    check(array_column(ecw_chatlink_columns($org, $case2, $me)[0]['extra_actions'], 'label') === ['Follow the chat'] && ecw_chatlink_columns($org, $case2, $handler)[0]['extra_actions'] === [],
        '"Follow the chat" is offered only to who can read it');
    ecw_chatlink_column_update($org, $me, $case2, $tgKey, ['follow' => true]);
    $t9 = $ins('tg_messages', ['chat_id' => $tgChat, 'tg_message_id' => 90, 'direction' => 'in', 'kind' => 'text', 'body' => 'Can you come Monday?', 'status' => 'received', 'occurred_at' => $at(60)]);
    $pdo->exec("UPDATE ecw_chat_link_columns SET synced_at = NULL WHERE case_id = {$case2}");
    ecw_chatlink_tick();
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_links WHERE case_id = {$case2} AND message_ref = '{$t9}'")->fetchColumn() === 1, 'the minute tick adds what arrived in a followed chat');
    check(ecw_chatlink_columns($org, $case2, $me)[0]['extra_actions'][0]['label'] === 'Stop following' && str_contains(ecw_chatlink_columns($org, $case2, $me)[0]['subtitle'], 'new messages are added'), 'the column says it follows, and offers to stop');
    ecw_chatlink_column_update($org, $me, $case2, $tgKey, ['follow' => false]);
    $ins('tg_messages', ['chat_id' => $tgChat, 'tg_message_id' => 91, 'direction' => 'in', 'kind' => 'text', 'body' => 'hello?', 'status' => 'received', 'occurred_at' => $at(90)]);
    ecw_chatlink_tick();
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_links WHERE case_id = {$case2} AND body_text = 'hello?'")->fetchColumn() === 0, 'stopped: nothing more is added');

    group('replying from the case');
    $waCol = array_values(array_filter(ecw_chatlink_columns($org, $case, $me), static fn($c) => $c['source'] === 'whatsapp'))[0];
    check($waCol['reply_action'] === 'chatlink_column_reply' && str_contains($waCol['reply_note'], '24 hours'), 'WhatsApp: everyone may reply, and is told about the 24 hours');
    check(ecw_chatlink_columns($org, $case2, $handler)[0]['reply_action'] === null && ecw_chatlink_columns($org, $case2, $me)[0]['reply_action'] === 'chatlink_column_reply', "Telegram: only its owner may write in it");
    $pdo->prepare('UPDATE ecw_organizations SET whatsapp_json = ? WHERE id = ?')->execute([json_encode(['phone_number_id' => 'P' . $org, 'app_secret_enc' => ecw_encrypt('s3cret'), 'token_enc' => ecw_encrypt('EAA-test')]), $org]);
    $sentTo = null;
    $GLOBALS['ecw_wa_transport'] = static function (string $m, string $url, ?array $json) use (&$sentTo): array { $sentTo = $json; return ['body' => ['messages' => [['id' => 'wamid.reply1']]]]; };
    check(ecw_chatlink_reply($org, $handler, $case, $waCol['thread_id'], 'The technician comes at 10.') === 1 && ($sentTo['text']['body'] ?? '') === 'The technician comes at 10.', 'a reply goes out on WhatsApp…');
    $last = $pdo->query("SELECT * FROM ecw_chat_links WHERE case_id = {$case} ORDER BY id DESC LIMIT 1")->fetch();
    check($last['body_text'] === 'The technician comes at 10.' && $last['direction'] === 'out' && $last['sender'] === 'Kostas', '…and is in the case at once, as ours');
    unset($GLOBALS['ecw_wa_transport']);
    throws(fn() => ecw_chatlink_reply($org, $handler, $case2, $tgKey, 'hi'), InvalidArgumentException::class, 'Only the owner', "nobody writes from someone else's Telegram");
    throws(fn() => ecw_chatlink_reply($org, $me, $case, $waCol['thread_id'], '   '), InvalidArgumentException::class, 'Write a message', 'an empty reply is refused');

    group('merging cases');
    $n1 = (int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_links WHERE case_id IN ({$case}, {$case2})")->fetchColumn();
    ecw_chatlink_case_merged($case2, $case);
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_links WHERE case_id = {$case}")->fetchColumn() === $n1 && (int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_links WHERE case_id = {$case2}")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM ecw_chat_link_columns WHERE case_id = {$case}")->fetchColumn() === 2, 'merging a case moves its chats (messages and columns) to the case it went into');

    group('the old Teams tab becomes chat columns (migration 058)');
    $mbT = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'aad-me-' . $org, 'email_address' => 't' . $org . '@shop.test', 'sync_status' => 'connected', 'connected_by_user_id' => $me]);
    $oldLink = $ins('case_teams_chats', ['case_id' => $case2, 'chat_id' => '19:old' . $org . '@thread.v2', 'title' => 'Pump team', 'linked_by_user_id' => $me, 'linked_at' => $at(-9000)]);
    $ins('case_teams_messages', ['link_id' => $oldLink, 'provider_message_id' => '1700000000001', 'from_name' => 'Maria', 'from_user_id' => 'aad-maria', 'body_html' => '<p>Pump &amp; motor<br>broken</p>', 'created_at' => $at(-8000)]);
    $ins('case_teams_messages', ['link_id' => $oldLink, 'provider_message_id' => '1700000000002', 'from_name' => 'Giorgos', 'from_user_id' => 'aad-me-' . $org, 'body_html' => '<p>On it</p>', 'created_at' => $at(-7000)]);
    $sql = (string)file_get_contents(dirname(__DIR__) . '/migrations/20261018_ecw_058_chatlink_follow.sql');
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql))), static fn($s) => str_starts_with($s, 'INSERT')) as $stmt) $pdo->exec($stmt);
    $moved = $pdo->query("SELECT * FROM ecw_chat_links WHERE case_id = {$case2} AND source = 'teams' ORDER BY sent_at")->fetchAll();
    $mcol = $pdo->query("SELECT * FROM ecw_chat_link_columns WHERE case_id = {$case2} AND source = 'teams'")->fetch();
    check(count($moved) === 2 && $moved[0]['chat_title'] === 'Pump team' && $moved[0]['direction'] === 'in' && $moved[1]['direction'] === 'out', 'its messages are copied, ours told apart from theirs');
    check(str_contains($moved[0]['body_text'], 'Pump &amp; motor') && str_contains($moved[0]['body_text'], "\nbroken") && $moved[0]['body_html'] === '<p>Pump &amp; motor<br>broken</p>', 'with the text for the AI and the HTML as it was');
    check($mcol && (int)$mcol['follow'] === 1 && (int)$mcol['follow_user_id'] === $me, 'and it keeps following the chat with the access of whoever linked it');

    group('the module in the core (lib/modules.php)');
    check(isset(ecw_modules()['chatlink']) && isset(ecw_feature_catalog()['chat_links']) && isset(ecw_feature_catalog()['chat_links_ai']), 'the module and its features are found');
    check(in_array('chat_links', ecw_packages()['pro']['features'], true) && in_array('chat_links_ai', ecw_packages()['business']['features'], true) && !in_array('chat_links', ecw_packages()['free']['features'], true), 'in Pro and above, not in Free');
    check(ecw_module_case_tabs() === [] && count(ecw_hook_functions('case.timeline_columns')) === 1 && count(ecw_hook_functions('case.timeline_events')) === 1, 'it adds columns to the case timeline (no side tab)');
    $a = ecw_module_api_action('chatlink_link');
    check(($a['feature'] ?? null) === 'chat_links' && !empty($a['post']) && ecw_module_api_action('chatlink_suggest')['feature'] === 'chat_links_ai' && !empty(ecw_module_api_action('chatlink_column_unlink')['case_scoped']), 'linking is a POST behind the feature; suggestions behind the AI feature; column changes check the case');
    check(in_array('20261018_ecw_056_chatlink.sql', array_map('basename', ecw_migration_files()), true), "its migration runs with the core's");
    throws(fn() => ecw_chatlink_read($org, $me, 'teams', '', null), InvalidArgumentException::class, 'Choose a chat', 'Teams: a chat is needed');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
