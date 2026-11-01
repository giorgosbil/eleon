<?php declare(strict_types=1);
/**
 * Whose clock a time is shown in (lib/timezone.php): every person sees times in the zone of the
 * computer they use (kept for them, for what is written while they are away), company rules
 * use the company's zone, and a company still on the default UTC takes an admin's zone.
 * Days ("today", overdue, a new day in the case timeline, report ranges) follow those zones,
 * not UTC.
 *
 *   php tests/timezone_test.php
 *
 * Runs in a transaction that is rolled back. A browser's zone is stood in for with
 * $GLOBALS['ecw_tz_test_device'] (the ecw_tz cookie), the person with ecw_tz_test_viewer.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/timezone.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/case_settings.php';
require_once dirname(__DIR__) . '/lib/reports.php';
require_once dirname(__DIR__) . '/lib/cases.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }
$browser = static function (?string $zone, ?int $viewer = null): void {
    if ($zone === null) unset($GLOBALS['ecw_tz_test_device']); else $GLOBALS['ecw_tz_test_device'] = $zone;
    if ($viewer === null) unset($GLOBALS['ecw_tz_test_viewer']); else $GLOBALS['ecw_tz_test_viewer'] = $viewer;
    ecw_tz_forget();
};

group('the helpers');
check(date_default_timezone_get() === 'UTC', 'PHP itself runs in UTC, whatever the server\'s php.ini says');
check(ecw_tz_valid('Europe/Athens') === 'Europe/Athens' && ecw_tz_valid('UTC') === 'UTC' && ecw_tz_valid('Mars/Base') === null && ecw_tz_valid("'; DROP") === null, 'only real zone names are accepted');
check(ecw_tz_format('2026-10-31 12:05:00', 'Europe/Athens', 'H:i') === '14:05', 'a stored 12:05 UTC is 14:05 in Athens (UTC+2 in winter)');
check(ecw_tz_format('2026-07-01 12:05:00', 'Europe/Athens', 'H:i') === '15:05', 'and 15:05 in summer (UTC+3)');
check(ecw_tz_to_utc('2026-10-31 00:00:00', 'Europe/Athens') === '2026-10-30 22:00:00', 'midnight in Athens is 22:00 UTC the day before');
check(ecw_tz_today('Europe/Athens', strtotime('2026-10-30 22:30:00 UTC')) === '2026-10-31' && ecw_tz_today('UTC', strtotime('2026-10-30 22:30:00 UTC')) === '2026-10-30', 'at 22:30 UTC it is already tomorrow in Athens');
check(ecw_tz_day_bounds('2026-10-31', '2026-10-31', 'Europe/Athens') === ['2026-10-30 22:00:00', '2026-10-31 22:00:00'], 'one Athens day as UTC times');
check(ecw_tz_sql_today(0) === 'UTC_DATE()', 'without a company, SQL keeps the database day');

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Athens company', 'timezone' => 'UTC']);
    $admin = $ins('users', ['name' => 'Maria', 'email' => 'maria' . $org . '@acme.test', 'password_hash' => 'x']);
    $member = $ins('users', ['name' => 'Nikos', 'email' => 'nikos' . $org . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $member, 'role' => 'member']);

    group('syncing the computer\'s zone');
    $browser('Europe/Athens', $member);
    ecw_tz_sync($org, $member, false);
    $prefs = json_decode((string)$pdo->query("SELECT preferences_json FROM ecw_users WHERE id = {$member}")->fetchColumn(), true);
    check(($prefs['tz'] ?? '') === 'Europe/Athens', 'the zone of the computer is kept for the person');
    check(ecw_tz_company($org) === 'UTC', 'a member does not change the company zone');
    $browser('Europe/Athens', $admin);
    ecw_tz_sync($org, $admin, true);
    check(ecw_tz_company($org) === 'Europe/Athens', 'an admin\'s computer gives a company still on the default UTC its zone');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action = 'settings.timezone_auto'")->fetchColumn() === 1, 'and that is written in the audit log');
    $browser('America/New_York', $admin);
    ecw_tz_sync($org, $admin, true);
    check(ecw_tz_company($org) === 'Europe/Athens', 'once set, a trip abroad does not move the company');
    check(ecw_tz_for_user($admin, $org) === 'America/New_York', '…but the travelling person sees New York times');

    group('whose zone, for whom');
    $browser('America/New_York', $admin);   // Maria is in New York, writing to Nikos
    check(ecw_tz_for_user($member, $org) === 'Europe/Athens', 'a time written for a colleague uses the colleague\'s zone, not the writer\'s computer');
    $browser(null);
    check(ecw_tz_for_user($admin, $org) === 'America/New_York', 'away from the screen: the zone the person was last seen in');
    $pdo->prepare('UPDATE ecw_users SET preferences_json = ? WHERE id = ?')->execute([json_encode(['home_tz' => 'Europe/London']), $admin]);
    ecw_tz_forget();
    check(ecw_tz_for_user($admin, $org) === 'Europe/London', 'never seen: the home zone they chose');
    $pdo->prepare('UPDATE ecw_users SET preferences_json = ? WHERE id = ?')->execute(['{}', $admin]);
    ecw_tz_forget();
    check(ecw_tz_for_user($admin, $org) === 'Europe/Athens', 'nothing known: the company\'s zone');

    group('days at the company, not in UTC');
    check(ecw_tz_sql_today($org) === "'" . ecw_tz_today('Europe/Athens') . "'", 'SQL "today" is the company\'s date, as a plain quoted date');
    check(str_contains(ecw_case_overdue_sql($org), ecw_tz_sql_today($org)) && !str_contains(ecw_case_overdue_sql($org), 'UTC_DATE'), 'overdue counts from the company\'s today');
    [$prefix] = ecw_case_number_parts($org, null);
    check(is_string($prefix), 'case numbers still build');
    $due = ecw_case_default_due($org, new DateTimeImmutable('2026-10-30', new DateTimeZone('UTC')));   // a Friday
    check($due === null || $due >= '2026-11-02', 'working days skip the weekend (when a default due date is set)');

    group('the case timeline\'s new day');
    $late = ecw_timeline_gap('2026-10-30 21:30:00', '2026-10-30 22:30:00', 'Europe/Athens');
    check($late !== null && $late['new_day'] === true, '23:30 and 00:30 in Athens are two days (one day in UTC)');
    $same = ecw_timeline_gap('2026-10-30 21:30:00', '2026-10-30 22:30:00', 'UTC');
    check($same !== null && $same['new_day'] === false, 'in UTC the same pair is one day');

    group('date filters and reports, as the viewer lives the days');
    $browser('Europe/Athens', $member);
    check(ecw_case_list_parse_date('2026-10-31', false) === '2026-10-30 22:00:00' && ecw_case_list_parse_date('2026-10-31', true) === '2026-10-31 21:59:59', 'the case list\'s "from–to" days are Athens days');
    check(ecw_search_date('2026-10-31', false) === '2026-10-30 22:00:00', 'search after: an Athens day');
    $f = ecw_report_filters(['from' => '2026-10-01', 'to' => '2026-10-31']);
    check($f['from'] === '2026-10-01' && $f['to'] === '2026-10-31', 'report range kept as chosen');
    $browser(null);
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ecw_tz_forget();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
