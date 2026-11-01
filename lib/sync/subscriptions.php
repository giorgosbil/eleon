<?php declare(strict_types=1);
/**
 * Email Case Workspace — Graph change notifications.
 *
 * NOTIFICATIONS ARE A TRIGGER, NOT A SOURCE OF TRUTH
 * --------------------------------------------------
 * A notification says "something changed in this folder". It never says what,
 * and it can be delayed, duplicated or dropped entirely. So the handler does
 * exactly one thing: enqueue a delta job. Delta then determines what actually
 * changed, and the periodic sweep re-runs delta whether or not a notification
 * ever arrived. Losing every notification for an hour costs latency, not data.
 *
 * THE CLIENTSTATE SECRET IS NEVER STORED
 * --------------------------------------
 * Graph echoes back the clientState we set at subscription time, and that echo
 * is the proof a notification is ours. Validation only ever compares, so the
 * table holds sha256(secret) and the handler hashes what arrives. Someone who
 * reads the database still cannot forge a notification.
 *
 * RENEWAL
 * -------
 * Graph caps a message subscription at just under three days and does not warn
 * before it lapses. The sweep renews anything expiring inside the buffer and
 * re-creates anything already dead, which is the "verify subscription health"
 * half of the step's periodic work.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../mailbox.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/folders.php';

/** Renew when less than this remains. */
const ECW_SUBSCRIPTION_RENEW_BUFFER = 6 * 3600;

function ecw_subscription_ensure(int $mailboxId, int $folderId, string $notificationUrl): array
{
    $pdo    = ecw_db();
    $folder = ecw_folder_row($folderId);
    if ($folder === null) {
        throw new RuntimeException("ecw: folder {$folderId} not found");
    }

    $st = $pdo->prepare(
        'SELECT * FROM `' . ecw_table('graph_subscriptions') . "`
          WHERE mailbox_id = ? AND resource LIKE ? AND status = 'active'"
    );
    $st->execute([$mailboxId, '%' . $folder['provider_folder_id'] . '%']);
    $existing = $st->fetch();

    if ($existing !== false && strtotime((string)$existing['expires_at'] . ' UTC') - time() > ECW_SUBSCRIPTION_RENEW_BUFFER) {
        return $existing;
    }

    $provider    = ecw_provider_for_mailbox($mailboxId);
    $clientState = ecw_new_client_state();
    $expiresAt   = gmdate('Y-m-d H:i:s', time() + $provider->maxSubscriptionSeconds() - 300);

    $created = $provider->createSubscription(
        (string)$folder['provider_folder_id'],
        $notificationUrl,
        $clientState,
        $expiresAt
    );

    $pdo->prepare(
        'INSERT INTO `' . ecw_table('graph_subscriptions') . '`
            (mailbox_id, provider, subscription_id, resource, notification_url,
             client_state_hash, expires_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "active")
         ON DUPLICATE KEY UPDATE
            resource = VALUES(resource), notification_url = VALUES(notification_url),
            client_state_hash = VALUES(client_state_hash), expires_at = VALUES(expires_at),
            status = "active", last_error = NULL'
    )->execute([
        $mailboxId,
        $provider->key(),
        $created['subscription_id'],
        $created['resource'],
        $notificationUrl,
        ecw_client_state_hash($clientState),
        $created['expires_at'],
    ]);

    ecw_log("subscription created for mailbox {$mailboxId} folder {$folderId}, expires {$created['expires_at']}");

    $st = $pdo->prepare('SELECT * FROM `' . ecw_table('graph_subscriptions') . '` WHERE provider = ? AND subscription_id = ?');
    $st->execute([$provider->key(), $created['subscription_id']]);
    return $st->fetch();
}

/** Renew everything close to expiry. Returns per-subscription outcomes. */
function ecw_subscriptions_renew_due(int $bufferSeconds = ECW_SUBSCRIPTION_RENEW_BUFFER): array
{
    $pdo = ecw_db();
    $st  = $pdo->prepare(
        'SELECT * FROM `' . ecw_table('graph_subscriptions') . "`
          WHERE status = 'active' AND expires_at <= ?"
    );
    $st->execute([gmdate('Y-m-d H:i:s', time() + $bufferSeconds)]);

    $results = [];
    foreach ($st->fetchAll() as $sub) {
        $mailboxId = (int)$sub['mailbox_id'];
        try {
            $provider  = ecw_provider_for_mailbox($mailboxId);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $provider->maxSubscriptionSeconds() - 300);
            $renewed   = $provider->renewSubscription((string)$sub['subscription_id'], $expiresAt);

            $pdo->prepare(
                'UPDATE `' . ecw_table('graph_subscriptions') . '`
                    SET expires_at = ?, last_renewed_at = ?, last_error = NULL, status = "active"
                  WHERE id = ? AND status <> "deleted"'
            )->execute([$renewed['expires_at'], ecw_now(), (int)$sub['id']]);

            $results[(int)$sub['id']] = 'renewed';
        } catch (Throwable $e) {
            // A subscription Graph has already dropped cannot be renewed. Mark
            // it expired so the ensure pass re-creates it rather than retrying
            // a PATCH against something that no longer exists.
            $pdo->prepare(
                'UPDATE `' . ecw_table('graph_subscriptions') . '`
                    SET status = "expired", last_error = ? WHERE id = ? AND status <> "deleted"'
            )->execute([substr(ecw_redact($e->getMessage()), 0, 2000), (int)$sub['id']]);

            ecw_log("subscription {$sub['subscription_id']} could not be renewed: " . $e->getMessage());
            $results[(int)$sub['id']] = 'expired';
        }
    }

    return $results;
}

/**
 * Handle one validated notification payload: find the subscription, check the
 * echoed clientState, enqueue a delta job. Never syncs inline.
 *
 * @return array{accepted:int, rejected:int, queued:int}
 */
function ecw_subscription_handle_notifications(array $payload): array
{
    $out = ['accepted' => 0, 'rejected' => 0, 'queued' => 0];
    $pdo = ecw_db();

    foreach ((array)($payload['value'] ?? []) as $notification) {
        $subscriptionId = (string)($notification['subscriptionId'] ?? '');
        $clientState    = (string)($notification['clientState'] ?? '');

        if ($subscriptionId === '') {
            $out['rejected']++;
            continue;
        }

        $st = $pdo->prepare('SELECT * FROM `' . ecw_table('graph_subscriptions') . '` WHERE subscription_id = ?');
        $st->execute([$subscriptionId]);
        $sub = $st->fetch();

        if ($sub === false || $sub['status'] !== 'active' || !ecw_client_state_matches($clientState, (string)$sub['client_state_hash'])) {
            // Unknown subscription, or a clientState that does not match: this
            // did not come from us. Logged without the value itself.
            // Worded to avoid the literal token names the redactor strips — this line
            // must stay readable in the log.
            ecw_log("rejected notification for subscription {$subscriptionId}: unknown subscription, or the echoed secret did not match");
            $out['rejected']++;
            continue;
        }

        $out['accepted']++;
        $pdo->prepare('UPDATE `' . ecw_table('graph_subscriptions') . '` SET last_notification_at = ? WHERE id = ?')
            ->execute([ecw_now(), (int)$sub['id']]);

        $folderId = ecw_folder_id_from_resource((int)$sub['mailbox_id'], (string)$sub['resource']);
        if (ecw_queue_enqueue((int)$sub['mailbox_id'], $folderId, 'delta', 'webhook') !== null) {
            $out['queued']++;
        }
    }

    return $out;
}

/** Pull the provider folder id out of "/me/mailFolders('AAA=')/messages". */
function ecw_folder_id_from_resource(int $mailboxId, string $resource): ?int
{
    if (!preg_match("/mailFolders[('\/]+([^')\/]+)/i", $resource, $m)) {
        return null;
    }
    $st = ecw_db()->prepare(
        'SELECT id FROM `' . ecw_table('mail_folders') . '` WHERE mailbox_id = ? AND provider_folder_id = ?'
    );
    $st->execute([$mailboxId, $m[1]]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Health, for the periodic sweep and for Settings.
 *
 * Silence is the signal worth surfacing: an active subscription that has not
 * delivered a notification in hours is the failure mode notifications actually
 * have, and it looks identical to a quiet mailbox until you compare against the
 * delta sync's own findings.
 */
function ecw_subscription_health(int $mailboxId): array
{
    $st = ecw_db()->prepare(
        'SELECT subscription_id, resource, status, expires_at, last_notification_at, last_error
           FROM `' . ecw_table('graph_subscriptions') . '` WHERE mailbox_id = ?'
    );
    $st->execute([$mailboxId]);
    $rows = $st->fetchAll();

    foreach ($rows as &$row) {
        $row['expires_in_seconds'] = strtotime((string)$row['expires_at'] . ' UTC') - time();
        $row['silent_for_seconds'] = $row['last_notification_at'] !== null
            ? time() - strtotime((string)$row['last_notification_at'] . ' UTC')
            : null;
    }
    return $rows;
}
