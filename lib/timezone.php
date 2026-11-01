<?php declare(strict_types=1);
/**
 * Email Case Workspace — whose clock a time is shown in.
 *
 * Times are stored in UTC everywhere. On screen every time follows the person looking at it:
 * the time zone of their computer, as Outlook does when travelling. The browser tells the
 * server that zone on every page (assets/timezone.js, cookie ecw_tz), and the server keeps the
 * last one it saw for that person (preferences "tz"), for what is written for them while they
 * are away from the screen (a notification, the morning briefing, a reminder's text).
 *
 * Company rules (working hours for reply times, the morning briefing hour, working days,
 * holidays) follow the company's time zone (Settings → Response times). A company whose zone
 * is still UTC — the default nobody chose — takes the zone of the first admin seen
 * (ecw_tz_sync), once, recorded in the audit log.
 */

require_once __DIR__ . '/db.php';

/** A real IANA zone name ("Europe/Athens"), or null. */
function ecw_tz_valid(?string $tz): ?string
{
    $tz = trim((string)$tz);
    if ($tz === '' || !preg_match('#^[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+){0,2}$#', $tz)) return $tz === 'UTC' ? 'UTC' : null;
    try { return (new DateTimeZone($tz))->getName(); } catch (Throwable $e) { return null; }
}

/** The zone of the computer making this request (assets/timezone.js), or null outside a page. */
function ecw_tz_device(): ?string
{
    if (PHP_SAPI === 'cli') return defined('ECW_TESTING') && isset($GLOBALS['ecw_tz_test_device']) ? ecw_tz_valid($GLOBALS['ecw_tz_test_device']) : null;   // tests stand in for a browser
    return ecw_tz_valid($_COOKIE['ecw_tz'] ?? null);
}

/** The person behind this page request, when there is one (for ecw_tz_for_user). */
function ecw_tz_viewer(): ?int
{
    if (PHP_SAPI === 'cli') return defined('ECW_TESTING') && isset($GLOBALS['ecw_tz_test_viewer']) ? (int)$GLOBALS['ecw_tz_test_viewer'] : null;
    return isset($_SESSION['ecw_user_id']) ? (int)$_SESSION['ecw_user_id'] : null;
}

/** The company's time zone, for its rules (Settings → Response times). */
function ecw_tz_company(int $orgId): string
{
    $cache = &$GLOBALS['ecw_tz_cache']['company'];
    if (!isset($cache[$orgId])) {
        $st = ecw_db()->prepare('SELECT timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?');
        $st->execute([$orgId]);
        $cache[$orgId] = ecw_tz_valid((string)$st->fetchColumn()) ?? 'UTC';
    }
    return $cache[$orgId];
}

/**
 * The zone to show a time in for this person: the computer they are using now (only when the
 * time is for the person making this request — a notification written for a colleague uses
 * the colleague's zone); away from the screen, the one they were last seen in; else their home
 * zone, the company's, UTC.
 */
function ecw_tz_for_user(?int $userId, ?int $orgId = null): string
{
    $viewer = ecw_tz_viewer();
    if (($userId === null || $userId === $viewer) && ($device = ecw_tz_device()) !== null) return $device;
    $userId ??= $viewer;
    $seen = &$GLOBALS['ecw_tz_cache']['user'];   // one look per person and request (ecw_tz_forget)
    if ($userId !== null && $userId > 0) {
        if (!is_array($seen) || !array_key_exists($userId, $seen)) {
            $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
            $st->execute([$userId]);
            $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
            $seen[$userId] = null;
            foreach (['tz', 'home_tz'] as $key) if (($z = ecw_tz_valid($prefs[$key] ?? null)) !== null) { $seen[$userId] = $z; break; }
        }
        if ($seen[$userId] !== null) return $seen[$userId];
    }
    return $orgId !== null ? ecw_tz_company($orgId) : 'UTC';
}

/** Forget the zones looked up during this request (after one changed). */
function ecw_tz_forget(): void
{
    unset($GLOBALS['ecw_tz_cache']);
}

/** A stored UTC time ('Y-m-d H:i:s') as wall-clock time in $tz, in $format. '' for none. */
function ecw_tz_format(?string $utc, string $tz, string $format = 'j M Y, H:i'): string
{
    if ($utc === null || trim($utc) === '') return '';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

/** A stored UTC time as the person making this request sees it ('' for none). */
function ecw_tz_local(?string $utc, string $format = 'j M Y, H:i', ?int $orgId = null): string
{
    return ecw_tz_format($utc, ecw_tz_for_user(null, $orgId), $format);
}

/** Today's date ('Y-m-d') where $tz is, now. */
function ecw_tz_today(string $tz, ?int $at = null): string
{
    try { return (new DateTimeImmutable('@' . ($at ?? time())))->setTimezone(new DateTimeZone($tz))->format('Y-m-d'); }
    catch (Throwable $e) { return gmdate('Y-m-d', $at ?? time()); }
}

/** Today's date at the company (its rules: due today, overdue, working days). */
function ecw_tz_company_today(int $orgId): string
{
    return ecw_tz_today(ecw_tz_company($orgId));
}

/**
 * Today at the company, for SQL: a quoted date ('2026-10-31'), made here from the clock (never
 * from input). Without a company (0): the database's UTC date.
 */
function ecw_tz_sql_today(int $orgId): string
{
    return $orgId > 0 ? "'" . ecw_tz_company_today($orgId) . "'" : 'UTC_DATE()';
}

/** UTC bounds ['Y-m-d H:i:s', 'Y-m-d H:i:s') of the calendar days $from..$to as lived in $tz. */
function ecw_tz_day_bounds(string $from, string $to, string $tz): array
{
    $start = ecw_tz_to_utc($from . ' 00:00:00', $tz) ?? $from . ' 00:00:00';
    $end = ecw_tz_to_utc(date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00', $tz) ?? $to . ' 23:59:59';
    return [$start, $end];
}

/** A wall-clock time someone typed in $tz ('Y-m-d H:i'), as stored UTC ('Y-m-d H:i:s'). */
function ecw_tz_to_utc(string $local, string $tz): ?string
{
    try {
        return (new DateTimeImmutable($local, new DateTimeZone($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Every page: keep the zone this person's computer is in (for what is written for them later),
 * and give a company still on the default UTC the zone of an admin's computer. Cheap when
 * nothing changed (one preferences read).
 */
function ecw_tz_sync(int $orgId, int $userId, bool $isAdmin): void
{
    $device = ecw_tz_device();
    if ($device === null) return;
    $pdo = ecw_db();
    $st = $pdo->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
    if (($prefs['tz'] ?? '') !== $device) {
        $prefs['tz'] = $device;
        $pdo->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $userId]);
        ecw_tz_forget();
    }
    if ($isAdmin && $device !== 'UTC' && ecw_tz_company($orgId) === 'UTC') {
        $up = $pdo->prepare('UPDATE `' . ecw_table('organizations') . "` SET timezone = ? WHERE id = ? AND (timezone IS NULL OR timezone = '' OR timezone = 'UTC')");
        $up->execute([$device, $orgId]);
        if ($up->rowCount() > 0) {
            ecw_tz_forget();
            require_once __DIR__ . '/audit.php';
            ecw_audit('settings.timezone_auto', 'organization', $orgId, ['timezone' => $device, 'was' => 'UTC'], $orgId, $userId);
            ecw_log('company ' . $orgId . ' time zone set to ' . $device . ' from an admin\'s computer (it was the default UTC)');
        }
    }
}
