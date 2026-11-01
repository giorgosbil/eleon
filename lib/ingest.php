<?php declare(strict_types=1);
/**
 * Email Case Workspace — turning a provider message into local rows.
 *
 * Every sync path (initial import, delta, backfill, post-send confirmation)
 * funnels through ecw_ingest_message(). One writer means one set of rules for
 * identity, direction and threading.
 *
 * IDENTITY, IN THE STEP'S ORDER
 * -----------------------------
 * (mailbox_id, provider_message_id) is the upsert key. Subject/body/date are
 * never used to identify a message.
 *
 * But a Graph message id is MUTABLE: moving a message between folders, and
 * sending a draft, both reissue it. Keyed on provider id alone, a moved message
 * arrives as a brand new row and the mailbox slowly fills with duplicates of
 * itself. So when the provider id is unknown, ingest looks for a single
 * existing row with the same (mailbox_id, internet_message_id, direction) and
 * migrates it onto the new provider id — a reconciliation, not an insert.
 *
 * The direction is part of that key on purpose. A user who mails themselves
 * has two genuinely different messages in one mailbox carrying one Message-ID
 * (the Sent copy and the Inbox copy), and they must stay two rows. Including
 * direction separates them while still catching the move case, where direction
 * never changes. If more than one row still matches, ingest inserts rather than
 * guessing which to overwrite.
 *
 * REMOVED IS NOT DELETED
 * ----------------------
 * A delta @removed stamps provider_removed_at. The row stays so that a case
 * timeline does not rewrite itself when someone tidies their mailbox.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/threading.php';
require_once __DIR__ . '/cases.php';

/**
 * @param array $msg normalized provider message
 * @return array{action:string, message_id:?int, thread_id:?int}
 */
function ecw_ingest_message(int $mailboxId, ?int $folderId, string $folderType, array $msg): array
{
    $pdo      = ecw_db();
    $messages = ecw_table('email_messages');
    $providerId = (string)($msg['provider_message_id'] ?? '');

    if ($providerId === '') {
        throw new InvalidArgumentException('ecw: message has no provider_message_id');
    }
    $msg = ecw_ingest_fit($msg);

    // --- removed ---------------------------------------------------------
    if (!empty($msg['removed'])) {
        $st = $pdo->prepare("UPDATE `{$messages}` SET provider_removed_at = ?
                              WHERE mailbox_id = ? AND provider_message_id = ? AND provider_removed_at IS NULL");
        $st->execute([ecw_now(), $mailboxId, $providerId]);

        $st = $pdo->prepare("SELECT * FROM `{$messages}` WHERE mailbox_id = ? AND provider_message_id = ?");
        $st->execute([$mailboxId, $providerId]);
        $row = $st->fetch();
        if ($row !== false) {
            ecw_thread_refresh_counters((int)$row['email_thread_id']);
            return ['action' => 'removed', 'message_id' => (int)$row['id'], 'thread_id' => (int)$row['email_thread_id']];
        }
        // Removal of something never stored: nothing to do, and not an error.
        return ['action' => 'removed_unknown', 'message_id' => null, 'thread_id' => null];
    }

    $mailbox = ecw_mailbox_row_cached($mailboxId);
    $direction = ecw_ingest_direction($msg, $folderType, (string)($mailbox['email_address'] ?? ''));

    // An unsent draft is not an email: drafts live in the Drafts list (lib/compose.php,
    // lib/drafts_sync.php). One found in another folder (a deleted draft in Deleted
    // Items) showed in its conversation like a reply that had been sent.
    if ($direction === 'draft') {
        $st = $pdo->prepare("SELECT id, email_thread_id FROM `{$messages}` WHERE mailbox_id = ? AND provider_message_id = ?");
        $st->execute([$mailboxId, $providerId]);
        if (($old = $st->fetch()) !== false) {
            $pdo->prepare("DELETE FROM `{$messages}` WHERE id = ?")->execute([(int)$old['id']]);
            ecw_thread_refresh_counters((int)$old['email_thread_id']);
        }
        return ['action' => 'skipped_draft', 'message_id' => null, 'thread_id' => null];
    }

    // --- locate an existing row -----------------------------------------
    $st = $pdo->prepare("SELECT * FROM `{$messages}` WHERE mailbox_id = ? AND provider_message_id = ?");
    $st->execute([$mailboxId, $providerId]);
    $existing = $st->fetch();
    $action   = $existing !== false ? 'updated' : 'inserted';

    if ($existing === false && !empty($msg['internet_message_id'])) {
        $st = $pdo->prepare("SELECT * FROM `{$messages}`
                              WHERE mailbox_id = ? AND internet_message_id = ? AND direction = ?");
        $st->execute([$mailboxId, (string)$msg['internet_message_id'], $direction]);
        $matches = $st->fetchAll();
        if (count($matches) === 1) {
            $existing = $matches[0];
            $action   = 'reconciled';
            ecw_log("message id changed, reconciling local row {$existing['id']} onto the new provider id");
        }
    }

    $threadId = $existing !== false
        ? (int)$existing['email_thread_id']
        : ecw_resolve_thread($mailboxId, $msg);

    $fields = [
        'mailbox_id'             => $mailboxId,
        'email_thread_id'        => $threadId,
        'mail_folder_id'         => $folderId,
        'provider_message_id'    => $providerId,
        'internet_message_id'    => $msg['internet_message_id'] ?? null,
        'in_reply_to_message_id' => $msg['in_reply_to_message_id'] ?? null,
        'references_json'        => !empty($msg['references'])
            ? json_encode(array_values((array)$msg['references']), JSON_UNESCAPED_SLASHES) : null,
        'subject'                => $msg['subject'] ?? null,
        'from_email'             => $msg['from_email'] ?? null,
        'from_name'              => $msg['from_name'] ?? null,
        'to_json'                => json_encode((array)($msg['to'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'cc_json'                => json_encode((array)($msg['cc'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'bcc_json'               => json_encode((array)($msg['bcc'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'direction'              => $direction,
        'is_read'                => !empty($msg['is_read']) ? 1 : 0,
        'is_flagged'             => !empty($msg['is_flagged']) ? 1 : 0,
        'meeting_kind'           => $msg['meeting_kind'] ?? null,
        'has_attachments'        => !empty($msg['has_attachments']) ? 1 : 0,
        'sent_at'                => $msg['sent_at'] ?? null,
        'received_at'            => $msg['received_at'] ?? null,
        'provider_created_at'    => $msg['provider_created_at'] ?? null,
        'provider_modified_at'   => $msg['provider_modified_at'] ?? null,
        'web_link'               => $msg['web_link'] ?? null,
    ];

    // A delta may contain only changed properties. Omitted fields are not
    // empty values: preserve the established envelope, timestamps and flags.
    if ($existing !== false) {
        $source = $msg['_source_fields'] ?? null;
        $sourceMap = [
            'internet_message_id' => ['internetMessageId'],
            'in_reply_to_message_id' => ['internetMessageHeaders'], 'references_json' => ['internetMessageHeaders'],
            'subject' => ['subject'], 'from_email' => ['from', 'sender'], 'from_name' => ['from', 'sender'],
            'to_json' => ['toRecipients'], 'cc_json' => ['ccRecipients'], 'bcc_json' => ['bccRecipients'],
            'is_read' => ['isRead'], 'is_flagged' => ['flag'], 'meeting_kind' => ['@odata.type'], 'has_attachments' => ['hasAttachments'],
            'web_link' => ['webLink'],
        ];
        foreach ($sourceMap as $field => $names) {
            if (is_array($source) && !array_intersect($source, $names)) $fields[$field] = $existing[$field];
        }
        // Read/unread set here and not yet pushed wins over the provider's old value.
        if ((int)($existing['read_push_pending'] ?? 0) === 1) {
            $fields['is_read'] = $existing['is_read'];
        }
        if ((int)($existing['flag_push_pending'] ?? 0) === 1) {
            $fields['is_flagged'] = $existing['is_flagged'];
        }
        foreach (['sent_at', 'received_at', 'provider_created_at', 'provider_modified_at'] as $field) {
            $fields[$field] = $fields[$field] ?? $existing[$field];
        }
        if (is_array($source) && !array_intersect($source, ['isDraft', 'from', 'sender'])
            && !in_array($folderType, ['inbox', 'sent', 'drafts'], true)) {
            $fields['direction'] = $existing['direction'];
        }
    }

    // A body only arrives on the paths that asked for one; a metadata-only
    // sync must never blank a body a previous fetch already stored.
    if (array_key_exists('body_html', $msg) && ($msg['body_html'] !== null || $msg['body_text'] !== null)) {
        // The same split the full fetch does (ecw_store_full_message): the reply
        // as the body, the history it quotes as quoted_*.
        require_once __DIR__ . '/body.php';
        $split = ecw_split_quoted((string)($msg['body_html'] ?? ''), (string)($msg['body_text'] ?? ''));
        $fields['body_html']        = $split['body_html'];
        $fields['body_text']        = $split['body_text'];
        $fields['quoted_html']      = $split['quoted_html'];
        $fields['quoted_text']      = $split['quoted_text'];
        $fields['body_fetch_state'] = 'fetched';
        // The text came with the list, the attachments did not (files, and pictures
        // inside the text): the background job fetches them (lib/sync/messages.php).
        $needsAttachments = !empty($msg['has_attachments']) || stripos((string)($msg['body_html'] ?? ''), 'cid:') !== false;
        if ($needsAttachments && empty($msg['attachments'])) {
            // Already known (listed before, also before this column existed): done, never fetched again.
            $known = false;
            if ($existing !== false) {
                $ka = $pdo->prepare('SELECT 1 FROM `' . ecw_table('email_attachments') . '` WHERE email_message_id = ? LIMIT 1');
                $ka->execute([(int)$existing['id']]);
                $known = ($existing['attachments_state'] ?? null) === 'done' || (bool)$ka->fetchColumn();
            }
            $fields['attachments_state'] = $known ? 'done' : 'pending';
        }
    }

    // A move can arrive as "removed from Inbox" before "added to Archive". The
    // add is reconciled onto the same row (above), and that row exists in the
    // mailbox again: it must not stay hidden from its conversation and case.
    // (Only on a reconcile: the same provider id coming back is a stale replay.)
    if ($action === 'reconciled' && !empty($existing['provider_removed_at'])) {
        $fields['provider_removed_at'] = null;
        ecw_log("message {$existing['id']} reappeared in another folder: no longer removed");
    }

    if ($existing !== false) {
        $set    = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($fields)));
        $params = array_values($fields);
        $params[] = (int)$existing['id'];
        $pdo->prepare("UPDATE `{$messages}` SET {$set} WHERE id = ?")->execute($params);
        $messageId = (int)$existing['id'];
    } else {
        $cols = array_keys($fields);
        $sql  = "INSERT INTO `{$messages}` (`" . implode('`,`', $cols) . '`) VALUES ('
              . implode(',', array_fill(0, count($cols), '?')) . ')';
        try {
            $pdo->prepare($sql)->execute(array_values($fields));
            $messageId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // Delta replays are normal and documented by Microsoft; a racing
            // second worker can therefore insert between our SELECT and INSERT.
            if (!str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
            $st = $pdo->prepare("SELECT id FROM `{$messages}` WHERE mailbox_id = ? AND provider_message_id = ?");
            $st->execute([$mailboxId, $providerId]);
            $messageId = (int)$st->fetchColumn();
            $action    = 'replayed';
        }
    }

    ecw_thread_refresh_counters($threadId);
    ecw_ingest_addresses((int)$mailbox['organization_id'], $msg);
    ecw_ingest_participants($messageId, [
        'from_email' => $fields['from_email'], 'from_name' => $fields['from_name'],
        'to' => json_decode($fields['to_json'], true), 'cc' => json_decode($fields['cc_json'], true),
        'bcc' => json_decode($fields['bcc_json'], true),
    ]);

    if (!empty($msg['attachments'])) {
        ecw_ingest_attachments($messageId, (array)$msg['attachments']);
    }

    if ($folderId !== null) {
        ecw_case_intake_check($mailboxId, $folderId, $threadId, $msg, $messageId);
    }

    // Reopen, reminders, routing, case-number linking. Never allowed to fail ingest.
    if ($action === 'inserted' && $direction !== 'draft') {
        try {
            require_once __DIR__ . '/automation.php';
            ecw_automation_after_ingest($mailbox, $threadId, $messageId, $fields, $direction);
        } catch (Throwable $e) {
            ecw_log('automation failed for message ' . $messageId . ': ' . $e->getMessage());
        }
        // A case conversation: who the case waits on now (lib/next_actions.php).
        try {
            require_once __DIR__ . '/next_actions.php';
            ecw_case_next_after_message($threadId, $messageId, $direction, $fields['sent_at'] ?? $fields['received_at'] ?? null);
        } catch (Throwable $e) {
            ecw_log('next step rule failed for message ' . $messageId . ': ' . $e->getMessage());
        }        // A new email in a case conversation gets the case's categories in Outlook too.
        try {
            if (ecw_case_for_thread($threadId) !== null) {
                $pdo->prepare("UPDATE `{$messages}` SET categories_push_pending = 1 WHERE id = ?")->execute([$messageId]);
                require_once __DIR__ . '/sync/queue.php';
                ecw_queue_enqueue($mailboxId, null, 'categories', 'user');
            }
        } catch (Throwable $e) { /* before migration 030 */ }
    }

    return ['action' => $action, 'message_id' => $messageId, 'thread_id' => $threadId];
}

/**
 * Direction: the system folder decides when it can, the sender decides otherwise.
 *
 * Neither signal is sufficient alone.
 *
 * Folder alone is wrong as soon as a user files a sent message into a project
 * folder or drags an incoming one into a custom folder — both are routine, and
 * both leave the message in a folder that says nothing about direction.
 *
 * Sender alone is wrong for mail a user sends to themselves: the Inbox copy has
 * the user's own address in From, but it was received, and calling it outgoing
 * collapses the Sent and Inbox copies into one row (they share a Message-ID —
 * direction is precisely what keeps them apart in ecw_ingest_message).
 *
 * So Inbox, Sent and Drafts are believed outright, and the sender comparison is
 * the tie-breaker for every other folder, where the provider has told us nothing.
 */
/**
 * Cut what is longer than its column (subject 512, address 320, name 255 characters): a sender
 * "name" of a whole sentence or a broken address made the email fail to store, and it was
 * skipped (dead-lettered) instead of shown.
 */
function ecw_ingest_fit(array $msg): array
{
    $cut = static fn($v, int $n) => is_string($v) && mb_strlen($v) > $n ? mb_substr($v, 0, $n) : $v;
    if (isset($msg['subject'])) $msg['subject'] = $cut($msg['subject'], 512);
    if (isset($msg['from_email'])) $msg['from_email'] = $cut($msg['from_email'], 320);
    if (isset($msg['from_name'])) $msg['from_name'] = $cut($msg['from_name'], 255);
    foreach (['to', 'cc', 'bcc'] as $role) {
        if (!is_array($msg[$role] ?? null)) continue;
        foreach ($msg[$role] as $i => $r) {
            if (!is_array($r)) continue;
            if (isset($r['email'])) $msg[$role][$i]['email'] = $cut($r['email'], 320);
            if (isset($r['name'])) $msg[$role][$i]['name'] = $cut($r['name'], 255);
        }
    }
    return $msg;
}

function ecw_ingest_direction(array $msg, string $folderType, string $mailboxAddress): string
{
    if (!empty($msg['is_draft']) || $folderType === 'drafts') {
        return 'draft';
    }
    // Providers whose messages live in several places at once (Gmail labels)
    // say so directly: a message with the SENT label is outgoing even when it
    // is synchronized through Inbox.
    if ($folderType === 'sent' || !empty($msg['is_sent'])) {
        return 'outgoing';
    }
    if ($folderType === 'inbox') {
        return 'incoming';
    }

    $from = strtolower(trim((string)($msg['from_email'] ?? '')));
    if ($from !== '' && $mailboxAddress !== '' && $from === strtolower(trim($mailboxAddress))) {
        return 'outgoing';
    }
    return 'incoming';
}

/** Mailbox row, cached per process — ingest reads it once per message otherwise. */
function ecw_mailbox_row_cached(int $mailboxId): array
{
    static $cache = [];
    if (!isset($cache[$mailboxId])) {
        $st = ecw_db()->prepare('SELECT id, organization_id, email_address, connected_by_user_id FROM `' . ecw_table('mailboxes') . '` WHERE id = ?');
        $st->execute([$mailboxId]);
        $row = $st->fetch();
        if ($row === false) {
            throw new RuntimeException("ecw: mailbox {$mailboxId} not found");
        }
        $cache[$mailboxId] = $row;
    }
    return $cache[$mailboxId];
}

/**
 * Keep the lightweight identity cache current.
 *
 * This is not a CRM: rows appear from traffic, carry no lifecycle, and are
 * never required for a message to be stored. A failure here must not fail an
 * ingest, so it is best-effort by design.
 */
function ecw_ingest_addresses(int $organizationId, array $msg): void
{
    $seen = [];
    if (!empty($msg['from_email'])) {
        $seen[] = ['email' => (string)$msg['from_email'], 'name' => $msg['from_name'] ?? null];
    }
    foreach (array_merge((array)($msg['to'] ?? []), (array)($msg['cc'] ?? []), (array)($msg['bcc'] ?? [])) as $r) {
        if (!empty($r['email'])) {
            $seen[] = ['email' => (string)$r['email'], 'name' => $r['name'] ?? null];
        }
    }

    foreach ($seen as $one) {
        $normalized = strtolower(trim($one['email']));
        if ($normalized === '' || !str_contains($normalized, '@')) {
            continue;
        }
        $domain = substr($normalized, strrpos($normalized, '@') + 1);
        try {
            ecw_db()->prepare(
                'INSERT INTO `' . ecw_table('email_addresses') . '`
                    (organization_id, email, normalized_email, display_name, domain)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    display_name = COALESCE(VALUES(display_name), display_name),
                    email        = VALUES(email)'
            )->execute([$organizationId, $one['email'], $normalized, $one['name'], $domain]);
        } catch (PDOException $e) {
            ecw_log('address cache write skipped: ' . $e->getMessage());
        }
    }
}

/**
 * Maintain the searchable participant index for one message.
 *
 * A derived index over to_json/cc_json/bcc_json, not a second source of truth:
 * it is deleted and rewritten on every ingest so a corrected recipient list on
 * an updated message cannot leave a stale row behind. The JSON columns remain
 * the record of what the provider actually sent.
 */
function ecw_ingest_participants(int $messageId, array $msg): void
{
    $rows = [];
    if (!empty($msg['from_email'])) {
        $rows[] = ['from', strtolower(trim((string)$msg['from_email'])), $msg['from_name'] ?? null];
    }
    foreach (['to', 'cc', 'bcc'] as $role) {
        foreach ((array)($msg[$role] ?? []) as $r) {
            if (!empty($r['email'])) {
                $rows[] = [$role, strtolower(trim((string)$r['email'])), $r['name'] ?? null];
            }
        }
    }

    $pdo = ecw_db();
    $pdo->prepare('DELETE FROM `' . ecw_table('message_participants') . '` WHERE email_message_id = ?')
        ->execute([$messageId]);

    if ($rows === []) {
        return;
    }

    $st = $pdo->prepare(
        'INSERT IGNORE INTO `' . ecw_table('message_participants') . '`
            (email_message_id, role, email, name) VALUES (?, ?, ?, ?)'
    );
    foreach ($rows as [$role, $email, $name]) {
        $st->execute([$messageId, $role, $email, $name]);
    }
}

/** Attachment METADATA only. Bytes are fetched on demand — see the step's rule. */
function ecw_ingest_attachments(int $messageId, array $attachments): void
{
    foreach ($attachments as $a) {
        ecw_db()->prepare(
            'INSERT INTO `' . ecw_table('email_attachments') . '`
                (email_message_id, provider_attachment_id, filename, mime_type, size_bytes, is_inline, content_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                filename = VALUES(filename), mime_type = VALUES(mime_type),
                size_bytes = VALUES(size_bytes), is_inline = VALUES(is_inline),
                content_id = VALUES(content_id)'
        )->execute([
            $messageId,
            $a['provider_attachment_id'] ?? null,
            $a['filename'] ?? null,
            $a['mime_type'] ?? null,
            $a['size_bytes'] ?? null,
            !empty($a['is_inline']) ? 1 : 0,
            $a['content_id'] ?? null,
        ]);
    }
}

/**
 * The "Create Case" folder convenience workflow.
 *
 * A message landing in a folder flagged is_case_intake drafts a case named
 * after the subject and links the thread. It is a shortcut, never the only
 * route — manual linking stays available, which is why nothing here is
 * required for a message to be stored.
 *
 * Silent no-op when the thread is already in a case: moving a second message
 * of an existing conversation into the intake folder must not spawn a
 * duplicate case.
 */
function ecw_case_intake_check(int $mailboxId, int $folderId, int $threadId, array $msg, ?int $messageId = null): ?int
{
    $st = ecw_db()->prepare('SELECT is_case_intake FROM `' . ecw_table('mail_folders') . '` WHERE id = ?');
    $st->execute([$folderId]);
    if ((int)($st->fetchColumn() ?: 0) !== 1) {
        return null;
    }

    if (ecw_case_for_thread($threadId) !== null) {
        return null;
    }

    $mailbox = ecw_mailbox_row_cached($mailboxId);
    $name    = ecw_normalize_subject($msg['subject'] ?? null);
    if ($name === '') {
        $name = 'Untitled case';
    }

    // Started from this email already by a copy of the app on another PC (lib/auto_case_guard.php).
    require_once __DIR__ . '/auto_case_guard.php';
    if ($messageId !== null && ecw_auto_case_started_elsewhere($mailboxId, $messageId) !== null) {
        return null;
    }

    $caseId = ecw_case_create((int)$mailbox['organization_id'], $name, null);
    if ($messageId !== null) ecw_auto_case_mark($mailboxId, $messageId, $caseId);
    ecw_case_link_thread($caseId, $threadId, $msg['from_name'] ?? $msg['from_email'] ?? null, null);
    ecw_log("case intake folder drafted case {$caseId} from thread {$threadId}");

    return $caseId;
}
