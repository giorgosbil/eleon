<?php declare(strict_types=1);
/**
 * Security settings of an organization (Settings → Security):
 *
 *   Microsoft sign-in   off / on / required (lib/sso.php does the sign-in)
 *   Legal hold          a held case cannot be deleted, its emails cannot be
 *                       deleted for good, retention never touches it
 *   Retention           how long the audit log, deleted cases and the local
 *                       copies of emails deleted from the mailbox are kept;
 *                       run once a day by the worker (bin/worker.php)
 *   Backup password     backups are written as AES-256 zips (instance-wide)
 *
 * Retention removes only what this app stores. Mail in the mailbox itself
 * follows the mailbox's own (Microsoft 365 / Google) retention.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

const ECW_SECURITY_DEFAULTS = [
    'sso' => 'off',             // off | on | required
    'sso_tenant' => '',         // the Microsoft 365 tenant (directory) id people sign in from
    'sso_create' => false,      // a person of that tenant without an account gets one
    'sso_role' => 'user',
    'audit_days' => 0,          // 0 = keep forever, else at least 90
    'trash_days' => 0,          // deleted cases removed for good after; 0 = never
    'removed_days' => 0,        // local copies of emails deleted from the mailbox; 0 = keep
];

/**
 * $saved: what the admin chose. Otherwise what is in force: Microsoft sign-in
 * and retention are part of Business, so without it they are off (passwords
 * work, nothing is removed). Legal holds are always kept.
 */
function ecw_security_settings(int $orgId, bool $saved = false): array
{
    $s = ECW_SECURITY_DEFAULTS;
    try {
        $st = ecw_db()->prepare('SELECT security_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
        $st->execute([$orgId]);
        $stored = json_decode((string)$st->fetchColumn(), true);
        if (is_array($stored)) $s = array_intersect_key($stored, $s) + $s;
    } catch (Throwable $e) {}   // before migration 038
    $s['sso'] = in_array($s['sso'], ['off', 'on', 'required'], true) ? $s['sso'] : 'off';
    $s['sso_create'] = (bool)$s['sso_create'];
    foreach (['audit_days', 'trash_days', 'removed_days'] as $k) $s[$k] = max(0, (int)$s[$k]);
    if (!$saved && !ecw_security_licensed()) {
        $s['sso'] = 'off';
        $s['audit_days'] = $s['trash_days'] = $s['removed_days'] = 0;
    }
    return $s;
}

function ecw_security_licensed(): bool
{
    require_once __DIR__ . '/license.php';
    return ecw_package_at_least('business');
}

/** @return string|null an error, or null when saved */
function ecw_security_settings_save(int $orgId, array $in): ?string
{
    require_once __DIR__ . '/schema.php';
    $old = ecw_security_settings($orgId, true);
    $s = $old;
    if (isset($in['sso'])) {
        $s['sso'] = in_array($in['sso'], ['off', 'on', 'required'], true) ? (string)$in['sso'] : 'off';
        $tenant = strtolower(trim((string)($in['sso_tenant'] ?? '')));
        if ($tenant !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $tenant)) return 'The tenant id looks like 8-4-4-4-12 letters and digits (a GUID).';
        if ($tenant === '9188040d-6c67-4c5b-b112-36a304b66dad') return 'That is the shared tenant of all personal Microsoft accounts. Use your company\'s tenant id; people with personal accounts can link them under Account.';
        $s['sso_tenant'] = $tenant;
        $s['sso_create'] = !empty($in['sso_create']) && $tenant !== '';
        $s['sso_role'] = in_array($in['sso_role'] ?? '', ECW_ROLES, true) && $in['sso_role'] !== 'admin' ? (string)$in['sso_role'] : 'user';
        if ($s['sso'] === 'required' && !ecw_security_admin_can_still_sign_in($orgId)) {
            return 'Before Microsoft sign-in is required, link your own Microsoft account under Account (admins keep their password as a way back in, but check that Microsoft sign-in works for you first).';
        }
    }
    foreach (['audit_days', 'trash_days', 'removed_days'] as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = max(0, (int)$in[$k]);
        if ($k === 'audit_days' && $v > 0) $v = max(90, $v);
        if ($k !== 'audit_days' && $v > 0) $v = max(7, $v);
        $s[$k] = $v;
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET security_json = ? WHERE id = ?')->execute([json_encode($s), $orgId]);
    $changed = [];
    foreach ($s as $k => $v) if (($old[$k] ?? null) !== $v) $changed[$k] = is_bool($v) ? ($v ? 'on' : 'off') : (string)$v;
    if ($changed) {
        require_once __DIR__ . '/audit.php';
        $retention = array_intersect_key($changed, ['audit_days' => 1, 'trash_days' => 1, 'removed_days' => 1]);
        if ($retention) ecw_audit('settings.retention', 'organization', $orgId, $retention, $orgId);
        if ($other = array_diff_key($changed, $retention)) ecw_audit('settings.security', 'organization', $orgId, $other, $orgId);
    }
    return null;
}

/** "Required" must not lock the admin out: the saving admin has a linked Microsoft account. */
function ecw_security_admin_can_still_sign_in(int $orgId): bool
{
    $me = (int)($_SESSION['ecw_user_id'] ?? 0);
    if ($me <= 0) return true;   // command line
    $st = ecw_db()->prepare('SELECT sso_subject FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$me]);
    return (string)$st->fetchColumn() !== '';
}

/**
 * Microsoft sign-in is required for this person: password sign-in is refused.
 * Admins keep their password as the way back in if Microsoft is unavailable.
 */
function ecw_security_password_blocked(int $userId): bool
{
    require_once __DIR__ . '/auth.php';
    $org = ecw_user_organization_id($userId);
    if ($org === null) return false;
    return ecw_security_settings($org)['sso'] === 'required' && ecw_user_role($userId, $org) !== 'admin';
}

// ------------------------------------------------------------------ legal hold

function ecw_case_held(int $caseId): bool
{
    try {
        $st = ecw_db()->prepare('SELECT legal_hold_at FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $st->execute([$caseId]);
        return (string)$st->fetchColumn() !== '';
    } catch (Throwable $e) { return false; }
}

/** Is the conversation in a held case? Then its emails may not be deleted for good. */
function ecw_thread_held(int $threadId): bool
{
    try {
        $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
                                  WHERE ct.email_thread_id = ? AND c.legal_hold_at IS NOT NULL LIMIT 1');
        $st->execute([$threadId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** Put a case on hold, or release it (admins). @return array{ok:bool, error?:string} */
function ecw_case_hold_set(int $orgId, int $caseId, bool $on, string $note, int $actorUserId): array
{
    require_once __DIR__ . '/auth.php';
    if (ecw_user_role($actorUserId, $orgId) !== 'admin') return ['ok' => false, 'error' => 'Only an admin can put a case on legal hold.'];
    $st = ecw_db()->prepare('SELECT id, case_number, legal_hold_at, deleted_at FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$caseId, $orgId]);
    $case = $st->fetch();
    if (!$case) return ['ok' => false, 'error' => 'Case not found.'];
    if ($on && $case['deleted_at'] !== null) return ['ok' => false, 'error' => 'Restore the case first.'];
    $note = mb_substr(trim($note), 0, 255);
    if ($on) {
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET legal_hold_at = COALESCE(legal_hold_at, UTC_TIMESTAMP()), legal_hold_by_user_id = ?, legal_hold_note = ? WHERE id = ?')
            ->execute([$actorUserId, $note !== '' ? $note : null, $caseId]);
    } else {
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET legal_hold_at = NULL, legal_hold_by_user_id = NULL, legal_hold_note = NULL WHERE id = ?')->execute([$caseId]);
    }
    require_once __DIR__ . '/audit.php';
    ecw_audit('case.hold', 'case', $caseId, ['case' => (string)$case['case_number'], 'hold' => $on ? 'on' : 'off', 'reason' => $note], $orgId, $actorUserId);
    return ['ok' => true];
}

/** Held cases of an organization, for the Security page. */
function ecw_held_cases(int $orgId): array
{
    try {
        $st = ecw_db()->prepare('SELECT c.id, c.case_number, c.name, c.legal_hold_at, c.legal_hold_note, u.name AS by_name
                                   FROM `' . ecw_table('cases') . '` c LEFT JOIN `' . ecw_table('users') . '` u ON u.id = c.legal_hold_by_user_id
                                  WHERE c.organization_id = ? AND c.legal_hold_at IS NOT NULL ORDER BY c.legal_hold_at DESC');
        $st->execute([$orgId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ------------------------------------------------------------------- retention

/**
 * Remove for good what the organization's retention says is too old.
 * $dryRun: only count. @return array{audit:int, cases:int, emails:int}
 */
function ecw_retention_run(int $orgId, bool $dryRun = false): array
{
    $s = ecw_security_settings($orgId);
    $pdo = ecw_db();
    $out = ['audit' => 0, 'cases' => 0, 'emails' => 0];

    if ($s['audit_days'] > 0) {
        if ($dryRun) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('audit_log') . '` WHERE organization_id = ? AND created_at < UTC_TIMESTAMP() - INTERVAL ' . max(90, $s['audit_days']) . ' DAY');
            $st->execute([$orgId]); $out['audit'] = (int)$st->fetchColumn();
        } else {
            require_once __DIR__ . '/audit.php';
            $out['audit'] = ecw_audit_prune($orgId, $s['audit_days']);
        }
    }

    if ($s['trash_days'] > 0) {
        $st = $pdo->prepare('SELECT id FROM `' . ecw_table('cases') . '` WHERE organization_id = ? AND deleted_at IS NOT NULL AND legal_hold_at IS NULL
                               AND deleted_at < UTC_TIMESTAMP() - INTERVAL ' . $s['trash_days'] . ' DAY LIMIT 500');
        $st->execute([$orgId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $caseId) {
            if ($dryRun || ecw_case_purge($orgId, (int)$caseId)) $out['cases']++;
        }
    }

    if ($s['removed_days'] > 0) {
        // Emails deleted from the mailbox long ago, except in held cases.
        $from = ' FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
                 WHERE mb.organization_id = ? AND m.provider_removed_at IS NOT NULL AND m.provider_removed_at < UTC_TIMESTAMP() - INTERVAL ' . $s['removed_days'] . ' DAY
                   AND NOT EXISTS (SELECT 1 FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
                                    WHERE ct.email_thread_id = m.email_thread_id AND c.legal_hold_at IS NOT NULL)';
        if ($dryRun) {
            $st = $pdo->prepare('SELECT COUNT(*)' . $from); $st->execute([$orgId]); $out['emails'] = (int)$st->fetchColumn();
        } else {
            $st = $pdo->prepare('SELECT m.id, m.email_thread_id' . $from . ' LIMIT 2000');
            $st->execute([$orgId]);
            $rows = $st->fetchAll();
            if ($rows) {
                $ids = array_map('intval', array_column($rows, 'id'));
                foreach (array_chunk($ids, 200) as $chunk) {
                    $in = implode(',', array_fill(0, count($chunk), '?'));
                    // attachments and participants go with them; drafts and notes keep, unlinked (foreign keys)
                    $del = $pdo->prepare('DELETE FROM `' . ecw_table('email_messages') . '` WHERE id IN (' . $in . ')');
                    $del->execute($chunk);
                    $out['emails'] += $del->rowCount();
                }
                require_once __DIR__ . '/threading.php';
                foreach (array_unique(array_map('intval', array_column($rows, 'email_thread_id'))) as $t) {
                    try { ecw_thread_refresh_counters($t); } catch (Throwable $e) {}
                }
            }
        }
    }

    if (!$dryRun && array_sum($out) > 0) {
        require_once __DIR__ . '/audit.php';
        ecw_audit('retention.purge', 'organization', $orgId, $out, $orgId, null, 'retention');
        ecw_log("retention for organization {$orgId}: " . json_encode($out));
    }
    return $out;
}

/** Delete a case for good (from the trash only, never one on hold). Child rows go with it (foreign keys). */
function ecw_case_purge(int $orgId, int $caseId): bool
{
    $pdo = ecw_db();
    $st = $pdo->prepare('SELECT case_number FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NOT NULL AND legal_hold_at IS NULL');
    $st->execute([$caseId, $orgId]);
    $number = $st->fetchColumn();
    if ($number === false) return false;
    foreach (['case_ai_summaries', 'ai_suggestions'] as $t) {
        try { $pdo->prepare('DELETE FROM `' . ecw_table($t) . '` WHERE case_id = ?')->execute([$caseId]); } catch (Throwable $e) {}
    }
    $pdo->prepare('DELETE FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ?')->execute([$caseId, $orgId]);
    return true;
}

/** Called by the worker: each organization's retention once a day. */
function ecw_retention_due(): void
{
    require_once __DIR__ . '/license.php';
    $today = gmdate('Y-m-d');
    if (ecw_app_setting('retention_ran') === $today) return;
    ecw_app_setting_set('retention_ran', $today);
    foreach (ecw_db()->query('SELECT id FROM `' . ecw_table('organizations') . '`')->fetchAll(PDO::FETCH_COLUMN) as $orgId) {
        try { ecw_retention_run((int)$orgId); }
        catch (Throwable $e) { ecw_log('retention failed for organization ' . $orgId . ': ' . $e->getMessage()); }
    }
}

// ------------------------------------------------------ database encryption at rest

/**
 * Is the database encrypted on disk (compose.encryption.yaml)?
 * @return array{keyring:bool, default_on:bool, tables:int, encrypted:int, redo:bool}
 */
function ecw_db_encryption_status(): array
{
    $out = ['keyring' => false, 'default_on' => false, 'tables' => 0, 'encrypted' => 0, 'redo' => false];
    try {
        $pdo = ecw_db();
        $vars = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('default_table_encryption', 'innodb_redo_log_encrypt')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out['default_on'] = strtoupper((string)($vars['default_table_encryption'] ?? '')) === 'ON';
        $out['redo'] = strtoupper((string)($vars['innodb_redo_log_encrypt'] ?? '')) === 'ON';
        $rows = $pdo->query("SELECT CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $out['tables'] = count($rows);
        foreach ($rows as $o) if (stripos((string)$o, "ENCRYPTION='Y'") !== false || stripos((string)$o, 'ENCRYPTION="Y"') !== false) $out['encrypted']++;
        // A keyring is loaded when the server will create encrypted tables at all.
        $out['keyring'] = $out['default_on'] || $out['encrypted'] > 0;
    } catch (Throwable $e) {}
    return $out;
}

/** Encrypt (or decrypt) every table of the database. Needs the keyring (compose.encryption.yaml). @return int tables changed */
function ecw_db_encrypt_tables(bool $on, ?callable $progress = null): int
{
    $pdo = ecw_db();
    $want = $on ? 'Y' : 'N';
    $n = 0;
    foreach ($pdo->query("SELECT TABLE_NAME, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll() as $t) {
        $is = stripos((string)$t['CREATE_OPTIONS'], "ENCRYPTION='Y'") !== false;
        if ($is === $on) continue;
        $pdo->exec('ALTER TABLE `' . str_replace('`', '``', (string)$t['TABLE_NAME']) . "` ENCRYPTION='" . $want . "'");
        $n++;
        $progress && $progress((string)$t['TABLE_NAME']);
    }
    return $n;
}

// -------------------------------------------------------------- backup password

/** The password backups are encrypted with, or null (stored encrypted with the app key). */
function ecw_backup_password(): ?string
{
    $env = (string)getenv('ECW_BACKUP_PASSWORD');
    if ($env !== '') return $env;
    require_once __DIR__ . '/license.php';
    require_once __DIR__ . '/crypto.php';
    $stored = ecw_app_setting('backup_password');
    if ($stored === null || $stored === '') return null;
    try { return ecw_decrypt($stored); } catch (Throwable $e) { return null; }
}

/** Set (or with '' remove) the backup password. @return string|null error */
function ecw_backup_password_set(string $password, int $orgId): ?string
{
    require_once __DIR__ . '/license.php';
    require_once __DIR__ . '/crypto.php';
    if ($password !== '' && mb_strlen($password) < 12) return 'Use at least 12 characters: a backup can be attacked offline for as long as someone likes.';
    ecw_app_setting_set('backup_password', $password === '' ? null : ecw_encrypt($password));
    require_once __DIR__ . '/audit.php';
    ecw_audit('settings.backup_key', 'organization', $orgId, ['password' => $password === '' ? 'removed' : 'set'], $orgId);
    return null;
}
