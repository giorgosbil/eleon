<?php declare(strict_types=1);
/**
 * Email Case Workspace — reports, with filters.
 *
 * Always computed over the cases the viewer can see (ecw_case_visible_sql), so
 * a report never reveals a members-only case. Filters:
 *   from, to      period (Y-m-d, inclusive; default the last 30 days)
 *   group_id      a group, or -1 for "no group"
 *   owner         Responsible user id, or -1 for unassigned
 *   handler       Handled-by user id, or -1 for unassigned
 *   status        open | waiting | closed
 * The period applies to flow figures (created, closed, reply and resolution
 * times, trend, customers); status totals and workload are "right now".
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/timezone.php';   // days as the viewer lives them

/** Normalize filters from the query string. */
function ecw_report_filters(array $in): array
{
    $day = static fn($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && strtotime($v) ? $v : null;
    $to = $day($in['to'] ?? null) ?? ecw_tz_today(ecw_tz_for_user(null));   // today where the viewer is
    $days = max(1, min(730, (int)($in['days'] ?? 30)));
    $from = $day($in['from'] ?? null) ?? gmdate('Y-m-d', strtotime($to . ' UTC') - ($days - 1) * 86400);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 730) {
        $from = gmdate('Y-m-d', strtotime($to . ' UTC') - 730 * 86400);
    }
    $int = static fn($v): int => (int)($v ?? 0);
    return [
        'from'     => $from,
        'to'       => $to,
        'group_id' => $int($in['group_id'] ?? 0),
        'owner'    => $int($in['owner'] ?? 0),
        'handler'  => $int($in['handler'] ?? 0),
        'status'   => in_array($in['status'] ?? '', ECW_CASE_STATUSES, true) ? (string)$in['status'] : '',
    ];
}

/** WHERE for the filtered, visible cases (alias c). Appends params in order. */
function ecw_report_where(int $orgId, int $userId, array $f, array &$params, bool $withStatus = true): string
{
    $where = ['c.organization_id = ?', 'c.deleted_at IS NULL'];
    $params[] = $orgId;
    $where[] = ecw_case_visible_sql('c', $userId, $params);
    // The cases of the account chosen at the top (lib/account.php).
    require_once __DIR__ . '/account.php';
    $where[] = ecw_case_account_sql('c', ecw_active_account_id($orgId, $userId), $params, $userId);
    foreach (['group_id' => 'c.group_id', 'owner' => 'c.owner_user_id', 'handler' => 'c.handler_user_id'] as $key => $col) {
        if ($f[$key] === -1) {
            $where[] = $col . ' IS NULL';
        } elseif ($f[$key] > 0) {
            $where[] = $col . ' = ?';
            $params[] = $f[$key];
        }
    }
    if ($withStatus && $f['status'] !== '') {
        $where[] = 'c.status = ?';
        $params[] = $f['status'];
    }
    return implode(' AND ', $where);
}

function ecw_median(array $values): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $n = count($values);
    return $n % 2 ? (float)$values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

function ecw_reports(int $orgId, int $userId, array $filters = []): array
{
    $f = ecw_report_filters($filters);
    $db = ecw_db();
    $C = ecw_table('cases');
    $T = ecw_table('case_threads');
    $M = ecw_table('email_messages');
    $U = ecw_table('users');
    // The chosen days where the viewer is, as UTC times (00:00 in Athens is 22:00 UTC the day before).
    $viewTz = ecw_tz_for_user(null, $orgId);
    [$start, $endEx] = ecw_tz_day_bounds($f['from'], $f['to'], $viewTz);
    $end = gmdate('Y-m-d H:i:s', strtotime($endEx . ' UTC') - 1);
    $q = static function (string $sql, array $params) use ($db): array {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    };

    // Right now: status totals (all filters except status itself).
    $p = [];
    $w = ecw_report_where($orgId, $userId, $f, $p, false);
    $status = ['open' => 0, 'waiting' => 0, 'closed' => 0];
    foreach ($q("SELECT c.status, COUNT(*) n FROM `{$C}` c WHERE {$w} GROUP BY c.status", $p) as $r) {
        $status[$r['status']] = (int)$r['n'];
    }

    // Flow in the period.
    $p = [];
    $w = ecw_report_where($orgId, $userId, $f, $p);
    $created = (int)($q("SELECT COUNT(*) n FROM `{$C}` c WHERE {$w} AND c.created_at BETWEEN ? AND ?", array_merge($p, [$start, $end]))[0]['n'] ?? 0);
    $closedRows = $q("SELECT c.created_at, c.closed_at FROM `{$C}` c WHERE {$w} AND c.closed_at BETWEEN ? AND ?", array_merge($p, [$start, $end]));
    $resolution = array_map(static fn($r) => max(0, strtotime($r['closed_at'] . ' UTC') - strtotime($r['created_at'] . ' UTC')), $closedRows);

    // Workload right now (not closed): by group, Responsible, Handled by.
    $p = [];
    $w = ecw_report_where($orgId, $userId, $f, $p);
    $open = "{$w} AND c.status <> 'closed'";
    $byGroup = array_map(static fn($r) => ['id' => $r['id'] === null ? -1 : (int)$r['id'], 'label' => $r['label'], 'color' => $r['color'], 'count' => (int)$r['n']],
        $q("SELECT g.id, COALESCE(g.name, 'No group') label, g.color, COUNT(*) n FROM `{$C}` c LEFT JOIN `" . ecw_table('case_groups') . "` g ON g.id = c.group_id
             WHERE {$open} GROUP BY g.id, g.name, g.color ORDER BY n DESC", $p));
    $person = static fn(string $col) => "SELECT u.id, COALESCE(u.name, u.email, 'Unassigned') label, COUNT(*) n FROM `{$C}` c LEFT JOIN `{$U}` u ON u.id = c.{$col}
             WHERE {$open} GROUP BY u.id, u.name, u.email ORDER BY n DESC LIMIT 15";
    $toRows = static fn(array $rows) => array_map(static fn($r) => ['id' => $r['id'] === null ? -1 : (int)$r['id'], 'label' => $r['label'], 'count' => (int)$r['n']], $rows);
    $byPerson = $toRows($q($person('owner_user_id'), $p));
    $byHandler = $toRows($q($person('handler_user_id'), $p));

    // Trend: weeks (or months for long periods) across the period.
    $spanDays = (int)round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
    $monthly = $spanDays > 182;
    $buckets = [];
    $cursor = $monthly ? strtotime(gmdate('Y-m-01', strtotime($f['from'] . ' UTC')) . ' UTC') : strtotime('monday this week', strtotime($f['from'] . ' UTC'));
    $limit = strtotime($end . ' UTC');
    while ($cursor <= $limit && count($buckets) < 60) {
        $key = gmdate($monthly ? 'Y-m' : 'Y-m-d', $cursor);
        $buckets[$key] = ['label' => gmdate($monthly ? 'M Y' : 'd M', $cursor), 'created' => 0, 'closed' => 0];
        $cursor = $monthly ? strtotime('+1 month', $cursor) : $cursor + 7 * 86400;
    }
    // Which week or month a moment belongs to, as the viewer counts days.
    $bucketOf = static function (string $at) use ($monthly, $viewTz): string {
        $local = ecw_tz_format($at, $viewTz, 'Y-m-d');
        return $monthly ? substr($local, 0, 7) : gmdate('Y-m-d', strtotime('monday this week', strtotime($local . ' UTC')));
    };
    foreach (['created' => 'created_at', 'closed' => 'closed_at'] as $key => $col) {
        foreach ($q("SELECT c.{$col} AS at FROM `{$C}` c WHERE {$w} AND c.{$col} BETWEEN ? AND ?", array_merge($p, [$start, $end])) as $r) {
            $b = $bucketOf($r['at']);
            if (isset($buckets[$b])) {
                $buckets[$b][$key]++;
            }
        }
    }

    // First reply: first incoming message on a case created in the period ->
    // first outgoing after it. Also the case's main customer domain.
    $rows = $q("SELECT ct.case_id, m.direction, m.occurred_at, m.from_email FROM `{$C}` c
                  JOIN `{$T}` ct ON ct.case_id = c.id
                  JOIN `{$M}` m ON m.email_thread_id = ct.email_thread_id
                 WHERE {$w} AND c.created_at BETWEEN ? AND ? AND m.direction IN ('incoming','outgoing') AND m.provider_removed_at IS NULL
                 ORDER BY ct.case_id, m.occurred_at LIMIT 50000", array_merge($p, [$start, $end]));
    $firstIn = $responses = $caseDomain = [];
    foreach ($rows as $r) {
        $id = (int)$r['case_id'];
        if ($r['direction'] === 'incoming') {
            $caseDomain[$id] = $caseDomain[$id] ?? strtolower((string)substr(strrchr((string)$r['from_email'], '@') ?: '', 1));
            if (!isset($firstIn[$id])) {
                $firstIn[$id] = strtotime($r['occurred_at'] . ' UTC');
            }
        } elseif (isset($firstIn[$id]) && !isset($responses[$id])) {
            $responses[$id] = max(0, strtotime($r['occurred_at'] . ' UTC') - $firstIn[$id]);
        }
    }
    $within24 = count(array_filter($responses, static fn($s) => $s <= 86400));
    $waitingFirst = count(array_diff_key($firstIn, $responses));

    // Busiest customers (by email domain), excluding the organization's own domains.
    $ownDomains = array_unique(array_map(static fn($e) => strtolower((string)substr(strrchr((string)$e, '@') ?: '', 1)),
        array_column($q('SELECT email_address FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ?', [$orgId]), 'email_address')));
    $domains = [];
    foreach ($caseDomain as $d) {
        if ($d !== '' && !in_array($d, $ownDomains, true)) {
            $domains[$d] = ($domains[$d] ?? 0) + 1;
        }
    }
    arsort($domains);
    $customers = array_map(static fn($d, $n) => ['label' => $d, 'count' => $n], array_keys(array_slice($domains, 0, 10, true)), array_slice($domains, 0, 10, true));

    // Needs attention: not closed, no activity for 7+ days.
    $aging = array_map(static fn($r) => ['id' => (int)$r['id'], 'number' => $r['case_number'], 'name' => $r['name'], 'status' => $r['status'],
        'owner' => $r['owner'], 'last_activity' => $r['last_activity']],
        $q("SELECT x.* FROM (SELECT c.id, c.case_number, c.name, c.status, u.name AS owner,
                   (SELECT MAX(m.occurred_at) FROM `{$T}` ct2 JOIN `{$M}` m ON m.email_thread_id = ct2.email_thread_id WHERE ct2.case_id = c.id) AS last_activity
              FROM `{$C}` c LEFT JOIN `{$U}` u ON u.id = c.owner_user_id WHERE {$open}) x
             WHERE x.last_activity IS NULL OR x.last_activity < ?
             ORDER BY x.last_activity IS NULL DESC, x.last_activity ASC LIMIT 25", array_merge($p, [gmdate('Y-m-d H:i:s', time() - 7 * 86400)])));

    // Response-time targets (business hours): replies in the period, and open cases late or nearly late now.
    require_once __DIR__ . '/sla.php';
    $sla = ecw_sla_report($orgId, $w, $p, $start, $end);
    $clocks = ecw_sla_for_cases($orgId, array_map(static fn($r) => (int)$r['id'], $q("SELECT c.id FROM `{$C}` c WHERE {$open} LIMIT 3000", $p)));
    $sla['overdue_now'] = count(array_filter($clocks, static fn($c) => $c['state'] === 'overdue'));
    $sla['due_soon'] = count(array_filter($clocks, static fn($c) => $c['state'] === 'soon'));
    $sla['waiting_now'] = count($clocks);
    $late = array_filter($clocks, static fn($c) => $c['state'] !== 'ok');
    uasort($late, static fn($a, $b) => $a['left_seconds'] <=> $b['left_seconds']);
    $late = array_slice($late, 0, 15, true);
    $sla['late_cases'] = [];
    if ($late !== []) {
        $ids = implode(',', array_keys($late));
        $info = [];
        foreach ($db->query("SELECT c.id, c.case_number, c.name, u.name AS owner FROM `{$C}` c LEFT JOIN `{$U}` u ON u.id = c.owner_user_id WHERE c.id IN ({$ids})") as $r) $info[(int)$r['id']] = $r;
        foreach ($late as $id => $c) {
            if (!isset($info[$id])) continue;
            $sla['late_cases'][] = ['id' => $id, 'number' => $info[$id]['case_number'], 'name' => $info[$id]['name'], 'owner' => $info[$id]['owner'],
                'state' => $c['state'], 'left_seconds' => $c['left_seconds'], 'waiting_since' => $c['waiting_since']];
        }
    }

    return [
        'filters'        => $f,
        'sla'            => $sla,
        'status'         => $status,
        'created'        => $created,
        'closed'         => count($closedRows),
        'first_response' => [
            'median_seconds'  => ecw_median(array_values($responses)),
            'average_seconds' => $responses === [] ? null : array_sum($responses) / count($responses),
            'within_24h_pct'  => $responses === [] ? null : round($within24 / count($responses) * 100),
            'cases'           => count($responses),
            'still_waiting'   => $waitingFirst,
        ],
        'resolution'     => ['median_seconds' => ecw_median($resolution), 'cases' => count($resolution)],
        'by_group'       => $byGroup,
        'by_person'      => $byPerson,
        'by_handler'     => $byHandler,
        'trend'          => ['unit' => $monthly ? 'month' : 'week', 'buckets' => array_values($buckets)],
        'customers'      => $customers,
        'aging'          => $aging,
    ];
}

/** The filtered cases as CSV rows (header first), for the export button. */
function ecw_reports_csv(int $orgId, int $userId, array $filters): array
{
    $f = ecw_report_filters($filters);
    $p = [];
    $w = ecw_report_where($orgId, $userId, $f, $p);
    $st = ecw_db()->prepare(
        'SELECT c.case_number, c.name, c.status, g.name AS grp, o.name AS owner, h.name AS handler, c.created_at, c.closed_at,
                (SELECT MAX(m.occurred_at) FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('email_messages') . '` m ON m.email_thread_id = ct.email_thread_id WHERE ct.case_id = c.id) AS last_activity,
                (SELECT COUNT(*) FROM `' . ecw_table('case_threads') . '` ct WHERE ct.case_id = c.id) AS threads
           FROM `' . ecw_table('cases') . '` c
           LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
           LEFT JOIN `' . ecw_table('users') . '` o ON o.id = c.owner_user_id
           LEFT JOIN `' . ecw_table('users') . '` h ON h.id = c.handler_user_id
          WHERE ' . $w . ' AND (c.created_at BETWEEN ? AND ? OR c.closed_at BETWEEN ? AND ? OR c.status <> \'closed\')
          ORDER BY c.created_at DESC LIMIT 5000'
    );
    // The chosen days where the viewer is, as UTC times (00:00 in Athens is 22:00 UTC the day before).
    $viewTz = ecw_tz_for_user(null, $orgId);
    [$start, $endEx] = ecw_tz_day_bounds($f['from'], $f['to'], $viewTz);
    $end = gmdate('Y-m-d H:i:s', strtotime($endEx . ' UTC') - 1);
    $st->execute(array_merge($p, [$start, $end, $start, $end]));
    $rows = [['Case', 'Name', 'Status', 'Group', 'Responsible', 'Handled by', 'Created (' . $viewTz . ')', 'Closed (' . $viewTz . ')', 'Last activity (' . $viewTz . ')', 'Threads']];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        foreach ([6, 7, 8] as $i) if (!empty($r[$i])) $r[$i] = ecw_tz_format((string)$r[$i], $viewTz, 'Y-m-d H:i');   // the viewer's time
        // A leading = + - @ would be run as a formula by spreadsheet apps (CSV injection).
        $rows[] = array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $r);
    }
    return $rows;
}
