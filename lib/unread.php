<?php declare(strict_types=1);
/**
 * Unread state across the workspace: the badges on the top navigation, unread
 * cases, and marking a case's conversations read or unread.
 *
 * There is one source of truth, email_messages.is_read, per mailbox (shared
 * like Outlook's own read state). The inbox, a case and Outlook all show the
 * same value: reading a conversation anywhere clears it everywhere, and
 * ecw_thread_mark_read() queues the change for the provider.
 *
 * With private mailboxes a user only counts (and can change) mail in the
 * mailboxes they connected themselves.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/cases.php';

/** @return int[] mailbox ids whose read state this user sees and may change */
function ecw_unread_mailbox_ids(int $orgId, int $userId): array
{
    $sql = 'SELECT mb.id FROM `' . ecw_table('mailboxes') . "` mb WHERE mb.organization_id = ? AND mb.sync_status <> 'disconnected'";
    $params = [$orgId];
    if (ecw_private_mailboxes()) { require_once __DIR__ . '/shared_mailboxes.php'; $sql .= ' AND ' . ecw_mailbox_visible_sql('mb', $userId, $params); }
    $st = ecw_db()->prepare($sql);
    $st->execute($params);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** SQL fragment restricting a mailbox id column to the readable mailboxes (ids are ints). */
function ecw_unread_mailbox_sql(string $column, array $ids): string
{
    return $ids === [] ? '0 = 1' : $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/**
 * Badges for the top navigation.
 *
 * @return array{mail:int, cases:int, latest:?array}
 *   mail: unread emails in the Inbox; cases: open cases with unread mail;
 *   latest: the newest unread Inbox email (for a desktop notification).
 */
function ecw_unread_badges(int $orgId, int $userId): array
{
    $ids = ecw_unread_mailbox_ids($orgId, $userId);
    $msgT = ecw_table('email_messages');
    $inboxWhere = 'm.is_read = 0 AND m.direction = \'incoming\' AND m.provider_removed_at IS NULL
                   AND ' . ecw_unread_mailbox_sql('m.mailbox_id', $ids) . "
                   AND f.folder_type = 'inbox'";

    // Unread Inbox mail per account: the badges count the account chosen at the
    // top (lib/account.php); the account picker shows the others' counts.
    require_once __DIR__ . '/account.php';
    $active = ecw_active_account_id($orgId, $userId);
    $accounts = [];
    foreach (ecw_db()->query("SELECT m.mailbox_id, COUNT(*) n FROM `{$msgT}` m JOIN `" . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id WHERE {$inboxWhere} GROUP BY m.mailbox_id") as $r) {
        $accounts[(int)$r['mailbox_id']] = (int)$r['n'];
    }
    $mail = $active === null ? array_sum($accounts) : ($accounts[$active] ?? 0);

    $latest = null;
    if ($mail > 0) {
        $row = ecw_db()->query(
            "SELECT m.id, m.email_thread_id, m.mailbox_id, m.from_name, m.from_email, m.subject, m.occurred_at
               FROM `{$msgT}` m JOIN `" . ecw_table('mail_folders') . "` f ON f.id = m.mail_folder_id
              WHERE {$inboxWhere} ORDER BY m.occurred_at DESC, m.id DESC LIMIT 1"
        )->fetch();
        if ($row !== false) {
            $latest = [
                'id'         => (int)$row['id'],
                'thread_id'  => (int)$row['email_thread_id'],
                'mailbox_id' => (int)$row['mailbox_id'],
                'from'       => (string)($row['from_name'] ?: $row['from_email']),
                'subject'    => (string)($row['subject'] ?? ''),
                'at'         => $row['occurred_at'],
            ];
        }
    }

    $params = [$orgId];
    $visible = ecw_case_visible_sql('c', $userId, $params);
    $st = ecw_db()->prepare(
        'SELECT COUNT(DISTINCT c.id) FROM `' . ecw_table('cases') . '` c
           JOIN `' . ecw_table('case_threads') . '` ct ON ct.case_id = c.id
           JOIN `' . ecw_table('email_threads') . "` t ON t.id = ct.email_thread_id
          WHERE c.organization_id = ? AND c.deleted_at IS NULL AND c.status <> 'closed'
            AND t.unread_count > 0 AND " . ecw_unread_mailbox_sql('t.mailbox_id', $ids) . ' AND ' . $visible . ' AND ' . ecw_case_account_sql('c', $active, $params, $userId)
    );
    $st->execute($params);

    return ['mail' => $mail, 'cases' => (int)$st->fetchColumn(), 'latest' => $latest, 'accounts' => (object)$accounts, 'account_id' => $active];
}

/**
 * Mark a case's conversations read (all of them, or one) or one unread.
 * The caller has already checked the user can see the case.
 *
 * @return int[] thread ids that changed
 */
function ecw_case_mark_read(int $orgId, int $userId, int $caseId, ?int $threadId, bool $isRead): array
{
    $ids = ecw_unread_mailbox_ids($orgId, $userId);
    $params = [$caseId];
    $sql = 'SELECT t.id, t.mailbox_id FROM `' . ecw_table('case_threads') . '` ct
              JOIN `' . ecw_table('email_threads') . '` t ON t.id = ct.email_thread_id
             WHERE ct.case_id = ? AND ' . ecw_unread_mailbox_sql('t.mailbox_id', $ids);
    if ($threadId !== null) { $sql .= ' AND t.id = ?'; $params[] = $threadId; }
    $sql .= $isRead ? ' AND t.unread_count > 0' : ' ORDER BY t.latest_message_at DESC LIMIT 1';
    $st = ecw_db()->prepare($sql);
    $st->execute($params);

    $changed = [];
    foreach ($st->fetchAll() as $t) {
        if (ecw_thread_mark_read((int)$t['mailbox_id'], (int)$t['id'], $isRead) > 0) {
            $changed[] = (int)$t['id'];
        }
    }
    return $changed;
}
