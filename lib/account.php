<?php declare(strict_types=1);
/**
 * One account at a time.
 *
 * A person with several mailboxes (their own Outlook, a second company's
 * Outlook, a shared info@) picks one at the top of every page. Mail, Cases,
 * Contacts, Calendar, Teams, Reports, the tabs of modules and the personal settings
 * (signatures, colour rules, own templates, mailbox settings) then show that
 * account only, so two companies never mix on one screen.
 *
 * The choice is kept per person (users.preferences_json "account_id") and
 * survives logging out. It falls back to the person's first own mailbox, then
 * the first mailbox they can see.
 *
 * What belongs to an account:
 *   mail, folders, drafts          the mailbox itself
 *   cases                          cases.mailbox_id (where the case was opened)
 *                                  plus any case with a conversation in it
 *   contacts                       people in that mailbox's emails
 *   calendar, Teams                the account's own Microsoft sign-in
 *   module tabs                    whatever the module decides (its module.php, nav → show)
 *   reports                        its cases
 *   signatures, colour rules, own templates   per account (NULL = every account)
 * Language, appearance and notifications stay per person.
 *
 * Workspaces: each company is its own workspace (organization) with its own people, case
 * groups, templates, rules and settings. A person may be in several, with one sign-in: the
 * picker lists their accounts of every workspace they are in, and picking one of another
 * workspace switches the whole app there (ecw_account_switch).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

/**
 * @return array<int,array> the mailboxes this person may switch to: this workspace's first (their
 *         own first), then those of the other workspaces they are in (organization_id, workspace).
 */
function ecw_account_choices(int $orgId, int $userId): array
{
    $key = $orgId . ':' . $userId;
    if (isset($GLOBALS['ecw_account_choices'][$key])) return $GLOBALS['ecw_account_choices'][$key];
    require_once __DIR__ . '/shared_mailboxes.php';
    $params = [$userId, $userId];
    $st = ecw_db()->prepare(
        'SELECT mb.*, (mb.connected_by_user_id = ?) AS mine, o.name AS workspace
           FROM `' . ecw_table('mailboxes') . '` mb
           JOIN `' . ecw_table('organization_users') . '` ou ON ou.organization_id = mb.organization_id AND ou.user_id = ?
           JOIN `' . ecw_table('organizations') . '` o ON o.id = mb.organization_id
          WHERE 1 = 1' . (ecw_private_mailboxes() ? ' AND ' . ecw_mailbox_visible_sql('mb', $userId, $params) : '') . '
          ORDER BY mine DESC, mb.id'
    );
    $st->execute($params);
    $rows = array_map(static fn(array $m): array => [
        'id' => (int)$m['id'], 'email_address' => (string)($m['email_address'] ?? ''), 'display_name' => $m['display_name'] ?? null,
        'provider' => (string)($m['provider'] ?? ''), 'sync_status' => (string)($m['sync_status'] ?? ''),
        'connected_by_user_id' => isset($m['connected_by_user_id']) ? (int)$m['connected_by_user_id'] : null,
        'mine' => (bool)(int)$m['mine'], 'is_shared' => (bool)(int)($m['is_shared'] ?? 0),
        'organization_id' => (int)$m['organization_id'], 'workspace' => (string)($m['workspace'] ?? ''),
    ], $st->fetchAll());
    // This workspace first; in each, own accounts first, then shared ones (info@), in the order they were connected.
    usort($rows, static fn(array $a, array $b): int => [$b['organization_id'] === $orgId, $a['organization_id'], $b['mine'], $a['is_shared'], $a['id']]
        <=> [$a['organization_id'] === $orgId, $b['organization_id'], $a['mine'], $b['is_shared'], $b['id']]);
    return $GLOBALS['ecw_account_choices'][$key] = $rows;
}

/** Forget cached choices (after connecting, removing or switching). */
function ecw_account_reset_cache(): void
{
    $GLOBALS['ecw_account_active'] = [];
    $GLOBALS['ecw_account_choices'] = [];
}

/** The account this person works in now, or null when they have no mailbox at all. */
function ecw_active_account(int $orgId, int $userId): ?array
{
    $key = $orgId . ':' . $userId;
    if (array_key_exists($key, $GLOBALS['ecw_account_active'] ?? [])) return $GLOBALS['ecw_account_active'][$key];
    // The account worked in is one of this workspace's (the others' switch the workspace).
    $choices = array_values(array_filter(ecw_account_choices($orgId, $userId), static fn($m) => $m['organization_id'] === $orgId));
    $wanted = (int)(ecw_account_prefs($userId)['account_id'] ?? 0);
    $active = null;
    foreach ($choices as $m) {
        if ($m['id'] === $wanted) { $active = $m; break; }
    }
    $active ??= $choices[0] ?? null;
    return $GLOBALS['ecw_account_active'][$key] = $active;
}

/** The active account's id, or null. */
function ecw_active_account_id(int $orgId, int $userId): ?int
{
    $a = ecw_active_account($orgId, $userId);
    return $a === null ? null : (int)$a['id'];
}

/**
 * Switch to another account. An account of another workspace switches the workspace too (the
 * session's organization). False when the person may not see that mailbox.
 */
function ecw_account_switch(int $orgId, int $userId, int $mailboxId): bool
{
    $target = null;
    foreach (ecw_account_choices($orgId, $userId) as $m) {
        if ($m['id'] === $mailboxId) { $target = $m; break; }
    }
    if ($target === null) return false;
    if ($target['organization_id'] !== $orgId) {
        require_once __DIR__ . '/auth.php';
        if (ecw_user_role($userId, $target['organization_id']) === null) return false;
        ecw_session_start();
        $_SESSION['ecw_organization_id'] = $target['organization_id'];
        ecw_account_reset_cache();
    }
    $prefs = ecw_account_prefs($userId);
    if ((int)($prefs['account_id'] ?? 0) !== $mailboxId) {
        $prefs['account_id'] = $mailboxId;
        ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $userId]);
    }
    unset($GLOBALS['ecw_account_active'][$orgId . ':' . $userId]);
    return true;
}

function ecw_account_prefs(int $userId): array
{
    try {
        $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
        $st->execute([$userId]);
        $p = json_decode((string)$st->fetchColumn(), true);
    } catch (Throwable $e) { return []; }   // a stripped-down test database
    return is_array($p) ? $p : [];
}

/**
 * SQL: the case aliased $alias belongs to account $mailboxId (opened there, or
 * with a conversation there). Cases of no account (made before accounts, by
 * someone without a mailbox) show in every account, and so do the cases a
 * colleague opened in their own mailbox: only $userId's own accounts are kept
 * apart, never what colleagues share with them. Appends its params.
 */
function ecw_case_account_sql(string $alias, ?int $mailboxId, array &$params, ?int $userId = null): string
{
    if ($mailboxId === null) return '1 = 1';
    $others = '';
    if ($userId !== null) {
        $st = ecw_db()->prepare('SELECT organization_id FROM `' . ecw_table('mailboxes') . '` WHERE id = ?');
        $st->execute([$mailboxId]);
        $orgId = (int)$st->fetchColumn();
        $mine = [];
        foreach (ecw_account_choices($orgId, $userId) as $m) {
            if ($m['organization_id'] === $orgId) $mine[] = $m['id'];
        }
        if ($mine !== []) $others = " OR {$alias}.mailbox_id NOT IN (" . implode(',', $mine) . ')';
    }
    array_push($params, $mailboxId, $mailboxId);
    return "({$alias}.mailbox_id = ? OR {$alias}.mailbox_id IS NULL{$others} OR EXISTS (SELECT 1 FROM `" . ecw_table('case_threads') . '` act JOIN `'
        . ecw_table('email_threads') . "` aet ON aet.id = act.email_thread_id WHERE act.case_id = {$alias}.id AND aet.mailbox_id = ?))";
}

/** The account a case belongs to, or null. */
function ecw_case_account(int $caseId): ?int
{
    $st = ecw_db()->prepare('SELECT mailbox_id FROM `' . ecw_table('cases') . '` WHERE id = ?');
    $st->execute([$caseId]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int)$v;
}

/**
 * Opening something of another account (a link to a case, an email, a
 * notification) switches to that account, so the page around it matches.
 * Only to an account the person may see; anything else keeps the choice.
 */
function ecw_account_follow(int $orgId, int $userId, ?int $mailboxId): void
{
    if ($mailboxId === null || $mailboxId === ecw_active_account_id($orgId, $userId)) return;
    ecw_account_switch($orgId, $userId, $mailboxId);
}

/** The account's own Microsoft sign-in, for Calendar and Teams. Null for a shared or Google mailbox. */
function ecw_active_microsoft_mailbox(int $orgId, int $userId): ?array
{
    $id = ecw_active_account_id($orgId, $userId);
    if ($id === null) return null;
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mailboxes') . "` WHERE id = ? AND organization_id = ? AND connected_by_user_id = ? AND provider = 'microsoft' AND sync_status <> 'disconnected'");
    $st->execute([$id, $orgId, $userId]);
    return $st->fetch() ?: null;
}
