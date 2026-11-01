<?php declare(strict_types=1);
/**
 * What the assistant can do (lib/ai_actions.php): open emails and cases, make
 * spreadsheets, list what is left unanswered, and propose changes that happen
 * only when the person confirms them, with their own rights.
 *
 *   php tests/ai_actions_test.php
 *
 * No AI is called: the tools are run directly. Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/ai_tools.php';
require_once dirname(__DIR__) . '/lib/ai_actions.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
$made = [];
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Assistant actions']);
    $u = $ins('users', ['name' => 'George Test', 'email' => 'g' . $org . '@act.test', 'password_hash' => 'x']);
    $other = $ins('users', ['name' => 'Maria Other', 'email' => 'm' . $org . '@act.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $other, 'role' => 'member']);
    $me = 'g' . $org . '@inq.test';
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-act' . $org, 'email_address' => $me, 'sync_status' => 'connected', 'connected_by_user_id' => $u]);
    $mbOther = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-act2' . $org, 'email_address' => 'm' . $org . '@inq.test', 'sync_status' => 'connected', 'connected_by_user_id' => $other]);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'IN-' . $mb, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $grp = $ins('case_groups', ['organization_id' => $org, 'name' => 'SupportIQ', 'color' => '#1f7a4d']);
    $n = 0;
    $email = static function (int $box, string $from, string $subject, string $text, int $daysAgo) use ($ins, $inbox, $me, &$n): int {
        $n++;
        $t = $ins('email_threads', ['mailbox_id' => $box, 'normalized_subject' => $subject]);
        $at = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - 3600);
        $id = $ins('email_messages', ['mailbox_id' => $box, 'email_thread_id' => $t, 'mail_folder_id' => $inbox, 'provider_message_id' => 'AP' . $n . '-' . $box, 'subject' => $subject,
            'from_email' => $from, 'from_name' => 'Customer', 'to_json' => json_encode([['email' => $me]]), 'cc_json' => '[]', 'body_text' => $text, 'body_fetch_state' => 'fetched',
            'direction' => 'incoming', 'received_at' => $at, 'sent_at' => $at]);
        $ins('message_participants', ['email_message_id' => $id, 'role' => 'to', 'email' => $me]);
        $ins('message_participants', ['email_message_id' => $id, 'role' => 'from', 'email' => $from]);
        return $id;
    };
    $battery = $email($mb, 'buyer@navindus.test', 'Μπαταρία LIBS', 'Could you send us a price for the battery pack?', 2);
    $order = $email($mb, 'buyer@navindus.test', 'Order 4500020310', 'Please confirm the delivery date.', 3);
    $foreign = $email($mbOther, 'x@y.test', 'Maria private', 'Not for George.', 1);
    $ctx = ['org' => $org, 'user' => $u, 'seen' => [], 'files' => [], 'actions' => [], 'open' => [], 'downloads' => []];

    echo "\n=== opening ===\n";
    $r = ecw_ai_tool_run($ctx, 'open_email', ['ref' => 'M' . $battery, 'where' => 'window']);
    check(!empty($r['opened']) && ($ctx['open'][0]['where'] ?? '') === 'window' && $ctx['open'][0]['message_id'] === $battery, 'an email opens where it was asked (a window)');
    $r = ecw_ai_tool_run($ctx, 'open_email', ['ref' => 'M' . $foreign]);
    putenv('ECW_PRIVATE_MAILBOXES=1');
    $r2 = ecw_ai_tool_run($ctx, 'open_email', ['ref' => 'M' . $foreign]);
    putenv('ECW_PRIVATE_MAILBOXES=0');
    check(isset($r2['error']), 'an email of a colleague\'s private mailbox is never opened');

    echo "\n=== proposals: nothing happens before Confirm ===\n";
    $before = (int)$pdo->query("SELECT COUNT(*) FROM ecw_cases WHERE organization_id = {$org}")->fetchColumn();
    $r = ecw_ai_tool_run($ctx, 'create_case', ['name' => 'Battery for NAVINDUS', 'emails' => ['M' . $battery], 'group' => 'supportiq', 'responsible' => 'Maria', 'due' => gmdate('Y-m-d', time() + 5 * 86400), 'note' => 'Asked for a price']);
    $prop = end($ctx['actions']);
    check(!empty($r['proposed']) && str_contains($prop['summary'], 'Battery for NAVINDUS') && str_contains($prop['summary'], 'SupportIQ') && str_contains($prop['summary'], 'Maria Other'),
        'create_case is a proposal that says exactly what will happen', $prop['summary'] ?? '');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_cases WHERE organization_id = {$org}")->fetchColumn() === $before, 'nothing is created by the proposal itself');
    $bad = ecw_ai_tool_run($ctx, 'create_case', ['name' => 'X', 'responsible' => 'Nobody Such']);
    check(isset($bad['error']), 'an unknown colleague makes it ask instead of guessing');

    echo "\n=== confirming ===\n";
    $res = ecw_ai_action_run($org, $u, ['type' => 'create_case', 'params' => $prop['params']]);
    $case = $pdo->query("SELECT * FROM ecw_cases WHERE organization_id = {$org} ORDER BY id DESC LIMIT 1")->fetch();
    check($res['done'] && $case['name'] === 'Battery for NAVINDUS' && (int)$case['group_id'] === $grp && (int)$case['owner_user_id'] === $other && $case['due_at'] !== null, 'Confirm creates the case with its group, Responsible and due date', json_encode($res));
    $threadOf = (int)$pdo->query("SELECT email_thread_id FROM ecw_email_messages WHERE id = {$battery}")->fetchColumn();
    check((int)$pdo->query("SELECT case_id FROM ecw_case_threads WHERE email_thread_id = {$threadOf}")->fetchColumn() === (int)$case['id'], 'with its conversation');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_notes WHERE case_id = " . (int)$case['id'])->fetchColumn() === 1, 'and its note');

    ecw_ai_tool_run($ctx, 'add_to_case', ['case' => $case['case_number'], 'emails' => ['M' . $order]]);
    $res = ecw_ai_action_run($org, $u, ['type' => 'add_to_case', 'params' => end($ctx['actions'])['params']]);
    check($res['done'] && (int)$pdo->query("SELECT COUNT(*) FROM ecw_case_threads WHERE case_id = " . (int)$case['id'])->fetchColumn() === 2, 'add_to_case adds the conversation');

    ecw_ai_tool_run($ctx, 'update_case', ['case' => $case['case_number'], 'status' => 'waiting', 'next_step' => 'Send the offer']);
    $res = ecw_ai_action_run($org, $u, ['type' => 'update_case', 'params' => end($ctx['actions'])['params']]);
    $now = $pdo->query("SELECT status, next_action FROM ecw_cases WHERE id = " . (int)$case['id'])->fetch();
    check($res['done'] && $now['status'] === 'waiting' && $now['next_action'] === 'Send the offer', 'update_case changes status and next step', json_encode($now));

    ecw_ai_tool_run($ctx, 'remind_me', ['when' => gmdate('Y-m-d\TH:i', time() + 2 * 86400), 'email' => 'M' . $order, 'text' => 'Chase the date']);
    $res = ecw_ai_action_run($org, $u, ['type' => 'remind_me', 'params' => end($ctx['actions'])['params']]);
    check($res['done'], 'remind_me sets a reminder');

    $r = ecw_ai_tool_run($ctx, 'reply', ['ref' => 'M' . $battery, 'text' => 'Καλημέρα σας, η τιμή είναι 290 €.']);
    check(!empty($r['proposed']) && str_contains(end($ctx['actions'])['summary'], 'Nothing is sent'), 'a reply opens in the composer, never sent by the assistant');

    $theirCase = ecw_case_create($org, 'Members only', $other, 'open');
    $pdo->exec("UPDATE ecw_cases SET visibility = 'members' WHERE id = {$theirCase}");
    try { ecw_ai_action_run($org, $u, ['type' => 'update_case', 'params' => ['case_id' => $theirCase, 'status' => 'closed']]); $closed = true; } catch (InvalidArgumentException $e) { $closed = false; }
    check(!$closed || ecw_case_visible($org, $u, $theirCase), 'a case the person may not see is never changed, whatever the card says');

    echo "\n=== left unanswered ===\n";
    $r = ecw_ai_tool_run($ctx, 'list_unanswered', ['days' => 1]);
    $ids = array_map(static fn($e) => (int)substr((string)$e['ref'], 1), $r['unanswered'] ?? []);
    check(in_array($battery, $ids, true) && in_array($order, $ids, true), 'emails sent to the person that ask something and got no answer', json_encode($ids));

    echo "\n=== spreadsheets ===\n";
    $r = ecw_ai_tool_run($ctx, 'make_spreadsheet', ['title' => 'Open cases', 'source' => 'cases', 'status' => 'any', 'format' => 'xlsx']);
    $dl = end($ctx['downloads']);
    preg_match('/token=([a-f0-9]{32})/', (string)$dl['url'], $tm);
    $file = ecw_ai_download($org, $u, $tm[1] ?? '');
    $made[] = $file['path'] ?? '';
    $zip = new ZipArchive();
    $okZip = $file && $zip->open($file['path']) === true;
    $sheet = $okZip ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
    check(!empty($r['made']) && $r['rows'] >= 1 && $okZip && str_contains($sheet, 'Battery for NAVINDUS') && str_contains($sheet, '<autoFilter'), 'an Excel file of the cases, with a filter row', json_encode($r));
    check(ecw_ai_download($org, $other, $tm[1] ?? '') === null, 'only the person who asked can download it');
    $r = ecw_ai_tool_run($ctx, 'make_spreadsheet', ['title' => 'Totals', 'source' => 'rows', 'format' => 'csv', 'headers' => ['Month', 'Total €'], 'rows' => [['2026-09', 1200.5], ['2026-10', 290]]]);
    $dl = end($ctx['downloads']);
    preg_match('/token=([a-f0-9]{32})/', (string)$dl['url'], $tm);
    $csv = ecw_ai_download($org, $u, $tm[1] ?? '');
    $made[] = $csv['path'] ?? '';
    $body = $csv ? (string)file_get_contents($csv['path']) : '';
    check(str_starts_with($body, "\xEF\xBB\xBF") && str_contains($body, '2026-09,1200.5'), 'a CSV of a table it put together, Greek-safe for Excel');
    $r = ecw_ai_tool_run($ctx, 'make_spreadsheet', ['title' => 'Emails', 'source' => 'emails', 'filters' => ['from' => 'navindus']]);
    check(($r['rows'] ?? 0) === 2, 'an Excel file of emails by the usual filters', json_encode($r));
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($made as $f) if ($f) { @unlink($f); @unlink(preg_replace('/\.(xlsx|csv)$/', '.json', $f)); }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
