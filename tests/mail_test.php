<?php declare(strict_types=1);
/**
 * Step 03 tests — inbox, search, composer.
 *
 *   php tests/mail_test.php
 *
 * The parts worth reading are the ones pinning behaviour that is easy to get
 * subtly wrong and hard to notice: keyset pagination that neither skips nor
 * repeats a row when mail arrives mid-scroll, reply-all that does not mail the
 * user their own message, search that does not silently return nothing for a
 * short term, and sanitization that survives the usual payloads.
 *
 * Database writes are real and rolled back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/compose.php';
require_once dirname(__DIR__) . '/lib/signature.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/body.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0;
$fail = 0;

function group(string $name): void { echo "\n=== {$name} ===\n"; }
function ok(string $what): void   { global $pass; $pass++; echo "  ok    {$what}\n"; }
function bad(string $what, string $detail = ''): void { global $fail; $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function check(bool $cond, string $what, string $detail = ''): void { $cond ? ok($what) : bad($what, $detail); }
function checkSame($expected, $actual, string $what): void
{
    $expected === $actual ? ok($what)
        : bad($what, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

ecw_set_graph_config_override([
    'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's',
    'redirect_uri' => 'https://example.test/cb',
]);

$pdo = ecw_db();
$pdo->beginTransaction();

try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Mail Test Org']);
    $userId = $ins('users', ['name' => 'Tester', 'email' => 'u' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $mailboxId = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-mail',
        'email_address' => 'ops@example.com', 'sync_status' => 'connected',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $inboxId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'F-IN', 'name' => 'Inbox',
        'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);
    $sentId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'F-SENT', 'name' => 'Sent Items',
        'folder_type' => 'sent', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);

    $provider = new MicrosoftGraphProvider(ecw_graph_config());

    // ------------------------------------------------------------ sanitize
    group('sanitization');
    $dirty = '<p onclick="steal()">hello <script>alert(1)</script><b>bold</b></p>'
           . '<iframe src="https://evil.test"></iframe><a href="javascript:alert(1)">click</a>';
    $clean = ecw_sanitize_email_html($dirty);
    check(!str_contains($clean, '<script'), 'script tags are removed');
    check(!str_contains($clean, 'onclick'), 'event handler attributes are removed');
    check(!str_contains($clean, '<iframe'), 'iframes are removed');
    check(!str_contains($clean, 'javascript:'), 'javascript: URLs are removed');
    check(str_contains($clean, '<b>bold</b>'), 'formatting survives');

    $withImage = '<p>hi</p><img src="https://tracker.test/pixel.gif">';
    $blocked = ecw_sanitize_email_html($withImage);
    check(!preg_match('/(?<![-\w])src="https:/', $blocked), 'a remote image src is defused by default');
    check(str_contains($blocked, 'data-ecw-src="https://tracker.test/pixel.gif"'),
        'the original src is preserved so the reader can choose to load it');
    $allowed = ecw_sanitize_email_html($withImage, true);
    check(str_contains($allowed, 'src="https://tracker.test/pixel.gif"'), 'images load once the reader asks');

    $cid = ecw_sanitize_email_html('<img src="cid:logo123">', false, static fn(string $c): ?string => 'api/x?cid=' . $c);
    check(str_contains($cid, 'api/x?cid=logo123'), 'inline cid images resolve to our own endpoint');
    $unresolved = ecw_sanitize_email_html('<img src="cid:missing">', false, static fn(string $c): ?string => null);
    check(str_contains($unresolved, ECW_PLACEHOLDER_PIXEL) && !str_contains($unresolved, 'cid:'),
        'an unresolvable cid renders a blank placeholder, not a broken image');

    check(ecw_sanitize_compose_html('<p>ok<script>x</script></p>') === '<p>ok</p>',
        'composer output is sanitized too, not trusted because an editor produced it');

    // ------------------------------------------------------- search parser
    group('search syntax');
    $f = ecw_search_parse('from:support@hexagon.com subject:scirocco "RTune calibration" case:CASE-00142 after:2026-09-01 has:attachment is:unread urgent');
    checkSame(['support@hexagon.com'], $f['from'], 'from: is parsed');
    checkSame(['scirocco'], $f['subject'], 'subject: is parsed');
    checkSame(['RTune calibration'], $f['phrases'], 'a quoted phrase is parsed');
    checkSame(['CASE-00142'], $f['case'], 'case: is parsed');
    checkSame('2026-09-01 00:00:00', $f['after'], 'after: becomes a UTC datetime');
    checkSame(true, $f['has_attachment'], 'has:attachment is parsed');
    checkSame(true, $f['unread'], 'is:unread is parsed');
    checkSame(['urgent'], $f['keywords'], 'bare words become keywords');

    $f = ecw_search_parse('before:2026-09-18');
    checkSame('2026-09-18 23:59:59', $f['before'], 'before: covers the whole day, not midnight');

    $f = ecw_search_parse('is:unlinked');
    checkSame(false, $f['linked'], 'is:unlinked is the negation of is:linked');

    $f = ecw_search_parse('note:something');
    checkSame(['note:something'], $f['keywords'], 'an unknown prefix is searched as text, not dropped');

    check(ecw_search_is_empty(ecw_search_parse('   ')), 'an empty query asks for nothing');
    check(!ecw_search_is_empty(ecw_search_parse('is:unread')), 'a filter-only query is not empty');

    // the full-text floor
    check(!ecw_ft_indexable('CM'), 'a 2-letter term is under the index floor (min_token_size is 3)');
    check(ecw_ft_indexable('scirocco'), 'a normal term is indexable');
    check(!ecw_ft_indexable('with'), 'a stopword is not indexable');
    $conditions = ecw_search_conditions(ecw_search_parse('CM'));
    check(!$conditions['uses_fulltext'], 'a short term does not go to full text');
    check(str_contains(implode(' ', $conditions['where']), 'LIKE'),
        'it falls back to LIKE, so the user gets results instead of silence');
    $conditions = ecw_search_conditions(ecw_search_parse('scirocco'));
    check($conditions['uses_fulltext'], 'a normal term does use the full-text index');

    // --------------------------------------------------------- ingest+list
    group('thread list');
    $mk = static function (string $id, string $subject, string $when, array $extra = []) use ($provider): array {
        return $provider->normalizeMessage(FakeGraph::message(array_merge([
            'id' => $id, 'subject' => $subject, 'conversationId' => 'conv-' . $id,
            'receivedDateTime' => $when, 'sentDateTime' => $when,
        ], $extra)));
    };

    $t1 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $mk('L1', 'Machine problem', '2026-09-17T14:42:00Z'));
    $t2 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $mk('L2', 'Re: Scirocco controller', '2026-09-17T13:07:00Z', [
        'from' => ['emailAddress' => ['address' => 'support@hexagon.com', 'name' => 'Hexagon Support']],
        'hasAttachments' => true,
    ]));

    $list = ecw_thread_list($mailboxId, []);
    checkSame(2, count($list['threads']), 'both threads are listed');
    checkSame('Machine problem', $list['threads'][0]['subject'], 'newest thread is first');
    check($list['threads'][0]['unread'], 'an unread incoming message marks the thread unread');
    check($list['threads'][1]['has_attachments'], 'the attachment marker comes from the cache');
    checkSame('Hexagon Support', $list['threads'][1]['from_name'], 'the sender comes from the cache');

    // the cached preview must not contain markup
    $withBody = $mk('L3', 'Body test', '2026-09-17T15:00:00Z');
    $withBody['body_html'] = '<p>Can you please let us know<br>the status?</p><script>x</script>';
    $withBody['body_text'] = '';
    $r3 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $withBody);
    $st = $pdo->prepare('SELECT latest_preview FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
    $st->execute([$r3['thread_id']]);
    $preview = (string)$st->fetchColumn();
    check(!str_contains($preview, '<'), 'the preview is plain text, never markup');
    check(str_contains($preview, 'Can you please let us know the status?'),
        'line breaks become spaces instead of running words together');

    // --------------------------------------------------------- pagination
    group('keyset pagination');
    for ($i = 0; $i < 8; $i++) {
        ecw_ingest_message($mailboxId, $inboxId, 'inbox',
            $mk('P' . $i, 'Paged ' . $i, sprintf('2026-09-1%dT08:00:00Z', $i % 9)));
    }

    $page1 = ecw_thread_list($mailboxId, ['limit' => 4]);
    checkSame(4, count($page1['threads']), 'the first page honours the limit');
    check($page1['next_cursor'] !== null, 'a cursor is returned while more remain');

    $page2 = ecw_thread_list($mailboxId, ['limit' => 4, 'cursor' => $page1['next_cursor']]);
    $ids1 = array_column($page1['threads'], 'id');
    $ids2 = array_column($page2['threads'], 'id');
    checkSame([], array_intersect($ids1, $ids2), 'pages do not repeat a thread');

    // A message arriving between pages must not shift the second page.
    ecw_ingest_message($mailboxId, $inboxId, 'inbox', $mk('NEW', 'Arrived mid-scroll', '2026-09-18T09:00:00Z'));
    $page2again = ecw_thread_list($mailboxId, ['limit' => 4, 'cursor' => $page1['next_cursor']]);
    checkSame($ids2, array_column($page2again['threads'], 'id'),
        'new mail arriving mid-scroll does not shift or duplicate the next page');

    check(ecw_decode_cursor('not-a-cursor') === null, 'a malformed cursor is rejected, not trusted');

    // ------------------------------------------------------------ sent items
    group('sent items');
    // We wrote on the 10th; the customer answered on the 30th. Sent Items must
    // show OUR email of the 10th, not their reply as if we had sent it today.
    $ours = ['address' => 'ops@example.com', 'name' => 'Ops'];
    $sentA = ecw_ingest_message($mailboxId, $sentId, 'sent', $mk('SX1', 'Calibration offer', '2026-09-10T08:00:00Z', [
        'conversationId' => 'conv-sent-x', 'from' => ['emailAddress' => $ours],
        'toRecipients' => [['emailAddress' => ['address' => 'buyer@customer.com', 'name' => 'Buyer']]],
        'body' => ['contentType' => 'text', 'content' => 'Please find our offer attached.'], 'bodyPreview' => 'Please find our offer attached.',
    ]));
    ecw_ingest_message($mailboxId, $inboxId, 'inbox', $mk('SX2', 'Re: Calibration offer', '2026-09-30T09:00:00Z', [
        'conversationId' => 'conv-sent-x', 'from' => ['emailAddress' => ['address' => 'buyer@customer.com', 'name' => 'Buyer']],
        'toRecipients' => [['emailAddress' => $ours]],
    ]));
    ecw_ingest_message($mailboxId, $sentId, 'sent', $mk('SY1', 'Visit next week', '2026-09-12T08:00:00Z', [
        'conversationId' => 'conv-sent-y', 'from' => ['emailAddress' => $ours],
        'toRecipients' => [['emailAddress' => ['address' => 'a@customer.com', 'name' => 'Anna']], ['emailAddress' => ['address' => 'b@customer.com']]],
    ]));
    $sentList = ecw_thread_list($mailboxId, ['folder_id' => $sentId])['threads'];
    $byId = array_column($sentList, null, 'id');
    $rowX = $byId[$sentA['thread_id']] ?? null;
    check($rowX !== null && str_starts_with((string)$rowX['latest_at'], '2026-09-10 08:00'), 'Sent Items dates a conversation by our sent email, not by the reply received later');
    checkSame('To: Buyer', $rowX['from_name'] ?? null, 'Sent Items shows who it went to, not who wrote last');
    check(str_contains((string)($rowX['preview'] ?? ''), 'our offer') && empty($rowX['unread']), 'Sent Items shows our text, and the received reply does not make it bold');
    $order = array_values(array_intersect(array_column($sentList, 'id'), [$sentA['thread_id'], $byId ? array_key_first(array_filter($byId, fn($r) => $r['subject'] === 'Visit next week')) : 0]));
    checkSame('Visit next week', $byId[$order[0]]['subject'] ?? null, 'Sent Items is in the order we sent, the reply does not lift an older one to the top');
    check(str_starts_with((string)($byId[$order[0]]['from_name'] ?? ''), 'To: Anna, b@customer.com'), 'several recipients are listed, by name or address');
    $sentByType = ecw_thread_list($mailboxId, ['folder_type' => 'sent'])['threads'];
    checkSame(array_column($sentList, 'latest_at'), array_column($sentByType, 'latest_at'), 'asking for the Sent folder by type gives the same list');
    $inboxRow = array_column(ecw_thread_list($mailboxId, ['folder_id' => $inboxId])['threads'], null, 'id')[$sentA['thread_id']] ?? null;
    check($inboxRow !== null && str_starts_with((string)$inboxRow['latest_at'], '2026-09-30') && $inboxRow['from_name'] === 'Buyer', 'the Inbox still shows the reply that arrived');
    $p1 = ecw_thread_list($mailboxId, ['folder_id' => $sentId, 'limit' => 1]);
    $p2 = ecw_thread_list($mailboxId, ['folder_id' => $sentId, 'limit' => 1, 'cursor' => $p1['next_cursor']]);
    check($p1['next_cursor'] !== null && $p2['threads'] !== [] && $p1['threads'][0]['id'] !== $p2['threads'][0]['id'] && $p2['threads'][0]['latest_at'] <= $p1['threads'][0]['latest_at'], 'Sent Items pages by the sent date without repeating');

    // ------------------------------------------------------------- search
    group('searching the local store');
    $hit = ecw_thread_list($mailboxId, ['query' => 'from:support@hexagon.com']);
    checkSame(1, count($hit['threads']), 'from: finds the sender');
    checkSame('Scirocco controller', $hit['threads'][0]['subject'], 'and it is the right thread');

    $hit = ecw_thread_list($mailboxId, ['query' => 'to:ops@example.com']);
    check(count($hit['threads']) > 0, 'to: is searchable via the participant index');

    $hit = ecw_thread_list($mailboxId, ['query' => 'has:attachment']);
    checkSame(1, count($hit['threads']), 'has:attachment filters');

    $hit = ecw_thread_list($mailboxId, ['query' => 'subject:scirocco']);
    checkSame(1, count($hit['threads']), 'subject: filters');

    $hit = ecw_thread_list($mailboxId, ['query' => 'is:unlinked']);
    check(count($hit['threads']) > 0, 'is:unlinked lists mail with no case');

    $caseId = ecw_case_create($orgId, 'AXAKALIS CMM', $userId);
    ecw_case_link_thread($caseId, $t2['thread_id'], 'Hexagon', $userId);

    $hit = ecw_thread_list($mailboxId, ['query' => 'is:linked']);
    checkSame(1, count($hit['threads']), 'is:linked lists only mail attached to a case');
    check($hit['threads'][0]['case'] !== null, 'the case marker travels with the list row');
    checkSame('AXAKALIS CMM', $hit['threads'][0]['case']['name'], 'and names the case without opening it');

    $hit = ecw_thread_list($mailboxId, ['query' => 'case:' . $hit['threads'][0]['case']['number']]);
    checkSame(1, count($hit['threads']), 'case: finds mail by case number');

    // --------------------------------------------- search at large volumes
    // (tools/perf/bench.php measures these on 110,000 emails.) Full text does
    // not see rows inside this test's transaction, so the full-text routes are
    // checked by the SQL they produce; the LIKE routes by their results.
    group('search at large volumes');
    check(str_contains(ecw_search_people_where('hexagon')[0], 'MATCH'), 'a whole word finds people through the full-text index');
    check(str_contains(ecw_search_people_where('support@hexagon.com')[0], 'p.email = ?'), 'a full address is one exact lookup');
    check(str_contains(ecw_search_people_where('he')[0], 'LIKE'), 'a fragment full text cannot index still works, with LIKE');
    check(!ecw_search_conditions(ecw_search_parse('scirocco'))['walk'], 'a rare word is fetched from the full-text index');
    $c = ecw_search_conditions(ecw_search_parse('scirocco'), ['scirocco']);
    check($c['walk'] && str_contains(implode(' ', $c['where']), 'm.body_text LIKE'),
        'a common word is checked with LIKE on the emails being read, not fetched from the index');
    $c = ecw_search_conditions(ecw_search_parse('is:unlinked'));
    check($c['message_where'] === [] && count($c['thread_where']) === 1, 'is:unlinked is tested on the conversation, without reading its emails');
    $hit = ecw_thread_list($mailboxId, ['query' => 'is:unlinked']);
    check(!in_array((int)$t2['thread_id'], array_column($hit['threads'], 'id'), true), 'and leaves out the conversation that is in a case');
    $c = ecw_search_conditions(ecw_search_parse('"Scirocco controller"'));
    check(!$c['walk'] && in_array('+"Scirocco controller"', $c['params'], true), 'a phrase of indexed words is found by the full-text index as a phrase, not by reading every email');
    $hit = ecw_thread_list($mailboxId, ['query' => '"Paged 3"', 'limit' => 1]);
    checkSame(['Paged 3'], array_column(array_merge($hit['top'], $hit['threads']), 'subject'), 'a phrase with a short word is read with LIKE, and only the conversations that match (here a Top result: its name)');
    $plain = ecw_thread_list($mailboxId, ['folder_id' => $inboxId, 'limit' => 3]);
    $all = ecw_thread_list($mailboxId, ['limit' => 3]);
    checkSame(array_column($all['threads'], 'id'), array_column($plain['threads'], 'id'), 'a folder listed newest-first gives the same order as before');

    require_once dirname(__DIR__) . '/lib/sla.php';
    $week = ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'];
    $mon = strtotime('2026-09-07 00:00:00 UTC');
    checkSame(4 * 5 * 8 * 3600, ecw_business_seconds($mon, $mon + 28 * 86400, $week), 'four working weeks are 160 business hours (whole weeks added at once)');
    checkSame('2026-10-02 17:00', gmdate('Y-m-d H:i', ecw_business_add($mon, 4 * 5 * 8 * 3600, $week)), 'and 160 business hours from a Monday end on the fourth Friday at 17:00');

    // ---------------------------------------------------------- reply rules
    group('reply recipients');
    $incoming = $provider->normalizeMessage(FakeGraph::message([
        'id' => 'R1', 'subject' => 'Quote request',
        'from' => ['emailAddress' => ['address' => 'john@customer.com', 'name' => 'John Smith']],
        'toRecipients' => [
            ['emailAddress' => ['address' => 'ops@example.com']],
            ['emailAddress' => ['address' => 'colleague@example.com']],
        ],
        'ccRecipients' => [
            ['emailAddress' => ['address' => 'manager@customer.com']],
            ['emailAddress' => ['address' => 'OPS@example.com']],
        ],
    ]));
    $in = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $incoming);

    $reply = ecw_compose_context($mailboxId, $in['message_id'], 'reply', $userId);
    checkSame(['john@customer.com'], array_column($reply['to'], 'email'), 'reply goes to the sender only');
    checkSame('Re: Quote request', $reply['subject'], 'reply subject is prefixed once');

    $replyAll = ecw_compose_context($mailboxId, $in['message_id'], 'reply_all', $userId);
    $allTo = array_column($replyAll['to'], 'email');
    $allCc = array_column($replyAll['cc'], 'email');
    check(in_array('john@customer.com', $allTo, true), 'reply all keeps the sender');
    check(in_array('colleague@example.com', $allTo, true), 'reply all keeps the other To recipients');
    check(in_array('manager@customer.com', $allCc, true), 'reply all keeps Cc');
    check(!in_array('ops@example.com', array_merge($allTo, $allCc), true),
        'reply all excludes this mailbox, in any letter case');
    checkSame(count($allTo), count(array_unique($allTo)), 'recipients are deduplicated');

    $forward = ecw_compose_context($mailboxId, $in['message_id'], 'forward', $userId);
    checkSame([], $forward['to'], 'forward starts with no recipients');
    checkSame('Fw: Quote request', $forward['subject'], 'forward subject is prefixed');
    check(str_contains($forward['quoted_html'], '<b>From:</b>') && str_contains($forward['quoted_html'], '<b>Subject:</b>') && str_contains($forward['quoted_html'], 'border-top'), 'forward carries the original as quoted content, under a line and a From/Sent/To/Subject header');

    // replying to our own sent message must not mail ourselves
    $outgoing = $provider->normalizeMessage(FakeGraph::message([
        'id' => 'S1', 'subject' => 'Our offer',
        'from' => ['emailAddress' => ['address' => 'ops@example.com', 'name' => 'Ops']],
        'toRecipients' => [['emailAddress' => ['address' => 'buyer@customer.com']]],
    ]));
    $out = ecw_ingest_message($mailboxId, $sentId, 'sent', $outgoing);
    $replyToOwn = ecw_compose_context($mailboxId, $out['message_id'], 'reply', $userId);
    checkSame(['buyer@customer.com'], array_column($replyToOwn['to'], 'email'),
        'replying to our own sent mail answers its recipient, not ourselves');

    // A reply we send must come back out of the splitter the same way any
    // other client's reply does: new text as the body, history as quoted.
    $roundTrip = ecw_split_quoted('<p>My answer</p>' . $reply['quoted_html'], '');
    check(str_contains((string)$roundTrip['body_html'], 'My answer')
        && !str_contains((string)$roundTrip['body_html'], '<b>From:</b>'),
        'a reply we composed splits back into new content...');
    check(str_contains((string)$roundTrip['quoted_html'], '<b>From:</b>'),
        '...and collapsible history, using our own quote boundary');

    // The step forbids destroying the provider's original content. The split
    // is an offset cut, so the two halves still concatenate back to exactly
    // what arrived — that is what makes it safe to store only the halves.
    $original = '<p>New text</p><div id="appendonsend"></div><blockquote>history</blockquote>';
    $halves   = ecw_split_quoted($original, '');
    checkSame($original, (string)$halves['body_html'] . (string)$halves['quoted_html'],
        'body_html + quoted_html reconstruct the original message byte for byte');

    // ------------------------------------------------------------ signature
    group('signatures');
    ecw_signature_save($orgId, $userId, null, [
        'display_name' => 'Georgios', 'title' => 'Operations', 'company' => 'Zetisis',
        'phone' => '+49 000', 'signature_html' => '<p>Best<script>evil()</script></p>',
    ]);
    $signature = ecw_signature_get($userId, null);
    check(!str_contains((string)$signature['signature_html'], 'script'),
        'signature HTML is sanitized on save, not on render');

    $html = ecw_signature_html_for($userId, null);
    check(str_contains($html, 'Best'), 'the saved signature is what gets used');

    ecw_signature_save($orgId, $userId, null, ['display_name' => 'Georgios', 'title' => 'Ops', 'signature_html' => '']);
    check(str_contains(ecw_signature_html_for($userId, null), 'Georgios'),
        'with no HTML, a signature is built from the structured fields');

    $mailboxSig = ecw_signature_save($orgId, $userId, $mailboxId, ['display_name' => 'Support Desk', 'signature_html' => '']);
    check(str_contains(ecw_signature_html_for($userId, $mailboxId), 'Support Desk'),
        'a mailbox-specific signature wins over the user default');

    // --------------------------------------------------------------- drafts
    group('drafts and sending');
    $draftId = ecw_draft_save($orgId, $mailboxId, $userId, [
        'mode' => 'reply', 'in_reply_to_message_id' => $in['message_id'],
        'subject' => 'Re: Quote request',
        'body_html' => '<p>Here you go<script>alert(1)</script></p>',
        'to' => [['email' => 'john@customer.com']],
        'cc' => [['email' => 'not an address']],
    ]);
    $draft = ecw_draft_load($orgId, $draftId);
    check(!str_contains((string)$draft['body_html'], 'script'), 'a stored draft body is sanitized');
    checkSame([], json_decode((string)$draft['cc_json'], true), 'an invalid address is dropped, not stored');

    $sameDraft = ecw_draft_save($orgId, $mailboxId, $userId, ['subject' => 'Updated'], $draftId);
    checkSame($draftId, $sameDraft, 'saving again updates the same draft instead of piling up');

    // inline images become cid attachments rather than a multi-megabyte body
    [$rewritten, $inline] = ecw_extract_inline_images('<p><img src="data:image/png;base64,AAAA"></p>');
    checkSame(1, count($inline), 'a pasted data: image becomes an inline attachment');
    check(str_contains($rewritten, 'src="cid:'), 'and the body references it by cid');
    check(!str_contains($rewritten, 'data:image'), 'the data: URI is gone from the body');

    // send, against a scripted Graph
    $fake = new FakeGraph();
    $fake->on('POST', '#/createReply#', ['body' => ['id' => 'DRAFT-1', 'internetMessageId' => '<sent@example.com>']]);
    $fake->on('PATCH', '#/me/messages/DRAFT-1#', ['body' => ['id' => 'DRAFT-1', 'internetMessageId' => '<sent@example.com>']]);
    $fake->on('POST', '#/me/messages/DRAFT-1/send#', ['body' => []]);
    $fake->on('GET', '#sentitems/messages#', ['body' => ['value' => [['id' => 'SENT-1']]]]);
    $fake->on('GET', '#/me/messages/SENT-1#', ['body' => FakeGraph::message(['id' => 'SENT-1', 'subject' => 'Re: Quote request'])]);
    $fake->on('GET', '#/me/messages/SENT-1/attachments#', ['body' => ['value' => []]]);
    $fake->install();

    $draftId = ecw_draft_save($orgId, $mailboxId, $userId, [
        'mode' => 'reply', 'in_reply_to_message_id' => $in['message_id'],
        'subject' => 'Re: Quote request', 'body_html' => '<p>Here you go</p>' . ecw_quote_block(['from_email'=>'john@customer.com', 'body_html'=>'<html><head><meta charset="utf-8"></head><body><p>Original full document</p></body></html>']),
        'to' => [['email' => 'john@customer.com']],
    ]);
    $result = ecw_draft_send($orgId, $draftId);
    checkSame('<sent@example.com>', $result['internet_message_id'], 'send returns the Message-ID of what was sent');
    check(ecw_draft_load($orgId, $draftId) === null, 'the draft is cleared once sent');

    $replyCalls = $fake->callsMatching('#createReply#');
    checkSame(1, count($replyCalls), 'a reply is sent through createReply, so the provider threads it');
    $patch = $fake->callsMatching('#/me/messages/DRAFT-1$#')[0]['json']['body']['content'] ?? '';
    check(str_contains($patch, 'Here you go') && str_contains($patch, 'Original full document') && strpos($patch,'Here you go') < strpos($patch,'Original full document'), 'actual provider payload preserves answer before quoted Outlook document');


    $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id = ? AND provider_message_id = ?');
    $st->execute([$mailboxId, 'SENT-1']);
    checkSame(1, (int)$st->fetchColumn(), 'the sent copy is ingested from the provider, not invented locally');

    // A new email (not from a case) naming a case number in its text joins that case
    // as soon as it is sent: the sent copy's body may arrive only later.
    $numberedCase = ecw_case_create($orgId, 'Measurement request', $userId);
    $caseNumber = (string)$pdo->query('SELECT case_number FROM `' . ecw_table('cases') . '` WHERE id = ' . $numberedCase)->fetchColumn();
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['body' => ['id' => 'DRAFT-2', 'internetMessageId' => '<sent2@example.com>']]);
    $fake->on('POST', '#/me/messages/DRAFT-2/send#', ['body' => []]);
    $fake->on('GET', '#sentitems/messages#', ['body' => ['value' => [['id' => 'SENT-2']]]]);
    $fake->on('GET', '#/me/messages/SENT-2#', ['body' => FakeGraph::message(['id' => 'SENT-2', 'conversationId' => 'conv-new-2', 'subject' => 'Questions from a customer'])]);
    $fake->on('GET', '#/me/messages/SENT-2/attachments#', ['body' => ['value' => []]]);
    $fake->install();
    $draftId = ecw_draft_save($orgId, $mailboxId, $userId, ['mode' => 'new', 'subject' => 'Questions from a customer',
        'body_html' => '<p>Dear Nik,</p><p>any ideas?</p><p>' . strtolower($caseNumber) . '</p><p>Kind regards</p>', 'to' => [['email' => 'nik@supplier.example']]]);
    ecw_draft_send($orgId, $draftId);
    $st = $pdo->prepare('SELECT ct.case_id FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('case_threads') . '` ct ON ct.email_thread_id = m.email_thread_id WHERE m.mailbox_id = ? AND m.provider_message_id = ?');
    $st->execute([$mailboxId, 'SENT-2']);
    checkSame($numberedCase, (int)$st->fetchColumn(), 'a sent email with "' . strtolower($caseNumber) . '" in its text joins that case the moment it is sent');

    FakeGraph::uninstall();

    try {
        ecw_draft_send($orgId, $draftId);
        bad('sending an already-sent draft fails');
    } catch (RuntimeException $e) {
        ok('sending an already-sent draft fails');
    }

    group('plain text message bodies');
    $plainMessage = $mk('PLAIN-BODY', 'Plain text body', '2026-09-17T15:30:00Z');
    $plainMessage['body_html'] = null;
    $plainMessage['body_text'] = "Plain text content\n<script>not executable</script>";
    $plainRow = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $plainMessage);
    $plainDetail = ecw_thread_detail($mailboxId, $plainRow['thread_id']);
    $plainHtml = $plainDetail['messages'][0]['body_html'];
    check(str_contains($plainHtml, 'Plain text content'), 'plain-text-only message is visible in the reader');
    check(!str_contains($plainHtml, '<script>') && str_contains($plainHtml, '&lt;script&gt;'),
        'plain-text markup remains escaped');

    // --------------------------------------------------------- thread detail
    group('reading pane');
    $detail = ecw_thread_detail($mailboxId, $t2['thread_id'], 'asc');
    check($detail !== null, 'a thread in this mailbox is readable');
    checkSame('AXAKALIS CMM', $detail['case']['name'], 'the linked case is visible on the thread');

    $otherOrg = $ins('organizations', ['name' => 'Other Org']);
    $otherMailbox = $ins('mailboxes', [
        'organization_id' => $otherOrg, 'provider' => 'microsoft', 'provider_account_id' => 'oid-other',
        'email_address' => 'other@example.com', 'sync_status' => 'connected',
    ]);
    check(ecw_thread_detail($otherMailbox, $t2['thread_id'], 'asc') === null,
        "another organization's mailbox id cannot read this thread");

    $ordered = ecw_thread_detail($mailboxId, $in['thread_id'], 'asc');
    $desc    = ecw_thread_detail($mailboxId, $in['thread_id'], 'desc');
    check(count($ordered['messages']) === count($desc['messages']), 'both orders return the same messages');

    // mark read updates the cached counter the list reads
    ecw_thread_mark_read($mailboxId, $t1['thread_id'], true);
    $st = $pdo->prepare('SELECT unread_count FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
    $st->execute([$t1['thread_id']]);
    checkSame(0, (int)$st->fetchColumn(), 'marking a thread read clears its cached unread count');

} finally {
    FakeGraph::uninstall();
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
