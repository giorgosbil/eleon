<?php declare(strict_types=1);
/**
 * Reliability: what messy mail delivery must never do to cases.
 *
 *   php tests/reliability_test.php
 *
 * Real mailboxes deliver the same email twice (a webhook and the timer both
 * fire, a delta page repeats), late (an older email after a newer one), in
 * pieces (a move arrives as "removed here" and "added there", in either
 * order), and sometimes not at all for a while (the sign-in expired). None of
 * that may:
 *   - lose an email from its case, or show a gone one;
 *   - duplicate an email, a case, a case link or a notification;
 *   - change who owns, handles or claimed something;
 *   - put the case's "waiting on" back to an older state.
 *
 * Runs the real sync pipeline against a scripted Graph (tests/support/FakeGraph.php),
 * inside a transaction that is rolled back at the end.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/oauth.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/next_actions.php';
require_once dirname(__DIR__) . '/lib/shared_mailboxes.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function group(string $name): void { echo "\n=== {$name} ===\n"; }
function check(bool $cond, string $what, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    {$what}\n"; } else { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}
function same($expected, $actual, string $what): void { check($expected === $actual, $what, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }

ecw_set_graph_config_override(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://example.test/cb']);
$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $one = static function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    $org = $ins('organizations', ['name' => 'Reliability']);
    $ann = $ins('users', ['name' => 'Ann Rel', 'email' => 'ann' . uniqid() . '@rel.test', 'password_hash' => 'x']);
    $bob = $ins('users', ['name' => 'Bob Rel', 'email' => 'bob' . uniqid() . '@rel.test', 'password_hash' => 'x']);
    foreach ([$ann, $bob] as $u) $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'user']);
    $addr = 'ops' . uniqid() . '@rel.test';
    $mb = $ins('mailboxes', ['organization_id' => $org, 'connected_by_user_id' => $ann, 'provider' => 'microsoft', 'email_address' => $addr,
        'access_token_encrypted' => ecw_encrypt('tok'), 'refresh_token_encrypted' => ecw_encrypt('ref'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'sync_status' => 'connected', 'is_shared' => 1]);
    $folder = static fn(string $pid, string $name, string $type) => $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => $pid, 'name' => $name,
        'folder_type' => $type, 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $inbox = $folder('F-IN', 'Inbox', 'inbox');
    $sent = $folder('F-SENT', 'Sent Items', 'sent');
    $archive = $folder('F-ARCH', 'Archive', 'archive');
    $provider = new MicrosoftGraphProvider(ecw_graph_config());

    // A Graph message, $ago minutes old: recent, so the "fresh mail" automation runs.
    $graph = static function (string $id, string $conv, string $from, int $ago, array $extra = []) use ($addr): array {
        $when = gmdate('Y-m-d\TH:i:s\Z', time() - $ago * 60);
        return FakeGraph::message(array_merge(['id' => $id, 'internetMessageId' => '<' . $id . '@rel.test>', 'conversationId' => $conv,
            'from' => ['emailAddress' => ['address' => $from, 'name' => $from === $addr ? 'Ops' : 'Customer']],
            'toRecipients' => [['emailAddress' => ['address' => $from === $addr ? 'buyer@cust.test' : $addr]]],
            'sentDateTime' => $when, 'receivedDateTime' => $when, 'createdDateTime' => $when, 'lastModifiedDateTime' => $when], $extra));
    };
    $ingest = static fn(array $g, int $folderId, string $type) => ecw_ingest_message($mb, $folderId, $type, $provider->normalizeMessage($g));
    // One delta round of a folder, from a scripted page.
    $delta = static function (int $folderId, array $items) use ($mb): array {
        FakeGraph::uninstall();
        $fake = new FakeGraph();
        $fake->on('GET', '#delta#', ['body' => ['value' => $items, '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta-' . uniqid()]]);
        $fake->install();
        return ecw_sync_folder_delta($mb, $folderId, 10, 50);
    };
    $rowsOf = static fn(string $pid) => (int)$one('SELECT COUNT(*) FROM ecw_email_messages WHERE mailbox_id = ? AND provider_message_id = ?', [$mb, $pid]);
    $events = static fn(int $caseId, string $type) => (int)$one('SELECT COUNT(*) FROM ecw_case_events WHERE case_id = ? AND event_type = ?', [$caseId, $type]);
    $visibleInCase = static fn(int $caseId) => (int)$one('SELECT COUNT(*) FROM ecw_email_messages m JOIN ecw_case_threads ct ON ct.email_thread_id = m.email_thread_id
                                                          WHERE ct.case_id = ? AND m.provider_removed_at IS NULL', [$caseId]);

    // ------------------------------------------------------------------ duplicates
    group('the same email delivered twice');
    $case = ecw_case_create($org, 'Pump failure', $ann);
    $num = (string)$one('SELECT case_number FROM ecw_cases WHERE id = ?', [$case]);
    $pdo->prepare("UPDATE ecw_cases SET status = 'waiting', owner_user_id = ?, handler_user_id = ? WHERE id = ?")->execute([$ann, $bob, $case]);
    $dup = $graph('DUP-1', 'conv-dup', 'buyer@cust.test', 20, ['subject' => 'Re: [' . $num . '] pump']);
    $r = $delta($inbox, [$dup, $dup]);   // one page carrying it twice
    same(1, $rowsOf('DUP-1'), 'a page that repeats an email stores it once');
    $delta($inbox, [$dup]);               // the next round brings it again
    $ingest($dup, $inbox, 'inbox');       // and a webhook-triggered run once more
    same(1, $rowsOf('DUP-1'), 'repeated rounds still leave one row');
    $thread = (int)$one('SELECT email_thread_id FROM ecw_email_messages WHERE provider_message_id = ? AND mailbox_id = ?', ['DUP-1', $mb]);
    same(1, (int)$one('SELECT message_count FROM ecw_email_threads WHERE id = ?', [$thread]), 'the conversation counts it once');
    same(1, (int)$one('SELECT COUNT(*) FROM ecw_case_threads WHERE case_id = ?', [$case]), 'it joined its case (by the case number) once');
    same(1, $events($case, 'THREAD_AUTO_LINKED'), 'and the case history says so once');
    same('open', (string)$one('SELECT status FROM ecw_cases WHERE id = ?', [$case]), 'the customer answered: the Waiting case reopened');
    same(1, (int)$one("SELECT COUNT(*) FROM ecw_notifications WHERE case_id = ? AND type = 'reopened' AND user_id = ?", [$case, $ann]), 'Responsible was told once, not once per delivery');
    same(1, $events($case, 'CASE_NEXT_CHANGED'), '"waiting on" changed once');
    same([$ann, $bob], [(int)$one('SELECT owner_user_id FROM ecw_cases WHERE id = ?', [$case]), (int)$one('SELECT handler_user_id FROM ecw_cases WHERE id = ?', [$case])], 'Responsible and Handled by are untouched');

    // ------------------------------------------------------------------ out of order
    group('emails that arrive late');
    $late = ecw_case_create($org, 'Late delivery', $ann);
    $t = $ingest($graph('LATE-0', 'conv-late', 'buyer@cust.test', 120), $inbox, 'inbox')['thread_id'];
    ecw_case_link_thread($late, $t, null, $ann);
    $ingest($graph('LATE-0', 'conv-late', 'buyer@cust.test', 120), $inbox, 'inbox');   // replayed: still the customer's turn → ours
    $ingest($graph('LATE-2', 'conv-late', $addr, 30), $sent, 'sent');                    // our answer, half an hour ago
    same('customer', ecw_case_next_get($late)['waiting_on'], 'we answered: waiting on the customer');
    $ingest($graph('LATE-1', 'conv-late', 'buyer@cust.test', 60), $inbox, 'inbox');      // an older customer email shows up only now
    same('customer', ecw_case_next_get($late)['waiting_on'], 'an older email arriving late does not put it back to our turn');
    $th = $pdo->query('SELECT latest_message_at, latest_direction, message_count FROM ecw_email_threads WHERE id = ' . (int)$t)->fetch();
    same(['outgoing', 3], [$th['latest_direction'], (int)$th['message_count']], 'the conversation still shows our answer as the newest, with all three emails');
    $order = array_column($pdo->query('SELECT provider_message_id FROM ecw_email_messages WHERE email_thread_id = ' . (int)$t . ' ORDER BY occurred_at, id')->fetchAll(), 'provider_message_id');
    same(['LATE-0', 'LATE-1', 'LATE-2'], $order, 'and the emails are in the order they were written, not the order they arrived');
    $tl = ecw_case_timeline($org, $late, false, null, $ann);
    same(['LATE-0', 'LATE-1', 'LATE-2'], array_values(array_map(static fn($e) => (string)$one('SELECT provider_message_id FROM ecw_email_messages WHERE id = ?', [$e['message_id']]),
        array_filter($tl['events'], static fn($e) => !empty($e['message_id'])))), 'the case timeline too');

    // ------------------------------------------------------------------ moves
    group('an email moved to another folder');
    $mv = ecw_case_create($org, 'Moves', $ann);
    $tm = $ingest($graph('MV-A', 'conv-mv', 'buyer@cust.test', 50), $inbox, 'inbox')['thread_id'];
    $ingest($graph('MV-B', 'conv-mv', 'buyer@cust.test', 40), $inbox, 'inbox');
    $ingest($graph('MV-C', 'conv-mv', 'buyer@cust.test', 35), $inbox, 'inbox');
    ecw_case_link_thread($mv, $tm, null, $ann);
    $before = $visibleInCase($mv);
    $rowA = (int)$one('SELECT id FROM ecw_email_messages WHERE provider_message_id = ? AND mailbox_id = ?', ['MV-A', $mb]);
    // Order 1: "appeared in Archive" (a new id, as Outlook gives) before "removed from Inbox".
    $delta($archive, [$graph('MV-A-NEW', 'conv-mv', 'buyer@cust.test', 50, ['internetMessageId' => '<MV-A@rel.test>'])]);
    $delta($inbox, [['id' => 'MV-A', '@removed' => ['reason' => 'deleted']]]);
    $a = $pdo->query('SELECT id, mail_folder_id, provider_removed_at, email_thread_id FROM ecw_email_messages WHERE id = ' . $rowA)->fetch();
    check($a['provider_removed_at'] === null && (int)$a['mail_folder_id'] === $archive && (int)$a['email_thread_id'] === $tm, 'added-then-removed: the same email, now in Archive, still in its case');
    // Order 2: "removed from Inbox" first, then "appeared in Archive".
    $rowB = (int)$one('SELECT id FROM ecw_email_messages WHERE provider_message_id = ? AND mailbox_id = ?', ['MV-B', $mb]);
    $delta($inbox, [['id' => 'MV-B', '@removed' => ['reason' => 'deleted']]]);
    same($before - 1, $visibleInCase($mv), 'removed-then-added: for a moment it is gone…');
    $delta($archive, [$graph('MV-B-NEW', 'conv-mv', 'buyer@cust.test', 40, ['internetMessageId' => '<MV-B@rel.test>'])]);
    $b = $pdo->query('SELECT id, mail_folder_id, provider_removed_at FROM ecw_email_messages WHERE id = ' . $rowB)->fetch();
    check($b['provider_removed_at'] === null && (int)$b['mail_folder_id'] === $archive, '…and back, the same email in Archive, as soon as it shows up there');
    same($before, $visibleInCase($mv), 'the case has all its emails again, none twice');
    same(3, (int)$one('SELECT COUNT(*) FROM ecw_email_messages WHERE email_thread_id = ?', [$tm]), 'no copies were made by the moves');
    // Deleted for good: removed and never seen again.
    $delta($inbox, [['id' => 'MV-C', '@removed' => ['reason' => 'deleted']]]);
    same($before - 1, $visibleInCase($mv), 'an email deleted for good leaves the case’s visible emails…');
    check($one('SELECT id FROM ecw_email_messages WHERE provider_message_id = ? AND mailbox_id = ?', ['MV-C', $mb]) !== false, '…but its row is kept (legal hold, history)');
    same(1, (int)$one('SELECT COUNT(*) FROM ecw_case_threads WHERE case_id = ?', [$mv]), 'and the case keeps its conversation, linked once');

    // ------------------------------------------------------------------ ownership
    group('who has it does not change by itself');
    $tc = $ingest($graph('CL-1', 'conv-claim', 'buyer@cust.test', 25), $inbox, 'inbox')['thread_id'];
    ecw_thread_claim($org, $ann, $mb, $tc, $ann);
    $own = ecw_case_create($org, 'Claimed', $ann);
    ecw_case_link_thread($own, $tc, null, $ann);
    $pdo->prepare('UPDATE ecw_cases SET owner_user_id = ?, handler_user_id = ? WHERE id = ?')->execute([$bob, $ann, $own]);
    $ingest($graph('CL-1', 'conv-claim', 'buyer@cust.test', 25), $inbox, 'inbox');                                         // replay
    $delta($archive, [$graph('CL-1-NEW', 'conv-claim', 'buyer@cust.test', 25, ['internetMessageId' => '<CL-1@rel.test>'])]);  // move
    $ingest($graph('CL-2', 'conv-claim', 'buyer@cust.test', 5), $inbox, 'inbox');                                          // new reply
    same($ann, (int)$one('SELECT claimed_by_user_id FROM ecw_email_threads WHERE id = ?', [$tc]), 'a claimed conversation stays claimed through a replay, a move and a new reply');
    same([$bob, $ann], [(int)$one('SELECT owner_user_id FROM ecw_cases WHERE id = ?', [$own]), (int)$one('SELECT handler_user_id FROM ecw_cases WHERE id = ?', [$own])], 'the case keeps its Responsible and Handled by');
    same(2, (int)$one('SELECT COUNT(*) FROM ecw_email_messages WHERE email_thread_id = ? AND provider_removed_at IS NULL', [$tc]), 'and has both emails, once each');

    // ------------------------------------------------------------------ sign-in lost
    group('the sign-in expires, then comes back');
    $pdo->prepare('UPDATE ecw_mail_folders SET delta_link_encrypted = NULL WHERE id = ?')->execute([$inbox]);
    $delta($inbox, [$graph('SG-1', 'conv-sg', 'buyer@cust.test', 15)]);
    $cursor = ecw_folder_delta_cursor($inbox);
    $pdo->prepare('UPDATE ecw_mailboxes SET token_expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $mb]);
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('POST', '#/oauth2/v2.0/token#', ['status' => 400, 'body' => ['error' => 'invalid_grant', 'error_description' => 'AADSTS70000']]);
    $fake->on('GET', '#delta#', ['body' => ['value' => [$graph('SG-2', 'conv-sg', 'buyer@cust.test', 10)], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/never']]);
    $fake->install();
    try { ecw_sync_folder_delta($mb, $inbox, 10, 50); check(false, 'a sync without a valid sign-in stops'); } catch (Throwable $e) { check(true, 'a sync without a valid sign-in stops'); }
    same('needs_reauth', (string)$one('SELECT sync_status FROM ecw_mailboxes WHERE id = ?', [$mb]), 'the mailbox says it must be reconnected (System health shows it)');
    same($cursor, ecw_folder_delta_cursor($inbox), 'the place it had reached is kept: nothing is skipped');
    same(0, $rowsOf('SG-2'), 'and nothing half-stored');
    // Reconnected: the same place onwards, mail of the gap included, once.
    $pdo->prepare("UPDATE ecw_mailboxes SET sync_status = 'connected', access_token_encrypted = ?, token_expires_at = ? WHERE id = ?")
        ->execute([ecw_encrypt('tok2'), gmdate('Y-m-d H:i:s', time() + 3600), $mb]);
    FakeGraph::uninstall();
    $fake = new FakeGraph();
    $fake->on('GET', '#' . preg_quote((string)parse_url((string)$cursor, PHP_URL_PATH), '#') . '#', ['body' => ['value' => [$graph('SG-1', 'conv-sg', 'buyer@cust.test', 15), $graph('SG-2', 'conv-sg', 'buyer@cust.test', 10)],
        '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/after-gap']]);
    $fake->install();
    ecw_sync_folder_delta($mb, $inbox, 10, 50);
    same([1, 1], [$rowsOf('SG-1'), $rowsOf('SG-2')], 'after reconnecting, the email of the gap arrives, and the earlier one is not doubled');
    same('https://graph.microsoft.com/v1.0/after-gap', ecw_folder_delta_cursor($inbox), 'and it carries on from there');

    // ------------------------------------------------------------------ a case from a folder
    group('a case started from a folder, delivered twice');
    $intake = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'F-INTAKE', 'name' => 'New case', 'folder_type' => 'custom', 'is_selected_for_sync' => 1, 'is_case_intake' => 1]);
    $cases0 = (int)$one('SELECT COUNT(*) FROM ecw_cases WHERE organization_id = ?', [$org]);
    $in = $graph('IN-1', 'conv-intake', 'buyer@cust.test', 8, ['subject' => 'Broken valve']);
    $delta($intake, [$in, $in]);
    $delta($intake, [$in]);
    same($cases0 + 1, (int)$one('SELECT COUNT(*) FROM ecw_cases WHERE organization_id = ?', [$org]), 'one case, however often the email is delivered');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
