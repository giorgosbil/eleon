<?php declare(strict_types=1);
/**
 * Email Case Workspace — follow-ups: reminders and snooze.
 *
 * A reminder belongs to one person and points at a case or an email thread.
 * When it falls due the person gets a notification; with "only if no reply"
 * it is cancelled silently once the other side answers (lib/automation.php).
 *
 * Snooze hides a thread from the mail list until a time. A new incoming
 * message wakes it early. Both are driven by bin/sync_daemon.php every minute
 * via ecw_followups_run_due().
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

/** A browser-supplied ISO time -> UTC 'Y-m-d H:i:s', in the future, within a year. */
function ecw_followup_parse_due(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false || $ts < time() - 60 || $ts > time() + 366 * 86400) {
        throw new InvalidArgumentException('Choose a time in the future (within a year).');
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function ecw_followup_add(int $orgId, int $userId, ?int $caseId, ?int $threadId, string $dueIso, ?string $note, bool $ifNoReply): int
{
    if ($caseId === null && $threadId === null) {
        throw new InvalidArgumentException('A reminder needs a case or an email.');
    }
    $note = $note === null ? null : mb_strimwidth(trim($note), 0, 255, '');
    ecw_db()->prepare(
        'INSERT INTO `' . ecw_table('followups') . '`
            (organization_id, user_id, case_id, thread_id, due_at, note, if_no_reply, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$orgId, $userId, $caseId, $threadId, ecw_followup_parse_due($dueIso), $note ?: null, $ifNoReply ? 1 : 0, ecw_now()]);
    return (int)ecw_db()->lastInsertId();
}

/** Open reminders on a case (everyone's: the case team should see who follows up when). */
function ecw_followups_for_case(int $caseId): array
{
    $st = ecw_db()->prepare(
        'SELECT f.id, f.due_at, f.note, f.if_no_reply, f.user_id, u.name, u.email
           FROM `' . ecw_table('followups') . '` f JOIN `' . ecw_table('users') . '` u ON u.id = f.user_id
          WHERE f.case_id = ? AND f.done_at IS NULL ORDER BY f.due_at'
    );
    $st->execute([$caseId]);
    return array_map('ecw_followup_row', $st->fetchAll());
}

/** The user's own open reminders on a thread. */
function ecw_followups_for_thread(int $threadId, int $userId): array
{
    $st = ecw_db()->prepare(
        'SELECT f.id, f.due_at, f.note, f.if_no_reply, f.user_id, u.name, u.email
           FROM `' . ecw_table('followups') . '` f JOIN `' . ecw_table('users') . '` u ON u.id = f.user_id
          WHERE f.thread_id = ? AND f.user_id = ? AND f.done_at IS NULL ORDER BY f.due_at'
    );
    $st->execute([$threadId, $userId]);
    return array_map('ecw_followup_row', $st->fetchAll());
}

function ecw_followup_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'due_at' => $r['due_at'], 'note' => $r['note'],
        'if_no_reply' => (bool)(int)$r['if_no_reply'],
        'user' => ['id' => (int)$r['user_id'], 'name' => $r['name'], 'email' => $r['email']],
    ];
}

/** Only the owner cancels their reminder. */
function ecw_followup_cancel(int $id, int $userId): bool
{
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('followups') . "` SET done_at = ?, outcome = 'cancelled' WHERE id = ? AND user_id = ? AND done_at IS NULL");
    $st->execute([ecw_now(), $id, $userId]);
    return $st->rowCount() > 0;
}

/**
 * Snooze ($until UTC) or wake (null) a thread of this mailbox.
 *
 * In the mailbox too (unless switched off for it): the conversation moves to a
 * "Snoozed" folder (Gmail: label) so Outlook and the phone are tidy as well,
 * and moves back where it was when it wakes. If the mailbox refuses, the
 * snooze still works here.
 */
function ecw_thread_snooze(int $mailboxId, int $threadId, ?string $until): bool
{
    $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
    $chk->execute([$threadId, $mailboxId]);
    if (!$chk->fetchColumn()) return false;
    ecw_db()->prepare('UPDATE `' . ecw_table('email_threads') . '` SET snoozed_until = ? WHERE id = ? AND mailbox_id = ?')
        ->execute([$until, $threadId, $mailboxId]);
    if ($until === null) {
        ecw_thread_snooze_restore($mailboxId, $threadId);
    } else {
        ecw_thread_snooze_move($mailboxId, $threadId);
    }
    return true;
}

/** Move a snoozed conversation to the mailbox's Snoozed folder (best effort). */
function ecw_thread_snooze_move(int $mailboxId, int $threadId): void
{
    try {
        $mb = ecw_db()->prepare('SELECT snooze_in_mailbox FROM `' . ecw_table('mailboxes') . '` WHERE id = ?');
        $mb->execute([$mailboxId]);
        if ((int)$mb->fetchColumn() !== 1) return;
        $folder = ecw_snooze_folder($mailboxId);
        require_once __DIR__ . '/mail_actions.php';
        $r = ecw_thread_move($mailboxId, $threadId, (string)(int)$folder['id']);
        if ($r['moved'] === []) return;
        $st = ecw_db()->prepare('SELECT snooze_moves_json FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
        $st->execute([$threadId]);
        $before = json_decode((string)$st->fetchColumn(), true) ?: [];
        ecw_db()->prepare('UPDATE `' . ecw_table('email_threads') . '` SET snooze_moves_json = ? WHERE id = ?')
            ->execute([json_encode(array_merge($before, $r['moved'])), $threadId]);
    } catch (Throwable $e) {
        ecw_log("snooze in mailbox skipped for thread {$threadId}: " . $e->getMessage());
    }
}

/** Put a woken conversation back where it was in the mailbox (best effort). */
function ecw_thread_snooze_restore(int $mailboxId, int $threadId): void
{
    $st = ecw_db()->prepare('SELECT snooze_moves_json FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND mailbox_id = ?');
    $st->execute([$threadId, $mailboxId]);
    $moves = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($moves) || $moves === []) return;
    ecw_db()->prepare('UPDATE `' . ecw_table('email_threads') . '` SET snooze_moves_json = NULL WHERE id = ?')->execute([$threadId]);
    try {
        require_once __DIR__ . '/mail_actions.php';
        ecw_messages_restore($mailboxId, $moves);
    } catch (Throwable $e) {
        ecw_log("unsnooze in mailbox failed for thread {$threadId}: " . $e->getMessage());
    }
}

/** The mailbox's "Snoozed" folder, created the first time. */
function ecw_snooze_folder(int $mailboxId): array
{
    // Outlook's own snooze folder first; else a top-level folder called Snoozed (the
    // root's id counts as top level: discovery stores it as the parent).
    $st = ecw_db()->prepare('SELECT f.* FROM `' . ecw_table('mail_folders') . "` f WHERE f.mailbox_id = ? AND (f.folder_type = 'snoozed' OR (LOWER(f.name) = 'snoozed' AND (f.parent_provider_folder_id IS NULL
                                OR f.parent_provider_folder_id = (SELECT i.parent_provider_folder_id FROM `" . ecw_table('mail_folders') . "` i WHERE i.mailbox_id = f.mailbox_id AND i.folder_type = 'inbox' LIMIT 1))))
                              ORDER BY f.folder_type = 'snoozed' DESC, f.id LIMIT 1");
    $st->execute([$mailboxId]);
    $f = $st->fetch();
    if ($f) return $f;
    require_once __DIR__ . '/folder_actions.php';
    return ecw_folder_create($mailboxId, 'Snoozed');
}

/** Wake a thread: clear the snooze and move it back in the mailbox. */
function ecw_thread_wake(int $threadId): void
{
    $st = ecw_db()->prepare('SELECT mailbox_id FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
    $st->execute([$threadId]);
    $mailboxId = $st->fetchColumn();
    if ($mailboxId === false) return;
    ecw_db()->prepare('UPDATE `' . ecw_table('email_threads') . '` SET snoozed_until = NULL WHERE id = ?')->execute([$threadId]);
    ecw_thread_snooze_restore((int)$mailboxId, $threadId);
}

/**
 * Due reminders and expired snoozes. Called every minute by the sync daemon.
 * Each reminder is claimed with a conditional UPDATE, so two workers can never
 * send the same notification twice.
 */
function ecw_followups_run_due(): int
{
    $pdo = ecw_db();
    $now = ecw_now();
    $st = $pdo->prepare(
        'SELECT f.*, c.case_number, c.name AS case_name, t.normalized_subject
           FROM `' . ecw_table('followups') . '` f
           LEFT JOIN `' . ecw_table('cases') . '` c ON c.id = f.case_id
           LEFT JOIN `' . ecw_table('email_threads') . '` t ON t.id = f.thread_id
          WHERE f.done_at IS NULL AND f.due_at <= ? ORDER BY f.due_at LIMIT 100'
    );
    $st->execute([$now]);
    $handled = 0;
    foreach ($st->fetchAll() as $f) {
        $claim = $pdo->prepare('UPDATE `' . ecw_table('followups') . "` SET done_at = ?, outcome = 'notified' WHERE id = ? AND done_at IS NULL");
        $claim->execute([$now, (int)$f['id']]);
        if ($claim->rowCount() === 0) {
            continue;
        }
        $what = $f['case_id'] !== null
            ? $f['case_number'] . ' ' . $f['case_name']
            : '“' . ((string)$f['normalized_subject'] ?: '(no subject)') . '”';
        $link = $f['case_id'] !== null ? 'case.php?id=' . (int)$f['case_id'] : 'app.php?thread=' . (int)$f['thread_id'];
        $text = 'Reminder: ' . $what . ($f['note'] ? ' · ' . $f['note'] : '') . ((int)$f['if_no_reply'] === 1 ? ' (no reply yet)' : '');
        ecw_notify((int)$f['organization_id'], (int)$f['user_id'], null, 'reminder', $text, $link,
            $f['case_id'] !== null ? (int)$f['case_id'] : null, $f['thread_id'] !== null ? (int)$f['thread_id'] : null);
        $handled++;
    }

    // Snoozes that ran out reappear in the list — and in the mailbox's Inbox.
    $due = $pdo->prepare('SELECT id FROM `' . ecw_table('email_threads') . '` WHERE snoozed_until IS NOT NULL AND snoozed_until <= ? LIMIT 200');
    $due->execute([$now]);
    foreach (array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN)) as $t) {
        ecw_thread_wake($t);
    }
    return $handled;
}

/**
 * "Only if no reply" reminders are settled by the other side answering.
 * Called by automation for every fresh incoming message.
 */
function ecw_followups_settle_replied(int $threadId, ?int $caseId): void
{
    $sql = 'UPDATE `' . ecw_table('followups') . "` SET done_at = ?, outcome = 'replied'
             WHERE done_at IS NULL AND if_no_reply = 1 AND (thread_id = ?";
    $params = [ecw_now(), $threadId];
    if ($caseId !== null) {
        $sql .= ' OR case_id = ?';
        $params[] = $caseId;
    }
    ecw_db()->prepare($sql . ')')->execute($params);
}
