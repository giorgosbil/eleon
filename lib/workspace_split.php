<?php declare(strict_types=1);
/**
 * Split a company out of a workspace (bin/workspace_split.php): one of its accounts (a mailbox)
 * becomes a workspace of its own, with its emails, cases and everything tied to them, so the
 * two companies have separate people, case groups, templates, rules and settings
 * (lib/account.php: several workspaces, one sign-in).
 *
 * What moves with the account: the mailbox (its folders, conversations and emails come along,
 * they hang off it), the cases opened in it (their notes, history, checklists and people come
 * along, they hang off the case), its drafts, colour rules, own templates and signatures, and
 * the deal documents, follow-ups, notifications, suggestions and chat links of those cases and
 * conversations. Case groups its cases are in are copied into the new workspace. The new
 * workspace starts with the old one's settings (features, view, response times, AI, agents,
 * security); its people are the account's owner (with their role). Audit log stays where it was.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

/**
 * @param bool $apply false = only say what would move
 * @return array{workspace_id:?int, from:int, moved:array<string,int>, notes:list<string>}
 */
function ecw_workspace_split(int $mailboxId, string $newName, ?string $renameOld = null, bool $apply = false): array
{
    $pdo = ecw_db();
    $T = static fn(string $t): string => '`' . ecw_table($t) . '`';
    $one = static function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    $col = static function (string $sql, array $p = []) use ($pdo): array { $st = $pdo->prepare($sql); $st->execute($p); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); };

    $mb = $pdo->prepare("SELECT * FROM {$T('mailboxes')} WHERE id = ?");
    $mb->execute([$mailboxId]);
    $mailbox = $mb->fetch();
    if (!$mailbox) throw new InvalidArgumentException('No such account (mailbox).');
    $from = (int)$mailbox['organization_id'];
    $newName = trim($newName);
    if ($newName === '') throw new InvalidArgumentException('Give the new workspace a name.');
    $others = (int)$one("SELECT COUNT(*) FROM {$T('mailboxes')} WHERE organization_id = ? AND id <> ?", [$from, $mailboxId]);
    if ($others === 0) throw new InvalidArgumentException('That account is the only one in its workspace: nothing to split.');

    $cases = $col("SELECT id FROM {$T('cases')} WHERE mailbox_id = ?", [$mailboxId]);
    $threads = $col("SELECT id FROM {$T('email_threads')} WHERE mailbox_id = ?", [$mailboxId]);
    $notes = [];
    if ($cases) {
        $mixed = (int)$one("SELECT COUNT(DISTINCT ct.case_id) FROM {$T('case_threads')} ct JOIN {$T('email_threads')} t ON t.id = ct.email_thread_id
                             WHERE ct.case_id IN (" . implode(',', $cases) . ") AND t.mailbox_id <> ?", [$mailboxId]);
        if ($mixed) $notes[] = "{$mixed} of its cases also hold conversations of other accounts: those conversations stay where they are and leave the case.";
    }
    $in = static fn(array $ids): string => $ids ? implode(',', $ids) : '0';
    $owners = $col("SELECT DISTINCT ou.user_id FROM {$T('organization_users')} ou WHERE ou.organization_id = ? AND ou.user_id = ?", [$from, (int)$mailbox['connected_by_user_id']]);

    // What moves, table by table (the rows tied to the account, its cases or its conversations).
    $moves = [
        'cases'                 => "id IN ({$in($cases)})",
        'drafts'                => "mailbox_id = {$mailboxId}",
        'rules'                 => "mailbox_id = {$mailboxId}",
        'templates'             => "mailbox_id = {$mailboxId}",
        'signatures'            => "mailbox_id = {$mailboxId}",
        'case_resolutions'      => "case_id IN ({$in($cases)})",
        'case_similar'          => "case_id IN ({$in($cases)})",
        'deal_docs'             => "case_id IN ({$in($cases)}) OR thread_id IN ({$in($threads)})",
        'chat_links'            => "case_id IN ({$in($cases)})",
        'chat_link_suggestions' => "case_id IN ({$in($cases)})",
        'wa_chats'              => "case_id IN ({$in($cases)})",
        'followups'             => "case_id IN ({$in($cases)}) OR thread_id IN ({$in($threads)})",
        'notifications'         => "case_id IN ({$in($cases)}) OR thread_id IN ({$in($threads)})",
        'ai_suggestions'        => "case_id IN ({$in($cases)}) OR thread_id IN ({$in($threads)})",
    ];
    $moved = ['mailbox' => 1, 'conversations' => count($threads)];
    foreach ($moves as $table => $where) {
        try { $moved[$table] = (int)$one("SELECT COUNT(*) FROM {$T($table)} WHERE organization_id = ? AND ({$where})", [$from]); }
        catch (PDOException $e) { if (!str_contains($e->getMessage(), '1146') && !str_contains($e->getMessage(), '1054')) throw $e; }   // a module not installed
    }
    $groups = $cases ? $col("SELECT DISTINCT group_id FROM {$T('cases')} WHERE id IN ({$in($cases)}) AND group_id IS NOT NULL") : [];
    $moved['case groups copied'] = count($groups);
    $moved['people'] = count($owners);
    if (!$apply) return ['workspace_id' => null, 'from' => $from, 'moved' => $moved, 'notes' => $notes];

    $own = !$pdo->inTransaction();   // inside a caller's transaction (tests): join it
    if ($own) $pdo->beginTransaction();
    try {
        // The new workspace, with the old one's settings.
        $copy = ['timezone', 'features_json', 'default_view_profile', 'sla_json', 'ai_json', 'copilot_json', 'agents_json', 'security_json'];
        $have = $pdo->query("SHOW COLUMNS FROM {$T('organizations')}")->fetchAll(PDO::FETCH_COLUMN);
        $copy = array_values(array_intersect($copy, $have));
        $pdo->prepare("INSERT INTO {$T('organizations')} (name" . ($copy ? ', ' . implode(', ', $copy) : '') . ")
                       SELECT ?" . ($copy ? ', ' . implode(', ', $copy) : '') . " FROM {$T('organizations')} WHERE id = ?")->execute([$newName, $from]);
        $to = (int)$pdo->lastInsertId();
        if ($renameOld !== null && trim($renameOld) !== '') $pdo->prepare("UPDATE {$T('organizations')} SET name = ? WHERE id = ?")->execute([trim($renameOld), $from]);
        // Its people: the account's owner, with the role they had.
        $pdo->prepare("INSERT INTO {$T('organization_users')} (organization_id, user_id, role)
                       SELECT ?, user_id, role FROM {$T('organization_users')} WHERE organization_id = ? AND user_id IN ({$in($owners)})")->execute([$to, $from]);
        $pdo->prepare("UPDATE {$T('mailboxes')} SET organization_id = ? WHERE id = ?")->execute([$to, $mailboxId]);
        foreach ($moves as $table => $where) {
            try { $pdo->prepare("UPDATE {$T($table)} SET organization_id = ? WHERE organization_id = ? AND ({$where})")->execute([$to, $from]); }
            catch (PDOException $e) { if (!str_contains($e->getMessage(), '1146') && !str_contains($e->getMessage(), '1054')) throw $e; }
        }
        // Conversations of other accounts in its cases stay behind: out of those cases.
        if ($cases) $pdo->exec("DELETE ct FROM {$T('case_threads')} ct JOIN {$T('email_threads')} t ON t.id = ct.email_thread_id
                                 WHERE ct.case_id IN ({$in($cases)}) AND t.mailbox_id <> {$mailboxId}");
        // The case groups its cases are in: copied into the new workspace (with the members who are there).
        foreach ($groups as $g) {
            $pdo->prepare("INSERT INTO {$T('case_groups')} (organization_id, name, color, created_by_user_id, auto_assign, default_sharing, match_ai, match_words, match_mailbox_id, match_action)
                           SELECT ?, name, color, created_by_user_id, auto_assign, default_sharing, match_ai, match_words,
                                  IF(match_mailbox_id = {$mailboxId}, match_mailbox_id, NULL), IF(match_mailbox_id IS NULL OR match_mailbox_id = {$mailboxId}, match_action, 'off') FROM {$T('case_groups')} WHERE id = ?")->execute([$to, $g]);
            $ng = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO {$T('case_group_members')} (group_id, user_id)
                           SELECT ?, gm.user_id FROM {$T('case_group_members')} gm JOIN {$T('organization_users')} ou ON ou.user_id = gm.user_id AND ou.organization_id = ? WHERE gm.group_id = ?")->execute([$ng, $to, $g]);
            $pdo->prepare("UPDATE {$T('cases')} SET group_id = ? WHERE organization_id = ? AND group_id = ?")->execute([$ng, $to, $g]);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
    require_once __DIR__ . '/audit.php';
    ecw_audit('workspace.split', 'mailbox', $mailboxId, ['to' => $to, 'name' => $newName] + $moved, $from);
    ecw_audit('workspace.split', 'mailbox', $mailboxId, ['from' => $from] + $moved, $to);
    ecw_log("account {$mailboxId} split out of workspace {$from} into new workspace {$to} ({$newName})");
    return ['workspace_id' => $to, 'from' => $from, 'moved' => $moved, 'notes' => $notes];
}
