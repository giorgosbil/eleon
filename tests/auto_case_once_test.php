<?php declare(strict_types=1);
/**
 * A case the app starts by itself from an email is started once, whichever copy of the app
 * (lib/auto_case_guard.php): two PCs with their own databases read the same mailbox, and each
 * started its own case from the same support email. The email in Outlook now carries the case
 * number (a hidden property); a copy that finds it there does not start the case again.
 *
 *   php tests/auto_case_once_test.php
 *
 * Microsoft is scripted with FakeGraph. Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/group_rules.php';
require_once dirname(__DIR__) . '/lib/automation.php';
require_once dirname(__DIR__) . '/lib/crypto.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

ecw_set_graph_config_override(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://example.test/cb']);
$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Two PCs']);
    $me = $ins('users', ['name' => 'Maria', 'email' => 'maria' . $org . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $mb = $ins('mailboxes', [
        'organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-' . $org, 'email_address' => 'support' . $org . '@acme.test',
        'sync_status' => 'connected', 'connected_by_user_id' => $me, 'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $gid = $ins('case_groups', ['organization_id' => $org, 'name' => 'Support']);
    $g = ['id' => $gid, 'name' => 'Support', 'why' => 'has the word “repair”'];
    $email = static function (string $providerId) use ($ins, $mb, $org): array {
        $t = $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'conv-' . $providerId, 'normalized_subject' => 'repair']);
        $m = $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $t, 'provider_message_id' => $providerId, 'subject' => 'Repair', 'direction' => 'incoming', 'received_at' => gmdate('Y-m-d H:i:s')]);
        return [$t, $m];
    };
    $cases = static fn() => (int)$pdo->query("SELECT COUNT(*) FROM ecw_cases WHERE organization_id = {$org}")->fetchColumn();

    group('the first copy to see the email');
    [$t1, $m1] = $email('AAMk-first');
    $g1 = new FakeGraph(); $g1->install();
    $caseId = ecw_group_rules_start_case($org, $me, $mb, $t1, $m1, $g, 'Repair', 'Customer');
    $number = (string)$pdo->query("SELECT case_number FROM ecw_cases WHERE id = " . (int)$caseId)->fetchColumn();
    check($caseId !== null && $cases() === 1, 'no mark on the email: the case is started');
    $asked = $g1->callsMatching('#AAMk-first.*EcwAutoCase#');
    $marked = array_values(array_filter($g1->calls, static fn($c) => $c['method'] === 'PATCH' && str_contains($c['url'], 'AAMk-first')));
    check(count($asked) === 1, 'it asked Outlook first whether the email has a mark');
    check(count($marked) === 1 && ($marked[0]['json']['singleValueExtendedProperties'][0]['value'] ?? '') === $number && str_contains((string)($marked[0]['json']['singleValueExtendedProperties'][0]['id'] ?? ''), 'EcwAutoCase'),
        'then marked the email in Outlook with the case number ' . $number);
    FakeGraph::uninstall();

    group('the other copy, the same email');
    [$t2, $m2] = $email('AAMk-second');
    $g2 = new FakeGraph();
    $g2->on('GET', '#AAMk-second.*EcwAutoCase#', ['body' => ['id' => 'AAMk-second', 'singleValueExtendedProperties' => [['id' => 'String {8a3f7c2e-5b41-4d9e-9c6a-2f1e0b7d4a63} Name EcwAutoCase', 'value' => 'CASE-00042']]]])->install();
    $before = $cases();
    check(ecw_group_rules_start_case($org, $me, $mb, $t2, $m2, $g, 'Repair', 'Customer') === null && $cases() === $before, 'the email is marked: no second case from a group\'s rules (or the assistant)');
    $rule = ['id' => 0, 'name' => 'Repairs'];
    check(ecw_rule_run_action($org, ['id' => $mb, 'organization_id' => $org], $me, $t2, $m2, ['subject' => 'Repair'], $rule, ['type' => 'create_case']) === null && $cases() === $before, '…nor from a rule');
    check(count(array_filter($g2->calls, static fn($c) => $c['method'] === 'PATCH')) === 0, 'and the email is not marked again');
    FakeGraph::uninstall();

    group('when Microsoft does not answer');
    [$t3, $m3] = $email('AAMk-third');
    $g3 = new FakeGraph();
    $g3->on('GET', '#EcwAutoCase#', ['status' => 503, 'body' => ['error' => ['code' => 'ServiceUnavailable']]])
       ->on('PATCH', '#EcwAutoCase#', ['status' => 503, 'body' => []])->install();
    check(ecw_group_rules_start_case($org, $me, $mb, $t3, $m3, $g, 'Repair', 'Customer') !== null, 'the case is still started (a missed duplicate is better than a missed case)');
    FakeGraph::uninstall();

    group('a Gmail mailbox');
    $pdo->exec("UPDATE ecw_mailboxes SET provider = 'google' WHERE id = {$mb}");
    [$t4, $m4] = $email('gmail-1');
    $g4 = new FakeGraph(); $g4->install();
    check(ecw_group_rules_start_case($org, $me, $mb, $t4, $m4, $g, 'Repair', 'Customer') !== null && $g4->calls === [], 'no mark to ask for: started as before, nothing asked of Microsoft');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
