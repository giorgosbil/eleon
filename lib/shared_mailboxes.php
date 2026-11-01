<?php declare(strict_types=1);
/**
 * Shared mailboxes: a mailbox the team works from together (info@, sales@,
 * support@).
 *
 * Two ways to get one:
 *   - a Microsoft 365 shared mailbox, connected by an admin who has Full
 *     Access to it in Exchange (lib/oauth.php, shared_address). The app reads
 *     and sends through that person's sign-in, under /users/{address}.
 *   - any connected mailbox (an info@ Gmail account, a licensed info@ user)
 *     that its owner or an admin marks "shared with the team".
 *
 * Who sees it: the people listed in mailbox_members, or everyone in the
 * organization when nobody is listed. Who handles a conversation: the person
 * who claimed it (email_threads.claimed_by_user_id). Sending a reply from an
 * unclaimed conversation claims it for the sender.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

/**
 * SQL predicate: can $userId read mailbox $alias? Appends its params. Only
 * meaningful with private mailboxes; without them every mailbox is the team's.
 */
function ecw_mailbox_visible_sql(string $alias, int $userId, array &$params): string
{
    array_push($params, $userId, $userId);
    return "({$alias}.connected_by_user_id = ? OR ({$alias}.is_shared = 1 AND (NOT EXISTS (SELECT 1 FROM `" . ecw_table('mailbox_members') . "` vmm WHERE vmm.mailbox_id = {$alias}.id)"
        . " OR EXISTS (SELECT 1 FROM `" . ecw_table('mailbox_members') . "` vmm2 WHERE vmm2.mailbox_id = {$alias}.id AND vmm2.user_id = ?))))";
}

/** The same rule as SQL above, for a mailbox row already loaded. */
function ecw_mailbox_shared_with(array $mailbox, int $userId): bool
{
    if ((int)($mailbox['is_shared'] ?? 0) !== 1) return false;
    $members = ecw_mailbox_members((int)$mailbox['id']);
    return $members === [] || in_array($userId, $members, true);
}

/** @return int[] the people chosen for a shared mailbox ([] = everyone) */
function ecw_mailbox_members(int $mailboxId): array
{
    try {
        $st = ecw_db()->prepare('SELECT user_id FROM `' . ecw_table('mailbox_members') . '` WHERE mailbox_id = ? ORDER BY user_id');
        $st->execute([$mailboxId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }   // before migration 039
}

/** Everyone who reads a mailbox: its owner, plus its members (or the whole organization) when shared. @return int[] */
function ecw_mailbox_audience(int $mailboxId): array
{
    $st = ecw_db()->prepare('SELECT organization_id, connected_by_user_id, is_shared FROM `' . ecw_table('mailboxes') . '` WHERE id = ?');
    $st->execute([$mailboxId]);
    $mb = $st->fetch();
    if (!$mb) return [];
    $ids = $mb['connected_by_user_id'] !== null ? [(int)$mb['connected_by_user_id']] : [];
    if ((int)$mb['is_shared'] === 1 || !ecw_private_mailboxes()) {
        $members = ecw_mailbox_members($mailboxId);
        if ($members === []) {
            $q = ecw_db()->prepare('SELECT ou.user_id FROM `' . ecw_table('organization_users') . '` ou JOIN `' . ecw_table('users') . '` u ON u.id = ou.user_id WHERE ou.organization_id = ? AND u.disabled_at IS NULL');
            $q->execute([(int)$mb['organization_id']]);
            $members = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids = array_merge($ids, $members);
    }
    return array_values(array_unique($ids));
}

/** Only the person who connected it, or an admin, decides who shares a mailbox. */
function ecw_mailbox_can_manage(int $orgId, int $userId, array $mailbox): bool
{
    require_once __DIR__ . '/auth.php';
    return (int)$mailbox['organization_id'] === $orgId
        && ((int)$mailbox['connected_by_user_id'] === $userId || ecw_user_role($userId, $orgId) === 'admin');
}

/**
 * Share a mailbox with the team (or stop), and choose who.
 * $memberIds: [] = everyone in the organization. @return string|null error
 */
function ecw_mailbox_share_save(int $orgId, int $actorId, int $mailboxId, bool $shared, array $memberIds): ?string
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mailboxes') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$mailboxId, $orgId]);
    $mb = $st->fetch();
    if (!$mb) return 'Mailbox not found.';
    if (!ecw_mailbox_can_manage($orgId, $actorId, $mb)) return 'Only the person who connected this mailbox, or an admin, can share it.';
    if (!$shared && (string)$mb['shared_address'] !== '') return 'A Microsoft 365 shared mailbox is always shared. Disconnect it to stop.';
    // Only people of this organization.
    $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
    if ($memberIds) {
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $q = ecw_db()->prepare('SELECT user_id FROM `' . ecw_table('organization_users') . "` WHERE organization_id = ? AND user_id IN ({$in})");
        $q->execute(array_merge([$orgId], $memberIds));
        $memberIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        sort($memberIds);
    }
    $pdo = ecw_db();
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET is_shared = ? WHERE id = ?')->execute([$shared ? 1 : 0, $mailboxId]);
        $pdo->prepare('DELETE FROM `' . ecw_table('mailbox_members') . '` WHERE mailbox_id = ?')->execute([$mailboxId]);
        if ($shared) {
            $ins = $pdo->prepare('INSERT INTO `' . ecw_table('mailbox_members') . '` (mailbox_id, user_id) VALUES (?, ?)');
            foreach ($memberIds as $uid) $ins->execute([$mailboxId, $uid]);
        }
        if (!$shared) $pdo->prepare('UPDATE `' . ecw_table('email_threads') . '` SET claimed_by_user_id = NULL, claimed_at = NULL WHERE mailbox_id = ?')->execute([$mailboxId]);
        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns) $pdo->rollBack();
        throw $e;
    }
    require_once __DIR__ . '/audit.php';
    ecw_audit('mailbox.share', 'mailbox', $mailboxId, ['address' => (string)$mb['email_address'], 'shared' => $shared ? 'on' : 'off', 'people' => $shared ? ($memberIds ? count($memberIds) : 'everyone') : 0], $orgId, $actorId);
    return null;
}

// ------------------------------------------------------------------ claiming

/**
 * Claim a conversation for someone ($forUserId), or release it (null).
 * Anyone who reads the mailbox may claim for themselves, hand it to a
 * colleague who also reads it, or release it.
 * @return array{claimed:?array}
 */
function ecw_thread_claim(int $orgId, int $actorId, int $mailboxId, int $threadId, ?int $forUserId): array
{
    $st = ecw_db()->prepare('SELECT t.id, t.normalized_subject, t.claimed_by_user_id, mb.is_shared, mb.email_address FROM `' . ecw_table('email_threads') . '` t
                               JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id WHERE t.id = ? AND t.mailbox_id = ? AND mb.organization_id = ?');
    $st->execute([$threadId, $mailboxId, $orgId]);
    $t = $st->fetch();
    if (!$t) throw new InvalidArgumentException('Conversation not found.');
    if ((int)$t['is_shared'] !== 1) throw new InvalidArgumentException('Only conversations in a shared mailbox are claimed.');
    if ($forUserId !== null && !in_array($forUserId, ecw_mailbox_audience($mailboxId), true)) throw new InvalidArgumentException('That person does not see this mailbox.');
    ecw_db()->prepare('UPDATE `' . ecw_table('email_threads') . '` SET claimed_by_user_id = ?, claimed_at = ' . ($forUserId === null ? 'NULL' : 'UTC_TIMESTAMP()') . ' WHERE id = ?')
        ->execute([$forUserId, $threadId]);
    if ($forUserId !== null && $forUserId !== $actorId && (int)$t['claimed_by_user_id'] !== $forUserId) {
        require_once __DIR__ . '/notifications.php';
        $who = ecw_db()->prepare('SELECT name FROM `' . ecw_table('users') . '` WHERE id = ?');
        $who->execute([$actorId]);
        ecw_notify($orgId, $forUserId, $actorId, 'assigned', ((string)$who->fetchColumn() ?: 'Someone') . ' gave you "' . mb_substr((string)$t['normalized_subject'], 0, 80) . '" (' . $t['email_address'] . ')',
            'app.php?mailbox=' . $mailboxId . '&thread=' . $threadId, null, $threadId);
    }
    return ['claimed' => ecw_thread_claim_info($threadId)];
}

/** Who handles a conversation: {id, name} or null. */
function ecw_thread_claim_info(int $threadId): ?array
{
    $st = ecw_db()->prepare('SELECT u.id, u.name, u.email, t.claimed_at FROM `' . ecw_table('email_threads') . '` t JOIN `' . ecw_table('users') . '` u ON u.id = t.claimed_by_user_id WHERE t.id = ?');
    $st->execute([$threadId]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => (string)($r['name'] ?: $r['email']), 'at' => $r['claimed_at']] : null;
}

/** After someone replies from a shared mailbox: an unclaimed conversation becomes theirs. */
function ecw_thread_claim_on_reply(int $threadId, int $userId): void
{
    try {
        ecw_db()->prepare('UPDATE `' . ecw_table('email_threads') . '` t JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id
                              SET t.claimed_by_user_id = ?, t.claimed_at = UTC_TIMESTAMP()
                            WHERE t.id = ? AND mb.is_shared = 1 AND t.claimed_by_user_id IS NULL')->execute([$userId, $threadId]);
    } catch (Throwable $e) {}
}

/** The people a conversation can be handed to (everyone who reads the mailbox). */
function ecw_mailbox_people(int $mailboxId): array
{
    $ids = ecw_mailbox_audience($mailboxId);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = ecw_db()->prepare('SELECT id, name, email FROM `' . ecw_table('users') . "` WHERE id IN ({$in}) AND disabled_at IS NULL ORDER BY name");
    $st->execute($ids);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)($r['name'] ?: $r['email'])], $st->fetchAll());
}
