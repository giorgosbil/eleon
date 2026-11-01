<?php declare(strict_types=1);
/**
 * The notification bell (review R12): the workspace one is in, with the other workspaces'
 * unread only counted; important kinds counted apart (the red badge); a notification about a
 * case one can no longer open loses its link; "mark all read" stays in the workspace; quiet
 * hours in the person's time zone, overnight and at weekends.
 *
 *   php tests/notifications_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/notifications.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/appearance.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $a = $ins('organizations', ['name' => 'Company A']);
    $b = $ins('organizations', ['name' => 'Company B']);
    $me = $ins('users', ['name' => 'Maria', 'email' => 'maria' . $a . '@acme.test', 'password_hash' => 'x']);
    $boss = $ins('users', ['name' => 'Nikos', 'email' => 'nikos' . $a . '@acme.test', 'password_hash' => 'x']);
    foreach ([$a, $b] as $org) $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $a, 'user_id' => $boss, 'role' => 'admin']);
    $case = ecw_case_create($a, 'Pump repair', $boss);
    $gone = ecw_case_create($a, 'Old matter', $boss);

    ecw_notify($a, $me, $boss, 'mention', 'Nikos mentioned you on CASE Pump repair', 'case.php?id=' . $case, $case);
    ecw_notify($a, $me, $boss, 'note_reply', 'Nikos replied on Pump repair', 'case.php?id=' . $case, $case);
    ecw_notify($a, $me, $boss, 'note_reply', 'Nikos replied again on Pump repair', 'case.php?id=' . $case, $case);
    ecw_notify($a, $me, $boss, 'assigned', 'You are Responsible for Old matter', 'case.php?id=' . $gone, $gone);
    ecw_notify($a, $me, null, 'digest', 'Good morning: 3 cases due', 'cases.php?status=open');
    ecw_notify($b, $me, null, 'reminder', 'Call the supplier (Company B)', 'cases.php');
    ecw_notify($b, $me, null, 'digest', 'Good morning (Company B)', 'cases.php');

    group('the workspace one is in');
    $here = ecw_notifications_list($me, 40, $a);
    check(count($here) === 5 && !array_filter($here, static fn($n) => str_contains($n['text'], 'Company B')), 'the list shows Company A only', (string)count($here));
    check(ecw_notifications_unread($me, $a) === 5 && ecw_notifications_unread($me) - ecw_notifications_unread($me, $a) === 2, 'unread here 5, in the other workspace 2 (counted, not listed)');

    group('important ones');
    check(ecw_notifications_unread_important($me, $a) === 2, 'a mention and an assignment are important; replies and the briefing are not');
    $byType = array_column($here, 'important', 'type');
    check($byType['mention'] === true && $byType['digest'] === false && $byType['note_reply'] === false, 'each item says whether it is important');

    group('a case one can no longer open');
    $pdo->prepare('UPDATE `' . ecw_table('cases') . '` SET deleted_at = ? WHERE id = ?')->execute([ecw_now(), $gone]);
    $items = array_column(ecw_notifications_list($me, 40, $a), null, 'type');
    check($items['assigned']['link'] === null && $items['assigned']['text'] !== '', 'its notification stays, without the link');
    check($items['mention']['link'] === 'case.php?id=' . $case, 'one still open keeps its link');

    group('mark all read, in this workspace');
    ecw_notifications_mark_read($me, null, $a);
    check(ecw_notifications_unread($me, $a) === 0 && ecw_notifications_unread($me, $b) === 2, 'Company B\'s stay unread');

    group('quiet hours');
    $set = static function (array $appearance, ?string $tz = 'Europe/Athens') use ($pdo, $me): void {
        $prefs = ['appearance' => ecw_appearance($appearance)] + ($tz ? ['home_tz' => $tz] : []);
        $pdo->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $me]);
        ecw_tz_forget();   // zones are looked up once per request (lib/timezone.php)
    };
    $at = static fn(string $utc): DateTimeImmutable => new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    $set(['quiet' => '0']);
    check(!ecw_notify_quiet_now($me, $at('2026-11-04 22:00')), 'off: never quiet');
    $set(['quiet' => '1', 'quiet_from' => '19:00', 'quiet_to' => '08:00']);
    // Athens is UTC+2 in November: 18:00 UTC = 20:00 there.
    check(ecw_notify_quiet_now($me, $at('2026-11-04 18:00')), '20:00 in Athens (a Wednesday): quiet');
    check(ecw_notify_quiet_now($me, $at('2026-11-05 04:30')), '06:30 the next morning: still quiet (past midnight)');
    check(!ecw_notify_quiet_now($me, $at('2026-11-04 10:00')), '12:00 at noon: not quiet');
    check(!ecw_notify_quiet_now($me, $at('2026-11-07 10:00')), 'Saturday noon without "all weekend": not quiet');
    $set(['quiet' => '1', 'quiet_from' => '19:00', 'quiet_to' => '08:00', 'quiet_weekend' => '1']);
    check(ecw_notify_quiet_now($me, $at('2026-11-07 10:00')), 'with "all weekend": Saturday noon is quiet');
    $set(['quiet' => '1', 'quiet_from' => '13:00', 'quiet_to' => '14:00']);
    check(ecw_notify_quiet_now($me, $at('2026-11-04 11:30')) && !ecw_notify_quiet_now($me, $at('2026-11-04 12:30')), 'a daytime window: 13:30 quiet, 14:30 not');
    check(ecw_appearance(['quiet_from' => '25:99', 'quiet_to' => 'soon'])['quiet_from'] === '19:00' && ecw_appearance(['quiet_to' => 'soon'])['quiet_to'] === '08:00', 'a time that is not HH:MM falls back to 19:00–08:00');
    check(ecw_appearance([])['quiet'] === false, 'quiet hours are off until a person turns them on');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
