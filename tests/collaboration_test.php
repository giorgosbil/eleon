<?php declare(strict_types=1);
/**
 * Collaboration features: notes + mentions, notifications, follow-ups, snooze,
 * automation (case number, routing, auto-reopen), colour rules, templates,
 * presence, case suggestions, reports, webhooks.
 *
 *   php tests/collaboration_test.php
 *
 * Runs inside one transaction that is always rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/notes.php';
require_once dirname(__DIR__) . '/lib/followups.php';
require_once dirname(__DIR__) . '/lib/automation.php';
require_once dirname(__DIR__) . '/lib/rules.php';
require_once dirname(__DIR__) . '/lib/templates.php';
require_once dirname(__DIR__) . '/lib/presence.php';
require_once dirname(__DIR__) . '/lib/reports.php';
require_once dirname(__DIR__) . '/lib/webhooks.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/mail.php';

$pass = 0; $fail = 0;
function group(string $t): void { echo "\n=== {$t} ===\n"; }
function check(bool $c, string $label): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$label}\n"; } else { $fail++; echo "  FAIL  {$label}\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = function (string $t, array $row) use ($pdo): int {
        $c = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'Collab test']);
    $u = [];
    foreach (['ann', 'bob', 'cat'] as $n) {
        $u[$n] = $ins('users', ['name' => ucfirst($n) . ' Test', 'email' => $n . uniqid() . '@acme.test', 'password_hash' => 'x']);
        $ins('organization_users', ['organization_id' => $org, 'user_id' => $u[$n], 'role' => 'user']);
    }
    $mb = $ins('mailboxes', ['organization_id' => $org, 'connected_by_user_id' => $u['ann'], 'provider' => 'microsoft',
        'email_address' => 'ann.box' . uniqid() . '@acme.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'snooze_in_mailbox' => 0]);   // no real mailbox behind this test: snooze stays in the app
    $mbEmail = $pdo->query('SELECT email_address FROM ecw_mailboxes WHERE id = ' . $mb)->fetchColumn();

    $now = gmdate('Y-m-d H:i:s');
    $ingest = function (string $subject, string $from, array $to, string $when, ?string $conv = null) use ($mb): array {
        return ecw_ingest_message($mb, null, 'inbox', [
            'provider_message_id' => 'p' . bin2hex(random_bytes(6)), 'internet_message_id' => '<' . uniqid() . '@x>',
            'provider_conversation_id' => $conv ?? 'c' . uniqid(), 'subject' => $subject,
            'from_email' => $from, 'from_name' => 'Customer', 'to' => $to, 'cc' => [], 'bcc' => [],
            'received_at' => $when, 'sent_at' => $when, 'is_read' => false, 'has_attachments' => false, 'references' => [],
        ]);
    };

    group('notes and mentions');
    $case = ecw_case_create($org, 'Pump failure', $u['ann']);
    ecw_case_sharing_save($org, $case, $u['ann'], ['visibility' => 'members', 'member_ids' => []]);
    check(!ecw_case_visible($org, $u['bob'], $case), 'bob cannot see the members-only case yet');
    $r = ecw_case_note_add($org, $case, $u['ann'], "Please check this @[Bob Test](user:{$u['bob']}) <script>x</script>");
    check($r['mentioned'] === [$u['bob']] && $r['added_to_case'] === [$u['bob']], 'mention is detected and adds bob to the case');
    check(ecw_case_visible($org, $u['bob'], $case), 'bob can now see the case');
    check(ecw_notifications_unread($u['bob']) === 1, 'bob has one notification');
    $notes = ecw_case_notes($case, $u['bob']);
    check(str_contains($notes[0]['html'], '<span class="mention" data-user="' . $u['bob'] . '">@Bob Test</span>')
        && !str_contains($notes[0]['html'], '<script>'), 'note renders the mention and escapes HTML');
    check(!ecw_case_note_delete($case, $notes[0]['id'], $u['bob'], false), 'bob cannot delete ann\'s note');
    check(ecw_case_note_delete($case, $notes[0]['id'], $u['ann'], false), 'ann deletes her note');
    ecw_notifications_mark_read($u['bob']);
    check(ecw_notifications_unread($u['bob']) === 0, 'mark all read');

    group('follow-ups');
    try { ecw_followup_add($org, $u['ann'], $case, null, gmdate('c', time() - 3600), null, false); check(false, 'past time rejected'); }
    catch (InvalidArgumentException $e) { check(true, 'past time rejected'); }
    $f = ecw_followup_add($org, $u['ann'], $case, null, gmdate('c', time() + 120), 'Call back', false);
    $pdo->prepare('UPDATE ecw_followups SET due_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 5), $f]);
    $before = ecw_notifications_unread($u['ann']);
    check(ecw_followups_run_due() >= 1 && ecw_notifications_unread($u['ann']) === $before + 1, 'due reminder notifies its owner');
    check(ecw_followups_run_due() === 0 || ecw_notifications_unread($u['ann']) === $before + 1, 'a reminder fires only once');

    group('automation: case number, reopen, if-no-reply');
    $caseNo = $pdo->query('SELECT case_number FROM ecw_cases WHERE id = ' . $case)->fetchColumn();
    $pdo->prepare("UPDATE ecw_cases SET status = 'waiting', handler_user_id = ? WHERE id = ?")->execute([$u['bob'], $case]);
    $nf = ecw_followup_add($org, $u['ann'], $case, null, gmdate('c', time() + 86400), 'Chase', true);
    $res = $ingest('Re: [' . $caseNo . '] pump', 'customer@client.test', [['email' => $mbEmail, 'name' => 'Ann']], $now);
    $linked = ecw_case_for_thread($res['thread_id']);
    check($linked !== null && (int)$linked['id'] === $case, 'subject case number links the thread');
    check($pdo->query('SELECT status FROM ecw_cases WHERE id = ' . $case)->fetchColumn() === 'open', 'waiting case reopens on reply');
    check(ecw_notifications_unread($u['bob']) === 1, 'Handled by is notified of the reopen');
    check($pdo->query('SELECT outcome FROM ecw_followups WHERE id = ' . $nf)->fetchColumn() === 'replied', '"if no reply" reminder is settled by the reply');
    $old = $ingest('Re: [' . $caseNo . '] older', 'customer@client.test', [], gmdate('Y-m-d H:i:s', time() - 40 * 86400));
    check(ecw_case_for_thread($old['thread_id']) !== null, 'old mail is still linked by case number');

    group('routing rules');
    $grp = ecw_case_group_save($org, $u['ann'], true, ['name' => 'Support', 'member_ids' => [$u['cat']]]);
    ecw_rule_save($org, $u['ann'], true, ['kind' => 'route', 'name' => 'Client support',
        'conditions' => ['match' => 'all', 'items' => [['field' => 'from', 'op' => 'domain', 'value' => '@client.test']]],
        'actions' => ['type' => 'create_case', 'group_id' => $grp, 'owner_user_id' => $u['cat']]]);
    $routed = $ingest('Broken valve', 'someone@client.test', [], $now);
    $rc = ecw_case_for_thread($routed['thread_id']);
    check($rc !== null && (int)$rc['group_id'] === $grp && (int)$rc['owner_user_id'] === $u['cat'], 'rule opens a case in the group with a Responsible');
    $old2 = $ingest('Ancient', 'someone@client.test', [], gmdate('Y-m-d H:i:s', time() - 40 * 86400));
    check(ecw_case_for_thread($old2['thread_id']) === null, 'imported old mail never creates cases');
    try { ecw_rule_save($org, $u['bob'], false, ['kind' => 'route', 'name' => 'x', 'conditions' => ['items' => [['field' => 'from', 'op' => 'is', 'value' => 'a@b.c']]], 'actions' => ['type' => 'create_case']]); check(false, 'non-admin route rule blocked'); }
    catch (InvalidArgumentException $e) { check(true, 'non-admin route rule blocked'); }

    group('automation rules: conditions, several actions, stop, run log');
    $legacy = array_values(array_filter(ecw_rules_list($org, 'route', null), static fn($r) => $r['name'] === 'Client support'))[0];
    check(count($legacy['actions']['list']) === 1 && $legacy['actions']['stop'] === true, 'an old one-action rule reads as a list that stops');
    check($legacy['runs']['count'] >= 1, 'each time a rule acts it is counted');
    $mk = static fn(array $actions, array $items = [['field' => 'subject', 'op' => 'contains', 'value' => 'zz']]) =>
        ecw_rule_save($org, $u['ann'], true, ['kind' => 'route', 'name' => 'T' . uniqid(), 'conditions' => ['match' => 'all', 'items' => $items], 'actions' => $actions]);
    try { $mk(['list' => [['type' => 'create_case'], ['type' => 'add_to_case', 'case_id' => $case]]]); check(false, 'open a case and add to one: refused'); }
    catch (InvalidArgumentException $e) { check(true, 'open a case and add to one: refused'); }
    try { $mk(['list' => [['type' => 'flag'], ['type' => 'flag']]]); check(false, 'the same action twice: refused'); }
    catch (InvalidArgumentException $e) { check(true, 'the same action twice: refused'); }
    try { $mk(['list' => [['type' => 'move', 'folder_id' => 999999999]]]); check(false, 'a folder of no mailbox here: refused'); }
    catch (InvalidArgumentException $e) { check(true, 'a folder of no mailbox here: refused'); }
    $msgT = ['from' => ['email' => 'a@x.test', 'name' => 'A'], 'to' => [], 'cc' => [], 'subject' => 'Order 55', 'body' => 'please call me', 'has_attachments' => true, 'mailbox_id' => $mb];
    check(ecw_rule_matches(['match' => 'all', 'items' => [['field' => 'body', 'op' => 'contains', 'value' => 'call me'], ['field' => 'attachment', 'op' => 'exists', 'value' => '']]], $msgT, []), 'message text and "has attachments" conditions');
    check(!ecw_rule_matches(['match' => 'all', 'items' => [['field' => 'subject', 'op' => 'not_contains', 'value' => 'order']]], $msgT, []), '"does not contain"');
    check(ecw_rule_matches(['match' => 'all', 'items' => [['field' => 'from', 'op' => 'not_contains', 'value' => 'other.test']]], $msgT, [])
        && ecw_rule_matches(['match' => 'all', 'items' => [['field' => 'mailbox', 'op' => 'is', 'value' => (string)$mb]]], $msgT, []), '"does not contain" on people, and the mailbox condition');

    // Two rules both match "URGENT" from vip@: the first does several things and does not stop.
    $grp2 = ecw_case_group_save($org, $u['ann'], true, ['name' => 'Escalations', 'member_ids' => [$u['bob']]]);
    $r1 = $mk(['list' => [['type' => 'create_case', 'group_id' => $grp2], ['type' => 'tag', 'tag' => 'Urgent!'], ['type' => 'notify', 'user_id' => $u['bob']], ['type' => 'snooze', 'hours' => 3]], 'stop' => false, 'first_only' => true],
        [['field' => 'subject', 'op' => 'contains', 'value' => 'urgent'], ['field' => 'body', 'op' => 'not_contains', 'value' => 'unsubscribe']]);
    $r2 = $mk(['list' => [['type' => 'set_owner', 'user_id' => $u['cat']]]], [['field' => 'from', 'op' => 'contains', 'value' => 'vip@']]);
    $r3 = $mk(['list' => [['type' => 'tag', 'tag' => 'never']]], [['field' => 'from', 'op' => 'contains', 'value' => 'vip@']]);
    $vip = $ingest('URGENT: pump stopped', 'vip@other.test', [], $now);
    $vc = ecw_case_for_thread($vip['thread_id']);
    require_once dirname(__DIR__) . '/lib/case_extras.php';
    check($vc !== null && (int)$vc['group_id'] === $grp2, 'rule 1 opened a case in its group');
    check(in_array('urgent', ecw_case_tags((int)$vc['id']), true), 'rule 1 tagged the case (cleaned to “urgent”)');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$u['bob']} AND type = 'rule' AND thread_id = {$vip['thread_id']}")->fetchColumn() === 1, 'rule 1 notified Bob');
    check($pdo->query('SELECT snoozed_until FROM ecw_email_threads WHERE id = ' . $vip['thread_id'])->fetchColumn() !== null, 'rule 1 snoozed the conversation');
    check((int)ecw_case_for_thread($vip['thread_id'])['owner_user_id'] === $u['cat'], 'rule 1 did not stop, so rule 2 set the Responsible');
    check(!in_array('never', ecw_case_tags((int)$vc['id']), true), 'rule 2 stops, so rule 3 never ran');
    $runsOf = static fn(int $rid): int => (int)$pdo->query('SELECT COUNT(*) FROM ecw_rule_runs WHERE rule_id = ' . $rid)->fetchColumn();
    check($runsOf($r1) === 1 && $runsOf($r2) === 1 && $runsOf($r3) === 0, 'the run log has what ran');
    $ingest('Re: URGENT: pump stopped', 'vip@other.test', [], $now, $pdo->query('SELECT provider_conversation_id FROM ecw_email_threads WHERE id = ' . $vip['thread_id'])->fetchColumn());
    check($runsOf($r1) === 1 && $runsOf($r2) === 2, '"first email only" skips replies; the other rule runs again');

    // Automatic reply: a shared template, never to robots, once per conversation.
    $pdo->prepare('INSERT INTO ecw_templates (organization_id, user_id, name, subject, body_html, created_by, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)')
        ->execute([$org, 'We got it', '', '<p>Hi {{first_name}}, thanks — we are on it.</p>', $u['ann'], $now]);
    $tpl = (int)$pdo->lastInsertId();
    $mk(['list' => [['type' => 'auto_reply', 'template_id' => $tpl]]], [['field' => 'subject', 'op' => 'contains', 'value' => 'quote request']]);
    $robot = $ingest('Quote request', 'no-reply@shop.test', [], $now);
    $human = $ingest('Quote request', 'maria@people.test', [], $now);
    $replies = static fn(int $t): int => (int)$pdo->query('SELECT COUNT(*) FROM ecw_drafts d JOIN ecw_email_messages m ON m.id = d.reply_to_local_message_id WHERE m.email_thread_id = ' . $t . " AND d.send_state = 'scheduled'")->fetchColumn();
    check($replies($robot['thread_id']) === 0, 'no automatic reply to a no-reply address');
    check($replies($human['thread_id']) === 1, 'a person gets the automatic reply (queued to send)');
    $body = (string)$pdo->query('SELECT d.body_html FROM ecw_drafts d JOIN ecw_email_messages m ON m.id = d.reply_to_local_message_id WHERE m.email_thread_id = ' . $human['thread_id'])->fetchColumn();
    check(str_contains($body, 'Hi Customer'), 'the template placeholders are filled in');
    $ingest('Re: Quote request', 'maria@people.test', [], $now, $pdo->query('SELECT provider_conversation_id FROM ecw_email_threads WHERE id = ' . $human['thread_id'])->fetchColumn());
    check($replies($human['thread_id']) === 1, 'never twice in one conversation');

    group('snooze');
    ecw_thread_snooze($mb, $routed['thread_id'], gmdate('Y-m-d H:i:s', time() + 3600));
    $ids = array_column(ecw_thread_list($mb, [])['threads'], 'id');
    check(!in_array($routed['thread_id'], $ids, true), 'snoozed thread leaves the list');
    check(in_array($routed['thread_id'], array_column(ecw_thread_list($mb, ['snoozed' => true])['threads'], 'id'), true), 'and is listed under Snoozed');
    $ingest('Re: Broken valve', 'someone@client.test', [], $now, $pdo->query('SELECT provider_conversation_id FROM ecw_email_threads WHERE id = ' . $routed['thread_id'])->fetchColumn());
    check($pdo->query('SELECT snoozed_until FROM ecw_email_threads WHERE id = ' . $routed['thread_id'])->fetchColumn() === null, 'a new reply wakes it');

    group('colour rules');
    ecw_rule_save($org, $u['ann'], false, ['kind' => 'color', 'name' => 'Me in To',
        'conditions' => ['match' => 'all', 'items' => [['field' => 'to', 'op' => 'me', 'value' => '']]], 'actions' => ['color' => '#2563eb']]);
    $rows = ecw_thread_list($mb, ['user_id' => $u['ann'], 'organization_id' => $org])['threads'];
    $byId = array_column($rows, 'color', 'id');
    check(($byId[$res['thread_id']] ?? null) === '#2563eb', 'thread with me in To is coloured');
    check(($byId[$old['thread_id']] ?? null) === null, 'thread without me in To is not');
    check(ecw_rule_matches(['match' => 'any', 'items' => [['field' => 'subject', 'op' => 'contains', 'value' => 'URGENT']]], ['subject' => 'very urgent'], ['emails' => []]), 'subject contains is case-insensitive');
    $toSales = ['to' => [['email' => 'sales.support@inquality.gr', 'name' => 'Sales Support']], 'cc' => [['email' => 'support@inquality.gr', 'name' => 'InQuality Support']]];
    $toSupport = ['items' => [['field' => 'to', 'op' => 'contains', 'value' => 'Support@InQuality.gr']]];
    check(!ecw_rule_matches($toSupport, $toSales, ['emails' => []]), '"To contains support@x": not sales.support@x in To (support@x is only in Cc)');
    check(ecw_rule_matches($toSupport, ['to' => [['email' => 'support@inquality.gr', 'name' => '']]], ['emails' => []]), 'but support@x itself in To, any case');
    check(ecw_rule_matches(['items' => [['field' => 'to', 'op' => 'contains', 'value' => 'support']]], $toSales, ['emails' => []])
        && ecw_rule_matches(['items' => [['field' => 'to', 'op' => 'contains', 'value' => '@inquality.gr']]], $toSales, ['emails' => []]), 'a part of an address is still found anywhere in it');

    group('templates, presence, suggestions, reports');
    $tid = ecw_template_save($org, $u['ann'], false, ['name' => 'Thanks', 'body_html' => '<p>Hi {{first_name}}</p><script>x</script>', 'shared' => true]);
    $list = ecw_templates_list($org, $u['bob']);
    $mine = array_values(array_filter($list, static fn($t) => $t['id'] === $tid));
    check(count($mine) === 1 && !str_contains($mine[0]['body_html'], '<script'), 'shared template is visible and sanitized');
    try { ecw_template_save($org, $u['bob'], false, ['id' => $tid, 'name' => 'X', 'body_html' => '<p>x</p>', 'shared' => true]); check(false, 'only creator edits a shared template'); }
    catch (InvalidArgumentException $e) { check(true, 'only creator edits a shared template'); }
    ecw_presence_beat($u['bob'], $res['thread_id']);
    check(ecw_presence_others($res['thread_id'], $u['ann']) === ['Bob Test'], 'ann sees bob replying');
    check(ecw_presence_others($res['thread_id'], $u['bob']) === [], 'bob does not see himself');
    $fresh = $ingest('Another question', 'customer@client.test', [], $now);
    $sug = ecw_case_suggest($org, $u['ann'], $fresh['thread_id'], [$mbEmail]);
    check($sug !== [] && in_array($case, array_column($sug, 'id'), true), 'a case with the same customer is suggested');
    $rep = ecw_reports($org, $u['ann'], ['days' => 30]);
    check($rep['status']['open'] >= 2 && $rep['trend']['unit'] === 'week' && count($rep['trend']['buckets']) >= 4, 'reports compute status totals and a weekly trend');
    check(ecw_reports($org, $u['bob'], ['days' => 30])['status']['open'] >= 1, 'reports run for another member');
    $grpOnly = ecw_reports($org, $u['ann'], ['days' => 30, 'group_id' => $grp]);
    check(array_sum($grpOnly['status']) >= 1 && array_sum($grpOnly['status']) < array_sum($rep['status']), 'the group filter narrows the report');
    $noGroup = ecw_reports($org, $u['ann'], ['days' => 30, 'group_id' => -1]);
    $grp2Only = ecw_reports($org, $u['ann'], ['days' => 30, 'group_id' => $grp2]);   // the automation rules' group
    check(array_sum($noGroup['status']) + array_sum($grpOnly['status']) + array_sum($grp2Only['status']) === array_sum($rep['status']), '"no group" plus the groups equals the whole');
    check(ecw_reports($org, $u['ann'], ['days' => 30, 'owner' => $u['cat']])['status']['open'] >= 1, 'the Responsible filter finds Cat\'s routed case');
    check(ecw_reports($org, $u['ann'], ['days' => 400])['trend']['unit'] === 'month', 'long periods switch to a monthly trend');
    $f = ecw_report_filters(['from' => '2026-12-31', 'to' => '2026-01-01', 'status' => 'bogus', 'group_id' => 'x']);
    check($f['from'] === '2026-01-01' && $f['status'] === '' && $f['group_id'] === 0, 'filters are normalized (swapped dates, unknown status ignored)');
    $csv = ecw_reports_csv($org, $u['ann'], ['days' => 30]);
    check($csv[0][0] === 'Case' && count($csv) >= 3, 'CSV export has a header and the cases');
    $pdo->prepare('UPDATE ecw_cases SET name = ? WHERE id = ?')->execute(['=HYPERLINK("http://evil")', $case]);
    $csv = ecw_reports_csv($org, $u['ann'], ['days' => 30]);
    check(!in_array('=HYPERLINK("http://evil")', array_column($csv, 1), true), 'CSV cells that start with = are neutralized (no spreadsheet formulas)');

    group('recipient suggestions');
    require_once dirname(__DIR__) . '/lib/compose.php';
    $sug = ecw_address_suggest($org, $u['ann'], 'cust');
    check(in_array('customer@client.test', array_column($sug, 'email'), true), 'a correspondent is suggested by address prefix');
    $sug = ecw_address_suggest($org, $u['ann'], 'bob');
    check(($sug[0]['colleague'] ?? false) === true, 'colleagues come first');
    check(!in_array(strtolower($mbEmail), array_column(ecw_address_suggest($org, $u['ann'], 'ann'), 'email'), true), 'your own mailbox address is never suggested');
    check(ecw_address_suggest($org, $u['ann'], '') === [], 'an empty query suggests nothing');

    group('template first names');
    check(ecw_first_name('George Bilalis', 'g@x.test') === 'George', 'the first word of a real name');
    check(ecw_first_name('person@example.test', 'person@example.test') === '', 'an address used as the display name is not a first name');
    check(ecw_first_name('Smith, John', '') === 'John', '"Last, First" gives the first name');
    check(ecw_first_name('', 'john.smith@x.test') === 'John', 'john.smith@ gives John when no name is known');
    check(ecw_first_name('', 'info.sales@x.test') === '' && ecw_first_name('', 'georgeb@x.test') === '', 'role or unsplittable addresses give nothing');
    check(ecw_first_name('"Μαρία Παπά"', '') === 'Μαρία', 'quotes are dropped and Greek names work');
    $rn = ecw_recipient_name($org, $u['ann'], 'customer@client.test');
    check(!str_contains($rn, '@'), 'the recipient name is never an address');
    check(ecw_recipient_name($org, $u['ann'], 'nobody-we-know@else.test') === '', 'unknown addresses have no name');

    group('AI provider');
    require_once dirname(__DIR__) . '/lib/ai.php';
    $GLOBALS['ecw_test_encryption_key'] = $GLOBALS['ecw_test_encryption_key'] ?? random_bytes(32);
    check(ecw_ai_settings($org)['jobs']['assistant'][0] === 'server' && ecw_ai_settings($org)['connections'] === [], 'the built-in Claude is the default');
    foreach (['ftp://x/v1', 'http://user:pw@host/v1', 'not a url', 'http://host/v1?x=1'] as $badUrl) {
        try { ecw_ai_connection_save($org, ['name' => 'Own', 'kind' => 'openai', 'base_url' => $badUrl, 'model' => 'm']); check(false, 'rejects ' . $badUrl); }
        catch (InvalidArgumentException $e) { check(true, 'rejects endpoint ' . $badUrl); }
    }
    $own = ecw_ai_connection_save($org, ['name' => 'Own', 'kind' => 'openai', 'base_url' => 'http://ollama:11434/v1/', 'model' => 'llama3.1:8b', 'api_key' => 'sk-secret-123']);
    ecw_ai_jobs_save($org, ['main' => ['assistant' => $own, 'agents' => $own, 'drafts' => $own], 'daily_requests' => '50']);
    $stored = (string)$pdo->query('SELECT ai_json FROM ecw_organizations WHERE id = ' . $org)->fetchColumn();
    check(!str_contains($stored, 'sk-secret-123'), 'the endpoint key is stored encrypted');
    $cfg = ecw_ai_config($org);
    check($cfg['provider'] === 'openai' && $cfg['base_url'] === 'http://ollama:11434/v1' && $cfg['key'] === 'sk-secret-123' && $cfg['daily_requests'] === 50 && ecw_ai_ready($cfg), 'the custom endpoint is used with its own key and cap');
    ecw_ai_connection_save($org, ['id' => $own, 'name' => 'Own', 'kind' => 'openai', 'base_url' => 'http://ollama:11434/v1', 'model' => 'llama3.1:8b', 'api_key' => '']);
    check(ecw_ai_config($org)['key'] === 'sk-secret-123', 'an empty key field keeps the stored key');
    $seen = null;
    $GLOBALS['ecw_ai_transport'] = static function (array $p) use (&$seen) { $seen = $p; return ['text' => 'OK', 'usage' => [], 'truncated' => false]; };
    ecw_ai_complete(ecw_ai_config($org), ['max_tokens' => 10, 'system' => 'x', 'messages' => [['role' => 'user', 'content' => 'y']]]);
    check(($seen['model'] ?? '') === 'llama3.1:8b', 'requests use the chosen model');
    unset($GLOBALS['ecw_ai_transport']);
    if (ecw_ai_server_key() !== '') {
        ecw_ai_jobs_save($org, ['main' => ['assistant' => 'server', 'agents' => 'server', 'drafts' => 'server'], 'daily_requests' => '100000']);
        $cfg = ecw_ai_config($org);
    } else {
        $cfg = ['provider' => 'claude', 'daily_requests' => 0];   // no server key here: nothing to raise
    }
    check($cfg['provider'] === 'claude' && $cfg['daily_requests'] <= 100, 'with the server key a workspace cannot raise the server allowance');

    group('assistant understands the question');
    $i = ecw_ai_understand('find me all the files that we have exchanged for the case:00002', '');
    check(($i['case_refs'][0]['n'] ?? 0) === 2 && $i['case_refs'][0]['prefix'] === null && $i['files'], '"files … case:00002" is a files question about case 2');
    $i = ecw_ai_understand('βρες τα συνημμένα της υπόθεσης 7', '');
    check(($i['case_refs'][0]['n'] ?? 0) === 7 && $i['files'] && $i['greek'], 'Greek: συνημμένα + υπόθεση 7');
    $i = ecw_ai_understand('what price did we give for GLO-1234?', '');
    check(!$i['files'] && ($i['case_refs'][0]['prefix'] ?? '') === 'GLO', 'a part code is only a possible case reference');
    check(ecw_ai_keywords('find me all the files that we have exchanged for the case:00002') === [], 'filler, "files" and the case reference are not search words');
    check(ecw_ai_keywords('quote from Nikos about GLO-1234') === ['quote', 'nikos', 'glo-1234'], 'real words stay');
    $caseNoN = (int)preg_replace('/\D+/', '', (string)$caseNo);
    $found = ecw_ai_find_case($org, $u['ann'], [['prefix' => null, 'n' => $caseNoN]]);
    check($found && (int)$found['id'] === $case, 'finds the case by its number whatever the prefix');
    check(ecw_ai_find_case($org, $u['ann'], [['prefix' => 'GLO', 'n' => $caseNoN]]) === null, 'a different prefix is not that case');
    $files = ecw_ai_files('m.email_thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)', [$case]);
    check(is_array($files) && count(array_unique(array_map(static fn($f) => strtolower($f['filename']) . '|' . $f['size'], $files))) === count($files), 'case files are listed once each');
    $ans = ecw_ai_files_answer([['direction' => 'incoming'], ['direction' => 'outgoing']], 'in CASE-1', false);
    check($ans['charged'] === false && str_contains($ans['text'], '2 files') && str_contains($ans['text'], '1 received, 1 sent'), 'a files answer needs no AI request');

    group('assistant tools');
    require_once dirname(__DIR__) . '/lib/ai_agent.php';
    $tctx = ['org' => $org, 'user' => $u['ann'], 'seen' => [], 'files' => []];
    $cnt = ecw_ai_tool_run($tctx, 'count_emails', ['from' => 'client.test', 'group_by' => 'month']);
    check($cnt['total'] > 0 && !empty($cnt['groups']), 'count_emails counts by sender and groups per month');
    $srch = ecw_ai_tool_run($tctx, 'search_emails', ['from' => 'client.test', 'limit' => 2]);
    check($srch['total_matches'] === $cnt['total'] && $srch['shown'] <= 2 && str_starts_with($srch['emails'][0]['ref'] ?? '', 'M'), 'search_emails returns refs and the true total');
    $ref = $srch['emails'][0]['ref'];
    check(!isset(ecw_ai_tool_run($tctx, 'read_email', ['ref' => $ref])['error']), 'read_email opens a visible email');
    check(isset(ecw_ai_tool_run($tctx, 'read_email', ['ref' => 'M999999999'])['error']), 'read_email refuses an unknown email');
    $otherCtx = ['org' => $org, 'user' => $u['cat'], 'seen' => [], 'files' => []];
    if (ecw_private_mailboxes()) check(ecw_ai_tool_run($otherCtx, 'count_emails', ['from' => 'client.test'])['total'] <= $cnt['total'], 'another user sees no more than their own scope');
    check(ecw_ai_tool_run($tctx, 'get_case', ['case' => (string)$caseNo])['case'] === $caseNo, 'get_case opens a case by its number');
    $money = ecw_ai_find_money("Invoice\nSubtotal €10,00\nTotal amount: €12,40\nPaid with card");
    check(($money['total']['amount'] ?? 0) === 12.4 && $money['total']['currency'] === 'EUR', 'amounts: "Total amount: €12,40" is the total');
    check(ecw_ai_parse_amount('1.234,56') === 1234.56 && ecw_ai_parse_amount('1,234.56') === 1234.56 && ecw_ai_parse_amount('12.5') === 12.5, 'amounts parse European and US formats');
    check(ecw_ai_find_money('Σύνολο πληρωτέο: 49,90 ευρώ')['total']['amount'] === 49.9, 'amounts: Greek "Σύνολο … ευρώ"');

    group('text of emails and of attached files');
    require_once dirname(__DIR__) . '/lib/attachment_text.php';
    $sp = ecw_split_quoted('<p>Total <b>12,40</b>&nbsp;€</p><style>.x{}</style><table><tr><td>Qty</td><td>50</td></tr></table>', '');
    check(str_contains((string)$sp['body_text'], 'Total 12,40 €') && str_contains((string)$sp['body_text'], 'Qty 50') && !str_contains((string)$sp['body_text'], '.x{'), 'HTML-only emails get a text version (for search and the AI)');
    // A real one-page PDF, made here: pdftotext must read it.
    $pdfText = 'BT /F1 12 Tf 72 720 Td (Invoice INV-2026-118 Total amount: EUR 249.90) Tj ET';
    $objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        "<< /Length " . strlen($pdfText) . " >>\nstream\n" . $pdfText . "\nendstream", "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"];
    $pdf = "%PDF-1.4\n"; $offs = [];
    foreach ($objs as $i => $o) { $offs[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n" . implode('', array_map(static fn($o) => sprintf("%010d 00000 n \n", $o), $offs)) . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    check(str_contains(ecw_extract_text($pdf, 'pdf'), 'Total amount: EUR 249.90'), 'PDF text is read (pdftotext)');
    $mkzip = static function (array $files): string { $t = tempnam(sys_get_temp_dir(), 'z'); $z = new ZipArchive(); $z->open($t, ZipArchive::OVERWRITE); foreach ($files as $n => $c) $z->addFromString($n, $c); $z->close(); $b = file_get_contents($t); unlink($t); return $b; };
    $docx = $mkzip(['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Προσφορά για 50 τεμάχια</w:t></w:r></w:p><w:p><w:r><w:t>Σύνολο 1.240,00 €</w:t></w:r></w:p></w:body></w:document>']);
    check(ecw_extract_text($docx, 'docx') === "Προσφορά για 50 τεμάχια\nΣύνολο 1.240,00 €", 'Word files are read, paragraph by paragraph (Greek too)');
    $xlsx = $mkzip(['xl/sharedStrings.xml' => '<sst><si><t>Part</t></si><si><t>GLO-1234</t></si></sst>',
        'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><v>Price</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2"><v>12.5</v></c></row></sheetData></worksheet>']);
    check(ecw_extract_text($xlsx, 'xlsx') === "Part\tPrice\nGLO-1234\t12.5", 'Excel files are read, row by row');
    check(ecw_extract_text("\xEF\xBB\xBFplain text", 'txt') === 'plain text' && str_contains(ecw_extract_text((string)iconv('UTF-8', 'CP1253', 'Γεια σας'), 'txt'), 'Γεια'), 'text files: BOM removed, Greek code pages converted');
    // A file on a client email, already read: the AI finds and reads it.
    $refId = (int)substr($ref, 1);
    $ins('email_attachments', ['email_message_id' => $refId, 'provider_attachment_id' => 'att-pdf-1', 'filename' => 'invoice-118.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdf), 'is_inline' => 0, 'text_content' => 'Invoice INV-2026-118 Total amount: EUR 249.90', 'text_state' => 'done']);
    $fileId = (int)$pdo->lastInsertId();
    $tctx = ['org' => $org, 'user' => $u['ann'], 'seen' => [], 'files' => []];
    check(ecw_ai_tool_run($tctx, 'search_emails', ['any_words' => ['INV-2026-118']])['total_matches'] >= 1, 'the AI finds an email by a word inside its PDF');
    $rf = ecw_ai_tool_run($tctx, 'read_file', ['file' => 'F' . $fileId]);
    check(str_contains((string)($rf['text'] ?? ''), '249.90') && isset($tctx['files'][$fileId]), 'read_file returns the text and shows the file as a card');
    $am = ecw_ai_tool_run($tctx, 'find_amounts', ['all_words' => ['INV-2026-118']]);
    check(($am['sum_of_likely_totals']['EUR'] ?? 0) >= 249.9, 'find_amounts counts the amount written in the PDF');
    $catCtx = ['org' => $org, 'user' => $u['cat'], 'seen' => [], 'files' => []];
    // That email is in a case the whole company sees: a colleague may read its file too.
    check(!isset(ecw_ai_tool_run($catCtx, 'read_file', ['file' => 'F' . $fileId])['error']), 'a file of an email in a case everyone sees can be read by a colleague');
    // The same kind of file on an email of Ann's own mailbox in no case: private to Ann.
    $privThread = $ins('email_threads', ['mailbox_id' => (int)$pdo->query('SELECT mailbox_id FROM `' . ecw_table('email_messages') . '` WHERE id = ' . $refId)->fetchColumn(), 'normalized_subject' => 'private file ' . uniqid()]);
    $privMsg = $ins('email_messages', ['mailbox_id' => (int)$pdo->query('SELECT mailbox_id FROM `' . ecw_table('email_messages') . '` WHERE id = ' . $refId)->fetchColumn(), 'email_thread_id' => $privThread,
        'provider_message_id' => 'priv-' . uniqid(), 'subject' => 'Private offer', 'from_email' => 'x@private-sender.test', 'direction' => 'incoming', 'received_at' => gmdate('Y-m-d H:i:s'), 'body_text' => 'see file', 'body_fetch_state' => 'fetched']);
    $ins('email_attachments', ['email_message_id' => $privMsg, 'provider_attachment_id' => 'att-priv-1', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 10, 'is_inline' => 0, 'text_content' => 'Secret price 999', 'text_state' => 'done']);
    $privFile = (int)$pdo->lastInsertId();
    check(!isset(ecw_ai_tool_run($tctx, 'read_file', ['file' => 'F' . $privFile])['error']), 'its owner reads a file of their own mailbox');
    check(isset(ecw_ai_tool_run($catCtx, 'read_file', ['file' => 'F' . $privFile])['error']) || !ecw_private_mailboxes(), 'someone without access cannot read the file (another person\'s mailbox, in no case)');

    group('assistant agent (both providers, same tools)');
    foreach (['claude', 'openai'] as $prov) {
        $GLOBALS['ecw_ai_test_config'] = $prov === 'openai'
            ? ['provider' => 'openai', 'model' => 'gemma4:12b', 'base_url' => 'http://ollama:11434/v1', 'key' => '', 'configured' => true, 'no_think' => true, 'daily_requests' => 50]
            : ['key' => 'fake', 'daily_requests' => 50];
        $bodies = [];
        $GLOBALS['ecw_ai_http_transport'] = static function (string $p, array $body) use (&$bodies, $ref) {
            $bodies[] = $body;
            $first = count($bodies) === 1;
            if ($p === 'openai') {
                return ['choices' => [['finish_reason' => $first ? 'tool_calls' : 'stop', 'message' => $first
                    ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'count_emails', 'arguments' => '{"from":"client.test"}']]]]
                    : ['role' => 'assistant', 'content' => 'You received **many** emails [' . $ref . '].']]]];
            }
            return $first
                ? ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'count_emails', 'input' => ['from' => 'client.test']]]]
                : ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'You received **many** emails [' . $ref . '].']]];
        };
        $events = [];
        $agentRes = ecw_ai_agent($org, $u['ann'], $mb, ['question' => 'How many emails did the client send?', 'history' => [['q' => 'Earlier question', 'a' => 'Earlier answer']]],
            static function ($e) use (&$events) { $events[] = $e; });
        check(count($bodies) === 2 && str_contains($agentRes['text'], 'many'), "{$prov}: one tool call, then the answer");
        check((bool)array_filter($events, static fn($e) => ($e['label'] ?? '') !== '' && str_starts_with($e['label'], 'Counting emails')), "{$prov}: the step is streamed live");
        $second = json_encode($bodies[1], JSON_UNESCAPED_UNICODE);
        check(str_contains($second, 'total') && str_contains($second, 'Earlier answer'), "{$prov}: the tool result and the conversation so far go back to the model");
        check(($agentRes['sources'][0]['label'] ?? '') === $ref, "{$prov}: the cited email becomes a source");
        $toolNames = $prov === 'openai' ? array_column(array_column($bodies[0]['tools'], 'function'), 'name') : array_column($bodies[0]['tools'], 'name');
        check(in_array('find_amounts', $toolNames, true) && in_array('list_files', $toolNames, true) && count($toolNames) === count(ecw_ai_tool_defs()), "{$prov}: offered the same tools");
    }
    unset($GLOBALS['ecw_ai_http_transport'], $GLOBALS['ecw_ai_test_config']);

    group('audit log');
    require_once dirname(__DIR__) . '/lib/audit.php';
    require_once dirname(__DIR__) . '/lib/timeline.php';
    ecw_audit('attachment.download', 'attachment', 77, ['file' => 'quote.pdf', 'body' => str_repeat('x', 500), 'nested' => ['a' => ['b']]], $org, $u['ann']);
    $row = $pdo->query("SELECT * FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'attachment.download' ORDER BY id DESC LIMIT 1")->fetch();
    check($row && (int)$row['user_id'] === $u['ann'] && (int)$row['target_id'] === 77 && $row['via'] === 'cli', 'an action is recorded with who, what and how');
    $d = json_decode((string)$row['detail'], true);
    check(($d['file'] ?? '') === 'quote.pdf' && mb_strlen((string)$d['body']) <= 120, 'details are kept short');
    $delCase = ecw_case_create($org, 'Audit me', $u['ann']);
    ecw_case_soft_delete($org, $delCase, $u['ann']);
    ecw_case_restore($org, $delCase, $u['bob']);
    $acts = $pdo->query("SELECT action, user_id FROM ecw_audit_log WHERE target_type = 'case' AND target_id = {$delCase} ORDER BY id")->fetchAll();
    check(array_column($acts, 'action') === ['case.create', 'case.delete', 'case.restore'] && (int)$acts[2]['user_id'] === $u['bob'], 'creating, deleting and restoring a case are audited with the person');
    check(ecw_audit_count($org, ['action' => 'case.']) >= 3 && ecw_audit_count($org, ['action' => 'case.', 'user' => $u['bob']]) >= 1, 'the log filters by kind and by person');
    check(ecw_audit_count($org, ['q' => 'quote.pdf']) >= 1 && ecw_audit_count($org + 999999, []) === 0, 'search works, and each organization sees only its own log');
    $_SESSION['ecw_user_id'] = $u['ann']; $_SESSION['ecw_organization_id'] = $org;
    $before = ecw_audit_count($org, ['action' => 'case.view']);
    ecw_audit_view('case.view', 'case', $delCase); ecw_audit_view('case.view', 'case', $delCase);
    check(ecw_audit_count($org, ['action' => 'case.view']) === $before + 1, 'opening the same case again within the hour is recorded once');
    unset($_SESSION['ecw_user_id'], $_SESSION['ecw_organization_id']);
    check(ecw_audit_label('case.delete') === 'Deleted a case' && ecw_audit_label('some.new') === 'some.new', 'actions have readable names');

    group('AI agents and suggestions');
    require_once dirname(__DIR__) . '/lib/agents.php';
    $ag = ecw_agents_settings($org);
    check($ag['on']['triage'] && $ag['on']['summary'] && !$ag['on']['drafter'], 'agents: triage and summaries on, prepared replies off by default');
    $ag = ecw_agents_settings_save($org, ['on' => ['triage' => 1, 'promises' => 1, 'chaser' => 1, 'summary' => 1], 'chaser_days' => 2]);
    check($ag['on']['triage'] && !$ag['on']['digest'] && $ag['chaser_days'] === 2 && $ag['since'] !== '', 'agent settings are saved, with a start time');
    $GLOBALS['ecw_ai_test_config'] = ['provider' => 'openai', 'model' => 'gemma4:12b', 'base_url' => 'http://ollama:11434/v1', 'key' => '', 'configured' => true, 'no_think' => true, 'daily_requests' => 100];
    $aiCalls = [];
    $aiReply = '';
    $GLOBALS['ecw_ai_http_transport'] = static function (string $p, array $body) use (&$aiCalls, &$aiReply) { $aiCalls[] = $body; return ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $aiReply]]]]; };
    $nowT = gmdate('Y-m-d H:i:s');
    $q = $ingest('Quote for 50 pcs GLO-1234', 'buyer@acme-parts.test', [['email' => strtolower($mbEmail), 'name' => 'Ann']], $nowT);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = ?, body_fetch_state = 'fetched' WHERE id = ?")->execute(["Hello, please send us a quote for 50 pcs of GLO-1234 by Friday. Our PO will be 4500123.", $q['message_id']]);
    $aiReply = json_encode(['type' => 'quote_request', 'urgency' => 'high', 'needs_reply' => true, 'reply_by' => gmdate('Y-m-d', time() + 4 * 86400),
        'summary' => 'Wants a quote for 50 pieces of GLO-1234 by Friday.', 'facts' => [['label' => 'Part', 'value' => 'GLO-1234'], ['label' => 'PO', 'value' => '4500123'], ['label' => 'Price', 'value' => '€999']],
        'new_case_name' => 'Acme quote GLO-1234']);
    $row = $pdo->query('SELECT m.*, mb.email_address AS mailbox_address FROM ecw_email_messages m JOIN ecw_mailboxes mb ON mb.id = m.mailbox_id WHERE m.id = ' . (int)$q['message_id'])->fetch();
    ecw_agent_triage_one($org, $ag, ecw_ai_config($org), $row);
    $sugs = ecw_suggestions_for($org, $u['ann'], (int)$q['thread_id']);
    check(count($sugs) === 1 && str_starts_with($sugs[0]['title'], 'Quote request · urgent · reply by'), 'triage: a new quote request gets one suggestion with kind, urgency and reply-by');
    check(array_column($sugs[0]['facts'], 'value') === ['GLO-1234', '4500123'], 'triage: only facts that are really in the email are kept (an invented price is dropped)');
    $types = array_column($sugs[0]['actions'], 'type');
    check(in_array('new_case', $types, true) && in_array('remind', $types, true) && in_array('note', $types, true) && in_array('draft_reply', $types, true), 'triage: start a case, a reminder, save the facts, draft a reply');
    check(str_contains(json_encode($aiCalls[0], JSON_UNESCAPED_UNICODE), 'untrusted data') && ($aiCalls[0]['response_format']['type'] ?? '') === 'json_object', 'triage asks for JSON only and treats the email as data');
    check(ecw_agent_ran('triage', 'message', (int)$q['message_id']), 'each email is triaged once');
    $act = ecw_suggestion_act($org, $u['ann'], $sugs[0]['id'], array_search('new_case', $types, true));
    $linked = ecw_case_for_thread((int)$q['thread_id']);
    check($linked !== null && $linked['name'] === 'Acme quote GLO-1234' && str_starts_with((string)$act['url'], 'case.php?id='), 'accepting "start a case" creates it and files the email');
    check(ecw_suggestions_for($org, $u['ann'], (int)$q['thread_id']) === [], 'an accepted suggestion is no longer open');
    $denied = false; try { ecw_suggestion_act($org, $u['bob'], $sugs[0]['id'], 0); } catch (InvalidArgumentException $e) { $denied = true; }
    check($denied, 'nobody can act on someone else\'s suggestion');
    $n0 = count($aiCalls);
    $news = $ingest('Our October newsletter', 'news@shop.test', [['email' => strtolower($mbEmail), 'name' => 'Ann']], $nowT);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = ?, body_fetch_state = 'fetched' WHERE id = ?")->execute(['Big sale! Unsubscribe here.', $news['message_id']]);
    ecw_agent_triage_one($org, $ag, ecw_ai_config($org), $pdo->query('SELECT m.*, mb.email_address AS mailbox_address FROM ecw_email_messages m JOIN ecw_mailboxes mb ON mb.id = m.mailbox_id WHERE m.id = ' . (int)$news['message_id'])->fetch());
    check(count($aiCalls) === $n0 && ecw_suggestions_for($org, $u['ann'], (int)$news['thread_id']) === [], 'newsletters are skipped without spending AI');
    // A promise in a sent email → a reminder suggestion.
    $sent = $ingest('Re: Quote for 50 pcs GLO-1234', strtolower($mbEmail), [['email' => 'buyer@acme-parts.test', 'name' => 'Buyer']], $nowT);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = ?, direction = 'outgoing' WHERE id = ?")->execute(['We will send you the drawing by Friday.', $sent['message_id']]);
    $aiReply = json_encode(['promises' => [['what' => 'send the drawing', 'by' => gmdate('Y-m-d', time() + 3 * 86400)]]]);
    ecw_agent_promises($org, $ag, ecw_ai_config($org));
    $ps = array_values(array_filter(ecw_suggestions_for($org, $u['ann']), static fn($s) => $s['kind'] === 'promise'));
    check(count($ps) === 1 && str_contains($ps[0]['title'], 'send the drawing') && $ps[0]['actions'][0]['type'] === 'remind', 'promises: "we will send it by Friday" offers a reminder');
    ecw_suggestion_act($org, $u['ann'], $ps[0]['id'], 0);
    check((int)$pdo->query('SELECT COUNT(*) FROM ecw_followups WHERE user_id = ' . $u['ann'] . " AND note LIKE 'Promised:%'")->fetchColumn() === 1, 'accepting it sets the reminder');
    // No reply to our question after N days.
    $old = gmdate('Y-m-d H:i:s', time() - 4 * 86400);
    $cold = $ingest('Our new service', strtolower($mbEmail), [['email' => 'stranger@cold.test', 'name' => 'Stranger']], $old);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = ?, direction = 'outgoing' WHERE id = ?")->execute(['Would you like a demo?', $cold['message_id']]);
    $pdo->prepare('UPDATE ecw_email_threads SET latest_message_id = ? WHERE id = ?')->execute([$cold['message_id'], $cold['thread_id']]);
    $ingest('Drawing question', 'eng@acme-parts.test', [['email' => strtolower($mbEmail), 'name' => 'Ann']], gmdate('Y-m-d H:i:s', time() - 9 * 86400));
    $ask = $ingest('Drawing approval', strtolower($mbEmail), [['email' => 'eng@acme-parts.test', 'name' => 'Engineer']], $old);
    $pdo->prepare("UPDATE ecw_email_messages SET body_text = ?, direction = 'outgoing' WHERE id = ?")->execute(['Can you approve the drawing?', $ask['message_id']]);
    $pdo->prepare('UPDATE ecw_email_threads SET latest_message_id = ? WHERE id = ?')->execute([$ask['message_id'], $ask['thread_id']]);
    $pdo->prepare('UPDATE ecw_organizations SET agents_json = JSON_SET(agents_json, "$.since", ?) WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 10 * 86400), $org]);
    ecw_agent_chaser($org, ecw_agents_settings($org));
    $cs = ecw_suggestions_for($org, $u['ann'], (int)$ask['thread_id']);
    check(count($cs) === 1 && $cs[0]['kind'] === 'chaser' && str_contains($cs[0]['title'], 'has not replied for 4 days'), 'no reply yet: an unanswered question is noticed (no AI needed)');
    check(ecw_suggestions_for($org, $u['ann'], (int)$cold['thread_id']) === [], 'no reply yet: cold outreach to someone who never wrote to us is not chased');
    check(ecw_suggestion_dismiss($org, $u['ann'], $cs[0]['id']) && ecw_suggestions_for($org, $u['ann'], (int)$ask['thread_id']) === [], 'a suggestion can be dismissed');
    // People who turned suggestions off get none.
    $pdo->prepare('UPDATE ecw_users SET preferences_json = ? WHERE id = ?')->execute([json_encode(['ai_suggestions_off' => true]), $u['bob']]);
    check(ecw_suggest($org, $u['bob'], 'triage', 'triage', 'x') === null, 'someone who turned AI suggestions off gets none');
    // The living summary.
    $aiReply = "Status: waiting for the drawing approval.\nOpen:\n- quote for 50 pcs\nWaiting: we wait for Acme since Monday.\nNext: chase the approval.";
    check(str_starts_with(ecw_agent_case_summary_text($org, (int)$linked['id'], ecw_ai_config($org)), 'Status: waiting'), 'case summaries are written by the AI');
    // The rule writer: names become ids, and the result passes the normal checks.
    $aiReply = json_encode(['kind' => 'route', 'name' => 'Acme quotes', 'conditions' => ['match' => 'all', 'items' => [['field' => 'from', 'op' => 'domain', 'value' => '@acme-parts.test'], ['field' => 'subject', 'op' => 'contains', 'value' => 'quote']]],
        'actions' => ['list' => [['type' => 'create_case'], ['type' => 'notify', 'user_id' => $u['bob']]], 'stop' => true], 'explanation' => 'Quotes from Acme open a case and tell Bob.']);
    $rule = ecw_agent_rule_from_text($org, $u['ann'], true, 'Quotes from Acme: open a case and tell Bob');
    check($rule['kind'] === 'route' && $rule['conditions']['items'][0]['value'] === 'acme-parts.test' && $rule['actions']['list'][1]['user_id'] === $u['bob'], 'rule writer: a sentence becomes a valid automation rule');
    check(ecw_agent_rule_from_text($org, $u['cat'], false, 'Quotes from Acme')['kind'] === 'color', 'rule writer: a non-admin only gets a colour rule');
    $aiReply = json_encode(['kind' => 'route', 'name' => 'x', 'conditions' => ['items' => []], 'actions' => ['list' => [['type' => 'notify', 'user_id' => 999999]]]]);
    $bad = false; try { ecw_agent_rule_from_text($org, $u['ann'], true, 'nonsense'); } catch (InvalidArgumentException $e) { $bad = true; }
    check($bad, 'rule writer: an incomplete AI rule is refused, not saved');
    unset($GLOBALS['ecw_ai_http_transport'], $GLOBALS['ecw_ai_test_config']);

    group('MCP server for Copilot and other AI apps');
    require_once dirname(__DIR__) . '/lib/copilot_package.php';
    $tok = ecw_mcp_token_create($org, $u['ann'], 'Test app');
    check(str_starts_with($tok, 'ecw_') && strlen($tok) > 30, 'a personal token is made');
    check(!str_contains((string)json_encode($pdo->query('SELECT * FROM ecw_ai_tokens WHERE user_id = ' . $u['ann'])->fetchAll()), $tok), 'only its hash is stored');
    $who = ecw_mcp_authenticate('Bearer ' . $tok);
    check($who['user'] === $u['ann'] && $who['org'] === $org && $who['via'] === 'token', 'the token signs in as its owner');
    $deny = static function (string $auth): bool { try { ecw_mcp_authenticate($auth); return false; } catch (EcwMcpAuthError $e) { return true; } };
    check($deny('') && $deny('Bearer ecw_wrong') && $deny('Basic abc'), 'no, wrong or other tokens are refused');
    $init = ecw_mcp_handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], $who);
    check(($init['result']['protocolVersion'] ?? '') === '2025-06-18' && isset($init['result']['capabilities']['tools']), 'initialize answers with the tools capability');
    check(ecw_mcp_handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $who) === null, 'notifications get no answer');
    $list = ecw_mcp_handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $who);
    $names = array_column($list['result']['tools'] ?? [], 'name');
    sort($names); $readOnly = ECW_MCP_TOOLS; sort($readOnly);
    check($names === $readOnly && !array_filter($list['result']['tools'] ?? [], static fn($t) => ($t['annotations']['readOnlyHint'] ?? false) !== true), 'tools/list: only the Ask AI tools that read, each marked read-only', json_encode($names));
    check(array_intersect($names, array_merge(ECW_AI_ACTING_TOOLS, ['open_email', 'open_case'])) === [], 'no tool that writes, proposes a change or opens the app is offered');
    $call = ecw_mcp_handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'count_emails', 'arguments' => ['from' => 'client.test']]], $who);
    check(($call['result']['structuredContent']['total'] ?? -1) === $cnt['total'] && ($call['result']['isError'] ?? true) === false, 'tools/call runs the tool for that user');
    check(isset(ecw_mcp_handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'send_email']], $who)['error']), 'there is no tool that sends or changes anything');
    foreach (['create_case', 'add_note', 'reply', 'make_spreadsheet', 'open_email'] as $assistantOnly) {
        $r = ecw_mcp_handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => $assistantOnly, 'arguments' => []]], $who);
        check(isset($r['error']) && !isset($r['result']), 'asked for by name anyway, "' . $assistantOnly . '" is refused over MCP');
    }
    $tid = (int)$pdo->query('SELECT id FROM ecw_ai_tokens WHERE user_id = ' . $u['ann'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    ecw_mcp_token_revoke($u['ann'], $tid);
    check($deny('Bearer ' . $tok), 'a removed token stops working');

    // Microsoft Entra SSO: a token signed with our own test key, served as the tenant's JWKS.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $key), null, $key, 1);
    openssl_x509_export($cert, $pem);
    $x5c = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    $GLOBALS['ecw_mcp_test_jwks'] = [['kid' => 'k1', 'kty' => 'RSA', 'x5c' => [$x5c]]];
    $tenant = '11111111-2222-4333-8444-555555555555';
    ecw_copilot_settings_save($org, ['enabled' => '1', 'tenant_id' => $tenant, 'client_id' => '66666666-7777-4888-8999-000000000000', 'audiences' => 'api://auth-abc/66666666-7777-4888-8999-000000000000', 'reference_id' => 'ref-123']);
    $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $jwt = static function (array $claims) use ($key, $b64): string {
        $h = $b64(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claims));
        openssl_sign($h, $sig, $key, OPENSSL_ALGO_SHA256);
        return $h . '.' . $b64($sig);
    };
    $good = ['tid' => $tenant, 'iss' => 'https://login.microsoftonline.com/' . $tenant . '/v2.0', 'aud' => 'api://auth-abc/66666666-7777-4888-8999-000000000000',
        'exp' => time() + 600, 'nbf' => time() - 10, 'preferred_username' => strtoupper($mbEmail)];
    $viaMs = ecw_mcp_authenticate('Bearer ' . $jwt($good));
    check($viaMs['user'] === $u['ann'] && $viaMs['via'] === 'copilot', 'Copilot: a Microsoft token signs in the person whose mailbox address it carries');
    check($deny('Bearer ' . $jwt(['aud' => 'api://someone-else'] + $good)), 'Copilot: a token for another app is refused');
    check($deny('Bearer ' . $jwt(['exp' => time() - 3600] + $good)), 'Copilot: an expired token is refused');
    check($deny('Bearer ' . $jwt(['preferred_username' => 'stranger@else.test'] + $good)), 'Copilot: an address that is nobody here is refused');
    $forged = explode('.', $jwt($good)); $forged[1] = $b64(json_encode(['preferred_username' => 'bob@acme.test'] + $good));
    check($deny('Bearer ' . implode('.', $forged)), 'Copilot: a changed token fails the signature check');
    check($deny('Bearer ' . $jwt(['tid' => '99999999-2222-4333-8444-555555555555', 'iss' => 'https://login.microsoftonline.com/99999999-2222-4333-8444-555555555555/v2.0'] + $good)), 'Copilot: another Microsoft 365 organization is refused');
    ecw_copilot_settings_save($org, ['tenant_id' => $tenant, 'client_id' => '66666666-7777-4888-8999-000000000000', 'reference_id' => 'ref-123']);
    check($deny('Bearer ' . $jwt($good)), 'Copilot: switched off means refused');
    unset($GLOBALS['ecw_mcp_test_jwks']);

    $files = ecw_copilot_package_files($org, 'https://cases.example.com/other_things/web/mcp.php');
    $pl = json_decode($files['ai-plugin.json'], true);
    $mf = json_decode($files['manifest.json'], true);
    check(($pl['runtimes'][0]['type'] ?? '') === 'RemoteMCPServer' && ($pl['runtimes'][0]['spec']['url'] ?? '') === 'https://cases.example.com/other_things/web/mcp.php'
        && ($pl['runtimes'][0]['auth'] ?? []) === ['type' => 'OAuthPluginVault', 'reference_id' => 'ref-123'], 'package: the plugin points at our MCP server with the Entra sign-in');
    check(($mf['copilotAgents']['declarativeAgents'][0]['file'] ?? '') === 'declarativeAgent.json' && ($mf['validDomains'] ?? []) === ['cases.example.com'], 'package: the app manifest declares the agent');
    check(str_starts_with($files['color.png'], "\x89PNG") && getimagesizefromstring($files['color.png'])[0] === 192 && getimagesizefromstring($files['outline.png'])[0] === 32, 'package: the two icons are real PNGs of the right size');

    group('webhooks');
    check(ecw_webhook_target('http://example.com/x') === null, 'http rejected');
    check(ecw_webhook_target('https://127.0.0.1/x') === null && ecw_webhook_target('https://10.0.0.5/x') === null, 'private addresses rejected');
    check(ecw_webhook_target('https://localhost/x') === null, 'localhost rejected');
    $GLOBALS['ecw_webhook_test_sink'] = [];
    $pdo->prepare('UPDATE ecw_organizations SET webhook_url_encrypted = ?, webhook_secret_encrypted = ?, webhook_events_json = ? WHERE id = ?')
        ->execute([ecw_encrypt('https://hooks.example.com/x'), ecw_encrypt('s3cret'), json_encode(['case.created']), $org]);
    check(ecw_webhook_emit($org, 'case.created', ['a' => 1]) && !ecw_webhook_emit($org, 'note.added', []), 'only chosen events are sent');
    $sent = $GLOBALS['ecw_webhook_test_sink'][0];
    check($sent['signature'] === 'sha256=' . hash_hmac('sha256', json_encode($sent['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 's3cret'), 'payload is HMAC-signed');
    check(ecw_webhook_case($case) === null, 'members-only cases are never sent');

    group('notes on conversations and in the timeline');
    require_once dirname(__DIR__) . '/lib/timeline.php';
    $caseThread = $res['thread_id'];          // linked to $case
    // In no case: a sender no routing rule above matches (@client.test is routed).
    $looseThread = $ingest('Unrelated question', 'stranger@other.test', [], $now)['thread_id'];
    check(ecw_case_for_thread($looseThread) === null, 'fixture: this conversation is in no case');
    $msgInCase = (int)$pdo->query('SELECT id FROM ecw_email_messages WHERE email_thread_id = ' . $caseThread . ' ORDER BY id LIMIT 1')->fetchColumn();
    $msgLoose = (int)$pdo->query('SELECT id FROM ecw_email_messages WHERE email_thread_id = ' . $looseThread . ' ORDER BY id LIMIT 1')->fetchColumn();

    $loose = ecw_note_add($org, $u['ann'], 'Customer sounds unhappy', null, $looseThread);
    check(count(ecw_thread_notes($looseThread, $u['ann'])) === 1 && $loose['case_id'] === null, 'a note on a conversation that is in no case');
    if (ecw_private_mailboxes()) {
        try { ecw_note_add($org, $u['ann'], "fyi @[Cat Test](user:{$u['cat']})", null, $looseThread); check(false, 'mention refused when the colleague cannot open the mailbox'); }
        catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'Add the conversation to a case first'), 'mention refused when the colleague cannot open the mailbox'); }
        check(count(ecw_thread_notes($looseThread, $u['ann'])) === 1, 'and nothing was saved');
    }
    $about = ecw_note_add($org, $u['ann'], 'Price is wrong here', null, $caseThread, $msgInCase);
    $rows = ecw_thread_notes($caseThread, $u['ann']);
    check($rows[0]['about'] !== null && $rows[0]['about']['message_id'] === $msgInCase, 'a note about one email carries that email');
    check($about['case_id'] === $case, 'a conversation note in a case belongs to that case');
    try { ecw_note_add($org, $u['ann'], 'x', null, $caseThread, $msgLoose); check(false, 'an email from another conversation is rejected'); }
    catch (InvalidArgumentException $e) { check(true, 'an email from another conversation is rejected'); }
    try { ecw_case_note_add($org, $case, $u['ann'], 'x', $msgLoose); check(false, 'a case note about an email outside the case is rejected'); }
    catch (InvalidArgumentException $e) { check(true, 'a case note about an email outside the case is rejected'); }
    ecw_case_note_add($org, $case, $u['ann'], 'Decision: replace the valve');
    $caseNotes = ecw_case_notes($case, $u['ann']);
    check(count($caseNotes) === 2, 'the case shows its own note and its conversations\' notes, not other notes');
    $tl = ecw_case_timeline($org, $case, false, null, $u['ann']);
    check(array_filter($tl['events'], static fn($e) => ($e['type'] ?? '') === 'note') === [], 'notes are not timeline rows any more (they open from each email\'s 💬)');
    $times = array_column($tl['events'], 'occurred_at');
    $sorted = $times; sort($sorted);
    check($times === $sorted, 'the timeline stays in chronological order');

    group('note conversations (like comments in Word)');
    $groups = ecw_note_groups(ecw_case_notes($case, $u['ann']));
    check(count($groups) === 2, 'each existing note is its own conversation');
    $g = array_values(array_filter($groups, static fn($x) => $x['message_id'] === $msgInCase))[0] ?? null;
    check($g !== null && $g['about']['message_id'] === $msgInCase, 'a conversation about one email knows the email');
    check(count(array_filter($groups, static fn($x) => $x['message_id'] === null)) === 1, 'the case-wide note is a General conversation');
    $second = ecw_note_add($org, $u['ann'], 'Also the delivery date', null, $caseThread, $msgInCase);
    check(count(array_filter(ecw_note_groups(ecw_thread_notes($caseThread, $u['ann'])), static fn($x) => $x['message_id'] === $msgInCase)) === 2, 'one email can have several conversations');
    $reply = ecw_case_note_add($org, $case, $u['bob'], "Agreed, I'll call them @[Ann Test](user:{$u['ann']})", null, $about['note_id']);
    check($reply['root_id'] === $about['note_id'], 'a reply joins its conversation');
    $g = array_values(array_filter(ecw_note_groups(ecw_thread_notes($caseThread, $u['ann'])), static fn($x) => $x['id'] === $about['note_id']))[0];
    check(count($g['notes']) === 2 && $g['notes'][1]['parent_id'] === $about['note_id'], 'the conversation shows the first note and the reply in order');
    check($g['message_id'] === $msgInCase && $g['notes'][1]['message_id'] === $msgInCase, 'the reply is about the same email');
    check($g['unread'], 'ann has not seen bob\'s reply yet');
    check(count($g['participants']) === 2, 'both people are in the conversation');
    ecw_note_seen($about['note_id'], $u['ann'], null, $caseThread);
    $g = array_values(array_filter(ecw_note_groups(ecw_thread_notes($caseThread, $u['ann'])), static fn($x) => $x['id'] === $about['note_id']))[0];
    check(!$g['unread'], 'opening it clears the unread dot');
    $replyToReply = ecw_note_add($org, $u['ann'], 'Thanks', null, $caseThread, null, $reply['note_id']);
    check($replyToReply['root_id'] === $about['note_id'], 'replying to a reply still joins the first note\'s conversation');
    check(ecw_note_resolve($about['note_id'], $u['bob'], true, $case, null), 'anyone who sees the case can resolve a conversation');
    $g = array_values(array_filter(ecw_note_groups(ecw_case_notes($case, $u['ann'])), static fn($x) => $x['id'] === $about['note_id']))[0];
    check($g['resolved'] && $g['resolved_by'] === 'Bob Test', 'it shows as resolved, and by whom');
    ecw_note_add($org, $u['ann'], 'One more thing', null, $caseThread, null, $about['note_id']);
    $g = array_values(array_filter(ecw_note_groups(ecw_case_notes($case, $u['ann'])), static fn($x) => $x['id'] === $about['note_id']))[0];
    check(!$g['resolved'], 'a new reply reopens a resolved conversation');
    check(!ecw_note_resolve($about['note_id'], $u['ann'], true, null, $looseThread), 'a conversation cannot be resolved through another conversation');
    try { ecw_note_add($org, $u['ann'], 'x', null, $looseThread, null, $about['note_id']); check(false, 'a reply through another conversation is refused'); }
    catch (InvalidArgumentException $e) { check(true, 'a reply through another conversation is refused'); }
    check(!ecw_note_delete($about['note_id'], $u['ann'], false, null, $looseThread), 'a note cannot be deleted through another conversation');
    check(ecw_note_delete($about['note_id'], $u['ann'], false, null, $caseThread), 'its author deletes the conversation in its own conversation');
    check(array_filter(ecw_thread_notes($caseThread, $u['ann']), static fn($n) => ($n['parent_id'] ?? $n['id']) === $about['note_id']) === [], 'deleting the first note takes its replies with it');
    check(count(ecw_note_groups(ecw_thread_notes($caseThread, $u['ann']))) === 1 && $second['root_id'] === $second['note_id'], 'the other conversation on that email stays');

    group('writing together: shared drafts and the Send check');
    require_once dirname(__DIR__) . '/lib/compose.php';
    require_once dirname(__DIR__) . '/lib/draft_share.php';
    $draftId = ecw_draft_save($org, $mb, $u['ann'], ['mode' => 'reply', 'in_reply_to_message_id' => $msgInCase, 'subject' => 'Re: pump', 'body_html' => '<p>First words</p>', 'to' => [['email' => 'x@client.test']]]);
    $d = ecw_draft_load($org, $draftId);
    check(ecw_draft_role($org, $u['ann'], $d) === 'owner' && ecw_draft_role($org, $u['bob'], $d) === null, 'a draft is its author\'s until shared');
    try { ecw_draft_share($org, $u['bob'], $d, true); check(false, 'only the author can share'); } catch (InvalidArgumentException $e) { check(true, 'only the author can share'); }
    ecw_draft_share($org, $u['ann'], $d, true);
    $d = ecw_draft_load($org, $draftId);
    check(ecw_draft_role($org, $u['bob'], $d) === 'editor', 'shared: a colleague who sees the conversation may edit it');
    check(count(ecw_shared_drafts_for_thread($org, $u['bob'], $caseThread)) === 1, 'it shows above the emails of that conversation');
    $s = ecw_draft_lock($org, $u['bob'], $d, false);
    check($s['mine'] && $s['editing']['id'] === $u['bob'], 'opening it takes the edit lock');
    $d = ecw_draft_load($org, $draftId);
    check(ecw_draft_locked_by_other($d, $u['ann']), 'the author now reads along');
    check(!ecw_draft_lock($org, $u['ann'], $d, false)['mine'], 'the lock is not taken from someone editing');
    check(ecw_draft_lock($org, $u['ann'], ecw_draft_load($org, $draftId), true)['mine'], '"Take over" takes it');
    ecw_draft_lock($org, $u['bob'], ecw_draft_load($org, $draftId), true);
    $before = (int)ecw_draft_load($org, $draftId)['version'];
    ecw_draft_save($org, $mb, $u['bob'], ['mode' => 'reply', 'in_reply_to_message_id' => $msgInCase, 'subject' => 'Re: pump', 'body_html' => '<p>Better words</p>'], $draftId);
    $d = ecw_draft_load($org, $draftId);
    check((int)$d['user_id'] === $u['ann'] && (int)$d['last_edited_by_user_id'] === $u['bob'], 'a colleague\'s edit keeps it the author\'s draft');
    check((int)$d['version'] === $before + 1, 'each save counts a new version');
    ecw_draft_unlock($u['bob'], $draftId);
    check(!ecw_draft_locked_by_other(ecw_draft_load($org, $draftId), $u['ann']), 'closing the composer frees the lock');
    $pdo->exec("UPDATE ecw_drafts SET editing_user_id = {$u['bob']}, editing_at = '2000-01-01 00:00:00' WHERE id = {$draftId}");
    check(!ecw_draft_locked_by_other(ecw_draft_load($org, $draftId), $u['ann']), 'a lock from a closed tab runs out by itself');
    $new = ecw_draft_save($org, $mb, $u['ann'], ['mode' => 'new', 'subject' => 'Hello']);
    try { ecw_draft_share($org, $u['ann'], ecw_draft_load($org, $new), true); check(false, 'a brand-new email cannot be shared'); }
    catch (InvalidArgumentException $e) { check(true, 'a brand-new email cannot be shared'); }
    $latest = (int)$pdo->query('SELECT MAX(id) FROM ecw_email_messages WHERE email_thread_id = ' . $caseThread)->fetchColumn();
    check(ecw_reply_collision($d, $latest) === null, 'Send check: nothing new since the writer looked');
    // A colleague answers in the same conversation while ann is still writing.
    $pdo->exec("INSERT INTO ecw_email_messages (mailbox_id, email_thread_id, mail_folder_id, provider_message_id, subject, from_email, from_name, to_json, cc_json, bcc_json, body_text, direction, received_at, sent_at)
                SELECT mailbox_id, email_thread_id, mail_folder_id, CONCAT('pnew', id), subject, 'bob@acme.test', 'Bob Test', to_json, cc_json, bcc_json, 'Already handled this, see below', 'outgoing',
                       DATE_ADD(COALESCE(received_at, sent_at), INTERVAL 10 MINUTE), DATE_ADD(COALESCE(sent_at, received_at), INTERVAL 10 MINUTE)
                  FROM ecw_email_messages WHERE id = {$latest}");
    $c = ecw_reply_collision($d, $latest);
    check($c !== null && $c['from'] === 'Bob Test' && $c['direction'] === 'outgoing' && str_contains($c['snippet'], 'Already handled'), 'Send check: a newer email is reported — who, and what they wrote');
    if (!ecw_private_mailboxes()) check(ecw_draft_can_send($org, $u['bob'], ecw_draft_load($org, $draftId)), 'without private mailboxes, an editor may send it too');
    else check(!ecw_draft_can_send($org, $u['bob'], ecw_draft_load($org, $draftId)), 'with private mailboxes, an editor may change it but not send it from another person\'s mailbox');

    group('contacts and companies');
    require_once dirname(__DIR__) . '/lib/contacts.php';
    $companies = array_column(ecw_companies_list($org, $u['ann']), null, 'domain');
    check(isset($companies['client.test']) && $companies['client.test']['people'] >= 2, 'a company per email domain, with its people');
    check(!isset($companies['acme.test']), 'our own domain is not a company');
    check(ecw_company_guess_name('acme-tools.co.uk') === 'Acme Tools', 'a readable name until someone types the real one');
    check(ecw_is_free_mail_domain('gmail.com') && ecw_company_detail($org, $u['ann'], 'gmail.com') === null, 'Gmail and the like are not companies');
    $co = ecw_company_detail($org, $u['ann'], 'client.test');
    check($co !== null && count($co['conversations']) >= 2 && count($co['cases']) >= 1, 'a company card shows its conversations and cases');
    check($co['stats']['received'] >= 2 && $co['stats']['last_from_them'] !== null, 'and how much mail came from them, and when');
    ecw_company_save($org, $u['ann'], 'client.test', ['name' => 'Client Ltd', 'website' => 'client.test', 'notes' => 'Pays late']);
    $co = ecw_company_detail($org, $u['bob'], 'client.test');
    check($co['name'] === 'Client Ltd' && $co['website'] === 'https://client.test' && $co['notes'] === 'Pays late', 'details colleagues add are shared');
    try { ecw_company_save($org, $u['ann'], 'gmail.com', ['name' => 'x']); check(false, 'a personal mail domain cannot be saved as a company'); }
    catch (InvalidArgumentException $e) { check(true, 'a personal mail domain cannot be saved as a company'); }
    ecw_person_save($org, $u['ann'], 'Customer@Client.test', ['name' => 'Kim Customer', 'job_title' => 'Buyer', 'phone' => '+30 210 000']);
    $person = ecw_person_detail($org, $u['ann'], 'customer@client.test');
    check($person !== null && $person['name'] === 'Kim Customer' && $person['company']['name'] === 'Client Ltd', 'a person card: the edited name and their company');
    check(count(array_filter(ecw_people_list($org, $u['ann'], 'kim'), static fn($p) => $p['email'] === 'customer@client.test')) === 1, 'people can be found by the name a colleague gave them');
    check(ecw_person_detail($org, $u['ann'], 'nobody@nowhere.test') === null, 'someone never seen on mail has no card');

    group('Outlook sync: folders, snooze, case categories');
    require_once dirname(__DIR__) . '/lib/folder_actions.php';
    require_once dirname(__DIR__) . '/lib/categories.php';
    try { ecw_folder_clean_name('a/b'); check(false, 'a folder name with / is refused'); } catch (InvalidArgumentException $e) { check(true, 'a folder name with / is refused'); }
    check(ecw_folder_clean_name("  Clients   2026 ") === 'Clients 2026', 'folder names are tidied');
    $pdo->prepare("INSERT INTO ecw_mail_folders (mailbox_id, provider_folder_id, name, folder_type, is_system, is_selected_for_sync) VALUES (?, 'inbox-x', 'Inbox', 'inbox', 1, 1), (?, 'proj-x', 'Projects', 'custom', 0, 1), (?, 'proj-a', 'Alpha', 'custom', 0, 1)")
        ->execute([$mb, $mb, $mb]);
    $pdo->prepare("UPDATE ecw_mail_folders SET parent_provider_folder_id = 'proj-x' WHERE mailbox_id = ? AND provider_folder_id = 'proj-a'")->execute([$mb]);
    $inboxF = (int)$pdo->query("SELECT id FROM ecw_mail_folders WHERE mailbox_id = {$mb} AND provider_folder_id = 'inbox-x'")->fetchColumn();
    $projF = (int)$pdo->query("SELECT id FROM ecw_mail_folders WHERE mailbox_id = {$mb} AND provider_folder_id = 'proj-x'")->fetchColumn();
    try { ecw_folder_rename($mb, $inboxF, 'Box'); check(false, 'Inbox cannot be renamed'); } catch (InvalidArgumentException $e) { check(true, 'Inbox cannot be renamed'); }
    try { ecw_folder_delete($mb, $inboxF); check(false, 'Inbox cannot be deleted'); } catch (InvalidArgumentException $e) { check(true, 'Inbox cannot be deleted'); }
    try { ecw_folder_delete($mb, $projF); check(false, 'a folder with subfolders is not deleted'); } catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'subfolders'), 'a folder with subfolders is not deleted'); }
    try { ecw_folder_create($mb, 'alpha', $projF); check(false, 'the same name twice in one place is refused'); } catch (InvalidArgumentException $e) { check(true, 'the same name twice in one place is refused'); }
    try { ecw_folder_of($mb, 999999999); check(false, 'a folder of another mailbox is refused'); } catch (InvalidArgumentException $e) { check(true, 'a folder of another mailbox is refused'); }

    check(ecw_thread_snooze($mb, $res['thread_id'], gmdate('Y-m-d H:i:s', time() + 3600)) && $pdo->query('SELECT snooze_moves_json FROM ecw_email_threads WHERE id = ' . $res['thread_id'])->fetchColumn() === null,
        'with "snooze in Outlook" off, a snooze stays in the app');
    ecw_thread_wake($res['thread_id']);
    check($pdo->query('SELECT snoozed_until FROM ecw_email_threads WHERE id = ' . $res['thread_id'])->fetchColumn() === null, 'waking clears the snooze');

    $caseNum = (string)$pdo->query('SELECT case_number FROM ecw_cases WHERE id = ' . $case)->fetchColumn();
    ecw_case_tags_save($case, ['pumps', 'Urgent'], $u['ann']);
    $want = ecw_categories_desired($caseThread);
    check($want[0] === $caseNum && in_array('pumps', $want, true) && in_array('urgent', $want, true), 'a case email carries the case number and the tags');
    check(ecw_categories_desired($looseThread) === [], 'an email in no case carries none');
    check((int)$pdo->query('SELECT COUNT(*) FROM ecw_email_messages WHERE email_thread_id = ' . $caseThread . ' AND categories_push_pending = 1')->fetchColumn() > 0,
        'changing tags marks the case\'s emails for Outlook');

    group('response times (SLA), business hours');
    require_once dirname(__DIR__) . '/lib/sla.php';
    $bh = ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'];
    $ts = static fn(string $s): int => strtotime($s . ' UTC');
    check(ecw_business_seconds($ts('2026-10-05 16:00'), $ts('2026-10-06 10:00'), $bh) === 7200, 'Monday 16:00 → Tuesday 10:00 is 2 business hours');
    check(ecw_business_seconds($ts('2026-10-09 16:00'), $ts('2026-10-12 10:00'), $bh) === 7200, 'Friday 16:00 → Monday 10:00 skips the weekend');
    check(ecw_business_add($ts('2026-10-09 16:00'), 7200, $bh) === $ts('2026-10-12 10:00'), 'a 2-hour target from Friday 16:00 is due Monday 10:00');
    check(ecw_business_add($ts('2026-10-10 12:00'), 3600, $bh) === $ts('2026-10-12 10:00'), 'mail on Saturday starts counting Monday 09:00');
    check(ecw_business_seconds($ts('2026-10-05 06:00'), $ts('2026-10-05 08:00'), $bh) === 0
        && ecw_business_seconds($ts('2026-10-05 18:00'), $ts('2026-10-05 23:00'), $bh) === 0, 'outside working hours nothing counts');
    check(ecw_business_seconds($ts('2026-10-05 06:00'), $ts('2026-10-05 07:00'), ['timezone' => 'Europe/Athens'] + $bh) === 3600, 'working hours follow the workspace time zone (09:00 Athens = 06:00 UTC)');
    $clock = ecw_sla_for_cases($org, [(int)$vc['id']])[(int)$vc['id']] ?? null;
    check($clock !== null && $clock['target_hours'] === 8.0 && in_array($clock['state'], ['ok', 'soon', 'overdue'], true), 'an open case with an unanswered email has a reply clock');
    ecw_db()->prepare('UPDATE ecw_case_groups SET sla_hours = 2 WHERE id = ?')->execute([$grp2]);
    check((ecw_sla_for_cases($org, [(int)$vc['id']])[(int)$vc['id']]['target_hours'] ?? null) === 2.0, 'its group\'s own target wins');
    $pdo->exec("UPDATE ecw_cases SET status = 'waiting' WHERE id = " . (int)$vc['id']);
    check(ecw_sla_for_cases($org, [(int)$vc['id']]) === [], 'waiting on the customer: no clock');
    $answered = $ingest('Question about delivery', 'buyer@answer.test', [], $now);
    $pdo->exec("INSERT INTO ecw_email_messages (mailbox_id, email_thread_id, provider_message_id, subject, from_email, from_name, to_json, cc_json, bcc_json, direction, received_at, sent_at)
                SELECT mailbox_id, email_thread_id, CONCAT('pans', id), subject, 'ann@acme.test', 'Ann', to_json, cc_json, bcc_json, 'outgoing',
                       DATE_ADD(COALESCE(received_at, sent_at), INTERVAL 5 MINUTE), DATE_ADD(COALESCE(sent_at, received_at), INTERVAL 5 MINUTE)
                  FROM ecw_email_messages WHERE id = " . (int)$answered['message_id']);
    $answeredCase = ecw_case_create($org, 'Delivery question', $u['ann']);
    ecw_case_link_thread($answeredCase, $answered['thread_id'], null, $u['ann']);
    check(ecw_sla_for_cases($org, [$answeredCase]) === [], 'answered last (our email is the newest): no clock');
    check(ecw_sla_label(['state' => 'overdue', 'left_seconds' => -5400]) === 'overdue 1.5 h' && ecw_sla_label(['state' => 'ok', 'left_seconds' => 1200]) === '20 min left', 'pill wording');

    group('read / unread everywhere');
    require_once dirname(__DIR__) . '/lib/unread.php';
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'inbox' . uniqid(), 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1]);
    $conv = 'c' . uniqid();
    $pid1 = 'p' . bin2hex(random_bytes(6));
    $msgRow = function (string $pid, string $when, bool $read) use ($conv): array {
        return ['provider_message_id' => $pid, 'internet_message_id' => '<' . $pid . '@x>', 'provider_conversation_id' => $conv,
            'subject' => 'Unread check', 'from_email' => 'stranger@other.test', 'from_name' => 'Stranger', 'to' => [], 'cc' => [], 'bcc' => [],
            'received_at' => $when, 'sent_at' => $when, 'is_read' => $read, 'has_attachments' => false, 'references' => []];
    };
    $first = ecw_ingest_message($mb, $inbox, 'inbox', $msgRow($pid1, gmdate('Y-m-d H:i:s', time() - 120), false));
    ecw_ingest_message($mb, $inbox, 'inbox', $msgRow('p' . bin2hex(random_bytes(6)), gmdate('Y-m-d H:i:s', time() - 60), false));
    $uThread = $first['thread_id'];
    $unreadOf = fn() => (int)$pdo->query('SELECT unread_count FROM ecw_email_threads WHERE id = ' . $uThread)->fetchColumn();
    $badgesBefore = ecw_unread_badges($org, $u['ann']);
    check($unreadOf() === 2 && $badgesBefore['mail'] >= 2, 'new Inbox mail counts on the Mail tab');
    check(($badgesBefore['latest']['thread_id'] ?? null) === $uThread, 'the newest unread email is offered for a desktop alert');

    $uCase = ecw_case_create($org, 'Unread case', $u['ann']);
    ecw_case_link_thread($uCase, $uThread, null, $u['ann']);
    check(ecw_unread_badges($org, $u['ann'])['cases'] >= 1, 'a case with unread mail counts on the Cases tab');
    $row = array_values(array_filter(ecw_case_list($org, ['user_id' => $u['ann'], 'status' => 'all'])['cases'], fn($c) => $c['id'] === $uCase))[0] ?? null;
    check(($row['unread_count'] ?? 0) === 2, 'the case list shows how many emails are unread');
    $tlU = ecw_case_timeline($org, $uCase, false, null, $u['ann']);
    check($tlU['columns'][0]['unread_count'] === 2 && count(array_filter($tlU['events'], fn($e) => !empty($e['bubble']['unread']))) === 2, 'the case timeline marks the unread emails');

    // Reading it in the case clears it in the inbox too: one read state.
    check(ecw_case_mark_read($org, $u['ann'], $uCase, null, true) === [$uThread], 'viewing the case marks its conversation read');
    check($unreadOf() === 0 && !ecw_thread_list($mb, ['folder_id' => $inbox])['threads'][0]['unread'], 'and the inbox shows it read');
    check((int)$pdo->query('SELECT COUNT(*) FROM ecw_email_messages WHERE read_push_pending = 1 AND email_thread_id = ' . $uThread)->fetchColumn() === 2, 'both changes wait to be pushed to Outlook');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_sync_queue WHERE mailbox_id = {$mb} AND job_type = 'read_state' AND status = 'queued'")->fetchColumn() === 1, 'one read_state job is queued (coalesced)');

    // A delta from Outlook with the old value must not undo it before the push.
    $stale = $msgRow($pid1, gmdate('Y-m-d H:i:s', time() - 120), false) + ['_source_fields' => ['isRead']];
    ecw_ingest_message($mb, $inbox, 'inbox', $stale);
    check($unreadOf() === 0, 'a stale provider update does not undo a change still waiting to be pushed');

    check(ecw_thread_mark_read($mb, $uThread, false) === 1 && $unreadOf() === 1, 'mark unread flags only the latest incoming email, like Outlook');
    check(ecw_thread_mark_read($mb, $uThread, false) === 0 && $unreadOf() === 1, 'marking unread again changes nothing more');
    if (ecw_private_mailboxes()) {
        check(ecw_case_mark_read($org, $u['bob'], $uCase, null, true) === [], 'with private mailboxes a colleague cannot change Ann\'s read state');
        check(ecw_unread_badges($org, $u['bob'])['mail'] === 0, 'and Ann\'s inbox is not counted for him');
    }
    check(ecw_case_mark_read($org, $u['ann'], $uCase, $uThread, true) === [$uThread] && $unreadOf() === 0, 'mark read again from the case');

    group('security: Microsoft sign-in');
    require_once dirname(__DIR__) . '/lib/sso.php';
    require_once dirname(__DIR__) . '/lib/case_extras.php';
    require_once dirname(__DIR__) . '/lib/mail_actions.php';
    @ecw_session_start();   // before $_SESSION is set below, so a late start cannot replace it
    $licensedNow = ecw_security_licensed();
    $dee = $ins('users', ['name' => 'Dee Admin', 'email' => 'dee' . uniqid() . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $dee, 'role' => 'admin']);
    $_SESSION = ['ecw_user_id' => $dee, 'ecw_organization_id' => $org];
    $tenant = '11111111-2222-3333-4444-555555555555';
    check(ecw_security_settings_save($org, ['sso' => 'on', 'sso_tenant' => 'not-a-guid']) !== null, 'a malformed tenant id is refused');
    check(ecw_security_settings_save($org, ['sso' => 'on', 'sso_tenant' => '9188040d-6c67-4c5b-b112-36a304b66dad']) !== null, 'the shared personal-account tenant is refused');
    check(ecw_security_settings_save($org, ['sso' => 'required', 'sso_tenant' => $tenant]) !== null, '"required" is refused while the admin has no Microsoft account linked');
    check(ecw_security_settings_save($org, ['sso' => 'on', 'sso_tenant' => strtoupper($tenant)]) === null && ecw_security_settings($org, true)['sso_tenant'] === $tenant, 'Microsoft sign-in turned on for the tenant');
    $annEmail = (string)$pdo->query('SELECT email FROM ecw_users WHERE id = ' . $u['ann'])->fetchColumn();
    $ssoTry = function (array $claims, string $mode = 'login', ?int $linkUser = null, int $age = 0): array {
        $_SESSION['ecw_sso'] = ['state' => 'st' . bin2hex(random_bytes(4)), 'verifier' => 'v', 'nonce' => 'n', 'at' => time() - $age, 'mode' => $mode, 'user_id' => $linkUser, 'next' => null];
        return @ecw_sso_callback(['state' => $_SESSION['ecw_sso']['state']], $claims);
    };
    if ($licensedNow) {
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'preferred_username' => strtoupper($annEmail), 'name' => 'Ann']);
        check($r['ok'] && (int)$_SESSION['ecw_user_id'] === $u['ann'], 'first Microsoft sign-in matches Ann by address in the trusted tenant');
        check((string)$pdo->query('SELECT sso_subject FROM ecw_users WHERE id = ' . $u['ann'])->fetchColumn() === $tenant . ':aaaaaaaa-0000-0000-0000-000000000001', 'and links her Microsoft account');
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'preferred_username' => 'renamed@acme.test']);
        check($r['ok'] && (int)$_SESSION['ecw_user_id'] === $u['ann'], 'later sign-ins use the linked account, even after an address change');
        $r = $ssoTry(['tid' => '99999999-2222-3333-4444-555555555555', 'oid' => 'bbbbbbbb-0000-0000-0000-000000000002', 'preferred_username' => (string)$pdo->query('SELECT email FROM ecw_users WHERE id = ' . $u['bob'])->fetchColumn()]);
        check(!$r['ok'], 'the same address from another tenant is not trusted');
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001'], 'login', null, 2000);
        check(!$r['ok'] && str_contains($r['error'], 'expired'), 'an old sign-in link is refused');
        $_SESSION['ecw_sso'] = ['state' => 'right', 'verifier' => 'v', 'nonce' => 'n', 'at' => time(), 'mode' => 'login', 'user_id' => null, 'next' => null];
        check(!@ecw_sso_callback(['state' => 'wrong'], ['tid' => $tenant, 'oid' => 'x'])['ok'] && !isset($_SESSION['ecw_sso']), 'a wrong state is refused and the handshake is used up');
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'cccccccc-0000-0000-0000-000000000003', 'preferred_username' => 'newcomer' . uniqid() . '@acme.test', 'name' => 'New Comer']);
        check(!$r['ok'], 'someone without an account is refused while automatic accounts are off');
        ecw_security_settings_save($org, ['sso' => 'on', 'sso_tenant' => $tenant, 'sso_create' => '1', 'sso_role' => 'admin']);
        check(ecw_security_settings($org)['sso_role'] === 'user', 'automatic accounts can never be admins');
        $newMail = 'newcomer' . uniqid() . '@acme.test';
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'cccccccc-0000-0000-0000-000000000004', 'preferred_username' => $newMail, 'name' => 'New Comer']);
        $newId = (int)$pdo->query('SELECT id FROM ecw_users WHERE email = ' . $pdo->quote($newMail))->fetchColumn();
        check($r['ok'] && $newId > 0 && ecw_user_role($newId, $org) === 'user', 'with automatic accounts on, a newcomer of the tenant gets a user account');
        // Linking from Account (signed in as Dee), any tenant.
        $_SESSION = ['ecw_user_id' => $dee, 'ecw_organization_id' => $org];
        $r = $ssoTry(['tid' => '9188040d-6c67-4c5b-b112-36a304b66dad', 'oid' => '00000000-0000-0000-4ad3-c0e1b2a39f07', 'preferred_username' => 'dee@outlook.test'], 'link', $dee);
        check($r['ok'] && str_contains($r['redirect'], 'sso=linked') && ecw_sso_linked($dee), 'an admin links a personal Microsoft account from Account');
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001'], 'link', $dee);
        check(str_contains($r['redirect'] ?? '', 'sso=taken'), 'an account already linked to Ann cannot be linked to Dee');
        check(ecw_security_settings_save($org, ['sso' => 'required', 'sso_tenant' => $tenant]) === null, 'with Dee linked, Microsoft sign-in can be required');
        check(ecw_security_password_blocked($u['bob']) && !ecw_security_password_blocked($dee), 'required: password sign-in refused for users, kept for admins');
        ecw_security_settings_save($org, ['sso' => 'off', 'sso_tenant' => $tenant]);
        $r = $ssoTry(['tid' => $tenant, 'oid' => 'aaaaaaaa-0000-0000-0000-000000000001']);
        check(!$r['ok'] && !ecw_security_password_blocked($u['bob']), 'off: Microsoft sign-in refused, passwords work');
        check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action IN ('sso.link', 'user.sso_create', 'sso.fail')")->fetchColumn() >= 4, 'links, the new account and refusals are in the audit log');
        check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'login.ok' AND detail LIKE '%microsoft%'")->fetchColumn() >= 2, 'Microsoft sign-ins are audited as such');
    } else {
        check(!ecw_sso_available(), 'without Business, Microsoft sign-in is not offered');
    }
    $_SESSION = ['ecw_user_id' => $dee, 'ecw_organization_id' => $org];

    group('security: legal hold');
    $hCase = ecw_case_create($org, 'Dispute with supplier', $u['ann']);
    $hMsg = $ingest('Claim 114', 'supplier@other.test', [$mbEmail], gmdate('Y-m-d H:i:s', time() - 3600));
    ecw_case_link_thread($hCase, $hMsg['thread_id'], null, $u['ann']);
    check(!ecw_case_hold_set($org, $hCase, true, 'x', $u['ann'])['ok'], 'only an admin can put a case on hold');
    check(ecw_case_hold_set($org, $hCase, true, 'Claim 2026-114', $dee)['ok'] && ecw_case_held($hCase) && ecw_thread_held($hMsg['thread_id']), 'an admin puts it on hold');
    try { ecw_case_soft_delete($org, $hCase, $u['ann']); check(false, 'a held case cannot be deleted'); }
    catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'legal hold'), 'a held case cannot be deleted'); }
    $other = ecw_case_create($org, 'Other case', $u['ann']);
    try { ecw_case_merge($org, $hCase, $other, $u['ann']); check(false, 'a held case cannot be merged away'); }
    catch (InvalidArgumentException $e) { check(true, 'a held case cannot be merged away'); }
    try { ecw_thread_delete_forever($mb, $hMsg['thread_id']); check(false, 'its emails cannot be deleted for good'); }
    catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), 'legal hold'), 'its emails cannot be deleted for good'); }
    check(count(array_filter(ecw_held_cases($org), fn($c) => (int)$c['id'] === $hCase && $c['legal_hold_note'] === 'Claim 2026-114')) === 1, 'the Security page lists it with the reason');
    check(ecw_case_hold_set($org, $hCase, false, '', $dee)['ok'] && !ecw_case_held($hCase), 'the hold is released');
    check(ecw_case_soft_delete($org, $other, $u['ann']), 'other cases delete as before');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'case.hold' AND target_id = {$hCase}")->fetchColumn() === 2, 'hold and release are in the audit log');

    group('security: retention');
    if ($licensedNow) {
        $old = ecw_case_create($org, 'Old deleted case', $u['ann']);
        ecw_case_soft_delete($org, $old, $u['ann']);
        $pdo->prepare('UPDATE ecw_cases SET deleted_at = ? WHERE id IN (?, ?)')->execute([gmdate('Y-m-d H:i:s', time() - 40 * 86400), $old, $other]);
        $recent = ecw_case_create($org, 'Recently deleted', $u['ann']);
        ecw_case_soft_delete($org, $recent, $u['ann']);
        $gone = $ingest('Deleted long ago', 'x@other.test', [$mbEmail], gmdate('Y-m-d H:i:s', time() - 200 * 86400));
        ecw_case_hold_set($org, $hCase, true, 'kept', $dee);
        $pdo->prepare('UPDATE ecw_email_messages SET provider_removed_at = ? WHERE id IN (?, ?)')->execute([gmdate('Y-m-d H:i:s', time() - 100 * 86400), $gone['message_id'], $hMsg['message_id']]);
        check(ecw_security_settings_save($org, ['trash_days' => 3, 'removed_days' => 90, 'audit_days' => 10]) === null
            && ecw_security_settings($org)['trash_days'] === 7 && ecw_security_settings($org)['audit_days'] === 90, 'too-short periods are raised to the minimum (7 days, audit 90)');
        ecw_security_settings_save($org, ['trash_days' => 30, 'audit_days' => 0]);
        $dry = ecw_retention_run($org, true);
        check($dry['cases'] === 2 && $dry['emails'] === 1 && ecw_case_load($org, $old, true) !== null, 'a preview counts without removing anything');
        $done = ecw_retention_run($org);
        check($done['cases'] === 2 && ecw_case_load($org, $old, true) === null && ecw_case_load($org, $recent, true) !== null, 'cases deleted over 30 days ago are removed for good; recent ones stay');
        $left = fn(int $id) => (int)$pdo->query('SELECT COUNT(*) FROM ecw_email_messages WHERE id = ' . $id)->fetchColumn();
        check($done['emails'] === 1 && $left($gone['message_id']) === 0 && $left($hMsg['message_id']) === 1, 'old copies of deleted emails go, except in a held case');
        check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'retention.purge'")->fetchColumn() === 1, 'the clean-up is in the audit log');
        check(ecw_retention_run($org) === ['audit' => 0, 'cases' => 0, 'emails' => 0], 'a second run finds nothing');
    } else {
        check(ecw_security_settings($org)['trash_days'] === 0, 'without Business nothing is removed');
    }

    group('security: backup password');
    require_once dirname(__DIR__) . '/lib/backup.php';
    $hadPw = ecw_app_setting('backup_password');
    check(ecw_backup_password_set('short', $org) !== null, 'a short backup password is refused');
    check(ecw_backup_password_set('correct horse battery', $org) === null && ecw_backup_password() === 'correct horse battery', 'the backup password is stored and read back');
    check(!str_contains((string)ecw_app_setting('backup_password'), 'horse'), 'and is stored encrypted');
    $zf = tempnam(sys_get_temp_dir(), 'ecwz');
    $z = new ZipArchive(); $z->open($zf, ZipArchive::OVERWRITE); $z->addFromString('database.sql', 'SECRET-ROW'); $z->setEncryptionName('database.sql', ZipArchive::EM_AES_256, ecw_backup_password()); $z->close();
    check(!str_contains((string)file_get_contents($zf), 'SECRET-ROW'), 'an encrypted backup does not contain the plain text');
    $z = new ZipArchive(); $z->open($zf); $z->setPassword('wrong password!!'); $wrong = @$z->getFromName('database.sql'); $z->setPassword('correct horse battery'); $right = $z->getFromName('database.sql'); $z->close(); @unlink($zf);
    check($wrong === false && $right === 'SECRET-ROW', 'it opens only with the right password');
    ecw_backup_password_set('', $org);
    check(ecw_backup_password() === null || (string)getenv('ECW_BACKUP_PASSWORD') !== '', 'the password can be removed');
    if ($hadPw !== null) ecw_app_setting_set('backup_password', $hadPw);
    check(is_array(ecw_db_encryption_status()) && isset(ecw_db_encryption_status()['tables']), 'encryption-at-rest status can be read');
    $_SESSION = [];
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
