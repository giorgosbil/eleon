<?php declare(strict_types=1);
/**
 * One account at a time (lib/account.php), mail history in two steps
 * (lib/sync/messages.php) and the attachment cache (lib/storage.php).
 *
 *   php tests/account_scope_test.php
 *
 * Two accounts of one person: every page shows the account chosen at the top
 * only. Microsoft is scripted with FakeGraph. Runs in a transaction that is
 * rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/account.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/contacts.php';
require_once dirname(__DIR__) . '/lib/reports.php';
require_once dirname(__DIR__) . '/lib/templates.php';
require_once dirname(__DIR__) . '/lib/rules.php';
require_once dirname(__DIR__) . '/lib/signature.php';
require_once dirname(__DIR__) . '/lib/unread.php';
require_once dirname(__DIR__) . '/lib/calendar.php';
require_once dirname(__DIR__) . '/lib/teams.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/attachment_text.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/activity.php';
require_once dirname(__DIR__) . '/bin/sync_jobs.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

ecw_set_graph_config_override(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://example.test/cb']);
$pdo = ecw_db();
$pdo->beginTransaction();
$privateBefore = getenv('ECW_PRIVATE_MAILBOXES');
$files = [];
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Two companies']);
    $u = $ins('users', ['name' => 'George', 'email' => 'george' . $org . '@acct.test', 'password_hash' => 'x']);
    $u2 = $ins('users', ['name' => 'Colleague', 'email' => 'col' . $org . '@acct.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u2, 'role' => 'member']);
    $box = static fn(string $addr, int $owner) => $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-' . $addr . $org,
        'email_address' => $addr . $org . '@acct.test', 'display_name' => strtoupper($addr), 'sync_status' => 'connected', 'connected_by_user_id' => $owner,
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $A = $box('zetisis', $u);
    $B = $box('inquality', $u);
    $C = $box('colleague', $u2);
    $inbox = static fn(int $mb) => $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'IN-' . $mb, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $inA = $inbox($A); $inB = $inbox($B);

    echo "\n=== choosing the account ===\n";
    putenv('ECW_PRIVATE_MAILBOXES=1');
    ecw_account_reset_cache();
    $choices = array_column(ecw_account_choices($org, $u), 'id');
    check($choices === [$A, $B], 'a person can choose between their own accounts (not a colleague\'s private mailbox)', json_encode($choices));
    check(ecw_active_account_id($org, $u) === $A, 'without a choice, the first own account is chosen');
    check(!ecw_account_switch($org, $u, $C), 'switching to a mailbox the person may not see is refused');
    check(ecw_account_switch($org, $u, $B) && ecw_active_account_id($org, $u) === $B, 'switching works');
    ecw_account_reset_cache();
    check(ecw_active_account_id($org, $u) === $B, 'the choice is remembered (kept with the person, not the browser)');
    $pdo->prepare('UPDATE ecw_mailboxes SET is_shared = 1 WHERE id = ?')->execute([$C]);
    ecw_account_reset_cache();
    check(in_array($C, array_column(ecw_account_choices($org, $u), 'id'), true), 'a shared mailbox (info@) is one of the accounts to choose');
    $pdo->prepare('UPDATE ecw_mailboxes SET is_shared = 0 WHERE id = ?')->execute([$C]);
    ecw_account_reset_cache();

    echo "\n=== mail of both accounts ===\n";
    $mail = static function (string $id, string $from, bool $read = false, ?string $when = null) {
        return FakeGraph::message(['id' => $id, 'conversationId' => 'conv-' . $id, 'isRead' => $read, 'subject' => 'About ' . $id, 'body' => ['contentType' => 'html', 'content' => '<p>Text of ' . $id . '</p>'],
            'from' => ['emailAddress' => ['address' => $from, 'name' => ucfirst(strtok($from, '@'))]],
            'sender' => ['emailAddress' => ['address' => $from, 'name' => ucfirst(strtok($from, '@'))]],
            'receivedDateTime' => $when ?? gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'sentDateTime' => $when ?? gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]);
    };
    $g = new FakeGraph();
    $g->on('GET', '#/mailFolders/IN-' . $A . '/messages\?#', ['body' => ['value' => [$mail('A1', 'anna@acme.test'), $mail('A2', 'anna@acme.test'), $mail('A3', 'bob@acme.test', true)]]]);
    $g->on('GET', '#/mailFolders/IN-' . $B . '/messages\?#', ['body' => ['value' => [$mail('B1', 'nick@beta.test')]]]);
    $g->install();
    ecw_sync_history($A, $inA, 1);
    ecw_sync_history($B, $inB, 1);
    $thread = static fn(string $pid) => (int)$pdo->query("SELECT email_thread_id FROM ecw_email_messages WHERE provider_message_id = " . $pdo->quote($pid) . " AND mailbox_id IN ({$A},{$B})")->fetchColumn();
    check($thread('A1') > 0 && $thread('B1') > 0, 'both accounts have mail');

    echo "\n=== cases ===\n";
    $cA = ecw_case_create($org, 'Pump for Acme', $u, 'open', $A);
    $cB = ecw_case_create($org, 'Audit for Beta', $u, 'open', $B);
    $cX = ecw_case_create($org, 'Opened by an automation', null);
    ecw_case_link_thread($cX, $thread('B1'));
    check(ecw_case_account($cX) === $B, 'a case without an account takes the account of its first conversation');
    $cBoth = ecw_case_create($org, 'Both companies', $u, 'open', $B);
    ecw_case_link_thread($cBoth, $thread('A1'));
    $cNone = ecw_case_create($org, 'From before accounts', $u);
    $pdo->exec("UPDATE ecw_cases SET mailbox_id = NULL WHERE id = {$cNone}");
    $list = static fn(int $acct) => array_map('intval', array_column(ecw_case_list($org, ['user_id' => $u, 'account_id' => $acct, 'status' => 'all'])['cases'], 'id'));
    $inA = $list($A); $inB = $list($B);
    check(in_array($cA, $inA, true) && !in_array($cB, $inA, true) && !in_array($cX, $inA, true), 'account A lists its cases, not account B\'s', json_encode($inA));
    check(in_array($cB, $inB, true) && in_array($cX, $inB, true) && !in_array($cA, $inB, true), 'account B lists its cases, not account A\'s', json_encode($inB));
    check(in_array($cBoth, $inA, true) && in_array($cBoth, $inB, true), 'a case with a conversation in both accounts shows in both');
    check(in_array($cNone, $inA, true) && in_array($cNone, $inB, true), 'a case of no account shows in every account');
    $countsA = ecw_case_view_counts($org, $u, $A);
    check($countsA['all'] === count($inA), 'the quick-view counts count the account\'s cases only', json_encode($countsA));
    // A colleague with a mailbox of their own still sees the company's cases opened in other people's mailboxes.
    $cC = ecw_case_create($org, 'Opened by the colleague', $u2, 'open', $C);
    ecw_account_reset_cache();
    $col = array_map('intval', array_column(ecw_case_list($org, ['user_id' => $u2, 'account_id' => $C, 'status' => 'all'])['cases'], 'id'));
    check(in_array($cA, $col, true) && in_array($cB, $col, true) && in_array($cC, $col, true), 'a colleague\'s own account lists the cases opened in someone else\'s mailbox too', json_encode($col));
    check(in_array($cC, $list($A), true) && in_array($cC, $list($B), true), 'and a case the colleague opened shows in each of my accounts');
    check(!in_array($cB, $list($A), true), 'my own two accounts still stay apart');
    $pdo->exec("UPDATE ecw_cases SET deleted_at = UTC_TIMESTAMP() WHERE id = {$cC}");
    // The same email reached the colleague too: their mailbox has its own copy of the conversation.
    $inC = $inbox($C);
    $g->on('GET', '#/mailFolders/IN-' . $C . '/messages\?#', ['body' => ['value' => [['internetMessageId' => '<A1@example.com>'] + $mail('CA1', 'anna@acme.test')]]]);
    ecw_sync_history($C, $inC, 1);
    $tC = (int)$pdo->query("SELECT email_thread_id FROM ecw_email_messages WHERE mailbox_id = {$C} AND provider_message_id = 'CA1'")->fetchColumn();
    check($tC > 0 && (int)(ecw_case_for_copy($tC)['id'] ?? 0) === $cBoth, 'the colleague\'s copy is known to be in a case already (through mine)');
    $numBoth = (string)$pdo->query("SELECT case_number FROM ecw_cases WHERE id = {$cBoth}")->fetchColumn();
    require_once dirname(__DIR__) . '/lib/automation.php';
    check(ecw_automation_link_auto($org, $u2, $tC, 'Re: ' . $numBoth, '') === null && ecw_case_for_thread($tC) === null,
        'so naming the case does not link that copy too: the case shows the conversation once');

    $gone = ecw_case_create($org, 'Made by mistake', $u, 'open', $B);
    $pdo->prepare('DELETE FROM ecw_case_threads WHERE email_thread_id = ?')->execute([$thread('B1')]);
    ecw_case_link_thread($gone, $thread('B1'));
    $pdo->exec("UPDATE ecw_cases SET deleted_at = UTC_TIMESTAMP() WHERE id = {$gone}");
    ecw_case_link_thread($cB, $thread('B1'));
    check((int)$pdo->query("SELECT case_id FROM ecw_case_threads WHERE email_thread_id = " . $thread('B1'))->fetchColumn() === $cB,
        'a conversation in a deleted case can be put into another case (it leaves the deleted one)');
    $held = ecw_case_create($org, 'Held and deleted', $u, 'open', $B);
    $pdo->prepare('DELETE FROM ecw_case_threads WHERE email_thread_id = ?')->execute([$thread('B1')]);
    ecw_case_link_thread($held, $thread('B1'));
    $pdo->exec("UPDATE ecw_cases SET deleted_at = UTC_TIMESTAMP(), legal_hold_at = UTC_TIMESTAMP() WHERE id = {$held}");
    try { ecw_case_link_thread($cB, $thread('B1')); $moved = true; } catch (RuntimeException $e) { $moved = false; }
    check(!$moved, 'not from a deleted case on legal hold');
    $pdo->prepare('DELETE FROM ecw_case_threads WHERE email_thread_id = ?')->execute([$thread('B1')]);
    ecw_case_link_thread($cX, $thread('B1'));

    echo "\n=== contacts ===\n";
    ecw_account_switch($org, $u, $A);
    $people = array_column(ecw_people_list($org, $u), 'email');
    check(in_array('anna@acme.test', $people, true) && !in_array('nick@beta.test', $people, true), 'contacts of account A only', json_encode($people));
    ecw_account_switch($org, $u, $B);
    $people = array_column(ecw_people_list($org, $u), 'email');
    check(in_array('nick@beta.test', $people, true) && !in_array('anna@acme.test', $people, true), 'contacts of account B only', json_encode($people));

    echo "\n=== reports ===\n";
    ecw_account_switch($org, $u, $A);
    $repA = ecw_reports($org, $u, ['days' => 30])['status'];
    ecw_account_switch($org, $u, $B);
    $repB = ecw_reports($org, $u, ['days' => 30])['status'];
    check(array_sum($repA) === count($inA) && array_sum($repB) === count($inB), 'reports count the chosen account\'s cases', json_encode([$repA, $repB]));

    echo "\n=== personal templates and colour rules ===\n";
    ecw_account_switch($org, $u, $A);
    ecw_template_save($org, $u, true, ['name' => 'Acme reply', 'body_html' => '<p>Dear Acme</p>']);
    ecw_template_save($org, $u, true, ['name' => 'Team hello', 'body_html' => '<p>Hello</p>', 'shared' => 1]);
    $tplA = array_column(ecw_templates_list($org, $u), 'name');
    ecw_account_switch($org, $u, $B);
    $tplB = array_column(ecw_templates_list($org, $u), 'name');
    check(in_array('Acme reply', $tplA, true) && !in_array('Acme reply', $tplB, true), 'my own template belongs to the account it was made in', json_encode([$tplA, $tplB]));
    check(in_array('Team hello', $tplB, true), 'a shared (team) template shows in every account');
    ecw_rule_save($org, $u, true, ['kind' => 'color', 'name' => 'Beta blue', 'conditions' => ['match' => 'all', 'items' => [['field' => 'from', 'op' => 'domain', 'value' => 'beta.test']]], 'actions' => ['color' => '#2563eb']]);
    $rulesB = array_column(ecw_rules_list($org, 'color', $u), 'name');
    ecw_account_switch($org, $u, $A);
    $rulesA = array_column(ecw_rules_list($org, 'color', $u), 'name');
    check($rulesB === ['Beta blue'] && $rulesA === [], 'colour rules are per account', json_encode([$rulesA, $rulesB]));

    echo "\n=== signatures: one default per account ===\n";
    $set = ecw_signature_profiles_save($u, ['name' => 'Zetisis', 'html' => '<p>George · Zetisis</p>', 'is_default' => 1], $A);
    $zet = $set['saved_id'];
    $set = ecw_signature_profiles_save($u, ['name' => 'InQuality', 'html' => '<p>George · InQuality</p>', 'is_default' => 1], $B);
    $inq = $set['saved_id'];
    check(ecw_signature_profiles($u, $A)['default_id'] === $zet && ecw_signature_profiles($u, $B)['default_id'] === $inq, 'each account keeps its own default signature');
    check(str_contains(ecw_signature_html_for($u, $A), 'Zetisis') && str_contains(ecw_signature_html_for($u, $B), 'InQuality'), 'a new email gets the signature of the account it is written from');
    check(count(ecw_signature_profiles($u, $A)['profiles']) === 2, 'both signatures can still be chosen while writing');

    echo "\n=== badges ===\n";
    ecw_account_switch($org, $u, $A);
    $b = ecw_unread_badges($org, $u);
    check($b['mail'] === 2 && (array)$b['accounts'] === [$A => 2, $B => 1], 'Mail counts the chosen account\'s unread, the picker gets the others\'', json_encode($b));

    echo "\n=== AI suggestions ===\n";
    require_once dirname(__DIR__) . '/lib/agents.php';
    $ins('ai_suggestions', ['organization_id' => $org, 'user_id' => $u, 'agent' => 'triage', 'kind' => 'triage', 'thread_id' => $thread('A2'), 'title' => 'For Acme', 'state' => 'open']);
    $ins('ai_suggestions', ['organization_id' => $org, 'user_id' => $u, 'agent' => 'triage', 'kind' => 'triage', 'thread_id' => $thread('B1'), 'title' => 'For Beta', 'state' => 'open']);
    $titles = array_column(ecw_suggestions_for($org, $u), 'title');
    check(in_array('For Acme', $titles, true) && !in_array('For Beta', $titles, true), 'Suggestions are the chosen account\'s only', json_encode($titles));

    echo "\n=== calendar, Teams ===\n";
    ecw_account_switch($org, $u, $B);
    check((int)(ecw_calendar_mailbox($org, $u)['id'] ?? 0) === $B, 'the calendar is the chosen account\'s Outlook calendar');
    check((int)(ecw_teams_mailbox($u)['id'] ?? 0) === $B, 'Teams is the chosen account\'s Teams');

    echo "\n=== history: the last two years first, older emails after ===\n";
    $H = $box('history', $u);
    $hIn = $ins('mail_folders', ['mailbox_id' => $H, 'provider_folder_id' => 'H-IN-' . $H, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $hSent = $ins('mail_folders', ['mailbox_id' => $H, 'provider_folder_id' => 'H-SENT-' . $H, 'name' => 'Sent Items', 'folder_type' => 'sent', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $day = static fn(int $daysAgo) => gmdate('Y-m-d\TH:i:s\Z', time() - $daysAgo * 86400);
    $g = new FakeGraph();
    $g->on('GET', '#/mailFolders/H-IN-' . $H . '/messages\?#', ['body' => ['value' => [$mail('H1', 'a@old.test', true, $day(5)), $mail('H2', 'a@old.test', true, $day(800))],
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/older-inbox-page-2']]);
    $g->on('GET', '#/older-inbox-page-2#', ['body' => ['value' => [$mail('H3', 'a@old.test', true, $day(2000))]]]);
    $g->on('GET', '#/mailFolders/H-SENT-' . $H . '/messages\?#', ['body' => ['value' => [$mail('H4', 'me@old.test', true, $day(3))]]]);
    $g->install();
    $r = ecw_run_sync_job('backfill', $H, $hIn);
    check(!empty($r['paused']) && empty($r['complete']), 'the Inbox walk stops once it is two years back', json_encode($r));
    check(ecw_history_phase($H) === 'recent' && ecw_mailbox_sync_activity($H)['importing'], 'Sent has not got its two years yet: still importing, the spinner turns');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_sync_queue WHERE mailbox_id = {$H} AND mail_folder_id = {$hIn} AND job_type = 'backfill' AND status = 'queued'")->fetchColumn() === 0,
        'the Inbox waits (no older emails queued yet)');
    $r = ecw_run_sync_job('backfill', $H, $hSent);
    check(!empty($r['complete']) && ($r['older_queued'] ?? 0) === 1, 'when the last folder has its two years, the older emails of the others are queued', json_encode($r));
    $act = ecw_mailbox_sync_activity($H);
    check(ecw_history_phase($H) === 'older' && !$act['busy'] && !$act['importing'] && $act['older_importing'] && $act['history_done'],
        'the last two years are complete: nothing turns, older emails come in the background', json_encode($act));
    $pdo->exec("UPDATE ecw_sync_queue SET status = 'done' WHERE mailbox_id = {$H} AND job_type = 'backfill'");
    $r = ecw_run_sync_job('backfill', $H, $hIn);
    check(!empty($r['complete']) && ecw_history_phase($H) === 'done', 'the Inbox continues where it stopped and completes', json_encode($r));
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_email_messages WHERE mailbox_id = {$H}")->fetchColumn() === 4, 'every email of every year is there');
    check($g->callsMatching('#/mailFolders/H-IN-' . $H . '/messages\?#') !== [] && count($g->callsMatching('#/mailFolders/H-IN-' . $H . '/messages\?#')) === 1, 'the older emails continue from the saved place, not from the start');

    echo "\n=== unsent drafts are never emails ===\n";
    $del = $ins('mail_folders', ['mailbox_id' => $A, 'provider_folder_id' => 'DEL-' . $A, 'name' => 'Deleted Items', 'folder_type' => 'deleted', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $g = new FakeGraph();
    $g->on('GET', '#/mailFolders/DEL-' . $A . '/messages\?#', ['body' => ['value' => [['isDraft' => true] + $mail('DR1', 'me@acct.test')]]]);
    $g->install();
    ecw_sync_history($A, $del, 1);
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_email_messages WHERE mailbox_id = {$A} AND provider_message_id = 'DR1'")->fetchColumn() === 0,
        'a deleted draft in Deleted Items is not stored (it looked like a sent reply in its conversation)');
    require_once dirname(__DIR__) . '/lib/compose.php';
    $aiDraft = ecw_draft_save($org, $A, $u, ['mode' => 'new', 'subject' => 'Prepared by the AI', 'body_html' => '<p>x</p>', 'to' => [], 'keep_local' => true]);
    $mine = ecw_draft_save($org, $A, $u, ['mode' => 'new', 'subject' => 'Written by me', 'body_html' => '<p>x</p>', 'to' => []]);
    $push = static fn(int $id) => (int)$pdo->query("SELECT provider_push_pending FROM ecw_drafts WHERE id = {$id}")->fetchColumn();
    check($push($aiDraft) === 0 && $push($mine) === 1, 'a draft the AI prepared stays in the app (not copied to Outlook Drafts); one a person writes is copied');
    ecw_draft_save($org, $A, $u, ['mode' => 'new', 'subject' => 'Prepared by the AI, then changed', 'body_html' => '<p>y</p>', 'to' => []], $aiDraft);
    check($push($aiDraft) === 1, 'once a person changes it, it is copied to Outlook like any draft');

    echo "\n=== attachment files: read, keep the text, not the file ===\n";
    $msgA1 = (int)$pdo->query("SELECT id FROM ecw_email_messages WHERE mailbox_id = {$A} AND provider_message_id = 'A1'")->fetchColumn();
    $g = new FakeGraph();
    $g->on('GET', '#/messages/A1/attachments/ATT-1/\$value#', ['body' => ['raw' => 'invoice text']]);
    $g->install();
    $attId = $ins('email_attachments', ['email_message_id' => $msgA1, 'provider_attachment_id' => 'ATT-1', 'filename' => 'invoice.txt', 'mime_type' => 'text/plain', 'size_bytes' => 12, 'is_inline' => 0]);
    $row = ['id' => $attId, 'mailbox_id' => $A, 'provider_message_id' => 'A1', 'provider_attachment_id' => 'ATT-1', 'local_path' => null];
    check(ecw_attachment_bytes($row, false) === 'invoice text' && $pdo->query("SELECT local_path FROM ecw_email_attachments WHERE id = {$attId}")->fetchColumn() === null,
        'reading a file for search does not keep it on the disk');
    $mk = static function (string $name, int $daysOld, int $inline) use ($ins, $msgA1, &$files): int {
        $f = tempnam(sys_get_temp_dir(), 'ecw-cache-'); file_put_contents($f, str_repeat('x', 100)); touch($f, time() - $daysOld * 86400, time() - $daysOld * 86400);
        $files[] = $f;
        return $ins('email_attachments', ['email_message_id' => $msgA1, 'provider_attachment_id' => 'P-' . $name, 'filename' => $name, 'mime_type' => 'application/pdf', 'size_bytes' => 100,
            'is_inline' => $inline, 'local_path' => $f, 'provider_download_state' => 'downloaded']);
    };
    $old = $mk('old.pdf', 10, 0); $fresh = $mk('fresh.pdf', 1, 0); $pic = $mk('logo.png', 10, 1);
    $pruned = ecw_attachment_cache_prune(7, 5000);
    $path = static fn(int $id) => $pdo->query("SELECT local_path FROM ecw_email_attachments WHERE id = {$id}")->fetchColumn();
    check($path($old) === null && !is_file($files[0]), 'a file nobody opened for a week leaves the disk (it stays in Outlook)', json_encode($pruned));
    check($path($fresh) !== null && is_file($files[1]), 'a file opened recently stays');
    check($path($pic) !== null && is_file($files[2]), 'pictures inside emails stay (every email shows them)');
    $held = $mk('held.pdf', 30, 0);
    $hold = ecw_case_create($org, 'Dispute', $u, 'open', $A);
    $pdo->exec("UPDATE ecw_cases SET legal_hold_at = UTC_TIMESTAMP() WHERE id = {$hold}");
    $pdo->prepare('DELETE FROM ecw_case_threads WHERE email_thread_id = ?')->execute([$thread('A1')]);
    ecw_case_link_thread($hold, $thread('A1'));
    ecw_attachment_cache_prune(7, 5000);
    check($path($held) !== null && is_file($files[3]), 'files of a case on legal hold stay');

    echo "\n=== System health shows the space used ===\n";
    $sz = ecw_storage_sizes();
    check($sz['db_bytes'] > 0 && $sz['emails'] > 0 && isset($sz['per_mailbox'][0]['email']), 'database size, emails per account and attachment files are measured');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($files as $f) @unlink($f);
    putenv($privateBefore === false ? 'ECW_PRIVATE_MAILBOXES' : 'ECW_PRIVATE_MAILBOXES=' . $privateBefore);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
