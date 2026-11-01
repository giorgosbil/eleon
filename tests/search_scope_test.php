<?php declare(strict_types=1);
/**
 * Search in every folder (like Outlook): a conversation filed in Archive is found from a search
 * without a folder, and each result says which folder it is in (the folder it was filed in,
 * not Sent Items because our reply is the newest). With the folder given, only that folder.
 *
 *   php tests/search_scope_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/mail_actions.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Search scope']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'scope-' . $org, 'email_address' => 'me@scope' . $org . '.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $folder = static fn(string $name, string $type) => $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => $type . $mb, 'name' => $name, 'folder_type' => $type, 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $inbox = $folder('Inbox', 'inbox'); $archive = $folder('Archive', 'archive'); $sent = $folder('Sent Items', 'sent');
    $n = 0;
    $msg = static function (int $thread, int $f, string $dir, string $subject, int $hoursAgo) use ($ins, $mb, &$n): int {
        $n++;
        $at = gmdate('Y-m-d H:i:s', time() - $hoursAgo * 3600);
        $id = $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread, 'mail_folder_id' => $f, 'provider_message_id' => 'sc' . $n . uniqid(), 'subject' => $subject,
            'from_email' => $dir === 'outgoing' ? 'me@scope.test' : 'smohamed@canexalu.test', 'from_name' => $dir === 'outgoing' ? 'Me' : 'Shimaa Mohamed | CANEX',
            'direction' => $dir, ($dir === 'outgoing' ? 'sent_at' : 'received_at') => $at, 'body_text' => 'Calibration dates', 'body_fetch_state' => 'fetched']);
        $pdo2 = ecw_db();
        $pdo2->prepare('UPDATE `' . ecw_table('email_threads') . '` SET latest_message_at = ?, message_count = message_count + 1 WHERE id = ?')->execute([$at, $thread]);
        return $id;
    };
    $filed = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'ascona basic fast calibration 2026', 'message_count' => 0]);
    $msg($filed, $archive, 'incoming', 'Ascona Basic Fast calibration 2026', 30);
    $msg($filed, $sent, 'outgoing', 'RE: Ascona Basic Fast calibration 2026', 2);   // our reply is the newest
    $other = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'ascona order', 'message_count' => 0]);
    $msg($other, $inbox, 'incoming', 'Ascona order', 5);

    $all = ecw_thread_list($mb, ['query' => 'subject:Ascona']);
    $ids = array_column($all['threads'], 'id');
    check(in_array($filed, $ids, true) && in_array($other, $ids, true), 'a search without a folder finds the conversation filed in Archive too');
    $row = array_values(array_filter($all['threads'], static fn($t) => $t['id'] === $filed))[0] ?? [];
    check(($row['folder']['name'] ?? '') === 'Archive', 'it says it is in Archive, not Sent Items', json_encode($row['folder'] ?? null));
    // Top results: named with every word typed, above the dates, and not again below.
    $other2 = $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'invoice for basic fast', 'message_count' => 0]);
    $msg($other2, $inbox, 'incoming', 'Invoice for Basic Fast', 1);   // the newest, named "basic fast" but not "calibration"
    $r = ecw_thread_list($mb, ['query' => 'subject:fast subject:calibration']);
    check(($r['top'] ?? null) === [], 'operators alone (subject:) give no Top results: only words typed do');
    $r = ecw_thread_list($mb, ['query' => 'FAST calibration']);
    $topIds = array_column($r['top'] ?? [], 'id');
    check($topIds === [$filed], 'the conversation named with every word is the top result (case does not matter)', json_encode($topIds));
    check(!in_array($filed, array_column($r['threads'], 'id'), true), 'and is not listed a second time below');
    $r2 = ecw_thread_list($mb, ['query' => 'FAST calibration', 'cursor' => ecw_encode_cursor(gmdate('Y-m-d H:i:s'), PHP_INT_MAX)]);
    check(($r2['top'] ?? []) === [], 'the next pages carry no Top results');
    $only = array_column(ecw_thread_list($mb, ['query' => 'subject:Ascona', 'folder_id' => $inbox])['threads'], 'id');
    check($only === [$other], 'with the folder given ("Only Inbox"), only that folder', json_encode($only));
    $plain = ecw_thread_list($mb, ['folder_id' => $inbox])['threads'][0] ?? [];
    check(!array_key_exists('folder', $plain), 'an ordinary folder list carries no folder tag');
    $types = array_column(ecw_thread_detail($mb, $filed)['messages'], 'folder_type');
    check(in_array('archive', $types, true) && in_array('sent', $types, true), 'the open conversation knows its emails are in Archive (the button lights up)');

    // "Back to Inbox" from the lit Archive button: the archived emails go to the Inbox, our reply stays in Sent.
    $fake = new FakeGraph();
    $fake->on('POST', '#/messages/[^/]+/move$#', ['status' => 201, 'body' => ['id' => 'MOVED-1']]);
    $fake->install();
    $r = ecw_thread_move($mb, $filed, 'unarchive');
    $moves = $fake->callsMatching('#/move$#');
    FakeGraph::uninstall();
    $now = array_column(ecw_thread_detail($mb, $filed)['messages'], 'folder_type');
    check(count($r['moved']) === 1 && count($moves) === 1 && ($moves[0]['json']['destinationId'] ?? '') === 'inbox' . $mb, 'one archived email moved to the Inbox at Outlook', json_encode($moves[0]['json'] ?? null));
    check(in_array('inbox', $now, true) && in_array('sent', $now, true) && !in_array('archive', $now, true), 'now in the Inbox; our reply still in Sent Items', json_encode($now));
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
