<?php declare(strict_types=1);
/**
 * Email Case Workspace — what each queue job actually does.
 *
 * Extracted from sync_worker.php so the worker and the continuous daemon share
 * ONE definition. Two copies of this switch would eventually disagree about
 * something small — the backfill re-queue, or the disconnected-mailbox guard —
 * and the two ways of running a sync would quietly behave differently.
 */

require_once dirname(__DIR__) . '/lib/sync/queue.php';
require_once dirname(__DIR__) . '/lib/sync/messages.php';
require_once dirname(__DIR__) . '/lib/sync/folders.php';

/** One place that knows what each job type means. */
function ecw_run_sync_job(string $type, int $mailboxId, ?int $folderId): array
{
    $mailbox = ecw_mailbox($mailboxId);
    if ($mailbox === null || in_array($mailbox['sync_status'], ['disconnected', 'needs_reauth'], true)) {
        return ['skipped' => true, 'reason' => 'connection_inactive'];
    }
    switch ($type) {
        case 'folders':
            $result = ecw_sync_folders($mailboxId);
            // Discovery is what makes the first import possible, so it queues
            // the follow-on work itself rather than waiting for the next cron.
            // In import order (Inbox and Sent first). Only folders whose history is not
            // complete are walked again (new ones, an import cut short): Sync now on a
            // complete mailbox must not re-import every email from the start.
            // History in two steps: a folder that has its last two years waits for the
            // others, then all continue with the older emails (reason 'older').
            $phase = ecw_history_phase($mailboxId);
            foreach (ecw_selected_folders($mailboxId) as $folder) {
                $fid = (int)$folder['id'];
                if (!ecw_folder_history_complete($mailboxId, $fid)) {
                    if (!ecw_folder_recent_complete($mailboxId, $fid)) ecw_queue_enqueue($mailboxId, $fid, 'backfill', 'initial');
                    elseif ($phase === 'older') ecw_queue_enqueue($mailboxId, $fid, 'backfill', 'older');
                }
                ecw_queue_enqueue($mailboxId, $fid, 'delta', 'initial');
            }
            return $result;

        case 'delta':
            if ($folderId === null) {
                throw new InvalidArgumentException('delta job requires a folder');
            }
            return ecw_sync_folder_delta($mailboxId, $folderId);

        case 'backfill':
            if ($folderId === null) {
                throw new InvalidArgumentException('backfill job requires a folder');
            }
            $result = ecw_sync_history($mailboxId, $folderId);
            // Keep walking backwards until the folder is exhausted, one queued
            // chunk at a time, so no single run holds a lease for hours. A folder
            // that reached two years back pauses; the last folder to get there
            // starts the older emails of all of them.
            if (!empty($result['paused']) || !empty($result['complete'])) {
                $result['older_queued'] = ecw_history_start_older($mailboxId);
            } elseif (empty($result['skipped'])) {
                ecw_queue_enqueue($mailboxId, $folderId, 'backfill', ecw_folder_recent_complete($mailboxId, $folderId) ? 'older' : 'cron', 5);
            }
            return $result;

        case 'bodies':
            return ecw_sync_bodies($mailboxId);

        case 'drafts_push':
            // Drafts written in the app, copied to the mailbox's Drafts folder.
            require_once dirname(__DIR__) . '/lib/drafts_sync.php';
            return ecw_drafts_push($mailboxId);

        case 'read_state':
            // Read/unread changed in the workspace, sent on to Outlook / Gmail.
            require_once dirname(__DIR__) . '/lib/mail.php';
            $result = ecw_push_read_state($mailboxId);
            if ($result['failed'] > 0 || $result['pushed'] >= 200) {
                ecw_queue_enqueue($mailboxId, null, 'read_state', 'retry', $result['failed'] > 0 ? 60 : 0);
            }
            return $result;

        case 'categories':
            // Case number and tags as Outlook categories / Gmail labels.
            require_once dirname(__DIR__) . '/lib/categories.php';
            $result = ecw_categories_push($mailboxId);
            if ($result['pushed'] >= 100 || $result['failed'] > 0) {
                ecw_queue_enqueue($mailboxId, null, 'categories', 'retry', $result['failed'] > 0 ? 120 : 0);
            }
            return $result;

        default:
            throw new InvalidArgumentException("unknown job type {$type}");
    }
}
