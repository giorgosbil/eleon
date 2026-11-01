<?php declare(strict_types=1);
/**
 * Case number and case tags show in Outlook as categories (Gmail: labels
 * "Case/CASE-00042", "Case/urgent") on every email of the case.
 *
 * Whenever a case's tags change, or a conversation joins or leaves a case, its
 * emails are marked categories_push_pending and the sync daemon's
 * "categories" job sends the change. Only categories the app itself set are
 * ever removed (app_categories_json); people's own Outlook categories stay.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

const ECW_CATEGORIES_MAX = 6;

/** Mark these conversations' emails for a categories update, and queue the job. */
function ecw_categories_mark_threads(array $threadIds): void
{
    $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
    if ($threadIds === []) return;
    try {
        $in = implode(',', $threadIds);
        ecw_db()->exec('UPDATE `' . ecw_table('email_messages') . "` SET categories_push_pending = 1
                         WHERE email_thread_id IN ({$in}) AND provider_removed_at IS NULL AND direction <> 'draft'");
        require_once __DIR__ . '/sync/queue.php';
        foreach (ecw_db()->query('SELECT DISTINCT mailbox_id FROM `' . ecw_table('email_threads') . "` WHERE id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN) as $mb) {
            ecw_queue_enqueue((int)$mb, null, 'categories', 'user');
        }
    } catch (Throwable $e) {
        ecw_log('categories mark failed: ' . $e->getMessage());   // before migration 030, or no queue
    }
}

function ecw_categories_mark_case(int $caseId): void
{
    $st = ecw_db()->prepare('SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?');
    $st->execute([$caseId]);
    ecw_categories_mark_threads($st->fetchAll(PDO::FETCH_COLUMN));
}

/** What a conversation's emails should carry: [case number, tags…], or nothing outside a case. */
function ecw_categories_desired(int $threadId): array
{
    $st = ecw_db()->prepare('SELECT c.id, c.case_number FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
                              WHERE ct.email_thread_id = ? AND c.deleted_at IS NULL LIMIT 1');
    $st->execute([$threadId]);
    $case = $st->fetch();
    if (!$case) return [];
    require_once __DIR__ . '/case_extras.php';
    return array_slice(array_values(array_unique(array_merge([(string)$case['case_number']], ecw_case_tags((int)$case['id'])))), 0, ECW_CATEGORIES_MAX);
}

/**
 * The daemon's "categories" job: send pending changes for one mailbox.
 * @return array{pushed:int, failed:int}
 */
function ecw_categories_push(int $mailboxId, int $limit = 100): array
{
    require_once __DIR__ . '/mailbox.php';
    $st = ecw_db()->prepare('SELECT id, email_thread_id, provider_message_id, app_categories_json FROM `' . ecw_table('email_messages') . '`
                              WHERE mailbox_id = ? AND categories_push_pending BETWEEN 1 AND 3 ORDER BY id LIMIT ' . max(1, min(500, $limit)));
    $st->execute([$mailboxId]);
    $rows = $st->fetchAll();
    if ($rows === []) return ['pushed' => 0, 'failed' => 0];
    $provider = ecw_provider_for_mailbox($mailboxId);
    $done = ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET app_categories_json = ?, categories_push_pending = 0 WHERE id = ?');
    $desiredByThread = [];
    $pushed = 0; $failed = 0;
    foreach ($rows as $r) {
        $t = (int)$r['email_thread_id'];
        $desired = $desiredByThread[$t] ??= ecw_categories_desired($t);
        $before = json_decode((string)$r['app_categories_json'], true) ?: [];
        $add = array_values(array_diff($desired, $before));
        $remove = array_values(array_diff($before, $desired));
        try {
            if ($add !== [] || $remove !== []) $provider->setCategories((string)$r['provider_message_id'], $add, $remove);
            $done->execute([$desired === [] ? null : json_encode($desired, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
            $pushed++;
        } catch (Throwable $e) {
            $failed++;
            ecw_log("categories push failed for message {$r['id']}: " . $e->getMessage());
            if (str_contains($e->getMessage(), '404')) { $done->execute([null, (int)$r['id']]); continue; }   // gone from the mailbox: nothing to do
            // Counts tries: 1 → 2 → 3 → 4, and 4 is not picked up again (no endless retry).
            ecw_db()->prepare('UPDATE `' . ecw_table('email_messages') . '` SET categories_push_pending = categories_push_pending + 1 WHERE id = ?')->execute([(int)$r['id']]);
        }
    }
    return ['pushed' => $pushed, 'failed' => $failed];
}
