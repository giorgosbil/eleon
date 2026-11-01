<?php declare(strict_types=1);
/**
 * Audit log: a permanent record of who did what, for compliance and for
 * "who deleted this case?". Written from the places that matter (sign-in,
 * users and rights, cases, exports and downloads, settings, AI access) and
 * shown to admins in Settings → Audit log.
 *
 * Rules:
 *   - never email content, passwords, tokens or keys; only ids and short facts
 *   - best effort: a failing audit write never breaks the action itself,
 *     but it is reported to the error log
 *   - repeated "views" are recorded at most once an hour per person and target
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';   // ecw_client_ip()

/** Labels for the admin page; unknown actions are shown as their code. */
const ECW_AUDIT_ACTIONS = [
    'login.ok' => 'Signed in', 'login.fail' => 'Failed sign-in', 'logout' => 'Signed out', 'login.2fa_fail' => 'Wrong two-factor code',
    '2fa.on' => 'Turned on two-factor', '2fa.off' => 'Turned off two-factor', 'password.change' => 'Changed password', 'password.reset' => 'Reset password by link',
    'user.create' => 'Created a user', 'user.invite' => 'Made an invitation link', 'user.role' => 'Changed a role', 'user.disable' => 'Switched a user off',
    'user.enable' => 'Switched a user on', 'user.reset_link' => 'Made a password reset link', 'user.2fa_reset' => 'Turned off someone\'s two-factor',
    'features.save' => 'Changed Feature access', 'license.install' => 'Installed a license', 'settings.ai' => 'Changed the AI provider',
    'settings.copilot' => 'Changed the Copilot connection', 'settings.sla' => 'Changed response times', 'settings.retention' => 'Changed data retention',
    'token.create' => 'Created an AI access token', 'token.revoke' => 'Removed an AI access token', 'mcp.call' => 'AI app read data',
    'mailbox.connect' => 'Connected a mailbox', 'mailbox.disconnect' => 'Disconnected a mailbox',
    'case.view' => 'Opened a case', 'case.create' => 'Created a case', 'case.delete' => 'Deleted a case', 'case.restore' => 'Restored a case',
    'case.merge' => 'Merged cases', 'case.sharing' => 'Changed who sees a case', 'case.export' => 'Printed / exported a case', 'case.hold' => 'Changed a legal hold',
    'email.send' => 'Sent an email', 'email.delete_forever' => 'Deleted email forever', 'attachment.download' => 'Downloaded a file',
    'attachments.zip' => 'Downloaded all files of an email', 'ai.ask' => 'Asked the AI assistant', 'rule.save' => 'Saved a rule', 'rule.delete' => 'Deleted a rule',
    'settings.agents' => 'Changed the AI agents', 'ai.suggestion' => 'Accepted an AI suggestion',
    'backup.download' => 'Downloaded a backup', 'retention.purge' => 'Removed old data (retention)', 'audit.export' => 'Exported the audit log',
    'settings.security' => 'Changed security settings', 'sso.link' => 'Linked a Microsoft account', 'sso.unlink' => 'Unlinked a Microsoft account',
    'mailbox.share' => 'Shared a mailbox with the team', 'user.away' => 'Set holiday cover', 'user.handover' => 'Handed over someone\'s work',
    'update.check' => 'Checked for updates', 'backup.offsite' => 'Changed off-site backups', 'settings.alerts' => 'Changed system alerts',
    'sso.fail' => 'Microsoft sign-in refused', 'settings.company' => 'Changed the company name or time zone', 'user.sso_create' => 'Created a user at Microsoft sign-in', 'settings.backup_key' => 'Changed the backup password',
];

/**
 * Record one action. $orgId / $userId default to the signed-in person.
 * $detail: a few short scalar facts (names, counts), never content.
 */
function ecw_audit(string $action, ?string $targetType = null, ?int $targetId = null, array $detail = [], ?int $orgId = null, ?int $userId = null, string $via = ''): void
{
    try {
        if ($orgId === null || $userId === null) {
            if (session_status() !== PHP_SESSION_NONE || isset($_SESSION)) {
                $orgId ??= isset($_SESSION['ecw_organization_id']) ? (int)$_SESSION['ecw_organization_id'] : null;
                $userId ??= isset($_SESSION['ecw_user_id']) ? (int)$_SESSION['ecw_user_id'] : null;
            }
        }
        $cli = PHP_SAPI === 'cli';
        if ($via === '') $via = $cli ? 'cli' : (str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') ? 'api' : 'web');
        // Keep details small and plain.
        $clean = [];
        foreach (array_slice($detail, 0, 12, true) as $k => $v) {
            if (is_bool($v) || is_int($v) || is_float($v) || $v === null) $clean[(string)$k] = $v;
            elseif (is_scalar($v)) $clean[(string)$k] = mb_substr((string)$v, 0, 120);
            elseif (is_array($v)) $clean[(string)$k] = array_slice(array_map(static fn($x) => is_scalar($x) ? mb_substr((string)$x, 0, 60) : '', $v), 0, 10);
        }
        $json = $clean ? (string)json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        if ($json !== null && strlen($json) > 1000) $json = mb_strcut($json, 0, 990, 'UTF-8') . '…';
        $ip = null;
        if (!$cli) {
            if (function_exists('ecw_client_ip')) $ip = ecw_client_ip();
            else $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        }
        ecw_db()->prepare('INSERT INTO `' . ecw_table('audit_log') . '` (organization_id, user_id, action, target_type, target_id, detail, via, ip, user_agent, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$orgId ?: null, $userId ?: null, mb_substr($action, 0, 60), $targetType !== null ? mb_substr($targetType, 0, 30) : null, $targetId,
                $json, mb_substr($via, 0, 20), $ip !== null ? mb_substr($ip, 0, 45) : null,
                $cli ? null : mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    } catch (Throwable $e) {
        error_log('[ecw] audit write failed (' . $action . '): ' . $e->getMessage());
    }
}

/** A "view" (opening a case…): at most once an hour per person and target. */
function ecw_audit_view(string $action, string $targetType, int $targetId, array $detail = []): void
{
    try {
        $userId = isset($_SESSION['ecw_user_id']) ? (int)$_SESSION['ecw_user_id'] : 0;
        if ($userId <= 0) return;
        $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('audit_log') . '` WHERE user_id = ? AND action = ? AND target_type = ? AND target_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR LIMIT 1');
        $st->execute([$userId, $action, $targetType, $targetId]);
        if ($st->fetchColumn()) return;
    } catch (Throwable $e) { return; }
    ecw_audit($action, $targetType, $targetId, $detail);
}

/**
 * Entries for the admin page, newest first.
 * $f: from, to (Y-m-d), user (id), action (prefix, e.g. "case."), q (words in details / target)
 */
function ecw_audit_list(int $orgId, array $f, int $limit = 200, int $offset = 0): array
{
    [$where, $params] = ecw_audit_where($orgId, $f);
    $st = ecw_db()->prepare('SELECT a.*, u.name AS user_name, u.email AS user_email FROM `' . ecw_table('audit_log') . '` a
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = a.user_id
                              WHERE ' . $where . ' ORDER BY a.id DESC LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($params);
    return $st->fetchAll();
}

function ecw_audit_count(int $orgId, array $f): int
{
    [$where, $params] = ecw_audit_where($orgId, $f);
    $st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('audit_log') . '` a WHERE ' . $where);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function ecw_audit_where(int $orgId, array $f): array
{
    $w = ['a.organization_id = ?']; $p = [$orgId];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? ''))) { $w[] = 'a.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? ''))) { $w[] = 'a.created_at < ? + INTERVAL 1 DAY'; $p[] = $f['to'] . ' 00:00:00'; }
    if ((int)($f['user'] ?? 0) > 0) { $w[] = 'a.user_id = ?'; $p[] = (int)$f['user']; }
    $action = (string)($f['action'] ?? '');
    if ($action !== '' && preg_match('/^[a-z0-9_.]{1,60}$/', $action)) { $w[] = 'a.action LIKE ?'; $p[] = str_replace('_', '\\_', $action) . '%'; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $w[] = '(a.detail LIKE ? OR a.ip LIKE ? OR CAST(a.target_id AS CHAR) = ?)'; array_push($p, $like, $like, $q);
    }
    if (!empty($f['target_type']) && preg_match('/^[a-z_]{1,30}$/', (string)$f['target_type'])) { $w[] = 'a.target_type = ?'; $p[] = $f['target_type']; }
    if ((int)($f['target_id'] ?? 0) > 0) { $w[] = 'a.target_id = ?'; $p[] = (int)$f['target_id']; }
    return [implode(' AND ', $w), $p];
}

function ecw_audit_label(string $action): string
{
    return ECW_AUDIT_ACTIONS[$action] ?? $action;
}

/** Remove entries older than $days (retention); keeps at least 90 days. */
function ecw_audit_prune(int $orgId, int $days): int
{
    $days = max(90, $days);
    $st = ecw_db()->prepare('DELETE FROM `' . ecw_table('audit_log') . '` WHERE organization_id = ? AND created_at < UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY');
    $st->execute([$orgId]);
    return $st->rowCount();
}
