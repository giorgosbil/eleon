<?php declare(strict_types=1);
/**
 * Reading a conversation fast and completely:
 *   - pictures already stored (inline attachments) are never fetched again;
 *   - the conversation comes without each email's quoted history (the "..."
 *     fetches one email's, with it);
 *   - saved draft replies show in their conversation (own, and colleagues'
 *     shared ones; never a colleague's private one or one being sent).
 *
 *   php tests/thread_reading_test.php
 *
 * Database writes are real and rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/compose.php';

$passed = 0; $failed = 0;
function check(bool $ok, string $label): void { global $passed, $failed; $ok ? $passed++ : $failed++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'Reading test']);
    $me = $ins('users', ['name' => 'Me', 'email' => 'me' . $org . '@example.test', 'password_hash' => 'x']);
    $ann = $ins('users', ['name' => 'Ann', 'email' => 'ann' . $org . '@example.test', 'password_hash' => 'x']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'rd-' . $org, 'email_address' => 'me@example.test', 'sync_status' => 'connected']);
    $folder = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'inbox', 'name' => 'Inbox', 'folder_type' => 'inbox']);
    $thread = $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'rd-' . uniqid(), 'normalized_subject' => 'Offer']);
    $msg = static fn(string $html, string $at) => $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread, 'mail_folder_id' => $folder,
        'provider_message_id' => 'rd' . uniqid('', true), 'subject' => 'Offer', 'from_email' => 'c@customer.test', 'direction' => 'incoming', 'received_at' => $at,
        'body_html' => $html, 'body_text' => strip_tags($html), 'body_fetch_state' => 'fetched']);
    $history = '<div id="divRplyFwdMsg"><b>From:</b> Me<br><b>Sent:</b> Monday</div><div>' . str_repeat('Earlier words. ', 400) . '</div>';
    $first = $msg('<p>First, with our logo <img src="cid:logo1"></p>', '2026-10-01 09:00:00');
    $second = $msg('<p>Second <img src="cid:logo2"></p>' . $history, '2026-10-02 09:00:00');
    $ins('email_attachments', ['email_message_id' => $first, 'filename' => 'logo.png', 'mime_type' => 'image/png', 'size_bytes' => 10, 'is_inline' => 1, 'content_id' => 'logo1']);

    // ---- pictures
    check(!ecw_inline_images_missing($first, '<img src="cid:logo1">'), 'an email whose inline pictures are stored needs nothing fetched');
    check(ecw_inline_images_missing($second, '<img src="cid:logo2">'), 'an email with pictures and none stored is fetched');
    check(!ecw_inline_images_missing($second, '<p>no pictures</p>'), 'an email without pictures needs nothing');
    $full = ecw_thread_detail($mb, $thread);
    $byId = array_column($full['messages'], null, 'id');
    check($byId[$first]['needs_inline_images'] === false, 'the conversation does not ask again for pictures already stored (the bug: inline ones did not count)');

    // ---- quoted history on demand
    $light = ecw_thread_detail($mb, $thread, 'asc', false, ['quoted' => false]);
    $lightById = array_column($light['messages'], null, 'id');
    check($lightById[$second]['has_quoted'] === true && $lightById[$second]['quoted_html'] === null, 'the conversation says there is history but leaves it out');
    check(strlen(json_encode($light)) < strlen(json_encode($full)) / 2, 'which makes it much smaller');
    $one = ecw_thread_detail($mb, $thread, 'asc', false, ['message_id' => $second]);
    check(count($one['messages']) === 1 && str_contains((string)$one['messages'][0]['quoted_html'], 'Earlier words'), 'one email comes alone, with its history, for the "..."');
    check(ecw_thread_detail($mb + 100000, $thread, 'asc', false, ['message_id' => $second]) === null, 'not from another mailbox');

    // ---- drafts in the conversation
    $draft = static fn(int $user, array $extra = []) => $ins('drafts', array_merge(['organization_id' => $org, 'mailbox_id' => $mb, 'user_id' => $user, 'mode' => 'reply',
        'reply_to_local_message_id' => $second, 'subject' => 'RE: Offer', 'to_json' => json_encode([['email' => 'c@customer.test']]),
        'body_html' => '<p>Dear customer,<br>here is <b>the</b> price.</p>'], $extra));
    $mine = $draft($me);
    $annPrivate = $draft($ann);
    $annShared = $draft($ann, ['shared_at' => gmdate('Y-m-d H:i:s')]);
    $leaving = $draft($me, ['send_state' => 'sending']);
    $scheduled = $draft($me, ['send_state' => 'scheduled', 'send_at' => '2026-12-01 08:00:00']);
    $list = ecw_thread_drafts($org, $me, $thread);
    $ids = array_column($list, 'id');
    check(in_array($mine, $ids, true) && in_array($scheduled, $ids, true), 'my saved and scheduled drafts show in the conversation');
    check(in_array($annShared, $ids, true) && !in_array($annPrivate, $ids, true), 'a colleague\'s shared draft shows, their private one never');
    check(!in_array($leaving, $ids, true), 'a draft already being sent does not show as a draft');
    $row = array_column($list, null, 'id')[$mine];
    check($row['mine'] && $row['to'] === ['c@customer.test'] && $row['preview'] === 'Dear customer, here is the price.', 'a draft shows who it is to and its text, without markup');
    check(array_column($list, null, 'id')[$annShared]['mine'] === false && array_column($list, null, 'id')[$annShared]['author'] === 'Ann', 'a colleague\'s draft says whose it is');
    check(ecw_thread_drafts($org + 100000, $me, $thread) === [], 'no drafts leak to another organization');
} finally {
    $pdo->rollBack();
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
