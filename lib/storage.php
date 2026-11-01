<?php declare(strict_types=1);
/**
 * Email Case Workspace — keeping the disk small.
 *
 * What takes space, and why it stays small:
 *   email text          the database (ecw_email_messages): about 20 KB an email
 *                       with its search index. Kept: it is the mail.
 *   attachment files    storage/attachments: a cache only. The file stays in
 *                       Outlook / Gmail; it is downloaded when someone opens
 *                       it. The text inside PDFs and Office files is read
 *                       once for search and the AI and kept in the database,
 *                       without keeping the file (lib/attachment_text.php).
 *   cache clean-up      ecw_attachment_cache_prune(): files nobody opened for
 *                       ECW_ATTACHMENT_CACHE_DAYS are deleted from the disk
 *                       (opening one downloads it again). Pictures inside
 *                       emails stay (every email shows them), and so does
 *                       everything in a case on legal hold.
 *   sync jobs           finished ones are deleted after a day (lib/sync/queue.php).
 *
 * ecw_storage_sizes() is what System health shows.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

const ECW_ATTACHMENT_CACHE_DAYS = 7;

/** Delete cached attachment files not used for a while. @return array{files:int, bytes:int} */
function ecw_attachment_cache_prune(int $days = ECW_ATTACHMENT_CACHE_DAYS, int $limit = 500): array
{
    $A = ecw_table('email_attachments');
    $rows = ecw_db()->query(
        'SELECT a.id, a.local_path FROM `' . $A . '` a
           JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
          WHERE a.local_path IS NOT NULL AND a.is_inline = 0
            AND NOT EXISTS (SELECT 1 FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
                             WHERE ct.email_thread_id = m.email_thread_id AND c.legal_hold_at IS NOT NULL)
          ORDER BY a.id LIMIT ' . max(1, $limit * 4)
    )->fetchAll();
    $cutoff = time() - $days * 86400;
    $clear = ecw_db()->prepare('UPDATE `' . $A . "` SET local_path = NULL, provider_download_state = 'not_downloaded' WHERE id = ? AND local_path = ?");
    $files = 0; $bytes = 0;
    foreach ($rows as $r) {
        if ($files >= $limit) break;
        $path = (string)$r['local_path'];
        if (is_file($path)) {
            // Last used: opened (read) or downloaded, whichever is later.
            $used = max((int)@filemtime($path), (int)@fileatime($path));
            if ($used > $cutoff) continue;
            $size = (int)@filesize($path);
            if (!@unlink($path)) continue;
            $bytes += $size;
        }
        $clear->execute([(int)$r['id'], $path]);
        $files++;
    }
    if ($files > 0) ecw_log("storage: removed {$files} cached attachment files (" . round($bytes / 1048576, 1) . ' MB) not used for ' . $days . ' days');
    return ['files' => $files, 'bytes' => $bytes];
}

/** Hourly housekeeping (bin/worker.php). */
function ecw_storage_housekeeping_due(): void
{
    static $last = 0;
    if (time() - $last < 3600) return;
    $last = time();
    ecw_attachment_cache_prune();
}

/**
 * How big the workspace is, for System health.
 * @return array{db_bytes:int, mail_bytes:int, emails:int, files_bytes:int, files:int, per_mailbox:list<array>}
 */
function ecw_storage_sizes(): array
{
    $pdo = ecw_db();
    $db = (int)$pdo->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
    $mail = (int)$pdo->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '" . ecw_table('email_messages') . "'")->fetchColumn();
    $per = $pdo->query('SELECT mb.id, mb.email_address, COUNT(m.id) AS emails, MIN(m.occurred_at) AS oldest
                          FROM `' . ecw_table('mailboxes') . '` mb LEFT JOIN `' . ecw_table('email_messages') . '` m ON m.mailbox_id = mb.id
                         GROUP BY mb.id, mb.email_address ORDER BY mb.id')->fetchAll();
    $files = 0; $filesBytes = 0;
    $dir = dirname(__DIR__) . '/storage/attachments';
    if (is_dir($dir)) {
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $f) {
            if ($f->isFile()) { $files++; $filesBytes += $f->getSize(); }
        }
    }
    return [
        'db_bytes'    => $db,
        'mail_bytes'  => $mail,
        'emails'      => (int)array_sum(array_column($per, 'emails')),
        'files_bytes' => $filesBytes,
        'files'       => $files,
        'per_mailbox' => array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => (string)$r['email_address'], 'emails' => (int)$r['emails'], 'oldest' => $r['oldest']], $per),
    ];
}
