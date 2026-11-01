<?php declare(strict_types=1);
/**
 * A workspace per company (lib/workspace_split.php, lib/account.php): one account split out of a
 * workspace takes its emails, cases and what hangs off them; its people are separate; one person
 * in both switches between them with the account picker; the other company's colleagues see
 * nothing of it.
 *
 *   php tests/workspace_split_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/workspace_split.php';
require_once dirname(__DIR__) . '/lib/account.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/access.php';
require_once dirname(__DIR__) . '/lib/cases.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
// The split runs its own transaction: here it joins the test's (savepoints are not used), so
// the whole test is rolled back at the end.
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Both companies', 'timezone' => 'Europe/Athens']);
    $me = $ins('users', ['name' => 'George', 'email' => 'g' . $org . '@split.test', 'password_hash' => 'x']);
    $colleague = $ins('users', ['name' => 'Colleague', 'email' => 'c' . $org . '@split.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $colleague, 'role' => 'user']);
    $big = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'big' . $org, 'email_address' => 'me@first' . $org . '.test', 'sync_status' => 'connected', 'connected_by_user_id' => $me]);
    $small = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'small' . $org, 'email_address' => 'hello@second' . $org . '.test', 'sync_status' => 'connected', 'connected_by_user_id' => $me]);
    $thread = $ins('email_threads', ['mailbox_id' => $small, 'normalized_subject' => 'second company thread']);
    $group = ecw_case_group_save($org, $me, true, ['name' => 'Support', 'scope' => 'company']);
    $case2 = $ins('cases', ['organization_id' => $org, 'case_number' => 'CASE-00001', 'name' => 'Second company case', 'status' => 'open', 'visibility' => 'organization', 'mailbox_id' => $small, 'group_id' => $group, 'created_by_user_id' => $me]);
    $ins('case_threads', ['case_id' => $case2, 'email_thread_id' => $thread]);
    $case1 = $ins('cases', ['organization_id' => $org, 'case_number' => 'CASE-00002', 'name' => 'First company case', 'status' => 'open', 'visibility' => 'organization', 'mailbox_id' => $big, 'group_id' => $group, 'created_by_user_id' => $me]);
    check(ecw_case_visible($org, $colleague, $case2), 'before: the colleague sees the second company\'s case (one workspace for both)');

    echo "\n=== what would move ===\n";
    $dry = ecw_workspace_split($small, 'Second Co', 'First Co', false);
    check($dry['workspace_id'] === null && $dry['moved']['cases'] === 1 && $dry['moved']['mailbox'] === 1, 'without --apply it only says what would move', json_encode($dry['moved']));
    check((int)$pdo->query('SELECT organization_id FROM `' . ecw_table('mailboxes') . '` WHERE id = ' . $small)->fetchColumn() === $org, 'and nothing changed');

    echo "\n=== the split ===\n";
    $r = ecw_workspace_split($small, 'Second Co', 'First Co', true);
    $new = (int)$r['workspace_id'];
    $orgOf = static fn(string $t, int $id) => (int)$pdo->query('SELECT organization_id FROM `' . ecw_table($t) . '` WHERE id = ' . $id)->fetchColumn();
    check($new > 0 && $orgOf('mailboxes', $small) === $new && $orgOf('cases', $case2) === $new && $orgOf('cases', $case1) === $org, 'the account and its case are in the new workspace; the other company\'s case stays');
    check($pdo->query('SELECT name FROM `' . ecw_table('organizations') . '` WHERE id = ' . $org)->fetchColumn() === 'First Co'
        && $pdo->query('SELECT timezone FROM `' . ecw_table('organizations') . '` WHERE id = ' . $new)->fetchColumn() === 'Europe/Athens', 'names set; the new workspace starts with the old one\'s settings');
    check(ecw_user_role($me, $new) === 'admin' && ecw_user_role($colleague, $new) === null, 'its people: the account\'s owner (admin), not the other company\'s colleague');
    // The colleague cannot enter the new workspace (no membership: no session there), and from
    // their own the case is gone.
    check(!ecw_case_visible($org, $colleague, $case2) && ecw_case_visible($org, $colleague, $case1), 'the colleague no longer sees the second company\'s case; their own company\'s case they still see');
    $g2 = (int)$pdo->query('SELECT group_id FROM `' . ecw_table('cases') . '` WHERE id = ' . $case2)->fetchColumn();
    check($g2 !== $group && $orgOf('case_groups', $g2) === $new && (int)$pdo->query('SELECT group_id FROM `' . ecw_table('cases') . '` WHERE id = ' . $case1)->fetchColumn() === $group,
        'the case group is copied into the new workspace; the old one keeps its own');

    echo "\n=== one person in both ===\n";
    $_SESSION = ['ecw_user_id' => $me, 'ecw_organization_id' => $org];
    ecw_account_reset_cache();
    $choices = ecw_account_choices($org, $me);
    check(count($choices) === 2 && $choices[0]['organization_id'] === $org && $choices[1]['workspace'] === 'Second Co', 'the picker lists the accounts of both workspaces, this one first', json_encode(array_column($choices, 'workspace')));
    check((int)ecw_active_account_id($org, $me) === $big, 'the account worked in is one of this workspace\'s');
    check(ecw_account_switch($org, $me, $small) && (int)$_SESSION['ecw_organization_id'] === $new, 'picking the other company\'s account switches the workspace');
    check(ecw_user_last_organization_id($me) === $new, 'and the next sign-in starts there');
    check(!ecw_account_switch($org, $colleague, $small), 'the colleague cannot switch to the other company\'s account');
    $_SESSION = [];
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
