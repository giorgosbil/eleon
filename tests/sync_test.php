<?php declare(strict_types=1);
/**
 * Step 02 tests — OAuth, synchronization, threading, notifications.
 *
 *   php tests/sync_test.php
 *
 * Everything runs against a scripted Graph (tests/support/FakeGraph.php), so
 * the full pipeline is exercised with no Azure tenant and no network. Database
 * writes happen for real and are rolled back at the end.
 *
 * The tests worth reading first are the ones that pin invariants rather than
 * behaviour: the cursor must not advance past an unresolved message, a poison
 * message must dead-letter instead of blocking the folder, a second runner must
 * be stopped by the lease, and a notification with the wrong clientState must
 * queue nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/license.php';
require_once dirname(__DIR__) . '/lib/sync/subscriptions.php';
require_once __DIR__ . '/support/FakeGraph.php';
require_once __DIR__ . '/support/GraphIdentity.php';

$pass = 0;
$fail = 0;
$currentGroup = '';

function group(string $name): void { global $currentGroup; $currentGroup = $name; echo "\n=== {$name} ===\n"; }
function ok(string $what): void   { global $pass; $pass++; echo "  ok    {$what}\n"; }
function bad(string $what, string $detail = ''): void { global $fail; $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function check(bool $cond, string $what, string $detail = ''): void { $cond ? ok($what) : bad($what, $detail); }
function checkSame($expected, $actual, string $what): void
{
    $cond = $expected === $actual;
    $cond ? ok($what) : bad($what, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

ecw_set_graph_config_override([
    'tenant_id'     => 'test-tenant',
    'client_id'     => 'test-client',
    'client_secret' => 'test-secret',
    'redirect_uri'  => 'https://example.test/other_things/web/oauth/callback.php',
]);

$pdo = ecw_db();
$pdo->beginTransaction();

try {
    // ------------------------------------------------------------- fixtures
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Test Org']);
    $userId = $ins('users', ['name' => 'Tester', 'email' => 'tester' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    $mailboxId = $ins('mailboxes', [
        'organization_id'         => $orgId,
        'connected_by_user_id'    => $userId,
        'provider'                => 'microsoft',
        'provider_account_id'     => 'oid-test',
        'email_address'           => 'ops@example.com',
        'display_name'            => 'Ops',
        'access_token_encrypted'  => ecw_encrypt('fake-access-token'),
        'refresh_token_encrypted' => ecw_encrypt('fake-refresh-token'),
        'token_expires_at'        => gmdate('Y-m-d H:i:s', time() + 3600),
        'sync_status'             => 'connected',
    ]);

    $inboxId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'FOLDER-INBOX', 'name' => 'Inbox',
        'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);
    $sentId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'FOLDER-SENT', 'name' => 'Sent Items',
        'folder_type' => 'sent', 'is_system' => 1, 'is_selected_for_sync' => 1,
    ]);

    // --------------------------------------------------------------- crypto
    group('token encryption');
    $secret = 'a-refresh-token-value';
    $cipher = ecw_encrypt($secret);
    checkSame($secret, ecw_decrypt($cipher), 'round trip');
    check(!str_contains($cipher, $secret), 'ciphertext does not contain the plaintext');
    check(ecw_encrypt($secret) !== $cipher, 'same input encrypts differently each time (random IV)');
    $tampered = substr($cipher, 0, -4) . 'AAAA';
    try { ecw_decrypt($tampered); bad('tampered ciphertext is rejected'); }
    catch (RuntimeException $e) { ok('tampered ciphertext is rejected'); }
    check(ecw_client_state_matches('abc', ecw_client_state_hash('abc')), 'clientState hash comparison accepts a match');
    check(!ecw_client_state_matches('abd', ecw_client_state_hash('abc')), 'clientState hash comparison rejects a mismatch');

    // ------------------------------------------------------------- subjects
    group('subject normalization');
    checkSame('Quote request', ecw_normalize_subject('Re: Quote request'), 'strips Re:');
    checkSame('Quote request', ecw_normalize_subject('AW: WG: Re: Quote request'), 'strips a German prefix chain');
    checkSame('Quote request', ecw_normalize_subject('  RE:   Quote   request  '), 'collapses whitespace');
    checkSame('', ecw_normalize_subject(null), 'null subject is empty, not an error');

    // ---------------------------------------------------------- quote split
    group('quoted history');
    $html  = '<p>The machine still has the problem.</p><div id="appendonsend"></div><blockquote>older</blockquote>';
    $split = ecw_split_quoted($html, '');
    check(str_contains((string)$split['body_html'], 'still has the problem')
        && !str_contains((string)$split['body_html'], 'older'), 'html splits at the Outlook boundary');
    check(str_contains((string)$split['quoted_html'], 'older'), 'quoted html keeps the history');

    $text  = "Can you advise?\n\n-----Original Message-----\nFrom: someone";
    $split = ecw_split_quoted('', $text);
    checkSame('Can you advise?', $split['body_text'], 'text splits at the original-message separator');

    $plain = ecw_split_quoted('<p>no markers here</p>', 'no markers here');
    check($plain['quoted_html'] === null && $plain['quoted_text'] === null,
        'a body with no marker is left whole rather than guessed at');

    // Formats seen in real mail that used to stay unfolded.
    $s = ecw_split_quoted('<div>Thanks, done.</div><hr><p>On 2026-10-02 20:47:20 UTC, George &lt;g@x.test&gt; wrote:</p><blockquote><p>Hello George</p></blockquote>', '');
    check(trim(strip_tags((string)$s['body_html'])) === 'Thanks, done.' && str_contains((string)$s['quoted_html'], 'wrote:') && str_contains((string)$s['quoted_html'], '<hr>'), '"On … wrote:" + blockquote (this app, Apple Mail) folds, divider included');
    $s = ecw_split_quoted('<p>Ευχαριστώ!</p><div>Στις Δευ 5 Οκτ 2026 10:00, ο Νίκος &lt;n@x.gr&gt; έγραψε:</div><blockquote><p>Γεια</p></blockquote>', '');
    check(str_contains((string)$s['body_html'], 'Ευχαριστώ') && str_contains((string)$s['quoted_html'], 'έγραψε'), 'Greek "Στις … έγραψε:" folds');
    $s = ecw_split_quoted('<p>See the quote:</p><blockquote><p>To be or not to be</p></blockquote><p>Nice, right?</p>', '');
    check($s['quoted_html'] === null, 'a blockquote without an attribution line is a real quote, not history');
    $s = ecw_split_quoted('<div class="hs_reply_wrap"><p>ηι</p></div><div class="hs_reply"><br><div>On Sun, Jan 11 … wrote:</div><blockquote>old</blockquote></div>', '');
    check(trim(strip_tags((string)$s['body_html'])) === 'ηι', 'HubSpot replies fold');
    $s = ecw_split_quoted('<div><p>Please see below.</p><p>George</p></div><div><p><b>From:</b> George &lt;sender@example.test&gt;<br><b>Sent:</b> Δευτέρα, 8 Ιουνίου 2026 10:40 πμ<br><b>To:</b> m@kayser.test<br><b>Subject:</b> RFQ</p><p>Dear Mr Gola…</p></div>', '');
    check(str_contains((string)$s['body_html'], 'Please see below') && str_contains((string)$s['quoted_html'], 'Dear Mr Gola'), 'an Outlook From/Sent/To/Subject block without a divider folds');
    $s = ecw_split_quoted('<div><p><b>From:</b> A &lt;a@x.test&gt;<br><b>Sent:</b> Monday<br><b>To:</b> b@x.test<br><b>Subject:</b> Fwd</p><p>Forwarded text</p></div>', '');
    check($s['quoted_html'] === null, 'a forward with nothing written above it is not folded away');

    // ------------------------------------------------------------ threading
    group('thread resolution');
    $fake = new FakeGraph();
    $fake->install();

    $m1 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-1', 'conversationId' => 'CONV-A', 'subject' => 'CMM axis problem',
    ]));
    $r1 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m1);
    checkSame('inserted', $r1['action'], 'first message inserts');

    $m2 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-2', 'conversationId' => 'CONV-A', 'subject' => 'Re: CMM axis problem',
    ]));
    $r2 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m2);
    checkSame($r1['thread_id'], $r2['thread_id'], 'same conversation id joins the same thread');

    // Graph read-status deltas omit the envelope; they must not erase it.
    $before = $pdo->query('SELECT * FROM ecw_email_messages WHERE id = ' . (int)$r1['message_id'])->fetch();
    $partial = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(['id'=>'MSG-1', 'isRead'=>true]);
    ecw_ingest_message($mailboxId, $inboxId, 'inbox', $partial);
    $after = $pdo->query('SELECT * FROM ecw_email_messages WHERE id = ' . (int)$r1['message_id'])->fetch();
    foreach (['sent_at','received_at','occurred_at','subject','from_email','to_json','internet_message_id'] as $field) {
        checkSame($before[$field], $after[$field], 'partial delta preserves ' . $field);
    }
    checkSame(1, (int)$after['is_read'], 'partial delta still updates read state');
    check((int)$pdo->query('SELECT COUNT(*) FROM ecw_message_participants WHERE email_message_id = ' . (int)$r1['message_id'])->fetchColumn() > 0, 'partial delta preserves participants');

    // In-Reply-To, with no conversation id at all
    $m3 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-3', 'conversationId' => null, 'subject' => 'Re: CMM axis problem',
        'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '<MSG-1@example.com>']],
    ]));
    $r3 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m3);
    checkSame($r1['thread_id'], $r3['thread_id'], 'In-Reply-To joins the referenced thread');

    // References chain
    $m4 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-4', 'conversationId' => null, 'subject' => 'Re: CMM axis problem',
        'internetMessageHeaders' => [['name' => 'References', 'value' => '<nope@x> <MSG-2@example.com>']],
    ]));
    $r4 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m4);
    checkSame($r1['thread_id'], $r4['thread_id'], 'References joins the referenced thread');

    // Subject fallback: allowed only with a shared participant
    $m5 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-5', 'conversationId' => null, 'subject' => 'Invoice',
        'from' => ['emailAddress' => ['address' => 'billing@alpha.com']],
    ]));
    $r5 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m5);

    $m6 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-6', 'conversationId' => null, 'subject' => 'Re: Invoice',
        'from' => ['emailAddress' => ['address' => 'billing@alpha.com']],
    ]));
    $r6 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m6);
    checkSame($r5['thread_id'], $r6['thread_id'], 'subject fallback joins when a participant is shared');

    $m7 = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'MSG-7', 'conversationId' => null, 'subject' => 'Invoice',
        'from' => ['emailAddress' => ['address' => 'accounts@totally-unrelated.com']],
        'toRecipients' => [['emailAddress' => ['address' => 'someone-else@example.com']]],
    ]));
    $r7 = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m7);
    check($r7['thread_id'] !== $r5['thread_id'],
        'same subject from an unrelated correspondent does NOT merge threads');

    // ------------------------------------------------------ identity/dedup
    group('message identity');
    $again = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $m1);
    checkSame('updated', $again['action'], 'replaying the same provider id updates, never duplicates');
    checkSame($r1['message_id'], $again['message_id'], 'the replay lands on the same row');

    // Graph reissues the id when a message moves folders
    $moved = $m1;
    $moved['provider_message_id'] = 'MSG-1-MOVED';
    $movedResult = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $moved);
    checkSame('reconciled', $movedResult['action'], 'a changed provider id reconciles onto the existing row');
    checkSame($r1['message_id'], $movedResult['message_id'], 'no duplicate row after a move');

    // Self-send: same Message-ID, two directions, two rows
    $selfIn = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'SELF-IN', 'internetMessageId' => '<self@example.com>',
        'from' => ['emailAddress' => ['address' => 'ops@example.com']],
        'toRecipients' => [['emailAddress' => ['address' => 'ops@example.com']]],
    ]));
    $selfOut = $selfIn;
    $selfOut['provider_message_id'] = 'SELF-OUT';
    $inRow  = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $selfIn);
    $outRow = ecw_ingest_message($mailboxId, $sentId, 'sent', $selfOut);
    check($inRow['message_id'] !== $outRow['message_id'],
        'a self-sent message keeps its Sent copy and its Inbox copy as two rows');

    $st = $pdo->prepare('SELECT direction FROM `' . ecw_table('email_messages') . '` WHERE id IN (?,?) ORDER BY id');
    $st->execute([$inRow['message_id'], $outRow['message_id']]);
    checkSame(['incoming', 'outgoing'], $st->fetchAll(PDO::FETCH_COLUMN),
        'the Inbox copy is incoming and the Sent copy outgoing, even though both are from us');

    // @removed is a soft delete
    $removed = ecw_ingest_message($mailboxId, $inboxId, 'inbox',
        ['provider_message_id' => 'MSG-2', 'removed' => true, 'removed_reason' => 'deleted']);
    checkSame('removed', $removed['action'], 'a removal is applied');
    $st = $pdo->prepare('SELECT provider_removed_at FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id = ? AND provider_message_id = ?');
    $st->execute([$mailboxId, 'MSG-2']);
    check($st->fetchColumn() !== null, 'the removed row is stamped, not deleted');

    // ---------------------------------------------------------- delta sync
    group('delta synchronization');
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#/messages/delta#',
        ['body' => ['value' => [FakeGraph::message(['id' => 'D-1'])], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next-page-2']],
        ['body' => ['value' => [FakeGraph::message(['id' => 'D-2'])], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta-final']]
    );
    $fake->on('GET', '#next-page-2#',
        ['body' => ['value' => [FakeGraph::message(['id' => 'D-2'])], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta-final']]);
    $fake->install();

    $counts = ecw_sync_folder_delta($mailboxId, $inboxId, 10, 50);
    checkSame(2, $counts['pages'], 'both pages of the round were read');
    checkSame(2, $counts['ingested'], 'both messages ingested');
    checkSame('https://graph.microsoft.com/v1.0/delta-final', ecw_folder_delta_cursor($inboxId),
        'the completed round stored its deltaLink as the folder cursor');

    // 410 Gone: the token expired, Graph hands back a resync URL
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#delta-final#',
        ['status' => 410, 'body' => ['error' => ['code' => 'syncStateNotFound']],
         'headers' => ['location' => 'https://graph.microsoft.com/v1.0/fresh-delta']]);
    $fake->on('GET', '#fresh-delta#',
        ['body' => ['value' => [FakeGraph::message(['id' => 'D-1'])], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta-v2']]);
    $fake->install();

    $counts = ecw_sync_folder_delta($mailboxId, $inboxId, 10, 50);
    checkSame(1, $counts['resyncs'], 'an expired delta token triggers exactly one resync');
    checkSame('https://graph.microsoft.com/v1.0/delta-v2', ecw_folder_delta_cursor($inboxId),
        'the resync round stored a fresh cursor');
    $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id = ? AND provider_message_id = ?');
    $st->execute([$mailboxId, 'D-1']);
    checkSame(1, (int)$st->fetchColumn(), 'a resync re-upserts rather than duplicating');

    // A poison message must dead-letter, and the cursor must still advance
    group('the dead-letter invariant');
    ecw_folder_set_delta_cursor($inboxId, null);
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#/messages/delta#', ['body' => [
        'value' => [
            FakeGraph::message(['id' => 'GOOD-1']),
            ['internetMessageId' => '<no-id@example.com>'],   // no provider id: unprocessable
            FakeGraph::message(['id' => 'GOOD-2']),
        ],
        '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta-after-poison',
    ]]);
    $fake->install();

    $counts = ecw_sync_folder_delta($mailboxId, $inboxId, 10, 50);
    checkSame(1, $counts['dead_lettered'], 'the unprocessable message was dead-lettered');
    checkSame(2, $counts['ingested'], 'the messages around it still ingested');
    checkSame('https://graph.microsoft.com/v1.0/delta-after-poison', ecw_folder_delta_cursor($inboxId),
        'the cursor advanced past the page, because every message resolved');
    $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('message_dead_letters') . '` WHERE mailbox_id = ?');
    $st->execute([$mailboxId]);
    checkSame(1, (int)$st->fetchColumn(), 'the failure is visible as a row, never a silent skip');

    // A page that throws must NOT advance the cursor
    ecw_folder_set_delta_cursor($inboxId, 'https://graph.microsoft.com/v1.0/known-cursor');
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#known-cursor#', ['status' => 500, 'body' => ['error' => ['code' => 'InternalServerError']]]);
    $fake->install();
    try {
        ecw_sync_folder_delta($mailboxId, $inboxId, 10, 50);
        bad('a failing page propagates');
    } catch (Throwable $e) {
        ok('a failing page propagates');
    }
    checkSame('https://graph.microsoft.com/v1.0/known-cursor', ecw_folder_delta_cursor($inboxId),
        'the cursor did not move past a page that never resolved');

    // ---------------------------------------------------------------- lease
    group('leases');
    $stateId = ecw_sync_state_id($mailboxId, $inboxId, 'delta');
    $leaseA  = ecw_sync_acquire_lease($stateId);
    check($leaseA !== null, 'the first runner claims the lease');
    check(ecw_sync_acquire_lease($stateId) === null, 'a second runner is refused while the lease is live');

    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#delta#', ['body' => ['value' => [], '@odata.deltaLink' => 'x']]);
    $fake->install();
    $blocked = ecw_sync_folder_delta($mailboxId, $inboxId, 10, 50);
    check(!empty($blocked['skipped']), 'a delta run exits immediately when the lease is held');
    checkSame([], $fake->callsMatching('#delta#'), 'the blocked run made no provider call at all');

    ecw_sync_release_lease($stateId, $leaseA);
    check(ecw_sync_acquire_lease($stateId) !== null, 'the lease is claimable once released');
    $pdo->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . '` SET run_lease_id = NULL WHERE id = ?')->execute([$stateId]);

    // A stale lease is stealable
    $stale = ecw_sync_acquire_lease($stateId);
    $pdo->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . '` SET run_lease_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 7200), $stateId]);
    check(ecw_sync_acquire_lease($stateId) !== null, 'a lease whose holder died is stealable after the TTL');

    // ----------------------------------------------------------- throttling
    // ------------------------------------------------ cost and correctness
    group('what we ask Graph for');
    // Both of these were real production faults on 2026-09-18.
    check(!in_array('internetMessageHeaders', MicrosoftGraphProvider::SELECT, true),
        'bulk sync does NOT request internetMessageHeaders (it costs ~6x per message)');
    check(in_array('internetMessageHeaders', MicrosoftGraphProvider::SELECT_FULL, true),
        'but a single full message does, so In-Reply-To still arrives with the body');
    check(in_array('conversationId', MicrosoftGraphProvider::SELECT, true),
        'conversationId stays in bulk sync — it is what threads mail without those headers');

    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#/attachments#', ['body' => ['value' => []]]);
    $fake->install();
    (new MicrosoftGraphProvider(ecw_graph_config(), static fn() => 'token'))->listAttachments('MSG-1');
    $call = $fake->callsMatching('#/attachments#')[0]['url'] ?? '';
    check(str_contains($call, 'microsoft.graph.fileAttachment/contentId'),
        'the attachment $select casts to fileAttachment for contentId');
    check(!preg_match('/[,=]contentId/', $call),
        'and never names contentId bare, which Graph answers with 400 BadRequest');

    // A message whose attachment listing fails must still keep its body.
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#/me/messages/BODY-1\?#', ['body' => FakeGraph::message([
        'id' => 'BODY-1', 'hasAttachments' => true,
        'body' => ['contentType' => 'html', 'content' => '<p>the body survived</p>'],
    ])]);
    $fake->on('GET', '#/attachments#', ['status' => 400, 'body' => ['error' => ['code' => 'BadRequest']]]);
    $fake->install();

    $bodyMsg = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message(['id' => 'BODY-1']));
    $bodyRow = ecw_ingest_message($mailboxId, $inboxId, 'inbox', $bodyMsg);
    ecw_store_full_message($mailboxId, (int)$bodyRow['message_id'], 'BODY-1');

    $st = $pdo->prepare('SELECT body_html, body_fetch_state FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
    $st->execute([(int)$bodyRow['message_id']]);
    $stored = $st->fetch();
    check(str_contains((string)$stored['body_html'], 'the body survived'), 'the body is stored even when the attachment list fails');
    checkSame('fetched', $stored['body_fetch_state'],
        'and is NOT marked fetch_failed, which would have it re-fetched forever');

    group('throttling');
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#/me/mailFolders/inbox#',
        ['status' => 429, 'headers' => ['retry-after' => '7'], 'body' => ['error' => ['code' => 'TooManyRequests']]],
        ['body' => ['id' => 'FOLDER-INBOX']]
    );
    $fake->install();
    $res = (new GraphHttp(static fn() => 'token'))->request('GET', '/me/mailFolders/inbox?$select=id');
    checkSame(200, $res['status'], '429 is retried and then succeeds');
    checkSame([7], $fake->sleeps, 'the Retry-After value is honoured, not a fixed guess');

    // -------------------------------------------------------------- folders
    group('folder discovery');
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    foreach (['inbox' => 'F-INBOX', 'sentitems' => 'F-SENT', 'drafts' => 'F-DRAFTS',
              'deleteditems' => 'F-DELETED', 'archive' => 'F-ARCHIVE', 'junkemail' => 'F-JUNK',
              'conversationhistory' => 'F-CONVHIST', 'outbox' => 'F-OUTBOX', 'syncissues' => 'F-SYNCISSUES',
              'conflicts' => 'F-CONFLICTS', 'localfailures' => 'F-LOCALFAIL', 'serverfailures' => 'F-SERVERFAIL', 'scheduled' => 'F-SNOOZED'] as $name => $fid) {
        $fake->on('GET', '#/me/mailFolders/' . $name . '\?#', ['body' => ['id' => $fid]]);
    }
    $fake->on('GET', '#/me/mailFolders\?#', ['body' => ['value' => [
        ['id' => 'F-INBOX',      'displayName' => 'Inbox',       'childFolderCount' => 0],
        ['id' => 'F-SENT',       'displayName' => 'Sent Items',  'childFolderCount' => 0],
        ['id' => 'F-DRAFTS',     'displayName' => 'Drafts',      'childFolderCount' => 0],
        ['id' => 'F-PROJECT',    'displayName' => 'Projects',    'childFolderCount' => 0],
        ['id' => 'F-JUNK',       'displayName' => 'Junk Email',  'childFolderCount' => 0],
        ['id' => 'F-SYNCISSUES', 'displayName' => 'Sync Issues', 'childFolderCount' => 0],
        ['id' => 'F-OUTBOX',     'displayName' => 'Outbox',      'childFolderCount' => 0],
    ]]]);
    $fake->install();

    $mailbox2 = $ins('mailboxes', [
        'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-2',
        'email_address' => 'second@example.com',
        'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'sync_status' => 'connected',
    ]);

    $folderCounts = ecw_sync_folders($mailbox2);
    checkSame(7, $folderCounts['seen'], 'every folder was discovered');
    $selected = array_column(ecw_selected_folders($mailbox2), 'name');
    sort($selected);
    checkSame(['Inbox', 'Junk Email', 'Projects', 'Sent Items'], $selected, 'every folder people put mail in is synced (own folders, Junk); not Drafts, not Outlook bookkeeping');
    $types = $pdo->query('SELECT name, folder_type FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ' . (int)$mailbox2)->fetchAll(PDO::FETCH_KEY_PAIR);
    check($types['Junk Email'] === 'junk' && $types['Sync Issues'] === 'system' && $types['Outbox'] === 'system', 'Junk is recognised, Sync Issues and Outbox are marked as system folders');
    check(!in_array('Sync Issues', array_column(ecw_folder_tree($mailbox2), 'name'), true), 'system folders are not shown in the folder list');

    // the user's choice must survive rediscovery
    $st = $pdo->prepare('UPDATE `' . ecw_table('mail_folders') . '` SET is_selected_for_sync = 0 WHERE mailbox_id = ? AND name = ?');
    $st->execute([$mailbox2, 'Projects']);
    ecw_sync_folders($mailbox2);
    $selected = array_column(ecw_selected_folders($mailbox2), 'name');
    check(!in_array('Projects', $selected, true), "rediscovery preserves the user's own folder selection");
    // A mailbox connected before every folder was synced: the rest is turned on once.
    $pdo->prepare('UPDATE `' . ecw_table('mail_folders') . "` SET is_selected_for_sync = 0 WHERE mailbox_id = ? AND folder_type NOT IN ('inbox', 'sent')")->execute([$mailbox2]);
    ecw_app_setting_set('folders_v2_' . $mailbox2, null);
    $up = ecw_sync_folders($mailbox2);
    $selected = array_column(ecw_selected_folders($mailbox2), 'name');
    check(($up['turned_on'] ?? 0) === 2 && in_array('Projects', $selected, true) && in_array('Junk Email', $selected, true) && !in_array('Drafts', $selected, true), 'an older mailbox gets its other folders turned on once');

    // ---------------------------------------------------------- case intake
    group('create-case folder');
    $intakeId = $ins('mail_folders', [
        'mailbox_id' => $mailboxId, 'provider_folder_id' => 'F-INTAKE', 'name' => 'Create Case',
        'folder_type' => 'custom', 'is_selected_for_sync' => 1, 'is_case_intake' => 1,
    ]);
    $intakeMsg = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'INTAKE-1', 'conversationId' => 'CONV-INTAKE', 'subject' => 'Re: AXAKALIS CMM problem',
    ]));
    $intakeResult = ecw_ingest_message($mailboxId, $intakeId, 'custom', $intakeMsg);
    $case = ecw_case_for_thread($intakeResult['thread_id']);
    check($case !== null, 'moving a message into the intake folder drafts a case');
    checkSame('AXAKALIS CMM problem', $case['name'] ?? null, 'the provisional name is the subject, prefixes stripped');
    check((bool)preg_match('/^CASE-\d{5}$/', (string)($case['case_number'] ?? '')), 'the case number is formatted CASE-00001');

    $second = (new MicrosoftGraphProvider(ecw_graph_config()))->normalizeMessage(FakeGraph::message([
        'id' => 'INTAKE-2', 'conversationId' => 'CONV-INTAKE', 'subject' => 'Re: AXAKALIS CMM problem',
    ]));
    ecw_ingest_message($mailboxId, $intakeId, 'custom', $second);
    $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('cases') . '` WHERE organization_id = ?');
    $st->execute([$orgId]);
    checkSame(1, (int)$st->fetchColumn(), 'a second message of the same thread does not spawn a second case');

    // ------------------------------------------------------------ webhooks
    group('change notifications');
    $clientState = ecw_new_client_state();
    $subId = $ins('graph_subscriptions', [
        'mailbox_id' => $mailboxId, 'provider' => 'microsoft', 'subscription_id' => 'SUB-1',
        'resource' => "/me/mailFolders('FOLDER-INBOX')/messages",
        'notification_url' => 'https://example.test/other_things/web/webhook.php',
        'client_state_hash' => ecw_client_state_hash($clientState),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'status' => 'active',
    ]);

    $result = ecw_subscription_handle_notifications(['value' => [
        ['subscriptionId' => 'SUB-1', 'clientState' => $clientState, 'resource' => 'x'],
    ]]);
    checkSame(1, $result['accepted'], 'a notification with the right clientState is accepted');
    checkSame(1, $result['queued'], 'it enqueues a delta job rather than syncing inline');

    $result = ecw_subscription_handle_notifications(['value' => [
        ['subscriptionId' => 'SUB-1', 'clientState' => 'forged', 'resource' => 'x'],
    ]]);
    checkSame(1, $result['rejected'], 'a forged clientState is rejected');
    checkSame(0, $result['queued'], 'and queues nothing');

    $result = ecw_subscription_handle_notifications(['value' => [
        ['subscriptionId' => 'UNKNOWN-SUB', 'clientState' => $clientState],
    ]]);
    checkSame(1, $result['rejected'], 'a notification for an unknown subscription is rejected');

    $st = $pdo->prepare("SELECT mail_folder_id FROM `" . ecw_table('sync_queue') . "` WHERE mailbox_id = ? AND reason = 'webhook'");
    $st->execute([$mailboxId]);
    checkSame($inboxId, (int)$st->fetchColumn(), 'the queued job targets the folder named in the subscription resource');

    // --------------------------------------------------------------- queue
    group('sync queue');
    $pdo->prepare('DELETE FROM `' . ecw_table('sync_queue') . '` WHERE mailbox_id = ?')->execute([$mailboxId]);
    $first  = ecw_queue_enqueue($mailboxId, $inboxId, 'delta', 'cron');
    $second = ecw_queue_enqueue($mailboxId, $inboxId, 'delta', 'webhook');
    check($first !== null && $second === null, 'an identical queued job is coalesced, not duplicated');

    $claimed = ecw_queue_claim('worker-1', $mailboxId);   // only this test's jobs, never a live mailbox's
    check($claimed !== null && (int)$claimed['id'] === $first, 'the worker claims the queued job');
    check(ecw_queue_claim('worker-2', $mailboxId) === null, 'a second worker finds nothing to claim');

    ecw_queue_fail((int)$claimed['id'], 'transient failure');
    $st = $pdo->prepare('SELECT status, attempts, available_at FROM `' . ecw_table('sync_queue') . '` WHERE id = ?');
    $st->execute([(int)$claimed['id']]);
    $row = $st->fetch();
    checkSame('queued', $row['status'], 'a failed job returns to the queue');
    check(strtotime((string)$row['available_at'] . ' UTC') > time(), 'and is delayed by backoff');

    // ---------------------------------------------------------------- OAuth
    group('OAuth token lifecycle');
    $identity = ecw_test_identity();
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('POST', '#/oauth2/v2.0/token#', ['body' => [
        'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'id_token' => $identity['jwt'],
        'expires_in' => 3600, 'scope' => 'Mail.ReadWrite Mail.Send offline_access',
    ]]);
    $fake->on('GET', '#/me\?#', ['body' => [
        'id' => 'oid-fresh', 'mail' => 'fresh@example.com', 'displayName' => 'Fresh User',
    ]]);
    $fake->install();

    $fake->on('GET', '#/discovery/v2.0/keys#', ['body' => ['keys' => $identity['keys']]]);
    $newMailboxId = ecw_oauth_complete($orgId, $userId, 'auth-code-123');
    $mb = ecw_mailbox($newMailboxId);
    checkSame('fresh@example.com', $mb['email_address'], 'the callback creates the mailbox for the signed-in account');
    checkSame('new-access', ecw_decrypt($mb['access_token_encrypted']), 'the access token is stored encrypted');
    checkSame('new-refresh', ecw_decrypt($mb['refresh_token_encrypted']), 'the refresh token is stored encrypted');
    check(!str_contains((string)$mb['access_token_encrypted'], 'new-access'), 'the stored column is not plaintext');
    checkSame('syncing', $mb['sync_status'], 'the mailbox reports that it is importing');

    $sameAgain = ecw_oauth_complete($orgId, $userId, 'auth-code-456');
    checkSame($newMailboxId, $sameAgain, 're-authorizing the same account reuses its mailbox row');

    // expired access token refreshes transparently
    $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET token_expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 60), $newMailboxId]);
    checkSame('new-access', ecw_mailbox_access_token($newMailboxId), 'an expired token is refreshed on demand');

    // a dead grant moves the mailbox to needs_reauth instead of retrying forever
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('POST', '#/oauth2/v2.0/token#',
        ['status' => 400, 'body' => ['error' => 'invalid_grant', 'error_description' => 'AADSTS70000']]);
    $fake->install();
    $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET token_expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 60), $newMailboxId]);
    try {
        ecw_mailbox_access_token($newMailboxId);
        bad('a revoked grant raises');
    } catch (Throwable $e) {
        ok('a revoked grant raises');
    }
    checkSame('needs_reauth', ecw_mailbox($newMailboxId)['sync_status'],
        'and the mailbox says so, visibly, instead of failing silently');

    // ----------------------------------------------- webhook endpoint shape
    group('webhook validation handshake');
    // Run the real endpoint in its own process: it calls exit(), and Graph
    // gives this handshake about ten seconds and accepts nothing but the bare
    // token. Getting it wrong means subscriptions are never created at all, so
    // it is worth testing the file rather than a function it happens to call.
    $endpoint = dirname(__DIR__) . '/web/webhook.php';
    $script   = '$_GET["validationToken"]="TOK-abc123"; $_SERVER["REQUEST_METHOD"]="GET"; include '
              . var_export($endpoint, true) . ';';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($script) . ' 2>/dev/null');
    checkSame('TOK-abc123', $output, 'the endpoint echoes validationToken verbatim, with nothing around it');

    // ------------------------------------------------------------- redaction
    group('logging never carries secrets');
    $line = ecw_redact('GET https://graph.microsoft.com/v1.0/delta?$deltatoken=SECRETVALUE123 Bearer eyJabcdefghijklmnopqrstuvwxyz0');
    check(!str_contains($line, 'SECRETVALUE123'), 'a delta token is redacted from a URL');
    check(!str_contains($line, 'eyJabcdefghijklmnopqrstuvwxyz0'), 'a bearer JWT is redacted');
    check(!str_contains(ecw_redact('{"refresh_token":"abc123def"}'), 'abc123def'), 'a refresh token is redacted');

} finally {
    FakeGraph::uninstall();
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
