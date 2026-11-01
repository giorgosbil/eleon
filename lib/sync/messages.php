<?php declare(strict_types=1);
/**
 * Email Case Workspace — message synchronization.
 *
 * THE INVARIANT THIS FILE EXISTS TO PROTECT
 * -----------------------------------------
 * A cursor may only move past a page once EVERY message on that page has
 * resolved to one of exactly two durable outcomes: ingested, or dead-lettered.
 * There is no third outcome. If a page throws, or if the dead-letter write
 * itself throws (nowhere left to record the failure), the exception propagates
 * and the cursor is simply never advanced — the next run re-reads from the last
 * fully-resolved position. Nothing is skipped, and no error is ever hidden by a
 * cursor that moved anyway.
 *
 * WHY THE CURSOR IS WRITTEN AFTER EVERY PAGE, NOT AFTER EVERY ROUND
 * -----------------------------------------------------------------
 * A delta round can be many pages. Persisting only the final deltaLink means a
 * run that dies on page 40 re-reads 39 pages. Graph's nextLink is resumable, so
 * each fully-resolved page's nextLink is stored as the folder cursor and the
 * final deltaLink overwrites it when the round completes. Both are opaque URLs
 * to the caller, which is exactly why the provider interface types the cursor
 * as an opaque string.
 *
 * DELTA CANNOT SERVE "NEWEST FIRST"
 * ---------------------------------
 * Graph guarantees no ordering on delta. So the initial import and the
 * historical backfill are the same newest-first walk over
 * $orderby=receivedDateTime desc (ecw_sync_history), with delta responsible
 * only for keeping up afterwards. The UI becomes usable after the first page
 * of that walk, not after the whole mailbox is local.
 */

require_once __DIR__ . '/lease.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../mailbox.php';
require_once __DIR__ . '/../license.php';   // ecw_app_setting: the newest-first top-up's clock
require_once __DIR__ . '/../ingest.php';
require_once __DIR__ . '/../body.php';
require_once __DIR__ . '/folders.php';

/**
 * Page sizes.
 *
 * Graph latency per CALL dominates; cost per MESSAGE is nearly flat once
 * internetMessageHeaders is out of the select (measured 8.5-8.9 ms/message at
 * top=50, 100 and 200 alike). So the way to make a mailbox sync quickly is to
 * ask for fewer, bigger pages.
 *
 * Delta stays at 100 because that is what Graph honours for
 * Prefer: odata.maxpagesize on a delta round; the historical walk uses $top,
 * which takes 200 happily.
 */
const ECW_SYNC_PAGE_SIZE       = 100;
const ECW_HISTORY_PAGE_SIZE    = 200;
const ECW_SYNC_MAX_PAGES       = 40;
const ECW_INITIAL_IMPORT_PAGES = 5;
/**
 * History in two steps: every folder's last two years first (so a new mailbox
 * is complete for daily work soon), then the older emails in the background,
 * folder by folder in the same order. A folder whose walk passes this line
 * pauses (mailbox_sync_state.recent_done_at) until every folder has.
 */
const ECW_HISTORY_RECENT_DAYS  = 730;
/**
 * A slow line (an airport, a phone): a page that times out is asked again in this size, and
 * the rest of the round stays at it. Outlook does the same, in small steps when the line is bad.
 */
const ECW_SYNC_SLOW_PAGE_SIZE  = 25;
/**
 * Newest first while a folder is still being read in full (its first delta round pages through
 * all of its history, in no order): every minute the newest of Inbox and Sent are fetched
 * directly, so new mail shows at once instead of after the whole folder.
 */
const ECW_SYNC_TOPUP_SIZE      = 25;
const ECW_SYNC_TOPUP_EVERY     = 60;

/**
 * One incremental round for one folder.
 *
 * @return array counters, for the CLI and for tests
 */
function ecw_sync_folder_delta(int $mailboxId, int $folderId, int $maxPages = ECW_SYNC_MAX_PAGES, int $pageSize = ECW_SYNC_PAGE_SIZE): array
{
    $folder = ecw_folder_row($folderId);
    if ($folder === null) {
        throw new RuntimeException("ecw: folder {$folderId} not found");
    }

    $stateId = ecw_sync_state_id($mailboxId, $folderId, 'delta');
    $lease   = ecw_sync_acquire_lease($stateId);
    if ($lease === null) {
        ecw_log("delta sync for folder {$folderId} skipped: lease held elsewhere");
        return ['skipped' => true, 'reason' => 'lease_held'];
    }

    $counts = ['pages' => 0, 'ingested' => 0, 'removed' => 0, 'dead_lettered' => 0, 'resyncs' => 0, 'skipped' => false];

    try {
        $provider = ecw_provider_for_mailbox($mailboxId);
        $cursor   = ecw_folder_delta_cursor($folderId);
        $nextLink = null;
        $counts['topup'] = ecw_sync_newest_topup($provider, $mailboxId, $folder, $cursor);

        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $result = $provider->deltaPage((string)$folder['provider_folder_id'], $cursor, $nextLink, $pageSize);
            } catch (RuntimeException $e) {
                // The page did not arrive in time (a slow line): the same page again, smaller.
                // Delta takes the size per request (Prefer), so the link stays valid.
                if ($pageSize <= ECW_SYNC_SLOW_PAGE_SIZE || !ecw_sync_is_timeout($e)) throw $e;
                ecw_log("folder {$folderId}: delta page timed out, asking again in pages of " . ECW_SYNC_SLOW_PAGE_SIZE);
                $pageSize = ECW_SYNC_SLOW_PAGE_SIZE;
                $counts['slow'] = true;
                $page--;
                continue;
            }

            if (!empty($result['needs_full_resync'])) {
                // The stored token expired. Start the round over from Graph's
                // own resync URL. Nothing local is discarded: every message
                // re-arrives and upserts onto the row it already has.
                $counts['resyncs']++;
                ecw_log("folder {$folderId} delta token expired, starting a full resync round");
                $cursor   = $result['resync_url'];
                $nextLink = null;
                ecw_folder_set_delta_cursor($folderId, $cursor);
                continue;
            }

            $counts['pages']++;

            foreach ($result['messages'] as $msg) {
                ecw_sync_daemon_alive();
                try {
                    $msg = ecw_sync_complete_metadata($provider, $mailboxId, $msg);
                    $outcome = ecw_ingest_message($mailboxId, $folderId, (string)$folder['folder_type'], $msg);
                    if ($outcome['action'] === 'removed' || $outcome['action'] === 'removed_unknown') {
                        $counts['removed']++;
                    } else {
                        $counts['ingested']++;
                    }
                } catch (Throwable $e) {
                    // Outcome two. If THIS throws as well, it propagates and the
                    // cursor stays put — by design.
                    ecw_dead_letter($mailboxId, $folderId, $msg, $e);
                    $counts['dead_lettered']++;
                }
            }

            ecw_sync_heartbeat($stateId, $lease);

            // Every message on this page resolved. Only now may the cursor move.
            if ($result['next_link'] !== null) {
                ecw_folder_set_delta_cursor($folderId, $result['next_link']);
                $nextLink = $result['next_link'];
                $cursor   = null;
                continue;
            }

            if ($result['cursor'] !== null) {
                ecw_folder_set_delta_cursor($folderId, $result['cursor']);
                $counts['round_complete'] = true;
                break;
            }

            break;  // neither link: nothing more to read this round
        }

        ecw_sync_record_success($stateId);
        ecw_mailbox_mark_synced($mailboxId);
    } catch (Throwable $e) {
        ecw_sync_record_error($stateId, $e->getMessage());
        ecw_mailbox_record_failure($mailboxId, $e);
        throw $e;
    } finally {
        ecw_sync_release_lease($stateId, $lease);
    }

    return $counts;
}

/** A request that ran out of time or lost its connection (not an answer from Graph). */
function ecw_sync_is_timeout(Throwable $e): bool
{
    return !($e instanceof GraphException) && (bool)preg_match('/transport error|timed out|timeout|Operation too slow|Connection reset|Could not resolve/i', $e->getMessage());
}

/**
 * Newest first while a folder (Inbox, Sent) is still in its first full read: its newest emails,
 * fetched directly at most once a minute, so they show before the rest of the folder.
 * Outlook-only: a delta cursor without a deltaToken is a first read still going.
 *
 * @return int emails brought in (new or changed); 0 when not needed
 */
function ecw_sync_newest_topup(MailProvider $provider, int $mailboxId, array $folder, ?string $cursor): int
{
    if (!($provider instanceof MicrosoftGraphProvider) || !in_array($folder['folder_type'], ['inbox', 'sent'], true)) return 0;
    if ($cursor !== null && stripos($cursor, 'deltatoken') !== false) return 0;   // caught up: delta brings new mail itself
    $key = 'sync_topup_' . (int)$folder['id'];
    $last = (int)(ecw_app_setting($key) ?? 0);
    if (time() - $last < ECW_SYNC_TOPUP_EVERY) return 0;
    ecw_app_setting_set($key, (string)time());
    $n = 0;
    try {
        $page = $provider->listMessages((string)$folder['provider_folder_id'], null, ECW_SYNC_TOPUP_SIZE);
        foreach ($page['messages'] as $msg) {
            try { ecw_ingest_message($mailboxId, (int)$folder['id'], (string)$folder['folder_type'], $msg); $n++; }
            catch (Throwable $e) { ecw_dead_letter($mailboxId, (int)$folder['id'], $msg, $e); }
        }
    } catch (Throwable $e) {
        ecw_log('newest emails of folder ' . (int)$folder['id'] . ' not fetched: ' . $e->getMessage());   // the delta round still runs
    }
    return $n;
}

/**
 * The newest-first walk: initial import on its first runs, historical backfill
 * afterwards. Same mechanism, same cursor — the only difference is whether the
 * mailbox has been marked usable yet.
 */
function ecw_sync_history(int $mailboxId, int $folderId, int $maxPages = ECW_INITIAL_IMPORT_PAGES, int $pageSize = ECW_HISTORY_PAGE_SIZE): array
{
    $folder = ecw_folder_row($folderId);
    if ($folder === null) {
        throw new RuntimeException("ecw: folder {$folderId} not found");
    }

    $stateId = ecw_sync_state_id($mailboxId, $folderId, 'backfill');
    $lease   = ecw_sync_acquire_lease($stateId);
    if ($lease === null) {
        return ['skipped' => true, 'reason' => 'lease_held'];
    }

    $counts = ['pages' => 0, 'ingested' => 0, 'dead_lettered' => 0, 'complete' => false, 'skipped' => false];

    try {
        $provider = ecw_provider_for_mailbox($mailboxId);
        $nextLink = ecw_sync_cursor($stateId);
        $oldest   = null;
        $recent   = ecw_db()->prepare('SELECT recent_done_at FROM `' . ecw_table('mailbox_sync_state') . '` WHERE id = ?');
        $recent->execute([$stateId]);
        $pauseAt  = $recent->fetchColumn() ? null : ecw_history_recent_cutoff();

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $provider->listMessages((string)$folder['provider_folder_id'], $nextLink, $pageSize);
            $counts['pages']++;

            foreach ($result['messages'] as $msg) {
                ecw_sync_daemon_alive();
                try {
                    $msg = ecw_sync_complete_metadata($provider, $mailboxId, $msg);
                    ecw_ingest_message($mailboxId, $folderId, (string)$folder['folder_type'], $msg);
                    $counts['ingested']++;
                    $when = $msg['received_at'] ?? $msg['sent_at'] ?? null;
                    if ($when !== null && ($oldest === null || $when < $oldest)) {
                        $oldest = $when;
                    }
                } catch (Throwable $e) {
                    ecw_dead_letter($mailboxId, $folderId, $msg, $e);
                    $counts['dead_lettered']++;
                }
            }

            ecw_sync_heartbeat($stateId, $lease);
            ecw_sync_set_cursor($stateId, $result['next_link']);
            $nextLink = $result['next_link'];

            if ($nextLink === null) {
                // Walked off the end: this folder's whole history is local.
                $counts['complete'] = true;
                break;
            }
            if ($pauseAt !== null && $oldest !== null && $oldest < $pauseAt) {
                // Two years in: the older emails wait until every folder has its two years.
                ecw_db()->prepare('UPDATE `' . ecw_table('mailbox_sync_state') . '` SET recent_done_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $stateId]);
                $counts['paused'] = true;
                break;
            }
        }

        if ($oldest !== null) {
            ecw_mailbox_record_history_progress($mailboxId, $oldest, $counts['complete']);
        }
        ecw_mailbox_mark_usable($mailboxId);
        ecw_sync_record_success($stateId);
    } catch (Throwable $e) {
        ecw_sync_record_error($stateId, $e->getMessage());
        ecw_mailbox_record_failure($mailboxId, $e);
        throw $e;
    } finally {
        ecw_sync_release_lease($stateId, $lease);
    }

    return $counts;
}

/** The date before which history is "older" (UTC, Y-m-d H:i:s). */
function ecw_history_recent_cutoff(): string
{
    return gmdate('Y-m-d H:i:s', time() - ECW_HISTORY_RECENT_DAYS * 86400);
}

/** Has this folder's walk reached two years back (or its end)? */
function ecw_folder_recent_complete(int $mailboxId, int $folderId): bool
{
    $st = ecw_db()->prepare('SELECT (cursor_encrypted IS NULL AND last_success_at IS NOT NULL) OR recent_done_at IS NOT NULL FROM `' . ecw_table('mailbox_sync_state') . "`
                              WHERE mailbox_id = ? AND mail_folder_id = ? AND sync_type = 'backfill'");
    $st->execute([$mailboxId, $folderId]);
    return (bool)$st->fetchColumn();
}

/**
 * Where the history import of a mailbox stands: 'recent' (the last two years
 * still coming in), 'older' (the two years are complete in every synced
 * folder, older emails are coming in the background) or 'done'.
 */
function ecw_history_phase(int $mailboxId): string
{
    require_once __DIR__ . '/folders.php';
    $phase = 'done';
    foreach (ecw_selected_folders($mailboxId) as $f) {
        if (ecw_folder_history_complete($mailboxId, (int)$f['id'])) continue;
        if (!ecw_folder_recent_complete($mailboxId, (int)$f['id'])) return 'recent';
        $phase = 'older';
    }
    return $phase;
}

/** Every folder has its two years: queue the older emails of the folders that paused, in import order. */
function ecw_history_start_older(int $mailboxId): int
{
    require_once __DIR__ . '/queue.php';
    if (ecw_history_phase($mailboxId) !== 'older') return 0;
    $n = 0;
    foreach (ecw_selected_folders($mailboxId) as $f) {
        if (!ecw_folder_history_complete($mailboxId, (int)$f['id'])) {
            ecw_queue_enqueue($mailboxId, (int)$f['id'], 'backfill', 'older');
            $n++;
        }
    }
    return $n;
}

/**
 * Has this folder's newest-first history walk reached its end? (It ran
 * successfully and has no place left to continue from.) A folder never walked,
 * or one whose walk stopped half-way, is not complete.
 */
function ecw_folder_history_complete(int $mailboxId, int $folderId): bool
{
    $st = ecw_db()->prepare('SELECT cursor_encrypted IS NULL AND last_success_at IS NOT NULL FROM `' . ecw_table('mailbox_sync_state') . "`
                              WHERE mailbox_id = ? AND mail_folder_id = ? AND sync_type = 'backfill'");
    $st->execute([$mailboxId, $folderId]);
    return (bool)$st->fetchColumn();
}

/**
 * Lazy body loading. Newest first, because that is what a user opens.
 *
 * Deliberately separate from the metadata sync: pulling every body during the
 * initial import would balloon the payload and delay the moment the mailbox
 * becomes usable, which is the one thing the step optimises for.
 */
function ecw_sync_bodies(int $mailboxId, int $limit = 50): array
{
    $provider = ecw_provider_for_mailbox($mailboxId);
    $messages = ecw_table('email_messages');

    $st = ecw_db()->prepare(
        "SELECT id, provider_message_id FROM `{$messages}`
          WHERE mailbox_id = ? AND body_fetch_state = 'not_fetched' AND provider_removed_at IS NULL
          ORDER BY occurred_at DESC LIMIT " . max(1, min($limit, 200))
    );
    $st->execute([$mailboxId]);
    $rows = $st->fetchAll();

    $counts = ['fetched' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        try {
            ecw_store_full_message($mailboxId, (int)$row['id'], (string)$row['provider_message_id'], $provider);
            $counts['fetched']++;
        } catch (Throwable $e) {
            ecw_db()->prepare("UPDATE `{$messages}` SET body_fetch_state = 'fetch_failed' WHERE id = ?")
                ->execute([(int)$row['id']]);
            ecw_log("body fetch failed for message {$row['id']}: " . $e->getMessage());
            $counts['failed']++;
        }
    }

    // Emails whose text came with the list still need their attachments.
    $counts += ecw_sync_attachments($mailboxId, $limit, $provider);
    return $counts;
}

/**
 * Attachment lists (files, and the pictures inside the text) for emails whose
 * text arrived with the import page (attachments_state = 'pending'). Newest
 * first, one Graph call each; never fetches the text again.
 */
function ecw_sync_attachments(int $mailboxId, int $limit = 50, ?MailProvider $provider = null): array
{
    $provider ??= ecw_provider_for_mailbox($mailboxId);
    $messages = ecw_table('email_messages');
    $st = ecw_db()->prepare(
        "SELECT id, provider_message_id FROM `{$messages}`
          WHERE mailbox_id = ? AND attachments_state = 'pending' AND provider_removed_at IS NULL
          ORDER BY occurred_at DESC LIMIT " . max(1, min($limit, 200))
    );
    $st->execute([$mailboxId]);
    $counts = ['attachments_listed' => 0, 'attachments_failed' => 0];
    $mark = ecw_db()->prepare("UPDATE `{$messages}` SET attachments_state = ? WHERE id = ?");
    foreach ($st->fetchAll() as $row) {
        try {
            ecw_ingest_attachments((int)$row['id'], $provider->listAttachments((string)$row['provider_message_id']));
            $mark->execute(['done', (int)$row['id']]);
            $counts['attachments_listed']++;
        } catch (Throwable $e) {
            $mark->execute(['failed', (int)$row['id']]);
            ecw_log("attachment list failed for message {$row['id']}: " . $e->getMessage());
            $counts['attachments_failed']++;
        }
    }
    return $counts;
}

/** Sparse updates for an unknown/damaged row need an authoritative timestamp. */
function ecw_sync_complete_metadata(MailProvider $provider, int $mailboxId, array $msg): array
{
    if (!empty($msg['removed']) || !empty($msg['is_draft']) || !empty($msg['sent_at']) || !empty($msg['received_at']) || empty($msg['provider_message_id'])) return $msg;
    $st = ecw_db()->prepare('SELECT occurred_at FROM `' . ecw_table('email_messages') . '` WHERE mailbox_id = ? AND provider_message_id = ?');
    $st->execute([$mailboxId, $msg['provider_message_id']]);
    if ($st->fetchColumn()) return $msg;
    return $provider->getMessage($msg['provider_message_id']);
}

/**
 * Fetch one message in full and store its body AND its headers.
 *
 * The single place that turns a metadata-only row into a complete one, shared
 * by the background body backfill and the on-demand fetch behind the reading
 * pane — they used to be two copies of this UPDATE, and only one of them would
 * have learned about the headers.
 *
 * Headers matter here because bulk sync no longer asks for them: they cost
 * roughly 6x on a list call (see MicrosoftGraphProvider::SELECT). This is where
 * In-Reply-To arrives, so the case timeline's "Replying to" reference fills in
 * for every message someone actually opens.
 */
function ecw_store_full_message(int $mailboxId, int $messageId, string $providerMessageId, ?MailProvider $provider = null): void
{
    $provider ??= ecw_provider_for_mailbox($mailboxId);
    $messages = ecw_table('email_messages');

    $full  = $provider->getMessage($providerMessageId);
    $split = ecw_split_quoted((string)($full['body_html'] ?? ''), (string)($full['body_text'] ?? ''));

    // COALESCE on the header columns: a later fetch must never blank a value an
    // earlier one already established.
    ecw_db()->prepare(
        "UPDATE `{$messages}`
            SET body_html = ?, body_text = ?, quoted_html = ?, quoted_text = ?,
                body_fetch_state = 'fetched', attachments_state = 'done',
                sent_at = COALESCE(?, sent_at), received_at = COALESCE(?, received_at),
                in_reply_to_message_id = COALESCE(?, in_reply_to_message_id),
                references_json        = COALESCE(?, references_json)
          WHERE id = ?"
    )->execute([
        $split['body_html'], $split['body_text'],
        $split['quoted_html'], $split['quoted_text'],
        $full['sent_at'] ?? null, $full['received_at'] ?? null,
        $full['in_reply_to_message_id'] ?? null,
        !empty($full['references']) ? json_encode(array_values((array)$full['references']), JSON_UNESCAPED_SLASHES) : null,
        $messageId,
    ]);

    // The text is here now: an email naming a case number or a case's reference
    // in its text joins that case (lib/automation.php). Never fatal.
    try {
        $st = ecw_db()->prepare("SELECT m.email_thread_id, m.subject, mb.organization_id, mb.connected_by_user_id FROM `{$messages}` m
                                   JOIN `" . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id WHERE m.id = ?');
        $st->execute([$messageId]);
        if ($row = $st->fetch()) {
            require_once dirname(__DIR__) . '/automation.php';
            ecw_automation_link_auto((int)$row['organization_id'], $row['connected_by_user_id'] !== null ? (int)$row['connected_by_user_id'] : null,
                (int)$row['email_thread_id'], (string)$row['subject'], trim($split['body_text'] . "\n" . $split['quoted_text']));
        }
    } catch (Throwable $e) {
        ecw_log('linking by number after the body arrived failed: ' . $e->getMessage());
    }
    // Attachment metadata arrives with the full message; inline images cannot be
    // resolved without it.
    //
    // Not fatal. The body is already stored by the time this runs, and letting
    // an attachment-listing failure propagate marked the message
    // 'fetch_failed' — so a body that had been fetched perfectly well was
    // flagged broken and re-fetched forever. The attachment list is worth
    // retrying; the body is not worth losing over it.
    if (!empty($full['has_attachments']) || stripos((string)($full['body_html'] ?? ''), 'cid:') !== false) {
        try {
            ecw_ingest_attachments($messageId, $provider->listAttachments($providerMessageId));
        } catch (Throwable $e) {
            ecw_log("attachment metadata unavailable for message {$messageId}: " . $e->getMessage());
            // Worth another try by the background job (ecw_sync_attachments).
            ecw_db()->prepare("UPDATE `{$messages}` SET attachments_state = 'pending' WHERE id = ?")->execute([$messageId]);
        }
    }
}

/**
 * Record an unprocessable message so the cursor may advance past it.
 *
 * This is the only sanctioned way a message leaves the pipeline without a row
 * in email_messages. It is never silent: the row is visible, counted, and
 * carries the error.
 */
function ecw_dead_letter(int $mailboxId, ?int $folderId, array $msg, Throwable $e): void
{
    $payload = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ecw_db()->prepare(
        'INSERT INTO `' . ecw_table('message_dead_letters') . '`
            (mailbox_id, mail_folder_id, provider_message_id, internet_message_id,
             error_class, error_message, payload_excerpt, retry_count, first_seen_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE
            error_class = VALUES(error_class), error_message = VALUES(error_message),
            payload_excerpt = VALUES(payload_excerpt),
            retry_count = retry_count + 1, last_seen_at = VALUES(last_seen_at)'
    )->execute([
        $mailboxId,
        $folderId,
        (string)($msg['provider_message_id'] ?? 'unknown'),
        $msg['internet_message_id'] ?? null,
        substr(get_class($e), 0, 64),
        substr(ecw_redact($e->getMessage()), 0, 2000),
        substr(ecw_redact((string)$payload), 0, 60000),
        ecw_now(),
        ecw_now(),
    ]);

    ecw_log('dead-lettered message ' . (string)($msg['provider_message_id'] ?? 'unknown') . ': ' . $e->getMessage());
}

// --------------------------------------------------------- mailbox progress

/**
 * A round finished cleanly.
 *
 * This also CLEARS the status message, which matters more than it looks: the
 * message is whatever last wrote to it, so a mailbox that had been told
 * "Synchronization queued" kept saying that forever afterwards — including
 * while it was demonstrably up to date. A status line that survives the
 * condition it describes trains people to ignore the status line.
 *
 * A mailbox still doing its first import stays 'syncing'; one that was in
 * temp_error has just proved otherwise, so it goes back to connected.
 */
function ecw_mailbox_mark_synced(int $mailboxId): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailboxes') . '`
            SET last_sync_at = ?,
                status_message = NULL,
                sync_status = CASE
                    WHEN initial_sync_completed_at IS NULL THEN sync_status
                    WHEN sync_status IN (?, ?, ?) THEN ?
                    ELSE sync_status END
          WHERE id = ?'
    )->execute([
        ecw_now(),
        ECW_MAILBOX_SYNCING, ECW_MAILBOX_TEMP_ERROR, ECW_MAILBOX_PENDING,
        ECW_MAILBOX_CONNECTED,
        $mailboxId,
    ]);
}

/** The first history page is what makes the mailbox worth opening. */
function ecw_mailbox_mark_usable(int $mailboxId): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailboxes') . '`
            SET initial_sync_completed_at = COALESCE(initial_sync_completed_at, ?),
                sync_status = CASE WHEN sync_status IN (?, ?) THEN ? ELSE sync_status END,
                last_sync_at = ?
          WHERE id = ?'
    )->execute([
        ecw_now(),
        ECW_MAILBOX_PENDING, ECW_MAILBOX_SYNCING, ECW_MAILBOX_CONNECTED,
        ecw_now(), $mailboxId,
    ]);
}

function ecw_mailbox_record_history_progress(int $mailboxId, string $oldestSeen, bool $complete): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailboxes') . '`
            SET history_synced_through = LEAST(COALESCE(history_synced_through, ?), ?),
                historical_sync_complete = GREATEST(historical_sync_complete, ?)
          WHERE id = ?'
    )->execute([$oldestSeen, $oldestSeen, $complete ? 1 : 0, $mailboxId]);
}
