<?php declare(strict_types=1);
/**
 * Send later held by Outlook (lib/compose.php ecw_draft_hold_in_outlook): the email goes to
 * Outlook at once with its send time (PR_DEFERRED_SEND_TIME) and Exchange sends it then, this
 * PC on or off. The app never sends it a second time; a change replaces the held copy; Cancel
 * and Delete take it back; once Outlook sent it, the draft goes. Gmail stays with the app.
 *
 *   php tests/outlook_schedule_test.php
 *
 * A scripted Graph (nothing reaches Microsoft); runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/compose.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Held send']);
    $user = $ins('users', ['name' => 'Sender', 'email' => 's' . $org . '@held.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $user, 'role' => 'admin']);
    $tokens = ['access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)];
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'held-' . $org, 'email_address' => 'me@held' . $org . '.test', 'sync_status' => 'connected', 'connected_by_user_id' => $user] + $tokens);
    $row = static fn(int $id) => $pdo->query('SELECT send_state, send_at, outlook_internet_id, outlook_held_id, outlook_schedule_dirty, subject FROM `' . ecw_table('drafts') . '` WHERE id = ' . $id)->fetch();
    $later = gmdate('Y-m-d H:i:s', time() + 86400);
    $newDraft = static fn(string $subject) => ecw_draft_save($org, $mb, $user, ['subject' => $subject, 'body_html' => '<p>Offer attached</p>', 'to' => ['customer@client.test']]);

    echo "\n=== Send later: handed to Outlook ===\n";
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'D1', 'internetMessageId' => '<held-1@x>']], ['status' => 201, 'body' => ['id' => 'D2', 'internetMessageId' => '<held-2@x>']]);
    $fake->on('POST', '#/me/messages/D[12]/send$#', ['status' => 202]);
    $fake->on('GET', '#/mailFolders/outbox/messages#', ['body' => ['value' => [['id' => 'OUT1']]]]);
    $fake->install();
    $draft = $newDraft('Offer for Monday');
    $at = ecw_draft_schedule($org, $draft, $later);
    $create = $fake->callsMatching('#/me/messages$#')[0]['json'] ?? [];
    $prop = $create['singleValueExtendedProperties'][0] ?? [];
    check(($prop['id'] ?? '') === 'SystemTime 0x3FEF' && ($prop['value'] ?? '') === str_replace(' ', 'T', $later) . 'Z', 'the email goes to Outlook with Outlook\'s own send time', json_encode($prop));
    check(count($fake->callsMatching('#/D1/send$#')) === 1, 'and is handed over now (Exchange holds it until then)');
    $r = $row($draft);
    check($r['send_state'] === 'scheduled' && $r['outlook_internet_id'] === '<held-1@x>' && $r['outlook_held_id'] === 'OUT1' && $at === $later, 'the draft remembers what Outlook holds', json_encode($r));
    $sends = count($fake->callsMatching('#/send$#'));
    $pdo->prepare('UPDATE `' . ecw_table('drafts') . '` SET send_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 5), $draft]);
    $fake->on('GET', '#/mailFolders/drafts/messages#', ['body' => ['value' => []]]);
    $fake->on('GET', '#/mailFolders/sentitems/messages#', ['body' => ['value' => []]]);
    ecw_drafts_send_due();
    check(count($fake->callsMatching('#/send$#')) === $sends && $row($draft)['send_state'] === 'scheduled', 'at its time the app does not send it a second time: Outlook does');
    $pdo->prepare('UPDATE `' . ecw_table('drafts') . '` SET send_at = ? WHERE id = ?')->execute([$later, $draft]);

    echo "\n=== changed while scheduled ===\n";
    ecw_draft_save($org, $mb, $user, ['subject' => 'Offer for Monday (v2)', 'body_html' => '<p>Offer attached</p>', 'to' => ['customer@client.test'], 'keep_schedule' => true], $draft);
    check((int)$row($draft)['outlook_schedule_dirty'] === 1 && $row($draft)['send_state'] === 'scheduled', 'a change keeps it scheduled and marks the held copy to be replaced');
    $fake->on('POST', '#/messages/OUT1/permanentDelete$#', ['status' => 204]);
    ecw_drafts_outlook_followup();
    $r = $row($draft);
    check(count($fake->callsMatching('#/OUT1/permanentDelete$#')) === 1 && $r['outlook_internet_id'] === '<held-2@x>' && (int)$r['outlook_schedule_dirty'] === 0,
        'the old held copy is taken back and the new one handed over', json_encode($r));
    check(($fake->callsMatching('#/me/messages$#')[1]['json']['subject'] ?? '') === 'Offer for Monday (v2)', 'the new copy is the changed email');

    echo "\n=== Cancel schedule ===\n";
    $deletes = count($fake->callsMatching('#/permanentDelete$#'));
    check(ecw_draft_unschedule($org, $draft) === true && count($fake->callsMatching('#/permanentDelete$#')) === $deletes + 1, 'Cancel takes it back from Outlook');
    $r = $row($draft);
    check($r['send_state'] === null && $r['outlook_internet_id'] === null, 'and it is a draft again', json_encode($r));
    FakeGraph::uninstall();

    echo "\n=== Outlook sent it ===\n";
    $sent = $newDraft('Sent by Outlook');
    $pdo->prepare('UPDATE `' . ecw_table('drafts') . "` SET send_state = 'scheduled', send_at = ?, outlook_internet_id = '<held-9@x>' WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - 120), $sent]);
    $fake = new FakeGraph();
    $fake->on('GET', '#/mailFolders/outbox/messages#', ['body' => ['value' => []]]);
    $fake->on('GET', '#/mailFolders/drafts/messages#', ['body' => ['value' => []]]);
    $fake->on('GET', '#/mailFolders/sentitems/messages#', ['body' => ['value' => [['id' => 'SENT9']]]]);
    $fake->on('GET', '#/me/messages/SENT9#', ['body' => FakeGraph::message(['id' => 'SENT9', 'subject' => 'Sent by Outlook', 'internetMessageId' => '<held-9@x>'])]);
    $fake->on('GET', '#/attachments#', ['body' => ['value' => []]]);
    $fake->install();
    ecw_drafts_outlook_followup();
    check($row($sent) === false, 'once Outlook sent it, the draft goes');
    check((int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . "` WHERE mailbox_id = {$mb} AND provider_message_id = 'SENT9'")->fetchColumn() === 1, 'and its Sent copy comes in at once');
    FakeGraph::uninstall();

    echo "\n=== deleting a scheduled email ===\n";
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'D5', 'internetMessageId' => '<held-5@x>']]);
    $fake->on('POST', '#/me/messages/D5/send$#', ['status' => 202]);
    $fake->on('GET', '#/mailFolders/outbox/messages#', ['body' => ['value' => [['id' => 'OUT5']]]]);
    $fake->on('POST', '#/messages/OUT5/permanentDelete$#', ['status' => 204]);
    $fake->install();
    $gone = $newDraft('Delete me');
    ecw_draft_schedule($org, $gone, $later);
    ecw_draft_delete($org, $gone);
    check(count($fake->callsMatching('#/OUT5/permanentDelete$#')) === 1 && $row($gone) === false, 'deleting it takes it back from Outlook first, so it never goes');
    FakeGraph::uninstall();

    echo "\n=== when Outlook cannot take it ===\n";
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 503, 'body' => ['error' => ['code' => 'ServiceUnavailable']]]);
    $fake->install();
    $local = $newDraft('Kept by the app');
    ecw_draft_schedule($org, $local, $later);
    $r = $row($local);
    check($r['send_state'] === 'scheduled' && $r['outlook_internet_id'] === null, 'not handed over: it stays scheduled in the app, as before', json_encode($r));
    FakeGraph::uninstall();
    $soon = $newDraft('In a minute');
    $calls = 0;
    GraphHttp::setTestTransport(static function () use (&$calls): array { $calls++; return ['status' => 500, 'body' => []]; });
    ecw_draft_schedule($org, $soon, gmdate('Y-m-d H:i:s', time() + 60));
    check($calls === 0 && $row($soon)['outlook_internet_id'] === null, 'a time a minute away (and Send with its Undo) stays with the app');
    GraphHttp::setTestTransport(null);

    echo "\n=== the hand-over breaks off ===\n";
    // Outlook did take it (it is in the Outbox): handed over, never kept by the app as well.
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'D7', 'internetMessageId' => '<held-7@x>']]);
    $fake->on('POST', '#/me/messages/D7/send$#', ['status' => 504, 'body' => ['error' => ['code' => 'GatewayTimeout']]]);
    $fake->on('GET', '#/mailFolders/outbox/messages#', ['body' => ['value' => [['id' => 'OUT7']]]]);
    $fake->install();
    $broke = $newDraft('Broke but taken');
    ecw_draft_schedule($org, $broke, $later);
    check($row($broke)['outlook_internet_id'] === '<held-7@x>', 'Outlook took it although the answer broke off: it counts as handed over (sent once, by Outlook)');
    FakeGraph::uninstall();
    // Outlook did not take it (still the unsent draft): that copy is removed, the app keeps it.
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'D8', 'internetMessageId' => '<held-8@x>']]);
    $fake->on('POST', '#/me/messages/D8/send$#', ['status' => 504, 'body' => ['error' => ['code' => 'GatewayTimeout']]]);
    $fake->on('GET', '#/mailFolders/outbox/messages#', ['body' => ['value' => []]]);
    $fake->on('GET', '#/mailFolders/drafts/messages#', ['body' => ['value' => [['id' => 'D8']]]]);
    $fake->on('DELETE', '#/me/messages/D8$#', ['status' => 204]);
    $fake->install();
    $kept = $newDraft('Broke and not taken');
    ecw_draft_schedule($org, $kept, $later);
    check($row($kept)['outlook_internet_id'] === null && count($fake->callsMatching('#/me/messages/D8$#')) === 1, 'not taken: the unsent copy in Outlook is removed and the app keeps the schedule (no second email)');
    FakeGraph::uninstall();

    echo "\n=== Sent once, from any copy of the app (2026-10-29) ===\n";
    $key = static fn(int $id) => (string)$pdo->query('SELECT send_key FROM `' . ecw_table('drafts') . '` WHERE id = ' . $id)->fetchColumn();
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'K1', 'internetMessageId' => '<key-1@x>']]);
    $fake->on('POST', '#/me/messages/K1/send$#', ['status' => 202]);
    $fake->on('GET', '#/mailFolders/outbox/messages\?\$select=id&#', ['body' => ['value' => [['id' => 'OUTK1']]]]);
    $fake->on('POST', '#/me/messages/OUTK1/permanentDelete$#', ['status' => 204]);
    $fake->install();
    $k = $newDraft('Keyed offer');
    ecw_draft_schedule($org, $k, $later);
    $first = $key($k);
    $sentProps = array_column((array)($fake->callsMatching('#/me/messages$#')[0]['json']['singleValueExtendedProperties'] ?? []), 'value');
    check(preg_match('/^[0-9a-f]{32}$/', $first) === 1 && in_array($first, $sentProps, true), 'a scheduled email gets a send key, and the email in Outlook carries it');
    ecw_draft_unschedule($org, $k);
    ecw_draft_schedule($org, $k);
    check($key($k) === $first, 'it keeps its key when scheduled again (every copy of the database knows it by the same one)');
    FakeGraph::uninstall();

    // The laptop sent it from its copy of the database; this PC's copy still says "scheduled".
    $fake = new FakeGraph();
    $fake->on('GET', '#/mailFolders/sentitems/messages\?.*EcwSendKey#', ['body' => ['value' => [['id' => 'SENT9', 'internetMessageId' => '<laptop@x>']]]]);
    $fake->on('GET', '#.#', ['body' => ['value' => []]]);
    $fake->install();
    $pdo->exec("UPDATE `" . ecw_table('drafts') . "` SET send_state = 'scheduled', send_at = '2000-01-01 00:00:00', outlook_internet_id = NULL WHERE id = " . $k);
    ecw_drafts_send_due();
    check($row($k) === false && $fake->callsMatching('#/send$#') === [], 'already sent from another copy of the app: not sent again, the draft goes');
    FakeGraph::uninstall();

    // Another copy handed it to Outlook: this one follows it, and hands nothing over itself.
    $h = $newDraft('Held by the laptop');
    ecw_draft_schedule($org, $h);   // within the Undo window: no hand-over yet
    $pdo->exec("UPDATE `" . ecw_table('drafts') . "` SET send_at = '" . $later . "' WHERE id = " . $h);
    $fake = new FakeGraph();
    $fake->on('GET', '#/mailFolders/outbox/messages\?.*EcwSendKey#', ['body' => ['value' => [['id' => 'OUTL', 'internetMessageId' => '<laptop-held@x>']]]]);
    $fake->on('GET', '#.#', ['body' => ['value' => []]]);
    $fake->install();
    ecw_draft_hold_in_outlook($org, $h);
    check($row($h)['outlook_internet_id'] === '<laptop-held@x>' && $fake->callsMatching('#/me/messages$#') === [], 'already held by Outlook from another copy: followed, not handed over twice');
    FakeGraph::uninstall();

    // Outlook cannot be asked (no connection, an antivirus in between): it waits, nothing is sent.
    $w = $newDraft('Waits for Outlook');
    ecw_draft_schedule($org, $w);
    $pdo->exec("UPDATE `" . ecw_table('drafts') . "` SET send_at = '2000-01-01 00:00:00' WHERE id = " . $w);
    $sends = 0;
    GraphHttp::setTestTransport(static function (string $method, string $url) use (&$sends): array {
        if (str_contains($url, '/send')) $sends++;
        throw new RuntimeException('ecw: Graph GET transport error: SSL certificate problem: unable to get local issuer certificate');
    });
    ecw_drafts_send_due();
    check($row($w)['send_state'] === 'scheduled' && $sends === 0, 'Outlook out of reach: it waits for the next run, nothing is sent (it might go twice)');
    GraphHttp::setTestTransport(null);

    // A hand-over that failed is tried again a few minutes later: then Outlook holds it.
    $r = $newDraft('Handed over later');
    GraphHttp::setTestTransport(static function (): array { throw new RuntimeException('ecw: Graph POST transport error: no network'); });
    ecw_draft_schedule($org, $r, $later);
    check($row($r)['outlook_internet_id'] === null, 'no connection when it was scheduled: not with Outlook yet');
    GraphHttp::setTestTransport(null);
    $pdo->exec("UPDATE `" . ecw_table('drafts') . "` SET outlook_hold_tried_at = '2000-01-01 00:00:00' WHERE id = " . $r);
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'R1', 'internetMessageId' => '<retry@x>']]);
    $fake->on('POST', '#/me/messages/R1/send$#', ['status' => 202]);
    $fake->on('GET', '#/mailFolders/outbox/messages\?\$select=id&#', ['body' => ['value' => [['id' => 'OUTR']]]]);
    $fake->on('GET', '#.#', ['body' => ['value' => []]]);
    $fake->install();
    ecw_drafts_outlook_followup();
    check($row($r)['outlook_internet_id'] === '<retry@x>', 'tried again a few minutes later: now Outlook holds it, and it goes with this PC off');
    FakeGraph::uninstall();

    echo "\n=== Gmail ===\n";
    $gmb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'google', 'provider_account_id' => 'gheld-' . $org, 'email_address' => 'me@gheld' . $org . '.test', 'sync_status' => 'connected', 'connected_by_user_id' => $user] + $tokens);
    $calls = 0;
    GraphHttp::setTestTransport(static function () use (&$calls): array { $calls++; return ['status' => 500, 'body' => []]; });
    $g = ecw_draft_save($org, $gmb, $user, ['subject' => 'Gmail one', 'body_html' => '<p>x</p>', 'to' => ['c@client.test']]);
    ecw_draft_schedule($org, $g, $later);
    check($calls === 0 && $row($g)['outlook_internet_id'] === null && $row($g)['send_state'] === 'scheduled', 'Gmail has no such thing: it stays with the app');
    GraphHttp::setTestTransport(null);
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
