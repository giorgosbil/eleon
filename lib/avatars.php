<?php declare(strict_types=1);
/**
 * Email Case Workspace — user avatars from the user's own Outlook profile.
 *
 * Each person's photo is read with THEIR OWN mailbox token (/me/photos), so
 * the existing User.Read permission is enough: no directory-wide permission,
 * no admin consent. A user who has not connected a mailbox has no photo and
 * the UI falls back to initials.
 *
 * Photos live as files under storage/avatars (outside the web root) and are
 * served by web/avatar.php after an organization check. ecw_users.avatar_hash
 * doubles as a cache-buster in URLs; NULL means "no photo".
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/session.php';   // ecw_web_base(); defines functions only

const ECW_AVATAR_REFRESH_SECONDS = 86400;

function ecw_avatar_dir(): string
{
    return dirname(__DIR__) . '/storage/avatars';
}

function ecw_avatar_path(int $userId): string
{
    return ecw_avatar_dir() . '/u' . $userId . '.jpg';
}

/** Store (or clear, when $jpeg is null) a user's photo. */
function ecw_avatar_store(int $userId, ?string $jpeg): void
{
    $path = ecw_avatar_path($userId);
    $hash = null;
    if ($jpeg !== null && $jpeg !== '') {
        if (!is_dir(ecw_avatar_dir())) {
            @mkdir(ecw_avatar_dir(), 0750, true);
        }
        if (@file_put_contents($path . '.tmp', $jpeg) === false || !@rename($path . '.tmp', $path)) {
            throw new RuntimeException('ecw: could not write avatar file');
        }
        $hash = substr(hash('sha256', $jpeg), 0, 16);
    } elseif (is_file($path)) {
        @unlink($path);
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET avatar_hash = ?, avatar_checked_at = ? WHERE id = ?')
        ->execute([$hash, ecw_now(), $userId]);
}

/** Fetch with a provider that is already authenticated as $userId. Never throws. */
function ecw_avatar_refresh_with(int $userId, object $provider): void
{
    if (!method_exists($provider, 'photo')) {
        return;
    }
    try {
        ecw_avatar_store($userId, $provider->photo());
    } catch (Throwable $e) {
        ecw_log("avatar refresh failed for user {$userId}: " . $e->getMessage());
    }
}

/**
 * Daily refresh, called from the sync daemon's housekeeping. One mailbox per
 * user (their own connected one); users not checked for a day go first.
 */
function ecw_avatars_refresh_due(int $limit = 20): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - ECW_AVATAR_REFRESH_SECONDS);
    $st = ecw_db()->prepare(
        'SELECT u.id AS user_id, MIN(m.id) AS mailbox_id
           FROM `' . ecw_table('users') . '` u
           JOIN `' . ecw_table('mailboxes') . "` m ON m.connected_by_user_id = u.id
          WHERE m.sync_status IN ('connected','syncing','temp_error')
            AND (u.avatar_checked_at IS NULL OR u.avatar_checked_at < ?)
          GROUP BY u.id
          LIMIT " . max(1, $limit)
    );
    $st->execute([$cutoff]);
    foreach ($st->fetchAll() as $row) {
        try {
            ecw_avatar_refresh_with((int)$row['user_id'], ecw_provider_for_mailbox((int)$row['mailbox_id']));
        } catch (Throwable $e) {
            ecw_log("avatar refresh skipped for user {$row['user_id']}: " . $e->getMessage());
        }
    }
}

/** URL for <img src>, or null when the user has no photo (show initials). */
function ecw_avatar_url(int $userId, ?string $hash): ?string
{
    return $hash ? ecw_web_base() . 'avatar.php?u=' . $userId . '&v=' . rawurlencode($hash) : null;
}
