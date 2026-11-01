<?php declare(strict_types=1);
/**
 * Junk mail: Report as junk, Block sender, Not junk.
 *
 * Report as junk moves the conversation to the mailbox's Junk folder (Outlook
 * "Junk Email", Gmail "Spam"). Block sender also remembers the address (or a
 * whole @domain) for that mailbox: new mail from it is moved to Junk as it
 * arrives (ecw_junk_after_ingest, called from the automation step). Not junk
 * moves it back to the Inbox and unblocks the sender.
 *
 * The block list lives in the app, so it works the same for Outlook and Gmail
 * without extra mailbox permissions — and only while the app is running.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/mail_actions.php';

/** The local folder row of the mailbox's Junk / Spam folder, or null. */
function ecw_junk_folder(int $mailboxId): ?array
{
    $mb = ecw_mailbox($mailboxId);
    if ($mb === null) return null;
    if (($mb['provider'] ?? '') === 'google') {
        $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mail_folders') . "` WHERE mailbox_id = ? AND provider_folder_id = 'SPAM'");
        $st->execute([$mailboxId]);
        return $st->fetch() ?: null;
    }
    // Outlook: ask once for the well-known folder's id, remember it.
    $key = 'junk_folder_' . $mailboxId;
    $st = ecw_db()->prepare('SELECT value FROM `' . ecw_table('app_settings') . '` WHERE name = ?');
    $st->execute([$key]);
    $pid = $st->fetchColumn();
    if ($pid === false) {
        try {
            $jp = ecw_provider_for_mailbox($mailboxId); $pid = (string)($jp->http()->request('GET', $jp->root() . '/mailFolders/junkemail?$select=id')['body']['id'] ?? '');
        } catch (Throwable $e) {
            ecw_log('junk folder lookup failed: ' . $e->getMessage());
            return null;
        }
        if ($pid === '') return null;
        ecw_db()->prepare('INSERT INTO `' . ecw_table('app_settings') . '` (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$key, $pid]);
    }
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND provider_folder_id = ?');
    $st->execute([$mailboxId, (string)$pid]);
    return $st->fetch() ?: null;
}

function ecw_sender_pattern(string $value): ?string
{
    $v = strtolower(trim($value));
    if (str_starts_with($v, '@')) return preg_match('/^@[a-z0-9.-]+\.[a-z]{2,}$/', $v) ? $v : null;
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
}

function ecw_sender_block(int $mailboxId, string $value, ?int $userId): string
{
    $p = ecw_sender_pattern($value);
    if ($p === null) throw new InvalidArgumentException('Enter an email address, or @domain.com for a whole domain.');
    $own = strtolower((string)(ecw_mailbox($mailboxId)['email_address'] ?? ''));
    if ($p === $own || ($p[0] === '@' && str_ends_with($own, $p))) throw new InvalidArgumentException('You cannot block your own address.');
    ecw_db()->prepare('INSERT IGNORE INTO `' . ecw_table('blocked_senders') . '` (mailbox_id, pattern, created_by_user_id) VALUES (?, ?, ?)')->execute([$mailboxId, $p, $userId]);
    return $p;
}

function ecw_sender_unblock(int $mailboxId, string $pattern): bool
{
    $st = ecw_db()->prepare('DELETE FROM `' . ecw_table('blocked_senders') . '` WHERE mailbox_id = ? AND pattern = ?');
    $st->execute([$mailboxId, strtolower(trim($pattern))]);
    return $st->rowCount() > 0;
}

/** @return array<int,array{pattern:string,created_at:string}> */
function ecw_blocked_senders(int $mailboxId): array
{
    $st = ecw_db()->prepare('SELECT pattern, created_at FROM `' . ecw_table('blocked_senders') . '` WHERE mailbox_id = ? ORDER BY pattern');
    $st->execute([$mailboxId]);
    return $st->fetchAll();
}

function ecw_sender_is_blocked(int $mailboxId, string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) return false;
    $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('blocked_senders') . '` WHERE mailbox_id = ? AND pattern IN (?, ?) LIMIT 1');
    $st->execute([$mailboxId, $email, substr($email, strpos($email, '@'))]);
    return $st->fetchColumn() !== false;
}

/** The sender of the conversation's latest incoming email. */
function ecw_thread_sender(int $mailboxId, int $threadId): ?string
{
    $st = ecw_db()->prepare("SELECT from_email FROM `" . ecw_table('email_messages') . "` WHERE email_thread_id = ? AND mailbox_id = ? AND direction = 'incoming' AND from_email IS NOT NULL ORDER BY occurred_at DESC LIMIT 1");
    $st->execute([$threadId, $mailboxId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** Report as junk (and optionally block the sender). @return array the move result + blocked */
function ecw_thread_junk(int $mailboxId, int $threadId, bool $block, ?int $userId): array
{
    $junk = ecw_junk_folder($mailboxId);
    if ($junk === null) throw new InvalidArgumentException('This mailbox has no Junk folder the app can see.');
    $blocked = null;
    if ($block && ($sender = ecw_thread_sender($mailboxId, $threadId)) !== null) {
        try { $blocked = ecw_sender_block($mailboxId, $sender, $userId); } catch (InvalidArgumentException $e) { $blocked = null; }
    }
    return ecw_thread_move($mailboxId, $threadId, (string)$junk['id']) + ['blocked' => $blocked];
}

/** Not junk: back to the Inbox, sender unblocked. */
function ecw_thread_not_junk(int $mailboxId, int $threadId): array
{
    $sender = ecw_thread_sender($mailboxId, $threadId);
    if ($sender !== null) { ecw_sender_unblock($mailboxId, $sender); }
    return ecw_thread_move($mailboxId, $threadId, 'inbox');
}

/**
 * New mail from a blocked sender goes to Junk. Called for fresh incoming mail
 * during sync (lib/automation.php). Only this message moves.
 */
function ecw_junk_after_ingest(int $mailboxId, int $messageId, string $fromEmail): void
{
    if (!ecw_sender_is_blocked($mailboxId, $fromEmail)) return;
    $junk = ecw_junk_folder($mailboxId);
    if ($junk === null) return;
    $st = ecw_db()->prepare('SELECT m.id, m.email_thread_id, m.provider_message_id, m.mail_folder_id, f.provider_folder_id AS from_provider, f.folder_type
                               FROM `' . ecw_table('email_messages') . '` m LEFT JOIN `' . ecw_table('mail_folders') . '` f ON f.id = m.mail_folder_id WHERE m.id = ?');
    $st->execute([$messageId]);
    $m = $st->fetch();
    if (!$m || (int)$m['mail_folder_id'] === (int)$junk['id'] || in_array($m['folder_type'], ['sent', 'drafts', 'deleted'], true)) return;
    $res = ecw_provider_for_mailbox($mailboxId)->moveMessage((string)$m['provider_message_id'], (string)$junk['provider_folder_id'], $m['from_provider'] !== null ? (string)$m['from_provider'] : null);
    ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET mail_folder_id = ?, provider_message_id = ? WHERE id = ?')
        ->execute([(int)$junk['id'], (string)($res['provider_message_id'] ?? '') ?: (string)$m['provider_message_id'], $messageId]);
    ecw_thread_refresh_counters((int)$m['email_thread_id']);
    ecw_log("message {$messageId} from a blocked sender moved to Junk");
}
