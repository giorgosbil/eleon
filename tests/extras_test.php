<?php declare(strict_types=1);
/**
 * Undo send / send later, drafts sync bookkeeping, blocked senders, meeting
 * invites, case tags / due / merge / auto-assign / history, Web Push crypto.
 *
 *   php tests/extras_test.php
 *
 * Runs inside one transaction that is always rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/compose.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/junk.php';
require_once dirname(__DIR__) . '/lib/case_extras.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/notifications.php';
require_once dirname(__DIR__) . '/lib/push.php';

$pass = 0; $fail = 0;
function group(string $t): void { echo "\n=== {$t} ===\n"; }
function check(bool $c, string $label): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$label}\n"; } else { $fail++; echo "  FAIL  {$label}\n"; } }

group('Web Push encryption (RFC 8291) and VAPID signature');
// A "browser": its key pair and auth secret.
$ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d = openssl_pkey_get_details($ua)['ec'];
$uaPublic = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
$auth = random_bytes(16);
$msg = '{"title":"Hello","body":"Γειά σου — test"}';
$body = ecw_push_encrypt($msg, ecw_b64u($uaPublic), ecw_b64u($auth));
// Decrypt exactly as the browser would.
$salt = substr($body, 0, 16); $rs = unpack('N', substr($body, 16, 4))[1]; $idlen = ord($body[20]);
$asPublic = substr($body, 21, $idlen); $cipher = substr($body, 21 + $idlen);
$der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPublic;
$asKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
$shared = openssl_pkey_derive($asKey, $ua, 32);
$ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
$cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
$plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
check($rs === 4096 && $idlen === 65, 'the header has the record size and the server key');
check($plain === $msg . "\x02", 'the browser can decrypt the message (and the padding delimiter is right)');
// VAPID JWT: raw r||s signature verifies with the public key.
$vk = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
openssl_pkey_export($vk, $pem);
$jwt = ecw_vapid_jwt('https://fcm.googleapis.com', $pem);
[$h64, $c64, $s64] = explode('.', $jwt);
$sig = ecw_b64u_decode($s64);
$int = static function (string $v): string { $v = ltrim($v, "\0"); if (ord($v[0]) & 0x80) $v = "\0" . $v; return "\x02" . chr(strlen($v)) . $v; };
$derSig = $int(substr($sig, 0, 32)) . $int(substr($sig, 32));
$derSig = "\x30" . chr(strlen($derSig)) . $derSig;
check(strlen($sig) === 64 && openssl_verify($h64 . '.' . $c64, $derSig, openssl_pkey_get_details($vk)['key'], OPENSSL_ALGO_SHA256) === 1, 'the VAPID signature verifies');
check((json_decode(ecw_b64u_decode($c64), true)['aud'] ?? '') === 'https://fcm.googleapis.com', 'the JWT names the push service');

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = function (string $t, array $row) use ($pdo): int {
        $c = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'Extras test']);
    $u = [];
    foreach (['ann', 'bob', 'cat'] as $n) {
        $u[$n] = $ins('users', ['name' => ucfirst($n) . ' X', 'email' => $n . uniqid() . '@acme.test', 'password_hash' => 'x']);
        $ins('organization_users', ['organization_id' => $org, 'user_id' => $u[$n], 'role' => 'user']);
    }
    $mb = $ins('mailboxes', ['organization_id' => $org, 'connected_by_user_id' => $u['ann'], 'provider' => 'microsoft', 'email_address' => 'box' . uniqid() . '@acme.test', 'sync_status' => 'connected']);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'in' . uniqid(), 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1]);

    group('undo send / send later');
    $draft = ecw_draft_save($org, $mb, $u['ann'], ['subject' => 'Offer', 'body_html' => '<p>Hi</p>', 'to' => ['customer@client.test']]);
    check((int)$pdo->query('SELECT provider_push_pending FROM ecw_drafts WHERE id = ' . $draft)->fetchColumn() === 1, 'a saved draft waits to be copied to the mailbox');
    $at = ecw_draft_schedule($org, $draft);
    check(abs(strtotime($at . ' UTC') - (time() + ECW_UNDO_SEND_SECONDS)) <= 2, 'Send queues it for the Undo window');
    check(ecw_draft_unschedule($org, $draft) && $pdo->query('SELECT send_state FROM ecw_drafts WHERE id = ' . $draft)->fetchColumn() === null, 'Undo takes it back');
    check(!ecw_draft_unschedule($org, $draft), 'a second Undo has nothing to undo');
    $later = gmdate('Y-m-d H:i:s', time() + 86400);
    ecw_draft_schedule($org, $draft, $later);
    check($pdo->query('SELECT send_at FROM ecw_drafts WHERE id = ' . $draft)->fetchColumn() === $later, 'Send later keeps the chosen time');
    check(ecw_drafts_send_due() === 0, 'nothing is sent before its time');
    // Reopened and closed in the composer (it says keep_schedule while it is scheduled): still scheduled, with the change.
    ecw_draft_save($org, $mb, $u['ann'], ['subject' => 'Offer v1b', 'body_html' => '<p>Hi</p>', 'to' => ['customer@client.test'], 'keep_schedule' => true], $draft);
    $row = $pdo->query('SELECT send_state, send_at, subject FROM ecw_drafts WHERE id = ' . $draft)->fetch();
    check($row['send_state'] === 'scheduled' && $row['send_at'] === $later && $row['subject'] === 'Offer v1b', 'a scheduled email opened and closed again stays scheduled, at its time, with the change');
    ecw_draft_save($org, $mb, $u['ann'], ['subject' => 'Offer v2', 'body_html' => '<p>Hi</p>', 'to' => ['customer@client.test']], $draft);
    check($pdo->query('SELECT send_state FROM ecw_drafts WHERE id = ' . $draft)->fetchColumn() === null, 'editing a scheduled draft takes it out of the queue');
    $empty = ecw_draft_save($org, $mb, $u['ann'], ['subject' => 'x', 'body_html' => '', 'to' => []]);
    try { ecw_draft_schedule($org, $empty); check(false, 'a draft without recipients cannot be queued'); } catch (InvalidArgumentException $e) { check(true, 'a draft without recipients cannot be queued'); }
    // Due now, but the mailbox has no tokens: the send fails, the draft is kept and its author told.
    ecw_draft_schedule($org, $draft);
    $pdo->exec("UPDATE ecw_drafts SET send_at = '2000-01-01 00:00:00' WHERE id = " . $draft);
    $before = ecw_notifications_unread($u['ann']);
    ecw_drafts_send_due();
    $row = $pdo->query('SELECT send_state, send_error FROM ecw_drafts WHERE id = ' . $draft)->fetch();
    check($row['send_state'] === 'failed' && $row['send_error'] !== '', 'a failed send keeps the draft, marked "not sent"');
    check(ecw_notifications_unread($u['ann']) === $before + 1, 'and its author gets a notification');
    $list = array_column(ecw_drafts_list($org, $u['ann']), null, 'id');
    check(($list[$draft]['send_state'] ?? '') === 'failed', 'the Drafts list shows it');

    group('blocked senders');
    check(ecw_sender_block($mb, 'Spam@Bad.test', $u['ann']) === 'spam@bad.test', 'an address can be blocked (case-insensitive)');
    check(ecw_sender_block($mb, '@junkmail.test', $u['ann']) === '@junkmail.test', 'a whole domain can be blocked');
    check(ecw_sender_is_blocked($mb, 'spam@bad.test') && ecw_sender_is_blocked($mb, 'anyone@junkmail.test'), 'both are recognised');
    check(!ecw_sender_is_blocked($mb, 'friend@bad.test.org'), 'a look-alike domain is not');
    try { ecw_sender_block($mb, 'not an address', $u['ann']); check(false, 'nonsense is refused'); } catch (InvalidArgumentException $e) { check(true, 'nonsense is refused'); }
    $own = (string)$pdo->query('SELECT email_address FROM ecw_mailboxes WHERE id = ' . $mb)->fetchColumn();
    try { ecw_sender_block($mb, $own, $u['ann']); check(false, 'you cannot block yourself'); } catch (InvalidArgumentException $e) { check(true, 'you cannot block yourself'); }
    check(ecw_sender_unblock($mb, 'spam@bad.test') && !ecw_sender_is_blocked($mb, 'spam@bad.test'), 'unblocking');

    group('meeting invitations');
    $pid = 'p' . bin2hex(random_bytes(5));
    $base = ['provider_message_id' => $pid, 'internet_message_id' => '<' . $pid . '@x>', 'provider_conversation_id' => 'c' . uniqid(), 'subject' => 'Kick-off',
        'from_email' => 'org@client.test', 'from_name' => 'Org', 'to' => [], 'cc' => [], 'bcc' => [], 'received_at' => gmdate('Y-m-d H:i:s'),
        'sent_at' => gmdate('Y-m-d H:i:s'), 'is_read' => false, 'has_attachments' => false, 'references' => []];
    $t = ecw_ingest_message($mb, $inbox, 'inbox', $base + ['meeting_kind' => 'eventMessageRequest'])['thread_id'];
    $detail = ecw_thread_detail($mb, $t);
    check(($detail['messages'][0]['meeting_kind'] ?? null) === 'eventMessageRequest', 'an invitation is recognised');
    ecw_ingest_message($mb, $inbox, 'inbox', $base + ['is_read' => true, '_source_fields' => ['isRead']]);
    check((ecw_thread_detail($mb, $t)['messages'][0]['meeting_kind'] ?? null) === 'eventMessageRequest', 'a later update without the type keeps it');

    group('case tags, due date, history');
    $case = ecw_case_create($org, 'Pump', $u['ann']);
    check(ecw_case_tags_save($case, [' Urgent ', 'quote', 'urgent', '<b>x</b>', ''], $u['ann']) === ['bxb', 'quote', 'urgent'], 'tags are cleaned (no markup), lower-cased and de-duplicated');
    check(ecw_case_list($org, ['user_id' => $u['ann'], 'status' => 'all', 'tag' => 'quote'])['total'] === 1, 'the case list filters by tag');
    check(ecw_case_list($org, ['user_id' => $u['ann'], 'status' => 'all', 'tag' => 'nothing'])['total'] === 0, 'and finds nothing for an unused tag');
    check(ecw_case_update($org, $case, ['due_at' => gmdate('Y-m-d', time() - 86400)], $u['ann']), 'a due date can be set');
    check(!ecw_case_update($org, $case, ['due_at' => '2026-02-31'], $u['ann']), 'an impossible date is refused');
    ecw_case_update($org, $case, ['handler_user_id' => $u['bob']], $u['ann']);
    $before = ecw_notifications_unread($u['ann']);
    ecw_cases_due_notify();
    check(ecw_notifications_unread($u['ann']) === $before + 1, 'Responsible is told the case is overdue');
    ecw_cases_due_notify();
    check(ecw_notifications_unread($u['ann']) === $before + 1, 'only once');
    $types = array_column(ecw_case_history($case), 'type');
    check(in_array('CASE_TAGS_CHANGED', $types, true) && in_array('CASE_DUE_CHANGED', $types, true) && in_array('CASE_CREATED', $types, true), 'the history records tags, due date and creation');
    check(str_contains(implode(' | ', array_column(ecw_case_history($case), 'text')), 'set the due date to'), 'in words');

    group('merge');
    $other = ecw_case_create($org, 'Pump (duplicate)', $u['ann']);
    $tOther = ecw_ingest_message($mb, $inbox, 'inbox', array_merge($base, ['provider_message_id' => 'q' . uniqid(), 'internet_message_id' => '<' . uniqid() . '@y>', 'provider_conversation_id' => 'c' . uniqid(), 'meeting_kind' => null]))['thread_id'];
    ecw_case_link_thread($other, $tOther, null, $u['ann']);
    ecw_case_tags_save($other, ['warranty'], $u['ann']);
    try { ecw_case_merge($org, $case, $case, $u['ann']); check(false, 'a case cannot merge into itself'); } catch (InvalidArgumentException $e) { check(true, 'a case cannot merge into itself'); }
    ecw_case_merge($org, $other, $case, $u['ann']);
    check((int)$pdo->query('SELECT case_id FROM ecw_case_threads WHERE email_thread_id = ' . $tOther)->fetchColumn() === $case, 'its conversation moved to the target');
    check(in_array('warranty', ecw_case_tags($case), true), 'its tags too');
    $gone = $pdo->query('SELECT deleted_at, merged_into_case_id FROM ecw_cases WHERE id = ' . $other)->fetch();
    check($gone['deleted_at'] !== null && (int)$gone['merged_into_case_id'] === $case, 'the merged case is closed with a pointer to the target');

    group('auto-assign');
    $grp = $ins('case_groups', ['organization_id' => $org, 'name' => 'Support ' . uniqid(), 'auto_assign' => 1]);
    $ins('case_group_members', ['group_id' => $grp, 'user_id' => $u['bob']]);
    $ins('case_group_members', ['group_id' => $grp, 'user_id' => $u['cat']]);
    $pdo->prepare("UPDATE ecw_cases SET owner_user_id = ? WHERE id = ?")->execute([$u['bob'], $case]);
    check(ecw_case_auto_owner($grp) === $u['cat'], 'the member with fewer open cases gets the next one');
    $pdo->exec('UPDATE ecw_case_groups SET auto_assign = 0 WHERE id = ' . $grp);
    check(ecw_case_auto_owner($grp) === null, 'nothing is assigned when the group does not auto-assign');
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
