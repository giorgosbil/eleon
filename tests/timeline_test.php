<?php declare(strict_types=1);
/**
 * Step 04 tests — the case timeline.
 *
 *   php tests/timeline_test.php
 *
 * The first group rebuilds the worked example from the step's own
 * specification and asserts the exact global ordering it prints. That test is
 * the product: if it passes, threads are genuinely sharing one chronological
 * axis; if it fails, the case view is lying about when things happened.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);
// This fixture models the original organization-shared mailbox mode and has no
// signed-in user. Private-mailbox isolation is exercised by account_scope_test.php.
putenv('ECW_PRIVATE_MAILBOXES=0');

require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/provider/MicrosoftGraphProvider.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0;
$fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function ok(string $w): void { global $pass; $pass++; echo "  ok    {$w}\n"; }
function bad(string $w, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
function check(bool $c, string $w, string $d = ''): void { $c ? ok($w) : bad($w, $d); }
function checkSame($e, $a, string $w): void
{
    $e === $a ? ok($w) : bad($w, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$pdo = ecw_db();
$pdo->beginTransaction();

try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Timeline Org', 'timezone' => 'Europe/Berlin']);
    $userId = $ins('users', ['name' => 'Giorgos', 'email' => 'g' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $mailboxId = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-tl',
        'email_address' => 'ops@zetisis.test', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $inboxId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'TL-IN', 'name' => 'Inbox',
        'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);
    $sentId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'TL-SENT', 'name' => 'Sent Items',
        'folder_type' => 'sent', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);

    $provider = new MicrosoftGraphProvider(['tenant_id' => 't', 'client_id' => 'c']);

    /** An incoming message: received_at is what places it on the axis. */
    $incoming = static function (string $id, string $conversation, string $from, string $at, string $body = 'text')
                use ($provider, $mailboxId, $inboxId): array {
        $message = $provider->normalizeMessage(FakeGraph::message([
            'id' => $id, 'conversationId' => $conversation, 'subject' => 'CMM axis problem',
            'from' => ['emailAddress' => ['address' => $from, 'name' => ucfirst(explode('@', $from)[0])]],
            'toRecipients' => [['emailAddress' => ['address' => 'ops@zetisis.test']]],
            // sent a minute earlier than delivery: proves which column is used
            'sentDateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($at . ' UTC') - 60),
            'receivedDateTime' => str_replace(' ', 'T', $at) . 'Z',
        ]));
        $message['body_html'] = '<p>' . $body . '</p>';
        return ecw_ingest_message($mailboxId, $inboxId, 'inbox', $message);
    };

    /** An outgoing message: sent_at places it. */
    $outgoing = static function (string $id, string $conversation, string $to, string $at, string $body = 'text', ?string $inReplyTo = null)
                use ($provider, $mailboxId, $sentId): array {
        $headers = $inReplyTo !== null ? [['name' => 'In-Reply-To', 'value' => $inReplyTo]] : [];
        $message = $provider->normalizeMessage(FakeGraph::message([
            'id' => $id, 'conversationId' => $conversation, 'subject' => 'CMM axis problem',
            'from' => ['emailAddress' => ['address' => 'ops@zetisis.test', 'name' => 'Ops']],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
            'sentDateTime' => str_replace(' ', 'T', $at) . 'Z',
            'receivedDateTime' => null,
            'internetMessageHeaders' => $headers,
        ]));
        $message['body_html'] = '<p>' . $body . '</p>';
        return ecw_ingest_message($mailboxId, $sentId, 'sent', $message);
    };

    // ------------------------------------- the step's own worked example
    group('chronological truth (the step\'s worked example)');

    $caseId = ecw_case_create($orgId, 'AXAKALIS — CMM X/Y Axis Problem', $userId);

    // Thread 1 — Customer: 09:00 question, 12:10 our update, 16:20 reply
    $c1 = $incoming('C1', 'CONV-CUSTOMER', 'john@customer.test', '2026-09-17 09:00:00', 'Customer asks question');
    $outgoing('C2', 'CONV-CUSTOMER', 'john@customer.test', '2026-09-17 12:10:00', 'We update customer');
    $incoming('C3', 'CONV-CUSTOMER', 'john@customer.test', '2026-09-17 16:20:00', 'Customer replies');

    // Thread 2 — Supplier A: 09:23 we ask, 11:45 they reply
    $a1 = $outgoing('A1', 'CONV-SUPPLIER-A', 'support@hexagon.test', '2026-09-17 09:23:00', 'We ask supplier');
    $incoming('A2', 'CONV-SUPPLIER-A', 'support@hexagon.test', '2026-09-17 11:45:00', 'Supplier replies');

    // Thread 3 — Supplier B: 10:15 we ask, 15:00 they reply
    $b1 = $outgoing('B1', 'CONV-SUPPLIER-B', 'sales@retrofit.test', '2026-09-17 10:15:00', 'We ask supplier B');
    $incoming('B2', 'CONV-SUPPLIER-B', 'sales@retrofit.test', '2026-09-17 15:00:00', 'Supplier B replies');

    ecw_case_link_thread($caseId, $c1['thread_id'], 'Customer', $userId);
    ecw_case_link_thread($caseId, $a1['thread_id'], 'Hexagon', $userId);
    ecw_case_link_thread($caseId, $b1['thread_id'], 'Retrofit Supplier', $userId);

    $timeline = ecw_case_timeline($orgId, $caseId);
    check($timeline !== null, 'the timeline loads');

    $actual = array_map(
        static fn(array $e): string => substr((string)$e['occurred_at'], 11, 5) . ' ' . $e['column'],
        $timeline['events']
    );
    // column 0 = Customer, 1 = Hexagon, 2 = Retrofit — exactly the step's table
    checkSame(
        ['09:00 0', '09:23 1', '10:15 2', '11:45 1', '12:10 0', '15:00 2', '16:20 0'],
        $actual,
        'every message lands at its true moment, in its own column'
    );

    $times = array_column($timeline['events'], 'occurred_at');
    $sorted = $times;
    sort($sorted);
    checkSame($sorted, $times, 'the stream is one globally ordered sequence, never packed per column');

    checkSame(3, count($timeline['columns']), 'one column per linked thread');
    checkSame(7, count($timeline['events']), 'no message is dropped or duplicated');

    // ----------------------------------------------------- effective time
    group('effective timestamp');
    $inbound = null;
    foreach ($timeline['events'] as $event) {
        if ($event['message_id'] === $c1['message_id']) { $inbound = $event; }
    }
    checkSame('2026-09-17 09:00:00', $inbound['occurred_at'] ?? null,
        'an incoming message is placed at received_at, not at when the sender sent it');

    $st = $pdo->prepare('SELECT sent_at, received_at, occurred_at FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
    $st->execute([$c1['message_id']]);
    $row = $st->fetch();
    check($row['sent_at'] !== $row['occurred_at'] && $row['received_at'] === $row['occurred_at'],
        'and the two columns really do differ, so the rule is doing work');

    // --------------------------------------------------------------- gaps
    group('time gaps');
    $gaps = array_values(array_filter(array_map(
        static fn(array $e) => $e['gap'],
        $timeline['events']
    )));
    checkSame(null, $timeline['events'][0]['gap'], 'the first event has no gap before it');
    checkSame('+23 min', $timeline['events'][1]['gap']['label'], 'a short gap is labelled in minutes');
    checkSame('+1h 30m', $timeline['events'][3]['gap']['label'], 'an hour-plus gap is labelled in hours and minutes');
    checkSame('xs', $timeline['events'][1]['gap']['size'], 'a sub-30-minute gap gets the smallest visible spacing');
    checkSame('sm', $timeline['events'][3]['gap']['size'], 'a 30 minute to 2 hour gap gets one step more');

    checkSame('+45 sec', ecw_format_elapsed(45), 'seconds');
    checkSame('+24 min', ecw_format_elapsed(24 * 60), 'minutes');
    checkSame('+3h 14m', ecw_format_elapsed(3 * 3600 + 14 * 60), 'hours and minutes');
    checkSame('+2 days 4h', ecw_format_elapsed(2 * 86400 + 4 * 3600), 'days and hours');
    checkSame('+1 day', ecw_format_elapsed(86400), 'exactly one day is singular and drops a zero remainder');

    $tight = ecw_timeline_gap('2026-09-17 09:00:00', '2026-09-17 09:02:00');
    checkSame(null, $tight['label'], 'messages two minutes apart get no separator at all');
    checkSame('none', $tight['size'], 'and no extra vertical space');

    $overnight = ecw_timeline_gap('2026-09-17 18:00:00', '2026-09-19 22:00:00');
    checkSame('xl', $overnight['size'], 'a multi-day silence is compressed to the largest bucket');
    checkSame('+2 days 4h', $overnight['label'], 'but the true elapsed time is still printed exactly');
    check($overnight['new_day'], 'a date change is flagged so the rail can show it');

    // The five visible buckets, exactly at their boundaries. ecw_gap_size()
    // itself knows nothing about the "under 5 minutes" cutoff — that is
    // ecw_timeline_gap()'s job, tested above via $tight.
    checkSame('xs', ecw_gap_size(1799), 'a minute under 30 is still the smallest visible bucket');
    checkSame('sm', ecw_gap_size(1800), '30 minutes exactly rolls into the next bucket');
    checkSame('sm', ecw_gap_size(7199), 'a second under 2 hours stays in that bucket');
    checkSame('md', ecw_gap_size(7200), '2 hours exactly rolls into the next bucket');
    checkSame('md', ecw_gap_size(28799), 'a second under 8 hours stays in that bucket');
    checkSame('lg', ecw_gap_size(28800), '8 hours exactly rolls into the next bucket');
    checkSame('lg', ecw_gap_size(86399), 'a second under a day stays in that bucket');
    checkSame('xl', ecw_gap_size(86400), 'a full day rolls into the largest bucket');

    // A day boundary always separates, even when only minutes apart.
    $midnight = ecw_timeline_gap('2026-09-17 23:59:00', '2026-09-18 00:01:00');
    check($midnight['label'] !== null && $midnight['new_day'],
        'two minutes across midnight still separates, because the date changed');

    // ------------------------------------------------------ reply context
    group('reply reference');
    $st = $pdo->prepare('SELECT internet_message_id FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
    $st->execute([$c1['message_id']]);
    $customerMessageId = (string)$st->fetchColumn();

    $answer = $outgoing('C4', 'CONV-CUSTOMER', 'john@customer.test', '2026-09-17 17:00:00',
        'Answering the question', $customerMessageId);

    $timeline = ecw_case_timeline($orgId, $caseId);
    $answerEvent = null;
    foreach ($timeline['events'] as $event) {
        if ($event['message_id'] === $answer['message_id']) { $answerEvent = $event; }
    }
    $reference = $answerEvent['bubble']['reply_to'] ?? null;
    check($reference !== null, 'a reply carries a structured reference to what it answers');
    checkSame($c1['message_id'], $reference['id'] ?? null, 'pointing at the right message');
    check(str_contains((string)($reference['snippet'] ?? ''), 'Customer asks question'),
        'with a plain-text snippet of it');
    check(!str_contains((string)($reference['snippet'] ?? ''), '<'), 'the snippet is never markup');
    check($reference['in_timeline'] === true, 'and is marked clickable because it is on this timeline');

    // A reference to something we never received must not become a dead link.
    $orphan = $outgoing('C5', 'CONV-CUSTOMER', 'john@customer.test', '2026-09-17 18:00:00',
        'Replying to something forwarded', '<never-seen@elsewhere.test>');
    $timeline = ecw_case_timeline($orgId, $caseId);
    foreach ($timeline['events'] as $event) {
        if ($event['message_id'] === $orphan['message_id']) {
            checkSame(null, $event['bubble']['reply_to'], 'a reference we do not hold locally is simply absent');
        }
    }

    // ------------------------------------------------------------ columns
    group('columns');
    $columns = ecw_case_columns($caseId);
    checkSame(['Customer', 'Hexagon', 'Retrofit Supplier'], array_column($columns, 'label'),
        'renamed columns keep their labels, in link order');

    check(count($columns[1]['participants']) > 0, 'a column lists its participants');
    check(in_array('support@hexagon.test', array_column($columns[1]['participants'], 'email'), true),
        'the thread column includes the external participant');

    // Auto-labelling, with no rename
    $auto = $incoming('X1', 'CONV-AUTO', 'anna@newsupplier.test', '2026-09-17 20:00:00');
    $autoCase = ecw_case_create($orgId, 'Auto label case', $userId);
    ecw_case_link_thread($autoCase, $auto['thread_id'], null, $userId);
    $autoColumns = ecw_case_columns($autoCase);
    checkSame($autoColumns[0]['subject'], $autoColumns[0]['label'],
        'an unnamed column is labelled by thread subject');
    check(!$autoColumns[0]['renamed'], 'and is marked as not renamed, so the UI can offer to name it');

    // ---------------------------------------------------- hide and unhide
    group('hiding a column');
    ecw_case_thread_update($orgId, $caseId, $a1['thread_id'], ['is_hidden' => true]);
    $hidden = ecw_case_timeline($orgId, $caseId);

    checkSame(3, count($hidden['columns']), 'a hidden column is still linked to the case');
    check($hidden['columns'][1]['is_hidden'], 'and is reported hidden so it can be brought back');
    $threadIds = array_unique(array_column($hidden['events'], 'thread_id'));
    check(!in_array($a1['thread_id'], $threadIds, true), 'its messages leave the axis while it is hidden');

    // Gaps must be recomputed for what is actually shown, not inherited.
    $visibleTimes = array_column($hidden['events'], 'occurred_at');
    $recomputed = true;
    for ($i = 1; $i < count($hidden['events']); $i++) {
        $gap = $hidden['events'][$i]['gap'];
        if ($gap === null) { continue; }
        $expected = strtotime($visibleTimes[$i] . ' UTC') - strtotime($visibleTimes[$i - 1] . ' UTC');
        if ($gap['seconds'] !== $expected) { $recomputed = false; }
    }
    check($recomputed, 'gaps describe the messages actually on screen, not the hidden ones');

    ecw_case_thread_update($orgId, $caseId, $a1['thread_id'], ['is_hidden' => false]);
    $restored = ecw_case_timeline($orgId, $caseId);
    check(in_array($a1['thread_id'], array_column($restored['events'], 'thread_id'), true),
        'unhiding restores it, because nothing was ever unlinked');

    // -------------------------------------------------- single-column view
    group('one thread at a time (narrow screens)');
    $single = ecw_case_timeline($orgId, $caseId, false, $c1['thread_id']);
    checkSame([$c1['thread_id']], array_values(array_unique(array_column($single['events'], 'thread_id'))),
        'only the chosen thread is streamed');
    checkSame(3, count($single['columns']), 'the other columns are still offered in the picker');
    check(count($single['events']) < count($restored['events']), 'fewer events than the full view');
    checkSame(0, $single['events'][0]['column'], 'the chosen thread renders in the first column');

    // ------------------------------------------------------- case actions
    group('case header and actions');
    $summary = ecw_case_summary($orgId, $caseId);
    checkSame('AXAKALIS — CMM X/Y Axis Problem', $summary['name'], 'the summary carries the name');
    checkSame(3, $summary['thread_count'], 'and the thread count');
    checkSame('Europe/Berlin', $summary['timezone'], "and the organization's timezone for rendering");
    check($summary['last_activity'] !== null, 'and the last activity across every linked thread');

    ecw_case_update($orgId, $caseId, ['name' => 'AXAKALIS — CMM axis, renamed'], $userId);
    checkSame('AXAKALIS — CMM axis, renamed', ecw_case_summary($orgId, $caseId)['name'], 'a case can be renamed');

    $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('case_events') . "` WHERE case_id = ? AND event_type = 'CASE_RENAMED'");
    $st->execute([$caseId]);
    checkSame(1, (int)$st->fetchColumn(), 'and the rename is on the audit trail');

    ecw_case_update($orgId, $caseId, ['status' => 'closed'], $userId);
    $closed = ecw_case_load($orgId, $caseId);
    checkSame('closed', $closed['status'], 'status can be changed');
    check($closed['closed_at'] !== null, 'closing stamps closed_at');

    ecw_case_update($orgId, $caseId, ['status' => 'open'], $userId);
    check(ecw_case_load($orgId, $caseId)['closed_at'] === null, 'reopening clears it, so the two cannot disagree');

    ecw_case_update($orgId, $caseId, ['status' => 'not-a-status'], $userId);
    checkSame('open', ecw_case_load($orgId, $caseId)['status'], 'an unknown status is ignored, not stored');

    ecw_case_thread_update($orgId, $caseId, $a1['thread_id'], ['display_name' => 'Hexagon Italy']);
    checkSame('Hexagon Italy', ecw_case_columns($caseId)[1]['label'], 'a column can be renamed');

    // ------------------------------------------------------- adding threads
    group('adding a thread');
    $loose = $incoming('L1', 'CONV-LOOSE', 'someone@else.test', '2026-09-17 21:00:00');
    $linkable = ecw_case_linkable_threads($orgId, '');
    $linkableIds = array_column($linkable, 'id');
    check(in_array($loose['thread_id'], array_map('intval', $linkableIds), true),
        'an unlinked thread is offered');
    check(!in_array($c1['thread_id'], array_map('intval', $linkableIds), true),
        'a thread already in a case is not offered, because linking it would fail');

    $found = ecw_case_linkable_threads($orgId, 'someone@else.test');
    checkSame(1, count($found), 'threads can be found by participant address');

    checkSame('[CASE-00001] Scirocco RC1 controller issue',
        ecw_case_subject_tag('CASE-00001', 'Scirocco RC1 controller issue'),
        'a new conversation gets the case number in its subject');
    checkSame('[CASE-00001] Already tagged',
        ecw_case_subject_tag('CASE-00001', '[CASE-00001] Already tagged'),
        'and it is never added twice');

    // --------------------------------------------------- bubble rendering
    // Step 05A: the case timeline must render exactly like the reading pane —
    // a signature collapsible and separate from the body, whitespace-dense.
    group('compact bubble rendering');
    $padded = $incoming('P1', 'CONV-PADDED', 'padded@customer.test', '2026-09-17 20:00:00');
    $pdo->prepare('UPDATE `' . ecw_table('email_messages') . '` SET body_html = ? WHERE id = ?')->execute([
        '<p>Hi team,</p><br><br><br><br><br><p>See attached.</p>'
        . '<div id="signature"><p>Best,</p><p>Jane</p></div>',
        $padded['message_id'],
    ]);
    ecw_case_link_thread($caseId, $padded['thread_id'], 'Padded', $userId);
    $paddedTimeline = ecw_case_timeline($orgId, $caseId);
    $paddedEvent = null;
    foreach ($paddedTimeline['events'] as $event) {
        if ($event['message_id'] === $padded['message_id']) { $paddedEvent = $event; }
    }
    check($paddedEvent !== null, 'the padded message reaches the timeline');
    check(!str_contains($paddedEvent['bubble']['body_html'], 'Best,'),
        'the signature is split out of the bubble body, same as the reading pane');
    check(str_contains($paddedEvent['bubble']['signature_html'] ?? '', 'Best,')
        && str_contains($paddedEvent['bubble']['signature_html'] ?? '', 'Jane'),
        'and travels as its own field, sanitized the same way');
    check(!preg_match('/(<br>\s*){3,}/', $paddedEvent['bubble']['body_html']),
        'a run of blank lines does not survive into the bubble body');

    // ------------------------------------------------- one bubble per email
    group('each email once in its column');
    $d1 = $incoming('D1', 'CONV-DUP', 'john@customer.test', '2026-09-18 09:00:00', 'Asked once');
    $d2 = $outgoing('D2', 'CONV-DUP', 'john@customer.test', '2026-09-18 10:00:00', 'Answered once');
    $dupCase = ecw_case_create($orgId, 'Copies of one email', $userId);
    ecw_case_link_thread($dupCase, $d1['thread_id'], null, $userId);
    $copy = static function (int $messageId, array $changes) use ($pdo, $ins): void {
        $row = $pdo->query('SELECT * FROM `' . ecw_table('email_messages') . '` WHERE id = ' . $messageId)->fetch();
        unset($row['id'], $row['occurred_at']);   // occurred_at is generated from sent_at / received_at
        $ins('email_messages', array_merge($row, $changes));
    };
    // Outlook re-created it under a new id: the old row stays, removed.
    $copy($d1['message_id'], ['provider_message_id' => 'D1-old', 'provider_removed_at' => '2026-09-18 09:30:00']);
    // Sent to oneself too: the Inbox copy of the reply.
    $copy($d2['message_id'], ['provider_message_id' => 'D2-inbox', 'direction' => 'incoming', 'mail_folder_id' => $inboxId]);
    // A draft of the next reply, saved in Outlook.
    $copy($d2['message_id'], ['provider_message_id' => 'D3-draft', 'internet_message_id' => '<d3-draft@x>', 'direction' => 'draft', 'sent_at' => '2026-09-18 11:00:00']);
    $dupEvents = ecw_case_timeline($orgId, $dupCase)['events'];
    $ids = array_column($dupEvents, 'message_id');
    check($ids === [$d1['message_id'], $d2['message_id']], 'two emails, two bubbles: no removed copy, no second copy of mail to oneself, no draft', json_encode($ids));
    check(!$dupEvents[0]['bubble']['removed'] && $dupEvents[1]['bubble']['direction'] === 'outgoing', 'the copy still in the mailbox is kept, the sent one for a reply');
    $pdo->exec('UPDATE `' . ecw_table('email_messages') . "` SET provider_removed_at = '2026-09-18 12:00:00' WHERE id = " . $d1['message_id']);
    $gone = ecw_case_timeline($orgId, $dupCase)['events'];
    check(count($gone) === 2 && $gone[0]['bubble']['removed'], 'an email removed in Outlook with no other copy still shows, once, as removed');

    // ---------------------------------------------------------- isolation
    group('isolation');
    $otherOrg = $ins('organizations', ['name' => 'Other Timeline Org']);
    check(ecw_case_timeline($otherOrg, $caseId) === null, "another organization cannot load this case's timeline");
    check(ecw_case_summary($otherOrg, $caseId) === null, 'nor its summary');
    check(!ecw_case_thread_update($otherOrg, $caseId, $a1['thread_id'], ['is_hidden' => true]),
        'nor hide one of its columns');
    check(!ecw_case_update($otherOrg, $caseId, ['name' => 'hijacked'], null), 'nor rename it');
    checkSame('AXAKALIS — CMM axis, renamed', ecw_case_summary($orgId, $caseId)['name'], 'and the case is untouched');

} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
