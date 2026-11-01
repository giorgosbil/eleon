<?php declare(strict_types=1);
/**
 * Email Case Workspace — the company's case settings (Settings → Case settings).
 *
 * Kept in organizations.case_settings_json, merged over ECW_CASE_SETTINGS_DEFAULTS:
 *   statuses       the company's own statuses. Each belongs to one of the three groups the app
 *                  works with (open, waiting, closed): the case's `status` keeps the group, so a
 *                  reply still reopens a waiting case, closed cases still leave the lists and the
 *                  reports still count right; `sub_status` holds the company's status.
 *   roles          conversation roles beyond customer / supplier / internal (the timeline's
 *                  "Whose conversation?"). Deals and reports know the three built-in ones only.
 *   waiting_overdue_days  a case in Waiting for longer counts as overdue (0: off).
 *   default_due_days      a new case gets a due date this many working days ahead (0: none).
 *   due_grace_days        a due date turns overdue this many days after it (0: the day after).
 *   number_format, number_digits  CASE-{N} → e.g. SRV-{YYYY}-{N}; {N} is the running number.
 *   tag_colors     a colour per tag.
 */

require_once __DIR__ . '/db.php';

const ECW_CASE_SETTINGS_DEFAULTS = [
    'statuses' => [], 'roles' => [],
    'waiting_overdue_days' => 0, 'default_due_days' => 0, 'due_grace_days' => 0,
    'number_format' => 'CASE-{N}', 'number_digits' => 5,
    'tag_colors' => [],
];
/** The groups every status belongs to, with their built-in names and colours. */
const ECW_CASE_STATUS_GROUPS = ['open' => ['Open', '#2563eb'], 'waiting' => ['Waiting', '#b06a00'], 'closed' => ['Closed', '#6b7280']];
const ECW_PARTY_ROLE_BUILTIN = ['customer' => ['Customer', '#d97706'], 'supplier' => ['Supplier', '#7c3aed'], 'internal' => ['Internal', '#0891b2']];
const ECW_CASE_SETTINGS_MAX_ITEMS = 30;

function ecw_case_settings(int $orgId, bool $fresh = false): array
{
    static $cache = [];
    if (isset($cache[$orgId]) && !$fresh) return $cache[$orgId];
    try {
        $st = ecw_db()->prepare('SELECT case_settings_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
        $st->execute([$orgId]);
        $raw = $st->fetchColumn();
    } catch (PDOException $e) { $raw = null; }   // before the migration (or a test's small stand-in database)
    $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    return $cache[$orgId] = array_replace(ECW_CASE_SETTINGS_DEFAULTS, array_intersect_key($saved, ECW_CASE_SETTINGS_DEFAULTS));
}

/** A key from a name: "Waiting for parts" → waiting-for-parts (letters of any language kept). */
function ecw_case_settings_key(string $label, int $max): string
{
    $k = mb_strtolower(trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $label), '-'));
    return mb_substr($k, 0, $max);
}

function ecw_case_settings_color($v, string $fallback): string
{
    return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
}

/**
 * Save the case settings. $in: the form (statuses[], roles[] as rows of label/base/color/key,
 * numbers, number_format…). Keys of statuses and roles in use stay as they were, so cases and
 * conversations keep theirs when a name changes. @return array the saved settings
 */
function ecw_case_settings_save(int $orgId, array $in, ?int $actorUserId = null): array
{
    $s = ecw_case_settings($orgId, true);
    $int = static fn($v, int $min, int $max): int => max($min, min($max, (int)$v));

    if (array_key_exists('statuses', $in)) {
        $out = []; $seen = array_fill_keys(array_merge(array_keys(ECW_CASE_STATUS_GROUPS), ['all']), true);
        foreach ((array)$in['statuses'] as $row) {
            $label = trim(mb_substr((string)($row['label'] ?? ''), 0, 40));
            if ($label === '') continue;
            $base = (string)($row['base'] ?? 'open');
            if (!isset(ECW_CASE_STATUS_GROUPS[$base])) $base = 'open';
            $key = (string)($row['key'] ?? '');
            if (!preg_match('/^[\p{L}\p{N}-]{1,32}$/u', $key)) $key = ecw_case_settings_key($label, 32);
            if ($key === '') $key = 's' . (count($out) + 1);
            for ($n = 2, $k = $key; isset($seen[$k]); $n++) $k = mb_substr($key, 0, 29) . '-' . $n;
            $seen[$k] = true;
            $out[] = ['key' => $k, 'label' => $label, 'base' => $base, 'color' => ecw_case_settings_color($row['color'] ?? null, ECW_CASE_STATUS_GROUPS[$base][1])];
            if (count($out) >= ECW_CASE_SETTINGS_MAX_ITEMS) break;
        }
        // A status taken away: its cases keep their group, without the removed name.
        $gone = array_diff(array_column($s['statuses'], 'key'), array_column($out, 'key'));
        if ($gone) {
            $ph = implode(',', array_fill(0, count($gone), '?'));
            ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET sub_status = NULL WHERE organization_id = ? AND sub_status IN (' . $ph . ')')->execute(array_merge([$orgId], array_values($gone)));
        }
        // A status moved to another group takes its cases along.
        foreach ($out as $st) {
            ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET status = ?, closed_at = IF(? = \'closed\', COALESCE(closed_at, UTC_TIMESTAMP()), NULL)
                                WHERE organization_id = ? AND sub_status = ? AND status <> ?')->execute([$st['base'], $st['base'], $orgId, $st['key'], $st['base']]);
        }
        $s['statuses'] = $out;
    }
    if (array_key_exists('roles', $in)) {
        $out = []; $seen = array_fill_keys(array_keys(ECW_PARTY_ROLE_BUILTIN), true);
        foreach ((array)$in['roles'] as $row) {
            $label = trim(mb_substr((string)($row['label'] ?? ''), 0, 30));
            if ($label === '') continue;
            $key = (string)($row['key'] ?? '');
            if (!preg_match('/^[\p{L}\p{N}-]{1,16}$/u', $key)) $key = ecw_case_settings_key($label, 16);
            if ($key === '') $key = 'r' . (count($out) + 1);
            for ($n = 2, $k = $key; isset($seen[$k]); $n++) $k = mb_substr($key, 0, 13) . '-' . $n;
            $seen[$k] = true;
            $out[] = ['key' => $k, 'label' => $label, 'color' => ecw_case_settings_color($row['color'] ?? null, '#475569')];
            if (count($out) >= ECW_CASE_SETTINGS_MAX_ITEMS) break;
        }
        $gone = array_diff(array_column($s['roles'], 'key'), array_column($out, 'key'));
        if ($gone) {
            $ph = implode(',', array_fill(0, count($gone), '?'));
            ecw_db()->prepare('UPDATE `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
                                SET ct.party_role = NULL WHERE c.organization_id = ? AND ct.party_role IN (' . $ph . ')')->execute(array_merge([$orgId], array_values($gone)));
        }
        $s['roles'] = $out;
    }
    foreach (['waiting_overdue_days' => 365, 'default_due_days' => 365, 'due_grace_days' => 60] as $k => $max) {
        if (array_key_exists($k, $in)) $s[$k] = $int($in[$k], 0, $max);
    }
    if (array_key_exists('number_format', $in)) {
        $f = trim((string)$in['number_format']);
        if ($f === '') $f = ECW_CASE_SETTINGS_DEFAULTS['number_format'];
        if (!str_ends_with($f, '{N}') || substr_count($f, '{N}') !== 1) throw new InvalidArgumentException('The case number must end with {N}, the running number (for example SRV-{YYYY}-{N}).');
        $prefix = str_replace(['{YYYY}', '{YY}'], ['2026', '26'], substr($f, 0, -3));
        if (!preg_match('/^[A-Za-z0-9\-_\/.#]{0,20}$/', $prefix)) throw new InvalidArgumentException('Use letters, numbers and - _ / . # before {N} (up to 20), and {YYYY} or {YY} for the year.');
        $s['number_format'] = $f;
    }
    if (array_key_exists('number_digits', $in)) $s['number_digits'] = $int($in['number_digits'], 1, 9);
    if (array_key_exists('tag_colors', $in)) {
        $c = [];
        foreach ((array)$in['tag_colors'] as $tag => $color) {
            $tag = mb_strtolower(trim((string)$tag));
            if ($tag !== '' && is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $c[$tag] = strtolower($color);
        }
        $s['tag_colors'] = $c;
    }

    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET case_settings_json = ? WHERE id = ?')
        ->execute([json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $orgId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('settings.cases', null, null, ['statuses' => count($s['statuses']), 'roles' => count($s['roles']), 'number' => $s['number_format']], $orgId);
    return ecw_case_settings($orgId, true);
}

// ------------------------------------------------------------------ statuses

/**
 * Every status a case can have, in order: each group, then the company's statuses in it.
 * @return list<array{value:string, label:string, base:string, color:string, custom:bool}>
 */
function ecw_case_status_options(int $orgId): array
{
    $custom = ecw_case_settings($orgId)['statuses'];
    $out = [];
    foreach (ECW_CASE_STATUS_GROUPS as $base => [$label, $color]) {
        $out[] = ['value' => $base, 'label' => $label, 'base' => $base, 'color' => $color, 'custom' => false];
        foreach ($custom as $c) if ($c['base'] === $base) $out[] = ['value' => $c['key'], 'label' => $c['label'], 'base' => $base, 'color' => $c['color'], 'custom' => true];
    }
    return $out;
}

/** A status a person chose (a group or one of the company's): [status, sub_status], or null. */
function ecw_case_status_resolve(int $orgId, string $value): ?array
{
    if (isset(ECW_CASE_STATUS_GROUPS[$value])) return [$value, null];
    foreach (ecw_case_settings($orgId)['statuses'] as $c) if ($c['key'] === $value) return [$c['base'], $c['key']];
    return null;
}

/** How a case's status reads, and its colour. @return array{value:string, label:string, color:string} */
function ecw_case_status_display(int $orgId, string $status, ?string $subStatus): array
{
    if ($subStatus !== null && $subStatus !== '') {
        foreach (ecw_case_settings($orgId)['statuses'] as $c) if ($c['key'] === $subStatus) return ['value' => $c['key'], 'label' => $c['label'], 'color' => $c['color']];
    }
    [$label, $color] = ECW_CASE_STATUS_GROUPS[$status] ?? [ucfirst($status), '#6b7280'];
    return ['value' => $status, 'label' => $label, 'color' => $color];
}

// ------------------------------------------------------------------ overdue, due dates

/**
 * SQL for "this case is overdue" (alias c): its due date passed (after the grace days), or it
 * has been in Waiting longer than the setting. Only whole numbers go into the SQL.
 */
function ecw_case_overdue_sql(int $orgId, string $alias = 'c'): string
{
    $s = ecw_case_settings($orgId);
    $grace = (int)$s['due_grace_days'];
    require_once __DIR__ . '/timezone.php';
    $today = ecw_tz_sql_today($orgId);   // the day at the company, not in UTC
    $sql = '(' . $alias . '.due_at IS NOT NULL AND ' . $alias . '.due_at < ' . ($grace > 0 ? $today . ' - INTERVAL ' . $grace . ' DAY' : $today) . ' AND ' . $alias . ".status <> 'closed')";
    if ((int)$s['waiting_overdue_days'] > 0) {
        $sql = '(' . $sql . ' OR (' . $alias . ".status = 'waiting' AND COALESCE(" . $alias . '.status_changed_at, ' . $alias . '.updated_at) < UTC_TIMESTAMP() - INTERVAL ' . (int)$s['waiting_overdue_days'] . ' DAY))';
    }
    return $sql;
}

/** The due date a new case gets ('YYYY-MM-DD', counting Monday–Friday), or null. */
function ecw_case_default_due(int $orgId, ?DateTimeImmutable $from = null): ?string
{
    $days = (int)ecw_case_settings($orgId)['default_due_days'];
    if ($days <= 0) return null;
    require_once __DIR__ . '/timezone.php';
    $d = $from ?? new DateTimeImmutable(ecw_tz_company_today($orgId), new DateTimeZone('UTC'));   // today at the company
    while ($days > 0) { $d = $d->modify('+1 day'); if ((int)$d->format('N') <= 5) $days--; }
    return $d->format('Y-m-d');
}

// ------------------------------------------------------------------ roles

/** Every conversation role: the three built-in ones, then the company's. @return array<string, array{label:string, color:string}> */
function ecw_party_roles(int $orgId): array
{
    $out = [];
    foreach (ECW_PARTY_ROLE_BUILTIN as $k => [$label, $color]) $out[$k] = ['label' => $label, 'color' => $color];
    foreach (ecw_case_settings($orgId)['roles'] as $r) $out[$r['key']] = ['label' => $r['label'], 'color' => $r['color']];
    return $out;
}

// ------------------------------------------------------------------ case numbers

/** What comes before the running number this year ('CASE-', 'SRV-2026-'), and its digits. @return array{0:string, 1:int} */
function ecw_case_number_parts(int $orgId, ?DateTimeImmutable $now = null): array
{
    $s = ecw_case_settings($orgId);
    require_once __DIR__ . '/timezone.php';
    $now ??= new DateTimeImmutable('now', new DateTimeZone(ecw_tz_company($orgId)));   // the year at the company
    $prefix = str_replace(['{YYYY}', '{YY}'], [$now->format('Y'), $now->format('y')], substr((string)$s['number_format'], 0, -3));
    return [$prefix, (int)$s['number_digits']];
}

// ------------------------------------------------------------------ tags

/** Every tag of the company with how many cases carry it. @return list<array{tag:string, cases:int}> */
function ecw_case_tag_list(int $orgId): array
{
    $st = ecw_db()->prepare('SELECT t.tag, COUNT(*) AS n FROM `' . ecw_table('case_tags') . '` t JOIN `' . ecw_table('cases') . '` c ON c.id = t.case_id
                              WHERE c.organization_id = ? AND c.deleted_at IS NULL GROUP BY t.tag ORDER BY t.tag');
    $st->execute([$orgId]);
    return array_map(static fn($r) => ['tag' => (string)$r['tag'], 'cases' => (int)$r['n']], $st->fetchAll());
}

/**
 * Rename a tag on every case of the company; renaming to a tag that exists merges the two.
 * An empty new name removes the tag from every case. @return int cases changed
 */
function ecw_case_tag_rename(int $orgId, string $from, string $to, ?int $actorUserId = null): int
{
    $from = mb_strtolower(trim($from));
    $to = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N} _\-]/u', '', $to))));
    $to = mb_substr($to, 0, 40);
    if ($from === '' || $from === $to) return 0;
    $pdo = ecw_db();
    $cases = $pdo->prepare('SELECT t.case_id FROM `' . ecw_table('case_tags') . '` t JOIN `' . ecw_table('cases') . '` c ON c.id = t.case_id WHERE c.organization_id = ? AND t.tag = ?');
    $cases->execute([$orgId, $from]);
    $ids = array_map('intval', $cases->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    if ($to !== '') $pdo->prepare('INSERT IGNORE INTO `' . ecw_table('case_tags') . '` (case_id, tag) SELECT case_id, ? FROM `' . ecw_table('case_tags') . '` WHERE tag = ? AND case_id IN (' . $ph . ')')->execute(array_merge([$to, $from], $ids));
    $pdo->prepare('DELETE FROM `' . ecw_table('case_tags') . '` WHERE tag = ? AND case_id IN (' . $ph . ')')->execute(array_merge([$from], $ids));
    require_once __DIR__ . '/cases.php';
    require_once __DIR__ . '/categories.php';
    foreach ($ids as $id) {
        ecw_case_log_event($id, 'CASE_TAGS_CHANGED', $actorUserId, null, null, ['renamed' => $from, 'to' => $to]);
        ecw_categories_mark_case($id);   // the tags show as Outlook categories
    }
    // The colour follows the name.
    $s = ecw_case_settings($orgId);
    if (isset($s['tag_colors'][$from])) {
        $colors = $s['tag_colors'];
        if ($to !== '' && !isset($colors[$to])) $colors[$to] = $colors[$from];
        unset($colors[$from]);
        ecw_case_settings_save($orgId, ['tag_colors' => $colors], $actorUserId);
    }
    return count($ids);
}

/** What every page needs to show statuses, roles and tags in the company's names and colours (window.ecwCaseSettings). */
function ecw_case_settings_for_pages(int $orgId): array
{
    $s = ecw_case_settings($orgId);
    return [
        'statuses' => ecw_case_status_options($orgId),
        'roles' => ecw_party_roles($orgId),
        'tag_colors' => (object)$s['tag_colors'],
        'due_grace_days' => (int)$s['due_grace_days'],
        'waiting_overdue_days' => (int)$s['waiting_overdue_days'],
    ];
}
