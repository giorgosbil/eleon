<?php declare(strict_types=1);
/**
 * Email Case Workspace — "someone else is replying" presence.
 *
 * The composer sends a heartbeat every 20 seconds while a reply to a thread is
 * open; anyone else viewing that thread sees who is replying. A heartbeat older
 * than ECW_PRESENCE_TTL is ignored, so a closed tab clears itself.
 */

require_once __DIR__ . '/db.php';

const ECW_PRESENCE_TTL = 45;

function ecw_presence_beat(int $userId, int $threadId): void
{
    ecw_db()->prepare(
        'INSERT INTO `' . ecw_table('presence') . "` (user_id, thread_id, activity, updated_at) VALUES (?, ?, 'replying', ?)
         ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)"
    )->execute([$userId, $threadId, ecw_now()]);
}

function ecw_presence_clear(int $userId, int $threadId): void
{
    ecw_db()->prepare('DELETE FROM `' . ecw_table('presence') . '` WHERE user_id = ? AND thread_id = ?')->execute([$userId, $threadId]);
}

/** @return string[] names of other people replying to the thread right now */
function ecw_presence_others(int $threadId, int $userId): array
{
    $st = ecw_db()->prepare(
        'SELECT u.name, u.email FROM `' . ecw_table('presence') . '` p JOIN `' . ecw_table('users') . '` u ON u.id = p.user_id
          WHERE p.thread_id = ? AND p.user_id <> ? AND p.updated_at >= ?'
    );
    $st->execute([$threadId, $userId, gmdate('Y-m-d H:i:s', time() - ECW_PRESENCE_TTL)]);
    return array_map(static fn(array $u): string => (string)($u['name'] ?: $u['email']), $st->fetchAll());
}
