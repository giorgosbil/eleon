<?php declare(strict_types=1);
/**
 * Settings → Case settings (lib/case_settings.php) and saved filters (lib/saved_filters.php):
 * the company's own statuses (each in a group the app works with), the overdue timers, the
 * conversation roles, the case number format, the tag list, and saved filters of the Cases list.
 *
 *   php tests/company_case_settings_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/case_settings.php';
require_once dirname(__DIR__) . '/lib/saved_filters.php';
require_once dirname(__DIR__) . '/lib/next_actions.php';
require_once dirname(__DIR__) . '/lib/case_extras.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }
function throws(callable $f, string $contains, string $what): void
{
    try { $f(); check(false, $what, 'nothing thrown'); }
    catch (Throwable $e) { check($e instanceof InvalidArgumentException && str_contains($e->getMessage(), $contains), $what, get_class($e) . ': ' . $e->getMessage()); }
}

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Case settings']);
    $me = $ins('users', ['name' => 'Eleni', 'email' => 'eleni' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $nick = $ins('users', ['name' => 'Nick', 'email' => 'nick' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $nick, 'role' => 'member']);
    $names = static fn(array $list) => array_column($list['cases'], 'name');

    group('statuses of the company');
    check(ecw_case_settings($org)['statuses'] === [] && count(ecw_case_status_options($org)) === 3, 'out of the box: Open, Waiting, Closed');
    $s = ecw_case_settings_save($org, ['statuses' => [
        ['label' => 'Waiting for parts', 'base' => 'waiting', 'color' => '#aa5500'],
        ['label' => 'Quoted', 'base' => 'open', 'color' => 'red'],
        ['label' => 'Open', 'base' => 'open'],            // a group's own name: gets its own key
        ['label' => '', 'base' => 'closed'],              // an empty row: nothing
    ]], $me);
    $keys = array_column($s['statuses'], 'key');
    check($keys[0] === 'waiting-for-parts' && $keys[1] === 'quoted' && $keys[2] !== 'open' && count($keys) === 3, 'saved with keys from their names, never a group\'s key', json_encode($keys));
    check($s['statuses'][1]['color'] === ECW_CASE_STATUS_GROUPS['open'][1], 'a colour that is not one falls back to its group\'s');
    $opts = array_column(ecw_case_status_options($org), 'value');
    check(array_search('waiting-for-parts', $opts, true) === array_search('waiting', $opts, true) + 1, 'listed under its group');

    $a = ecw_case_create($org, 'Pump repair', $me);
    $b = ecw_case_create($org, 'Valve offer', $me);
    ecw_case_update($org, $a, ['status' => 'waiting-for-parts'], $me);
    $row = ecw_case_load($org, $a);
    check($row['status'] === 'waiting' && $row['sub_status'] === 'waiting-for-parts', 'a case set to "Waiting for parts" is in Waiting, with the company\'s status');
    check(ecw_case_update($org, $a, ['status' => 'nonsense'], $me) && ecw_case_load($org, $a)['sub_status'] === 'waiting-for-parts', 'a status that does not exist changes nothing');
    $list = ecw_case_list($org, ['user_id' => $me, 'status' => 'waiting-for-parts']);
    check($names($list) === ['Pump repair'] && $list['cases'][0]['status_label'] === 'Waiting for parts' && $list['cases'][0]['status_color'] === '#aa5500', 'the list filters by it and shows its name and colour');
    check($names(ecw_case_list($org, ['user_id' => $me, 'status' => 'waiting'])) === ['Pump repair'], '…and it counts as Waiting');
    $ev = $pdo->query("SELECT metadata_json FROM ecw_case_events WHERE case_id = {$a} AND event_type = 'CASE_STATUS_CHANGED' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(str_contains((string)$ev, 'Waiting for parts'), 'the history says the company\'s status name');
    ecw_case_update($org, $a, ['status' => 'open'], $me);
    $row = ecw_case_load($org, $a);
    check($row['status'] === 'open' && $row['sub_status'] === null, 'back to Open: the company\'s status goes');
    ecw_case_update($org, $a, ['status' => 'waiting-for-parts'], $me);
    $s2 = ecw_case_settings_save($org, ['statuses' => [['key' => 'waiting-for-parts', 'label' => 'Parts ordered', 'base' => 'closed', 'color' => '#aa5500'], ['key' => 'quoted', 'label' => 'Quoted', 'base' => 'open']]], $me);
    $row = ecw_case_load($org, $a);
    check($s2['statuses'][0]['key'] === 'waiting-for-parts' && $row['sub_status'] === 'waiting-for-parts' && $row['status'] === 'closed' && $row['closed_at'] !== null, 'renamed and moved to Closed: its cases keep it and move along');
    ecw_case_settings_save($org, ['statuses' => [['key' => 'quoted', 'label' => 'Quoted', 'base' => 'open']]], $me);
    $row = ecw_case_load($org, $a);
    check($row['sub_status'] === null && $row['status'] === 'closed', 'a status taken away: its cases keep their group');

    group('overdue');
    ecw_case_update($org, $b, ['status' => 'waiting'], $me);
    $pdo->exec("UPDATE ecw_cases SET status_changed_at = UTC_TIMESTAMP() - INTERVAL 10 DAY WHERE id = {$b}");
    check(!in_array('Valve offer', $names(ecw_case_list($org, ['user_id' => $me, 'status' => 'all', 'view' => 'overdue'])), true), 'timer off: a case waiting 10 days is not overdue');
    ecw_case_settings_save($org, ['waiting_overdue_days' => 7], $me);
    $list = ecw_case_list($org, ['user_id' => $me, 'status' => 'all', 'view' => 'overdue']);
    check(in_array('Valve offer', $names($list), true), 'waiting more than 7 days: overdue');
    check((bool)(array_values(array_filter($list['cases'], static fn($c) => $c['name'] === 'Valve offer'))[0]['waiting_overdue'] ?? false), '…and the row says so');
    check((ecw_case_view_counts($org, $me)['overdue'] ?? 0) >= 1, 'the Overdue view counts it');
    $c = ecw_case_create($org, 'Due case', $me);
    ecw_case_update($org, $c, ['due_at' => gmdate('Y-m-d', time() - 2 * 86400)], $me);
    ecw_case_settings_save($org, ['due_grace_days' => 3], $me);
    check(!in_array('Due case', $names(ecw_case_list($org, ['user_id' => $me, 'status' => 'all', 'view' => 'overdue'])), true), 'two days past its date with 3 days of grace: not overdue yet');
    ecw_case_settings_save($org, ['due_grace_days' => 1], $me);
    check(in_array('Due case', $names(ecw_case_list($org, ['user_id' => $me, 'status' => 'all', 'view' => 'overdue'])), true), '…with 1 day of grace: overdue');
    check(ecw_case_default_due($org) === null && ecw_case_load($org, $c)['status_changed_at'] !== null, 'no default due date unless set; a new case knows when its status began');
    ecw_case_settings_save($org, ['default_due_days' => 5], $me);
    check(ecw_case_default_due($org, new DateTimeImmutable('2026-10-30')) === '2026-11-06', 'five working days from a Friday: the next Friday');
    $d = ecw_case_create($org, 'Gets a due date', $me);
    check(ecw_case_load($org, $d)['due_at'] === ecw_case_default_due($org), 'a new case gets the due date');

    group('conversation roles');
    $thread = $ins('email_threads', ['mailbox_id' => $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'o' . $org, 'email_address' => 'm' . $org . '@shop.test', 'sync_status' => 'connected']), 'provider_conversation_id' => 'c' . $org, 'normalized_subject' => 'x']);
    ecw_case_link_thread($d, $thread, null, $me);
    throws(fn() => ecw_case_thread_role_set($d, $thread, 'carrier', $me), 'roles added in Settings', 'a role the company has not added is refused');
    $r = ecw_case_settings_save($org, ['roles' => [['label' => 'Carrier', 'color' => '#0f766e'], ['label' => 'Customer', 'color' => '#111111']]], $me);
    check($r['roles'][0]['key'] === 'carrier' && $r['roles'][1]['key'] !== 'customer', 'saved; a built-in role\'s key is never taken');
    ecw_case_thread_role_set($d, $thread, 'carrier', $me);
    check($pdo->query("SELECT party_role FROM ecw_case_threads WHERE case_id = {$d}")->fetchColumn() === 'carrier', 'a conversation can be the Carrier\'s');
    check(isset(ecw_party_roles($org)['customer'], ecw_party_roles($org)['carrier']), 'the list has the built-in roles and the company\'s');
    ecw_case_settings_save($org, ['roles' => []], $me);
    check($pdo->query("SELECT party_role FROM ecw_case_threads WHERE case_id = {$d}")->fetchColumn() === null, 'a role taken away leaves its conversations unmarked');

    group('case numbers');
    check(str_starts_with(ecw_case_next_number($org), 'CASE-'), 'out of the box: CASE-00001…');
    throws(fn() => ecw_case_settings_save($org, ['number_format' => 'SRV-{N}-X'], $me), 'end with {N}', 'the running number must come last');
    throws(fn() => ecw_case_settings_save($org, ['number_format' => 'SR V<{N}'], $me), 'letters, numbers', 'odd characters are refused');
    ecw_case_settings_save($org, ['number_format' => 'SRV-{YYYY}-{N}', 'number_digits' => 3], $me);
    $year = gmdate('Y');
    $e = ecw_case_create($org, 'New format', $me);
    $f = ecw_case_create($org, 'New format 2', $me);
    check(ecw_case_load($org, $e)['case_number'] === "SRV-{$year}-001" && ecw_case_load($org, $f)['case_number'] === "SRV-{$year}-002", 'new cases: SRV-' . $year . '-001, -002', ecw_case_load($org, $e)['case_number']);
    check(str_starts_with((string)ecw_case_load($org, $a)['case_number'], 'CASE-'), 'existing cases keep their numbers');
    require_once dirname(__DIR__) . '/lib/automation.php';
    $mb2 = (int)$pdo->query("SELECT mailbox_id FROM ecw_email_threads WHERE id = {$thread}")->fetchColumn();
    $t2 = $ins('email_threads', ['mailbox_id' => $mb2, 'provider_conversation_id' => 'c2' . $org, 'normalized_subject' => 'y']);
    $t3 = $ins('email_threads', ['mailbox_id' => $mb2, 'provider_conversation_id' => 'c3' . $org, 'normalized_subject' => 'z']);
    $linked = ecw_automation_link_auto($org, $me, $t2, "Re: [SRV-{$year}-002] parts", '');
    check(($linked['case_id'] ?? 0) === $f, 'a reply quoting the new number finds its case', json_encode($linked));
    $oldNumber = (string)ecw_case_load($org, $a)['case_number'];
    $linked = ecw_automation_link_auto($org, $me, $t3, 'Re: ' . strtolower($oldNumber) . ' pump', '');
    check(($linked['case_id'] ?? 0) === $a, '…and one quoting an old CASE- number still does', json_encode($linked));

    group('tags');
    ecw_case_tags_save($a, ['repair', 'urgent'], $me);
    ecw_case_tags_save($b, ['Repairs', 'urgent'], $me);
    ecw_case_settings_save($org, ['tag_colors' => ['repair' => '#ff0000', 'urgent' => 'nope']], $me);
    check(ecw_case_settings($org)['tag_colors'] === ['repair' => '#ff0000'], 'a tag gets a colour; a colour that is not one is dropped');
    check(array_column(ecw_case_tag_list($org), 'cases', 'tag') === ['repair' => 1, 'repairs' => 1, 'urgent' => 2], 'the tag list with how many cases each');
    check(ecw_case_tag_rename($org, 'repairs', 'repair', $me) === 1 && array_column(ecw_case_tag_list($org), 'cases', 'tag') === ['repair' => 2, 'urgent' => 2], 'renaming to a tag that exists merges them');
    ecw_case_tag_rename($org, 'repair', 'Service', $me);
    check(ecw_case_tags($a) === ['service', 'urgent'] && (ecw_case_settings($org)['tag_colors']['service'] ?? '') === '#ff0000', 'renamed on every case; its colour follows');
    ecw_case_tag_rename($org, 'urgent', '', $me);
    check(ecw_case_tags($b) === ['service'], 'deleted from every case');

    group('saved filters');
    $id = ecw_saved_filter_save($org, $me, ' My  waiting ', '?status=waiting&group_ids[]=4&page=3&q=', false);
    $mine = ecw_saved_filters($org, $me);
    check(count($mine) === 1 && $mine[0]['name'] === 'My waiting' && $mine[0]['query'] === 'status=waiting&group_ids[]=4' && $mine[0]['mine'], 'saved as the page writes it, without the page number and empty parts', json_encode($mine));
    check(ecw_saved_filters($org, $nick) === [], 'a personal one: a colleague does not see it');
    check(ecw_saved_filter_save($org, $me, 'My waiting', 'status=closed', true) === $id, 'the same name again replaces it');
    $seen = ecw_saved_filters($org, $nick);
    check(count($seen) === 1 && $seen[0]['shared'] && !$seen[0]['mine'] && $seen[0]['owner_name'] === 'Eleni', 'shared: colleagues see it, with who made it');
    throws(fn() => ecw_saved_filter_delete($org, $nick, $id, false), 'not yours', 'a colleague cannot delete it');
    throws(fn() => ecw_saved_filter_save($org, $me, '  ', 'status=open', false), 'name', 'a filter needs a name');
    check(ecw_saved_filter_save($org, $me, 'Waiting, all groups', 'status=waiting&page=2', true, $id) === $id, 'changed by its id…');
    $seen = ecw_saved_filters($org, $me);
    check(count($seen) === 1 && $seen[0]['name'] === 'Waiting, all groups' && $seen[0]['query'] === 'status=waiting' && $seen[0]['shared'], '…renamed, with the new filters, still one filter', json_encode($seen));
    throws(fn() => ecw_saved_filter_save($org, $nick, 'Mine now', 'status=open', true, $id, false), 'not yours', 'a colleague cannot change it');
    $other = ecw_saved_filter_save($org, $me, 'Open', 'status=open', false);
    throws(fn() => ecw_saved_filter_save($org, $me, 'Open', 'status=waiting', true, $id), 'already', 'renaming onto another of one\'s filters is refused');
    ecw_saved_filter_delete($org, $me, $other, false);
    ecw_saved_filter_delete($org, $nick, $id, true);
    check(ecw_saved_filters($org, $me) === [], 'an admin can delete a shared one');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
