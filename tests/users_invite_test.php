<?php declare(strict_types=1);
/**
 * Settings → Users: add someone with name, email and role only. No password is set by anyone:
 * they get an email with a link to choose their own (lib/account_security.php
 * ecw_invite_email_send), and cannot sign in before. Delete for good removes a disabled person
 * completely (lib/auth.php ecw_admin_delete_user), so they can be added again.
 *
 *   php tests/users_invite_test.php
 *
 * A scripted Graph (no email reaches anyone); runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/account_security.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once dirname(__DIR__) . '/lib/mailbox.php';
require_once dirname(__DIR__) . '/lib/license.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Invite Co']);
    $admin = $ins('users', ['name' => 'Admin Person', 'email' => 'admin' . $org . '@invite.test', 'password_hash' => password_hash('admin-password-1', PASSWORD_DEFAULT)]);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);
    $tokens = ['access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)];
    $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'inv-' . $org, 'email_address' => 'office@invite' . $org . '.test', 'sync_status' => 'connected', 'connected_by_user_id' => $admin] + $tokens);
    $email = 'new.colleague' . $org . '@invite.test';

    echo "\n=== add: name, email, role; no password ===\n";
    $r = ecw_admin_create_user($admin, $org, ['name' => 'Maria Colleague', 'email' => $email, 'role' => 'user', 'invite' => true]);
    check($r['ok'], 'added with name, email and role only', (string)$r['error']);
    $uid = (int)$r['user_id'];
    $hash = $pdo->query('SELECT password_hash FROM `' . ecw_table('users') . '` WHERE id = ' . $uid)->fetchColumn();
    check($hash === ECW_INVITED_HASH && ecw_user_is_invited($uid), 'no password is stored: only the "invited" mark');
    check(!ecw_attempt_login($email, '', '10.0.0.1')['ok'] && !ecw_attempt_login($email, ECW_INVITED_HASH, '10.0.0.1')['ok'], 'nobody can sign in to it yet, whatever they type');

    echo "\n=== the invitation email ===\n";
    $fake = new FakeGraph();
    $fake->on('POST', '#/me/messages$#', ['status' => 201, 'body' => ['id' => 'INV1', 'internetMessageId' => '<inv1@x>']]);
    $fake->on('POST', '#/me/messages/INV1/send$#', ['status' => 202]);
    $fake->on('GET', '#/mailFolders/sentitems/messages#', ['body' => ['value' => [['id' => 'S1']]]]);
    $fake->install();
    // The address colleagues use is not set, and this runs on localhost: nothing broken is sent.
    ecw_app_setting_set('app_url', null);
    $_SERVER['HTTP_HOST'] = 'localhost:8080';
    putenv('ECW_LAN_IP=off');
    $none = ecw_invite_email_send($org, $uid, $admin);
    check(!$none['sent'] && $none['link'] === null && $fake->callsMatching('#/me/messages$#') === [] && str_contains((string)$none['error'], 'address colleagues use'),
        'no address for colleagues and only localhost: no email with a link that works only on this PC', json_encode($none));
    putenv('ECW_LAN_IP=192.168.5.7');
    check(ecw_email_link_base() === 'http://192.168.5.7:8080', 'not set, on localhost: this PC\'s network address, same port', (string)ecw_email_link_base());
    ecw_app_url_save('http://192.168.2.2:8080');
    check(ecw_app_url() === 'http://192.168.5.7:8080', 'a saved office-network address follows this PC to another network', (string)ecw_app_url());
    ecw_app_url_save('https://cases.example.com');
    check(ecw_app_url() === 'https://cases.example.com', 'a web address stays as saved');
    putenv('ECW_LAN_IP=192.168.2.2');
    ecw_app_url_save('http://192.168.2.2:8080');
    $sent = ecw_invite_email_send($org, $uid, $admin);
    $mail = $fake->callsMatching('#/me/messages$#')[0]['json'] ?? [];
    $body = (string)($mail['body']['content'] ?? '');
    check($sent['sent'] && $sent['link'] === null, 'it is emailed (the admin sees no link and no password)');
    check(($mail['toRecipients'][0]['emailAddress']['address'] ?? '') === $email && str_contains((string)$mail['subject'], 'Admin Person'), 'to the new person, saying who invited them', json_encode($mail['subject'] ?? null));
    check(!str_contains((string)$mail['subject'] . $body, 'Invite Co') && !str_contains((string)$mail['subject'] . $body, 'Case Workspace'), 'without the workspace\'s internal name or the product\'s name', (string)$mail['subject'] . ' || ' . strip_tags($body));
    check(str_contains($body, 'http://192.168.2.2:8080') && !str_contains($body, 'ts.net'), 'the link opens the address colleagues use, not the public webhook address');
    check(preg_match('#reset\.php\?token=([A-Za-z0-9_-]+)#', $body, $m) === 1 && str_contains($body, 'Set my password') && str_contains($body, '3 days'), 'with a "Set my password" link that works for 3 days');
    $token = $m[1] ?? '';
    FakeGraph::uninstall();

    echo "\n=== they choose their password ===\n";
    $who = ecw_password_reset_user($token);
    check($who !== null && (int)$who['id'] === $uid && (int)$who['invited'] === 1, 'the link opens their welcome page (it knows they are new)');
    $exp = $pdo->query('SELECT expires_at FROM `' . ecw_table('password_resets') . "` WHERE token_hash = " . $pdo->quote(hash('sha256', $token)))->fetchColumn();
    check(abs(strtotime($exp . ' UTC') - (time() + 72 * 3600)) < 120, 'it lasts 3 days (a reset link 24 hours)');
    $use = ecw_password_reset_use($token, 'my own secret 2026');
    check($use['ok'] && !ecw_user_is_invited($uid) && ecw_attempt_login($email, 'my own secret 2026', '10.0.0.2')['ok'], 'their own password works, and they are no longer "invited"');
    check(ecw_password_reset_user($token) === null, 'the link works only once');

    echo "\n=== no mailbox to send from ===\n";
    $org2 = $ins('organizations', ['name' => 'No Mail Co']);
    $admin2 = $ins('users', ['name' => 'Admin Two', 'email' => 'admin2' . $org2 . '@invite.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org2, 'user_id' => $admin2, 'role' => 'admin']);
    $r2 = ecw_admin_create_user($admin2, $org2, ['name' => 'Nick', 'email' => 'nick' . $org2 . '@invite.test', 'role' => 'user', 'invite' => true]);
    $s2 = ecw_invite_email_send($org2, (int)$r2['user_id'], $admin2);
    check(!$s2['sent'] && str_contains((string)$s2['link'], 'reset.php?token='), 'no mailbox to send from: the link is given to the admin to pass on (still no password)');

    echo "\n=== delete for good ===\n";
    $case = $ins('cases', ['organization_id' => $org, 'case_number' => 'INV-' . $org, 'name' => 'Their case', 'status' => 'open', 'owner_user_id' => $uid, 'created_by_user_id' => $uid]);
    $theirBox = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'their-' . $org, 'email_address' => $email, 'sync_status' => 'disconnected', 'connected_by_user_id' => $uid]);
    $ins('rules', ['organization_id' => $org, 'user_id' => $uid, 'kind' => 'color', 'name' => 'Their rule', 'conditions_json' => '[]', 'actions_json' => '[]', 'created_at' => gmdate('Y-m-d H:i:s')]);
    check(!ecw_admin_delete_user($admin, $org, $uid)['ok'], 'an active person cannot be deleted: disable first');
    check(!ecw_admin_delete_user($admin, $org, $admin)['ok'], 'nobody deletes themselves');
    ecw_admin_set_user_disabled($admin, $org, $uid, true);
    $d = ecw_admin_delete_user($admin, $org, $uid);
    check($d['ok'] && $d['mailboxes'] === 1, 'a disabled person is deleted, with the mailbox they connected', json_encode($d));
    $left = static fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    check($left('SELECT COUNT(*) FROM `' . ecw_table('users') . '` WHERE id = ' . $uid) === 0
        && $left('SELECT COUNT(*) FROM `' . ecw_table('organization_users') . '` WHERE user_id = ' . $uid) === 0
        && $left('SELECT COUNT(*) FROM `' . ecw_table('rules') . '` WHERE user_id = ' . $uid) === 0
        && $left('SELECT COUNT(*) FROM `' . ecw_table('mailboxes') . '` WHERE id = ' . $theirBox) === 0, 'their sign-in, membership, personal rules and mailbox are gone');
    check($left('SELECT COUNT(*) FROM `' . ecw_table('cases') . '` WHERE id = ' . $case . ' AND owner_user_id IS NULL') === 1, 'their case stays, without their name');
    $again = ecw_admin_create_user($admin, $org, ['name' => 'Maria Colleague', 'email' => $email, 'role' => 'user', 'invite' => true]);
    check($again['ok'], 'the same address can be added again', (string)$again['error']);
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
