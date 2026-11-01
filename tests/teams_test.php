<?php declare(strict_types=1);
/**
 * Starting Teams chats from the app (lib/teams.php ecw_teams_new_chat,
 * ecw_teams_people): one person, a group with a name, the first message,
 * people outside the organization, and the people suggestions.
 *
 *   php tests/teams_test.php
 *
 * Microsoft is scripted with FakeGraph. Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/teams.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

ecw_set_graph_config_override(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://example.test/cb']);
$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Teams chats']);
    $me = $ins('users', ['name' => 'Maria', 'email' => 'maria' . $org . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $nick = $ins('users', ['name' => 'Nick', 'email' => 'nick' . $org . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $nick, 'role' => 'member']);
    $mb = $ins('mailboxes', [
        'organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-me', 'email_address' => 'maria@acme.test',
        'sync_status' => 'connected', 'connected_by_user_id' => $me, 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'teams_enabled' => 1,
        'teams_token_encrypted' => ecw_encrypt('teams-token'), 'teams_token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);

    group('one person');
    $g = new FakeGraph();
    $g->on('GET', '#/chats\?#', ['body' => ['value' => []]])
      ->on('POST', '#/chats$#', ['body' => ['id' => '19:one', 'webUrl' => 'https://teams/one']])
      ->on('POST', '#/chats/19%3Aone/messages$#', ['body' => ['id' => 'm1']]);
    $g->install();
    $chat = ecw_teams_new_chat($me, [' Nick@Acme.test '], 'ignored for one', "Hi Nick\nAre you in?");
    $posts = $g->callsMatching('#/chats$#');
    $sent = $g->callsMatching('#/messages$#');
    check($chat['id'] === '19:one' && $posts[0]['json']['chatType'] === 'oneOnOne' && !isset($posts[0]['json']['topic']), 'a one-to-one chat, no group name');
    check(count($sent) === 1 && $sent[0]['json']['body']['content'] === 'Hi Nick<br />' . "\n" . 'Are you in?', 'the first message was sent into it, line breaks kept', json_encode($sent));

    group('a group with a name');
    $g = new FakeGraph();
    $g->on('POST', '#/chats$#', ['body' => ['id' => '19:grp', 'webUrl' => 'https://teams/grp']]);
    $g->install();
    $chat = ecw_teams_new_chat($me, ['nick@acme.test', 'eva@acme.test', 'NICK@acme.test', 'maria@acme.test'], '  Pump   delivery ', '');
    $body = $g->callsMatching('#/chats$#')[0]['json'];
    $binds = array_column($body['members'], 'user@odata.bind');
    check($body['chatType'] === 'group' && $body['topic'] === 'Pump delivery' && $chat['title'] === 'Pump delivery', 'a group chat with its name');
    check(count($binds) === 3 && str_contains($binds[0], "users('oid-me')") && str_contains($binds[2], "users('eva@acme.test')"), 'me plus each person once; my own address is not added twice', json_encode($binds));
    check($g->callsMatching('#/messages#') === [], 'no message sent when none was written');

    group('someone outside the organization');
    $g = new FakeGraph();
    $g->on('POST', '#/chats$#', ['status' => 404, 'body' => ['error' => ['code' => 'NotFound']]]);
    $g->install();
    try {
        ecw_teams_new_chat($me, ['nick@acme.test', 'john@customer.test'], 'Pump', 'Hello both');
        check(false, 'a group with an outsider opens in Teams');
    } catch (EcwTeamsOpenInTeams $e) {
        check(str_contains($e->url, 'users=nick%40acme.test%2Cjohn%40customer.test') && str_contains($e->url, 'topicName=Pump') && str_contains($e->url, 'message=Hello%20both'), 'a group with an outsider: a Teams link with the people, the name and the message', $e->url);
    }
    $g = new FakeGraph();
    $g->on('GET', '#/chats\?#', ['body' => ['value' => []]])->on('POST', '#/chats$#', ['status' => 404, 'body' => []]);
    $g->install();
    try {
        ecw_teams_new_chat($me, ['john@customer.test'], '', 'Hi John');
        check(false, 'one outsider opens in Teams');
    } catch (EcwTeamsOpenInTeams $e) {
        check(str_contains($e->url, 'users=john%40customer.test') && str_contains($e->url, 'message=Hi%20John') && !str_contains($e->getMessage(), 'new tab'), 'one outsider: the link carries the message too');
    }

    group('checks before asking Microsoft');
    foreach ([[[], 'Add at least one person'], [['not-an-email'], 'is not an email'], [['maria@acme.test'], 'your own address']] as [$in, $msg]) {
        try { ecw_teams_new_chat($me, $in); check(false, $msg); } catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), $msg), 'refused: ' . $msg); }
    }

    group('people to chat with');
    $thread = $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'c-' . $org, 'normalized_subject' => 'x']);
    foreach (['john@customer.test', 'john@customer.test', 'noreply@shop.test'] as $i => $to) {
        $m = $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread, 'provider_message_id' => 'p' . $i, 'subject' => 'x', 'direction' => 'outgoing', 'sent_at' => gmdate('Y-m-d H:i:s')]);
        $ins('message_participants', ['email_message_id' => $m, 'role' => 'to', 'email' => $to, 'name' => 'John']);
    }
    $people = array_column(ecw_teams_people($org, $me), null, 'email');
    $nickMail = 'nick' . $org . '@acme.test';
    check(isset($people[$nickMail]) && $people[$nickMail]['from'] === 'colleague' && $people[$nickMail]['inside'] === true, 'colleagues, marked inside the organization');
    check(isset($people['john@customer.test']) && $people['john@customer.test']['from'] === 'emailed' && $people['john@customer.test']['inside'] === false, 'people I email, marked outside');
    check(!isset($people['noreply@shop.test']) && !isset($people['maria@acme.test']), 'no no-reply addresses, not myself');

    group('pictures in messages');
    $hc = 'https://graph.microsoft.com/v1.0/chats/19:abc@thread.v2/messages/1700000000000/hostedContents/aWQ9eF8wLXd1cy1kMy1hYmM=/$value';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $g = new FakeGraph();
    $g->on('GET', '#/chats/19%3Aabc%40thread\.v2/messages\?#', ['body' => ['value' => [[
        'id' => '1700000000000', 'messageType' => 'message', 'createdDateTime' => '2026-10-31T10:00:00Z', 'from' => ['user' => ['id' => 'oid-me', 'displayName' => 'Maria']],
        'body' => ['contentType' => 'html', 'content' => '<p>Look</p><p><img src="' . $hc . '" width="300" alt="image"></p><img src="https://evil.test/track.gif">'],
    ]]]])
      ->on('GET', '#/hostedContents/aWQ9eF8wLXd1cy1kMy1hYmM%3D/\$value$#', ['body' => ['raw' => $png], 'headers' => ['content-type' => 'image/png']])
      ->install();
    [$http, $teamsMb] = ecw_teams_client($me);
    $msgs = ecw_teams_fetch_messages($http, $teamsMb, '19:abc@thread.v2');
    $html = $msgs[0]['html'] ?? '';
    check(str_contains($html, 'src="api/index.php?action=teams_image&amp;chat_id=19%3Aabc%40thread.v2&amp;message_id=1700000000000&amp;hc=aWQ9eF8wLXd1cy1kMy1hYmM%3D"'), 'a picture sent in Teams points at this app, which fetches it with the person\'s sign-in', $html);
    check(!str_contains($html, 'graph.microsoft.com') && str_contains($html, 'data-ecw-src="https://evil.test/track.gif"'), 'no Microsoft address left in the page; a picture from elsewhere stays blocked');
    $img = ecw_teams_image($me, '19:abc@thread.v2', '1700000000000', 'aWQ9eF8wLXd1cy1kMy1hYmM=');
    check($img['bytes'] === $png && $img['mime'] === 'image/png', 'the picture itself comes back, as a picture');
    $bad = false; try { ecw_teams_image($me, '19:abc', '1', 'x?y&z'); } catch (InvalidArgumentException $e) { $bad = true; }
    check($bad, 'an odd picture address is refused');
    FakeGraph::uninstall();
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);