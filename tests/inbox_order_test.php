<?php declare(strict_types=1);
/**
 * A folder lists each conversation by its newest email IN THAT FOLDER (lib/mail.php
 * ecw_thread_list). Answering an email received weeks ago must not move it to the top of
 * the Inbox as if it came today: the reply is in Sent Items.
 *
 *   php tests/inbox_order_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/provider/MicrosoftGraphProvider.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Inbox order']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-order' . $org, 'email_address' => 'me' . $org . '@order.test',
        'sync_status' => 'connected', 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'O-IN', 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $sent = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'O-SENT', 'name' => 'Sent Items', 'folder_type' => 'sent', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $provider = new MicrosoftGraphProvider(['tenant_id' => 't', 'client_id' => 'c']);
    $at = static fn(int $daysAgo) => gmdate('Y-m-d\TH:i:s\Z', time() - $daysAgo * 86400);
    $receive = static function (string $id, string $conv, string $from, int $daysAgo) use ($provider, $mb, $inbox, $at): array {
        $m = $provider->normalizeMessage(FakeGraph::message(['id' => $id, 'conversationId' => $conv, 'subject' => 'About ' . $conv, 'isRead' => true,
            'from' => ['emailAddress' => ['address' => $from, 'name' => ucfirst(strtok($from, '@'))]], 'receivedDateTime' => $at($daysAgo), 'sentDateTime' => $at($daysAgo)]));
        $m['body_html'] = '<p>Question ' . $id . '</p>';
        return ecw_ingest_message($mb, $inbox, 'inbox', $m);
    };
    $reply = static function (string $id, string $conv, int $daysAgo) use ($provider, $mb, $sent, $at, $org): array {
        $m = $provider->normalizeMessage(FakeGraph::message(['id' => $id, 'conversationId' => $conv, 'subject' => 'RE: About ' . $conv,
            'from' => ['emailAddress' => ['address' => 'me' . $org . '@order.test', 'name' => 'Me']], 'sentDateTime' => $at($daysAgo), 'receivedDateTime' => null]));
        $m['body_html'] = '<p>My answer ' . $id . '</p>';
        return ecw_ingest_message($mb, $sent, 'sent', $m);
    };

    echo "\n=== an old email answered today ===\n";
    $old = $receive('OLD', 'CONV-OLD', 'anna@acme.test', 12);      // received 12 days ago
    $mid = $receive('MID', 'CONV-MID', 'bob@beta.test', 3);         // received 3 days ago
    $reply('ANS', 'CONV-OLD', 0);                                   // I answer the old one today
    $list = ecw_thread_list($mb, ['folder_id' => $inbox]);
    $order = array_column($list['threads'], 'id');
    check($order === [(int)$mid['thread_id'], (int)$old['thread_id']],'the Inbox keeps it where it was received: below the email of 3 days ago', json_encode($order));
    $row = array_values(array_filter($list['threads'], static fn($r) => $r['id'] === (int)$old['thread_id']))[0] ?? [];
    check(substr((string)($row['latest_at'] ?? ''), 0, 10) === gmdate('Y-m-d', time() - 12 * 86400), 'with the date it was received, not today', (string)($row['latest_at'] ?? ''));
    check(($row['from_name'] ?? '') === 'Anna' && str_contains((string)($row['preview'] ?? ''), 'Question OLD') && ($row['direction'] ?? '') === 'incoming',
        'and who wrote it and what, not my answer', json_encode([$row['from_name'] ?? null, $row['preview'] ?? null]));
    $sentList = ecw_thread_list($mb, ['folder_id' => $sent]);
    check(array_column($sentList['threads'], 'id') === [(int)$old['thread_id']] && substr((string)$sentList['threads'][0]['latest_at'], 0, 10) === gmdate('Y-m-d'),
        'Sent Items shows my answer, today');
    $receive('NEW', 'CONV-OLD', 'anna@acme.test', 0);
    check(array_column(ecw_thread_list($mb, ['folder_id' => $inbox])['threads'], 'id')[0] === (int)$old['thread_id'], 'when they reply, it comes to the top of the Inbox');

    echo "\n=== pages ===\n";
    for ($i = 0; $i < 7; $i++) $receive('P' . $i, 'CONV-P' . $i, 'p' . $i . '@many.test', 20 + $i);
    $seen = [];
    $cursor = null;
    for ($n = 0; $n < 10; $n++) {
        $page = ecw_thread_list($mb, ['folder_id' => $inbox, 'limit' => 3] + ($cursor !== null ? ['cursor' => $cursor] : []));
        $seen = array_merge($seen, array_column($page['threads'], 'id'));
        $cursor = $page['next_cursor'];
        if ($cursor === null) break;
    }
    check(count($seen) === 9 && count(array_unique($seen)) === 9, 'page by page, every conversation once, none twice', json_encode($seen));

    echo "\n=== a colour rule judges the email the row shows ===\n";
    require_once dirname(__DIR__) . '/lib/rules.php';
    $user = $ins('users', ['name' => 'Me', 'email' => 'me' . $org . '@order.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $user, 'role' => 'admin']);
    $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET connected_by_user_id = ? WHERE id = ?')->execute([$user, $mb]);
    ecw_rule_save($org, $user, false, ['kind' => 'color', 'name' => 'support', 'conditions' => ['match' => 'all', 'items' => [['field' => 'to', 'op' => 'contains', 'value' => 'support@order.test']]],
        'actions' => ['color' => '#dc2626']]);
    $addressed = static function (string $id, string $conv, array $to, array $cc, int $daysAgo) use ($provider, $mb, $inbox, $at): array {
        $people = static fn(array $list) => array_map(static fn($e) => ['emailAddress' => ['address' => $e, 'name' => '']], $list);
        $m = $provider->normalizeMessage(FakeGraph::message(['id' => $id, 'conversationId' => $conv, 'subject' => 'Rule ' . $conv, 'isRead' => true,
            'from' => ['emailAddress' => ['address' => 'client@x.test', 'name' => 'Client']], 'toRecipients' => $people($to), 'ccRecipients' => $people($cc),
            'receivedDateTime' => $at($daysAgo), 'sentDateTime' => $at($daysAgo)]));
        return ecw_ingest_message($mb, $inbox, 'inbox', $m);
    };
    $toThread = $addressed('R1', 'CONV-RULE-TO', ['support@order.test'], [], 2);
    $ccThread = $addressed('R2', 'CONV-RULE-CC', ['support@order.test'], [], 2);
    $addressed('R3', 'CONV-RULE-CC', ['anna@acme.test'], ['support@order.test'], 1);   // the reply has support@ in Cc
    $rows = array_column(ecw_thread_list($mb, ['folder_id' => $inbox, 'user_id' => $user, 'organization_id' => $org])['threads'], null, 'id');
    check(($rows[(int)$toThread['thread_id']]['color'] ?? null) === '#dc2626', '"To contains support@": the email with support@ in To is coloured');
    check(empty($rows[(int)$ccThread['thread_id']]['color']), 'the row whose email has support@ only in Cc is not', json_encode($rows[(int)$ccThread['thread_id']]['color'] ?? null));
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
