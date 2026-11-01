<?php declare(strict_types=1);
/**
 * Get started: the first-day checklist (web/get-started.php).
 *
 * An admin sets the workspace up — company details, their mailbox, the team,
 * ready-made case templates, safe backups, the AI. A colleague gets their own
 * short list — mailbox, signature, safe sign-in. Every step is checked against
 * the real state (a mailbox is connected, a backup password is set…), so the
 * list is never out of date, and most steps can be done on the page itself.
 *
 * It opens by itself the first time someone has no mailbox connected, and
 * stays reachable from Settings until they hide it.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/features.php';

/**
 * @return array<int,array{key:string, title:string, why:string, done:bool, optional:bool, url:?string, action:?string}>
 */
function ecw_getting_started_steps(int $orgId, int $userId, bool $isAdmin): array
{
    $pdo = ecw_db();
    $q = static function (string $sql, array $p) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    $base = ecw_web_base();
    $steps = [];
    $add = static function (string $key, string $title, string $why, bool $done, ?string $url = null, ?string $action = null, bool $optional = false) use (&$steps): void {
        $steps[] = compact('key', 'title', 'why', 'done', 'url', 'action', 'optional');
    };

    if ($isAdmin) {
        $add('company', 'Your company', 'Its name and time zone: reply times, reminders and the morning briefing follow your working hours.',
            ecw_app_setting('gs_company_' . $orgId) !== null);
    }
    $hasMailbox = (int)$q('SELECT COUNT(*) FROM `' . ecw_table('mailboxes') . "` WHERE organization_id = ? AND connected_by_user_id = ? AND sync_status <> 'disconnected'", [$orgId, $userId]) > 0;
    $add('mailbox', 'Connect your mailbox', 'Your Outlook or Gmail comes in: search, cases and the assistant work on your real mail. Nothing changes in your mailbox until you act.',
        $hasMailbox, $base . 'settings/mailbox.php', 'Connect');
    if ($isAdmin) {
        $people = (int)$q('SELECT COUNT(*) FROM `' . ecw_table('organization_users') . '` ou JOIN `' . ecw_table('users') . '` u ON u.id = ou.user_id WHERE ou.organization_id = ? AND u.disabled_at IS NULL', [$orgId]);
        $invited = 0;
        try { $invited = (int)$q('SELECT COUNT(*) FROM `' . ecw_table('user_invitations') . '` WHERE organization_id = ? AND accepted_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$orgId]); } catch (Throwable $e) {}
        $add('team', 'Invite your team', 'Colleagues get their own sign-in and connect their own mailbox. Cases are shared; each person\'s mailbox stays theirs.',
            $people > 1 || $invited > 0, $base . 'settings/users.php');
        if (ecw_feature_allowed('case_templates')) {
            require_once __DIR__ . '/checklists.php';
            $add('templates', 'Ready-made cases', 'Templates for work that repeats — a complaint, a new customer — with their steps, people and dates.',
                count(ecw_case_templates($orgId, true)) > 0, $base . 'settings/case-templates.php', null, true);
        }
        require_once __DIR__ . '/security.php';
        require_once __DIR__ . '/offsite.php';
        $add('backups', 'Keep your data safe', 'A password on the backups, and a copy somewhere else than this computer (a NAS, OneDrive, cloud storage).',
            ecw_backup_password() !== null && ecw_offsite_settings()['type'] !== 'none', $base . 'settings/backups.php#offsite', 'Set up');
        if (ecw_feature_allowed('ai')) {
            require_once __DIR__ . '/ai.php';
            $add('ai', 'Switch on the AI', 'The assistant answers questions about your mail and cases, drafts replies, and the agents suggest what to do with new email.',
                ecw_ai_ready(ecw_ai_config($orgId)), $base . 'settings/ai.php', 'Set up', true);
        }
    }
    $sig = (int)$q('SELECT COUNT(*) FROM `' . ecw_table('signatures') . "` WHERE user_id = ? AND signature_html IS NOT NULL AND signature_html <> ''", [$userId]);
    $prefs = json_decode((string)$q('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?', [$userId]), true) ?: [];
    $add('signature', 'Your email signature', 'Added under every email you write here.', $sig > 0 || !empty($prefs['signature_profiles']), $base . 'settings/signatures.php', 'Add');
    require_once __DIR__ . '/account_security.php';
    require_once __DIR__ . '/sso.php';
    $add('signin', 'Safe sign-in', 'A code from your phone (two-factor), or your Microsoft account, so a stolen password is not enough.',
        ecw_totp_enabled($userId) || ecw_sso_linked($userId), $base . 'account.php#two-factor', 'Turn on');
    return $steps;
}

/** [done, total] counting only the steps that are not optional. */
function ecw_getting_started_progress(array $steps): array
{
    $needed = array_filter($steps, static fn($s) => !$s['optional']);
    return [count(array_filter($needed, static fn($s) => $s['done'])), count($needed)];
}

function ecw_getting_started_hidden(int $userId): bool
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    return !empty((json_decode((string)$st->fetchColumn(), true) ?: [])['getting_started_hidden']);
}

function ecw_getting_started_hide(int $userId, bool $hidden = true): void
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
    if ($hidden) $prefs['getting_started_hidden'] = true; else unset($prefs['getting_started_hidden']);
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $userId]);
}

/** Company step: name and time zone (the time zone reply targets, reminders and the briefing use). @return string|null error */
function ecw_getting_started_company(int $orgId, int $actorId, string $name, string $timezone): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) return 'Enter the company name (up to 120 characters).';
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) return 'Choose a time zone from the list.';
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET name = ?, timezone = ? WHERE id = ?')->execute([$name, $timezone, $orgId]);
    ecw_app_setting_set('gs_company_' . $orgId, gmdate('Y-m-d H:i:s'));
    require_once __DIR__ . '/audit.php';
    ecw_audit('settings.company', 'organization', $orgId, ['name' => $name, 'timezone' => $timezone], $orgId, $actorId);
    return null;
}

/**
 * Team step: invitation links for several addresses at once, emailed from the
 * admin's connected mailbox when there is one (otherwise shown to copy).
 * @return array{sent:string[], links:array<string,string>, errors:string[]}
 */
function ecw_getting_started_invite(int $orgId, int $actorId, string $addresses): array
{
    require_once __DIR__ . '/invitations.php';
    $out = ['sent' => [], 'links' => [], 'errors' => []];
    $list = array_values(array_unique(array_filter(array_map(static fn($a) => strtolower(trim($a)), preg_split('/[\s,;]+/', $addresses) ?: []))));
    if (!$list) { $out['errors'][] = 'Type one or more email addresses.'; return $out; }
    require_once __DIR__ . '/account_security.php';
    // Emailed links only with the configured ECW_BASE_URL (never the request's Host header); shown links may use this address.
    $mailBase = ecw_email_link_base();   // the address colleagues use (Settings → Users), never the webhooks' one
    $screenBase = ecw_link_base_url();
    $st = ecw_db()->prepare('SELECT u.name, o.name AS org FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organizations') . '` o ON o.id = ? WHERE u.id = ?');
    $st->execute([$orgId, $actorId]);
    $who = $st->fetch() ?: ['name' => 'A colleague', 'org' => 'your company'];
    require_once __DIR__ . '/account_security.php';
    $mailboxId = ecw_system_mailbox_id($orgId);
    foreach (array_slice($list, 0, 30) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $out['errors'][] = '"' . $email . '" is not an email address.'; continue; }
        try { $token = ecw_create_invitation($actorId, $orgId, $email); }
        catch (Throwable $e) { $out['errors'][] = $email . ': ' . $e->getMessage(); continue; }
        $path = ecw_web_base() . 'register.php?token=' . $token;
        $link = $screenBase . $path;
        require_once __DIR__ . '/audit.php';
        ecw_audit('user.invite', null, null, ['email' => $email, 'via' => 'get started'], $orgId, $actorId);
        $sent = false;
        if ($mailboxId !== null && $mailBase !== null) {
            $mailLink = $mailBase . $path;
            try {
                require_once __DIR__ . '/mailbox.php';
                $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                ecw_provider_for_mailbox($mailboxId)->sendMessage([
                    // No workspace or product name: the company's own (Settings → Branding), or none.
                    'subject' => $who['name'] . ' invites you to work together on email and cases',
                    'body_html' => '<p>Hello,</p><p>' . $h($who['name']) . ' invites you to work together on email and cases' . (($brand = ecw_email_brand($orgId)) !== '' ? ' at ' . $h($brand) : '') . '.</p>'
                        . '<p><a href="' . $h($mailLink) . '">Accept the invitation and choose your password</a></p><p style="color:#666">The link works once and for three days.</p>',
                    'to' => [['email' => $email, 'name' => null]], 'cc' => [], 'bcc' => [], 'attachments' => [],
                ]);
                $sent = true;
            } catch (Throwable $e) { ecw_log('invitation email not sent: ' . $e->getMessage()); }
        }
        if ($sent) $out['sent'][] = $email; else $out['links'][$email] = $link;
    }
    return $out;
}
