<?php declare(strict_types=1);
/**
 * Response-time targets (SLA), counted in business hours.
 *
 * An OPEN case is "waiting for us" from the oldest customer email that nobody
 * has answered yet (an incoming email after the last outgoing one, in any of
 * the case's conversations). Its clock runs only during business hours (the
 * workspace's days and hours, in its time zone). A case marked Waiting (on the
 * customer) or Closed has no clock.
 *
 *   ok       more than a quarter of the target left
 *   soon     a quarter or less left
 *   overdue  past the target
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';

const ECW_SLA_DEFAULTS = ['enabled' => true, 'reply_hours' => 8.0, 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00'];

function ecw_sla_settings(int $orgId): array
{
    static $cache = [];
    if (isset($cache[$orgId])) return $cache[$orgId];
    $st = ecw_db()->prepare('SELECT sla_json, timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $st->execute([$orgId]);
    $row = $st->fetch() ?: [];
    $s = (json_decode((string)($row['sla_json'] ?? ''), true) ?: []) + ECW_SLA_DEFAULTS;
    try { $tz = new DateTimeZone((string)($row['timezone'] ?? 'UTC') ?: 'UTC'); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $s['timezone'] = $tz->getName();
    return $cache[$orgId] = $s;
}

function ecw_sla_save(int $orgId, array $in): array
{
    $hm = static function ($v, string $fallback): string {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $fallback;
    };
    $hours = round((float)($in['reply_hours'] ?? 8), 1);
    if ($hours < 0.5 || $hours > 240) throw new InvalidArgumentException('Choose a target between half an hour and 240 business hours.');
    $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['days'] ?? [])), static fn($d) => $d >= 1 && $d <= 7)));
    sort($days);
    if ($days === []) throw new InvalidArgumentException('Pick at least one working day.');
    $start = $hm($in['start'] ?? null, '09:00');
    $end = $hm($in['end'] ?? null, '17:00');
    if ($end <= $start) throw new InvalidArgumentException('The working day must end after it starts.');
    $s = ['enabled' => !empty($in['enabled']), 'reply_hours' => $hours, 'days' => $days, 'start' => $start, 'end' => $end];
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET sla_json = ? WHERE id = ?')->execute([json_encode($s), $orgId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('settings.sla', null, null, ['enabled' => $s['enabled'], 'hours' => $s['reply_hours']], $orgId);
    return $s;
}

// ------------------------------------------------------------ business time

/** Business seconds in one whole week (every working day, start to end). */
function ecw_business_week_seconds(array $s): int
{
    [$sh, $sm] = array_map('intval', explode(':', $s['start']));
    [$eh, $em] = array_map('intval', explode(':', $s['end']));
    return count(array_unique($s['days'])) * max(0, ($eh * 60 + $em - $sh * 60 - $sm) * 60);
}

/**
 * Seconds of business time between two moments.
 * Whole weeks in the middle all count the same, so they are added at once
 * instead of walked day by day (Reports worked out ~1,000 open clocks, some a
 * year old: 600 ms of walking; tools/perf/bench.php).
 */
function ecw_business_seconds(int $from, int $to, array $s): int
{
    if ($to <= $from) return 0;
    $tz = new DateTimeZone($s['timezone'] ?? 'UTC');
    $day = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0);
    $weekly = ecw_business_week_seconds($s);
    $total = 0;
    for ($i = 0; $i < 400 && $day->getTimestamp() < $to; $i++, $day = $day->modify('+1 day')) {
        if ($i === 1 && $weekly > 0) {
            // Past the first (partial) day; keep the last week for the walk.
            $whole = intdiv($to - $day->getTimestamp(), 7 * 86400) - 1;
            if ($whole > 0) {
                $total += $whole * $weekly;
                $day = $day->modify('+' . (7 * $whole) . ' days');
            }
        }
        if (!in_array((int)$day->format('N'), $s['days'], true)) continue;
        [$sh, $sm] = array_map('intval', explode(':', $s['start']));
        [$eh, $em] = array_map('intval', explode(':', $s['end']));
        $open = $day->setTime($sh, $sm)->getTimestamp();
        $close = $day->setTime($eh, $em)->getTimestamp();
        $total += max(0, min($close, $to) - max($open, $from));
    }
    return $total;
}

/** The moment $seconds of business time after $from have passed. */
function ecw_business_add(int $from, int $seconds, array $s): int
{
    $tz = new DateTimeZone($s['timezone'] ?? 'UTC');
    $day = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0);
    $left = $seconds;
    $weekly = ecw_business_week_seconds($s);
    for ($i = 0; $i < 400; $i++, $day = $day->modify('+1 day')) {
        if ($i === 1 && $weekly > 0 && $left > 2 * $weekly) {
            $whole = intdiv($left, $weekly) - 1;   // as in ecw_business_seconds()
            $left -= $whole * $weekly;
            $day = $day->modify('+' . (7 * $whole) . ' days');
        }
        if (!in_array((int)$day->format('N'), $s['days'], true)) continue;
        [$sh, $sm] = array_map('intval', explode(':', $s['start']));
        [$eh, $em] = array_map('intval', explode(':', $s['end']));
        $open = max($day->setTime($sh, $sm)->getTimestamp(), $from);
        $close = $day->setTime($eh, $em)->getTimestamp();
        if ($close <= $open) continue;
        if ($close - $open >= $left) return $open + $left;
        $left -= $close - $open;
    }
    return $from + $seconds;   // no working days configured: plain clock time
}

// ------------------------------------------------------------ per case

/**
 * The reply clock of open cases.
 * @param int[] $caseIds
 * @return array<int,array{waiting_since:string, due_at:string, left_seconds:int, target_hours:float, state:string}> case id => clock (only cases waiting for us)
 */
function ecw_sla_for_cases(int $orgId, array $caseIds): array
{
    $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
    $s = ecw_sla_settings($orgId);
    if ($caseIds === [] || !$s['enabled']) return [];
    $in = implode(',', $caseIds);
    $cases = [];
    foreach (ecw_db()->query('SELECT c.id, c.status, g.sla_hours FROM `' . ecw_table('cases') . '` c LEFT JOIN `' . ecw_table('case_groups') . "` g ON g.id = c.group_id
                              WHERE c.id IN ({$in}) AND c.organization_id = " . (int)$orgId . " AND c.status = 'open' AND c.deleted_at IS NULL") as $c) {
        $cases[(int)$c['id']] = $c['sla_hours'] !== null ? (float)$c['sla_hours'] : (float)$s['reply_hours'];
    }
    if ($cases === []) return [];
    $open = implode(',', array_keys($cases));
    // Per conversation: the first incoming email after the last outgoing one.
    $rows = ecw_db()->query('SELECT ct.case_id, m.email_thread_id, m.direction, m.occurred_at FROM `' . ecw_table('case_threads') . '` ct
                               JOIN `' . ecw_table('email_messages') . "` m ON m.email_thread_id = ct.email_thread_id
                              WHERE ct.case_id IN ({$open}) AND m.direction IN ('incoming', 'outgoing') AND m.provider_removed_at IS NULL
                              ORDER BY ct.case_id, m.email_thread_id, m.occurred_at, m.id")->fetchAll();
    $pending = [];   // case => [thread => first unanswered incoming]
    foreach ($rows as $r) {
        $c = (int)$r['case_id']; $t = (int)$r['email_thread_id'];
        if ($r['direction'] === 'outgoing') { unset($pending[$c][$t]); continue; }
        $pending[$c][$t] ??= (string)$r['occurred_at'];
    }
    $now = time();
    $out = [];
    foreach ($pending as $caseId => $threads) {
        if ($threads === []) continue;
        $since = min($threads);
        $sinceTs = strtotime($since . ' UTC');
        $target = (int)round($cases[$caseId] * 3600);
        $used = ecw_business_seconds($sinceTs, $now, $s);
        $left = $target - $used;
        $out[$caseId] = [
            'waiting_since' => $since,
            'due_at' => gmdate('Y-m-d H:i:s', ecw_business_add($sinceTs, $target, $s)),
            'left_seconds' => $left,
            'target_hours' => $cases[$caseId],
            'state' => $left <= 0 ? 'overdue' : ($left <= $target / 4 ? 'soon' : 'ok'),
        ];
    }
    return $out;
}

/**
 * Replies in a period, in business hours, for Reports: every stretch where a
 * customer waited (first incoming after an outgoing) and how long until we
 * answered. Over cases matching $caseWhere (alias c, already visibility-checked).
 */
function ecw_sla_report(int $orgId, string $caseWhere, array $params, string $start, string $end): array
{
    $s = ecw_sla_settings($orgId);
    $st = ecw_db()->prepare('SELECT ct.case_id, c.owner_user_id, g.sla_hours, m.email_thread_id, m.direction, m.occurred_at
                               FROM `' . ecw_table('cases') . '` c
                               LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
                               JOIN `' . ecw_table('case_threads') . '` ct ON ct.case_id = c.id
                               JOIN `' . ecw_table('email_messages') . "` m ON m.email_thread_id = ct.email_thread_id
                              WHERE {$caseWhere} AND m.direction IN ('incoming', 'outgoing') AND m.provider_removed_at IS NULL
                                AND m.occurred_at BETWEEN ? AND DATE_ADD(?, INTERVAL 14 DAY)
                              ORDER BY m.email_thread_id, m.occurred_at, m.id LIMIT 60000");
    $st->execute(array_merge($params, [$start, $end]));
    $waitStart = [];
    $answered = [];   // [business seconds, target seconds, owner]
    foreach ($st->fetchAll() as $r) {
        $t = (int)$r['email_thread_id'];
        if ($r['direction'] === 'incoming') {
            if (!isset($waitStart[$t]) && $r['occurred_at'] >= $start && $r['occurred_at'] <= $end) $waitStart[$t] = [strtotime($r['occurred_at'] . ' UTC'), $r];
            continue;
        }
        if (isset($waitStart[$t])) {
            [$from, $first] = $waitStart[$t];
            $target = (int)round(($first['sla_hours'] !== null ? (float)$first['sla_hours'] : (float)$s['reply_hours']) * 3600);
            $answered[] = [ecw_business_seconds($from, strtotime($r['occurred_at'] . ' UTC'), $s), $target, $first['owner_user_id'] !== null ? (int)$first['owner_user_id'] : null];
            unset($waitStart[$t]);
        }
    }
    $within = count(array_filter($answered, static fn($a) => $a[0] <= $a[1]));
    $byOwner = [];
    foreach ($answered as [$secs, $target, $owner]) {
        $k = $owner ?? -1;
        $byOwner[$k] ??= ['replies' => 0, 'within' => 0, 'seconds' => []];
        $byOwner[$k]['replies']++;
        $byOwner[$k]['within'] += $secs <= $target ? 1 : 0;
        $byOwner[$k]['seconds'][] = $secs;
    }
    $names = [];
    if ($byOwner !== []) {
        $ids = implode(',', array_filter(array_keys($byOwner), static fn($k) => $k > 0)) ?: '0';
        foreach (ecw_db()->query('SELECT id, COALESCE(NULLIF(name, \'\'), email) n FROM `' . ecw_table('users') . "` WHERE id IN ({$ids})") as $u) $names[(int)$u['id']] = $u['n'];
    }
    $people = [];
    foreach ($byOwner as $k => $v) {
        $people[] = ['label' => $k > 0 ? ($names[$k] ?? 'Former member') : 'Unassigned', 'replies' => $v['replies'],
            'within_pct' => (int)round($v['within'] / max(1, $v['replies']) * 100), 'median_seconds' => ecw_sla_median($v['seconds'])];
    }
    usort($people, static fn($a, $b) => $b['replies'] <=> $a['replies']);
    return [
        'enabled' => (bool)$s['enabled'], 'target_hours' => (float)$s['reply_hours'],
        'hours' => $s['start'] . '–' . $s['end'], 'days' => $s['days'],
        'replies' => count($answered), 'within_pct' => $answered === [] ? null : (int)round($within / count($answered) * 100),
        'median_seconds' => ecw_sla_median(array_column($answered, 0)),
        'by_person' => array_slice($people, 0, 15),
    ];
}

/** "3.5 h left", "due in 40 min", "overdue 2 h" — for pills. */
function ecw_sla_label(array $clock): string
{
    $d = static function (int $s): string {
        $s = abs($s);
        return $s < 3600 ? max(1, (int)round($s / 60)) . ' min' : ($s < 86400 * 2 ? round($s / 3600, 1) . ' h' : round($s / 86400, 1) . ' d');
    };
    return $clock['state'] === 'overdue' ? 'overdue ' . $d($clock['left_seconds']) : $d($clock['left_seconds']) . ' left';
}

function ecw_sla_median(array $v): ?float
{
    if ($v === []) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}
