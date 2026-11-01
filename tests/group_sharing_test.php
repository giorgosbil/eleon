<?php declare(strict_types=1);
/**
 * Case groups are Company or Personal (lib/cases.php, lib/access.php):
 *   - Company: everyone sees the group and its cases;
 *   - Personal ("only me"): nobody but its owner sees the group or its cases (except someone
 *     placed on a case), not even an admin; nobody else can use, change or delete it;
 * the choice applies to the cases already in the group too; each group shows its owner.
 *
 *   php tests/group_sharing_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/access.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Groups Co']);
    $u = [];
    foreach (['me' => 'admin', 'other' => 'user', 'boss' => 'admin'] as $n => $role) {
        $u[$n] = $ins('users', ['name' => ucfirst($n) . ' Person', 'email' => $n . $org . '@groups.test', 'password_hash' => 'x']);
        $ins('organization_users', ['organization_id' => $org, 'user_id' => $u[$n], 'role' => $role]);
    }
    $newCase = static fn(string $name, ?int $ownerId = null) => $ins('cases', ['organization_id' => $org, 'case_number' => 'G-' . $name . $org, 'name' => $name, 'status' => 'open',
        'visibility' => 'organization', 'owner_user_id' => $ownerId, 'created_by_user_id' => $u['me']]);
    $sees = static fn(string $who, int $case) => ecw_case_visible($org, $u[$who], $case);
    $find = static fn(?int $viewer, int $id) => array_values(array_filter(ecw_case_groups($org, $viewer), static fn($g) => $g['id'] === $id))[0] ?? null;

    echo "\n=== made personal, with cases already in it ===\n";
    $mine = ecw_case_group_save($org, $u['me'], true, ['name' => 'Support Me', 'member_ids' => [$u['me'], $u['other']], 'scope' => 'company']);
    $old = $newCase('already in it');
    ecw_case_set_group($org, $old, $mine, $u['me']);
    check($sees('other', $old), 'a company group: everyone sees its case');
    ecw_case_group_save($org, $u['me'], true, ['id' => $mine, 'name' => 'Support Me', 'member_ids' => [$u['me'], $u['other']], 'scope' => 'personal']);
    check(!$sees('other', $old) && !$sees('boss', $old) && $sees('me', $old), 'made "only me": the case already in it is hidden from the others (an admin too) at once, its owner still sees it');
    $later = $newCase('added later');
    ecw_case_set_group($org, $later, $mine, $u['me']);
    check(!$sees('other', $later) && $sees('me', $later), 'and so is a case put in it later');
    $placed = $newCase('with a Responsible', $u['other']);
    ecw_case_set_group($org, $placed, $mine, $u['me']);
    check($sees('other', $placed), 'someone placed on a case (its Responsible) still sees that case');

    echo "\n=== nobody else sees or uses it ===\n";
    check($find($u['me'], $mine) !== null && $find($u['other'], $mine) === null && $find($u['boss'], $mine) === null, 'the group is listed for its owner only');
    $g = $find($u['me'], $mine);
    check(($g['scope'] ?? '') === 'personal' && $g['members'] === [], 'it is personal, with no members to share it');
    $theirs = $newCase('theirs', $u['other']);
    $threw = false;
    try { ecw_case_set_group($org, $theirs, $mine, $u['other']); } catch (InvalidArgumentException $e) { $threw = true; }
    check($threw, 'others cannot put a case in it');
    $threw = false;
    try { ecw_case_group_save($org, $u['boss'], true, ['id' => $mine, 'name' => 'Taken', 'scope' => 'company']); } catch (InvalidArgumentException $e) { $threw = true; }
    $threw2 = false;
    try { ecw_case_group_delete($org, $u['boss'], true, $mine); } catch (InvalidArgumentException $e) { $threw2 = true; }
    check($threw && $threw2, 'nobody else changes or deletes it, not even an admin');

    echo "\n=== company groups ===\n";
    $team = ecw_case_group_save($org, $u['boss'], true, ['name' => 'Sales', 'member_ids' => [$u['boss']], 'scope' => 'company']);
    $t = $find($u['other'], $team);
    check($t !== null && ($t['scope'] ?? '') === 'company' && ($t['owner_name'] ?? '') === 'Boss Person', 'a company group is listed for everyone, with its owner');
    $pdo->exec('UPDATE `' . ecw_table('cases') . "` SET visibility = 'members' WHERE id = {$theirs}");
    ecw_case_set_group($org, $theirs, $team, $u['other']);
    check($sees('me', $theirs), 'a case put in a company group is seen by everyone');
    ecw_case_group_save($org, $u['me'], true, ['id' => $mine, 'name' => 'Support Me', 'scope' => 'company']);
    check($sees('other', $old) && $sees('other', $later), 'made company again: its cases are seen by everyone again');

    echo "\n=== members ===\n";
    $chosen = ecw_case_group_save($org, $u['me'], true, ['name' => 'Repairs', 'member_ids' => [$u['other']], 'scope' => 'members']);
    $rc = $newCase('repair');
    ecw_case_set_group($org, $rc, $chosen, $u['me']);
    check($sees('other', $rc) && $sees('me', $rc) && !$sees('boss', $rc), '"Members": the chosen people and its owner see its case, nobody else (an admin neither)');
    $g = $find($u['me'], $chosen);
    check(($g['scope'] ?? '') === 'members' && in_array($u['me'], array_column($g['members'], 'id'), true), 'its owner is one of its members');
    check($find($u['other'], $chosen) !== null && $find($u['boss'], $chosen) === null, 'the group is listed for its members only');
    ecw_case_group_save($org, $u['me'], true, ['id' => $chosen, 'name' => 'Repairs', 'member_ids' => [], 'scope' => 'members']);
    check($sees('me', $rc) && !$sees('other', $rc), 'taken off the list: they no longer see it; its owner always does');
    ecw_case_group_save($org, $u['me'], true, ['id' => $chosen, 'name' => 'Repairs', 'member_ids' => [$u['other']], 'scope' => 'company']);
    check(($find($u['me'], $chosen)['members'] ?? null) === [] && $sees('boss', $rc), '"Company": everyone sees it, and the people list is cleared (it has no use there)');

    echo "\n=== groups from before ===\n";
    $legacy = $ins('case_groups', ['organization_id' => $org, 'name' => 'Old one', 'created_by_user_id' => $u['me']]);
    $kept = $newCase('kept');
    $pdo->exec('UPDATE `' . ecw_table('cases') . "` SET visibility = 'members' WHERE id = {$kept}");
    ecw_case_set_group($org, $kept, $legacy, $u['me']);
    check(!$sees('other', $kept) && ($find($u['other'], $legacy)['scope'] ?? '') === 'company', 'a group from before is a company group, and its cases keep the sharing they have');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
