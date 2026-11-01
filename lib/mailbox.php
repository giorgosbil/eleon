<?php declare(strict_types=1);
/**
 * Email Case Workspace — mailbox state and token lifecycle.
 *
 * Owns three things nothing else may duplicate:
 *   1. the encrypted OAuth tokens and when to refresh them,
 *   2. the visible mailbox status ("never silently stop syncing"),
 *   3. building a MailProvider bound to a specific mailbox.
 *
 * REFRESH HAPPENS UNDER THE TOKEN SUPPLIER, NOT AT CALL SITES
 * -----------------------------------------------------------
 * The provider is handed a closure, not a token string. Every Graph request
 * therefore asks for a token at the moment it needs one, and a long sync whose
 * access token expires mid-run refreshes transparently instead of dying on
 * page 40. Call sites never see a token at all, which is also what keeps them
 * out of logs.
 *
 * WHY A FAILED REFRESH IS NOT AN EXCEPTION AT THE CALL SITE
 * ---------------------------------------------------------
 * invalid_grant means the user revoked consent, changed their password, or the
 * refresh token aged out. No retry fixes it, so the mailbox is moved to
 * needs_reauth with a human-readable reason and the caller stops. A sync job
 * that treated this as a transient error would retry forever against a dead
 * grant and hide the one thing the user has to act on.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/GraphProductConfig.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/provider/MicrosoftGraphProvider.php';
require_once __DIR__ . '/provider/GmailProvider.php';

/** Refresh when less than this much life remains. */
const ECW_TOKEN_SKEW_SECONDS = 300;

/** Visible mailbox states, exactly the step's list plus the pre-OAuth one. */
const ECW_MAILBOX_PENDING      = 'pending';
const ECW_MAILBOX_CONNECTED    = 'connected';
const ECW_MAILBOX_SYNCING      = 'syncing';
const ECW_MAILBOX_NEEDS_REAUTH = 'needs_reauth';
const ECW_MAILBOX_TEMP_ERROR   = 'temp_error';
const ECW_MAILBOX_DISCONNECTED = 'disconnected';

/**
 * Test-only override, so the whole sync pipeline can be exercised without an
 * Azure app registration. Gated exactly like the Graph transport seam — CLI
 * plus an explicit ECW_TESTING — so a web request can never run on a fake
 * config.
 */
function ecw_set_graph_config_override(?array $config): void
{
    if (PHP_SAPI !== 'cli' || !defined('ECW_TESTING')) {
        throw new RuntimeException('ecw: the Graph config override is CLI-and-ECW_TESTING only');
    }
    $GLOBALS['ecw_graph_config_override'] = $config;
}

function ecw_graph_config(): array
{
    if (PHP_SAPI === 'cli' && defined('ECW_TESTING') && isset($GLOBALS['ecw_graph_config_override'])) {
        return $GLOBALS['ecw_graph_config_override'];
    }

    // Environment values permit deployment without editing the host application's files.
    // ECW_GRAPH_PERSONAL_ACCOUNTS=1: also personal Outlook.com / Hotmail accounts. The
    // app registration must then allow "personal Microsoft accounts" too.
    if (getenv('ECW_GRAPH_CLIENT_ID') !== false) {
        return ['client_id' => getenv('ECW_GRAPH_CLIENT_ID'),
            'client_secret' => getenv('ECW_GRAPH_CLIENT_SECRET') ?: '',
            'redirect_uri' => getenv('ECW_GRAPH_REDIRECT_URI') ?: '',
            'personal_accounts' => getenv('ECW_GRAPH_PERSONAL_ACCOUNTS') === '1'];
    }
    $config = ecw_config();
    $graph  = $config['ecw_graph'] ?? null;
    if (!is_array($graph) || ($graph['client_id'] ?? '') === '') {
        throw new RuntimeException(
            'Microsoft integration is not configured on this server.'
        );
    }
    return $graph + ['personal_accounts' => getenv('ECW_GRAPH_PERSONAL_ACCOUNTS') === '1'];
}

/**
 * Google OAuth client for Gmail: env ECW_GOOGLE_CLIENT_ID / _SECRET /
 * _REDIRECT_URI, else config ecw_google. The redirect URI defaults to the
 * Microsoft one: both providers return to the same web/oauth/callback.php.
 * Throws when Gmail is not set up.
 */
function ecw_google_config(): array
{
    if (PHP_SAPI === 'cli' && defined('ECW_TESTING') && isset($GLOBALS['ecw_google_config_override'])) {
        return $GLOBALS['ecw_google_config_override'];
    }
    if ((string)getenv('ECW_GOOGLE_CLIENT_ID') !== '') {
        $redirect = (string)(getenv('ECW_GOOGLE_REDIRECT_URI') ?: '');
        if ($redirect === '') {
            try { $redirect = (string)(ecw_graph_config()['redirect_uri'] ?? ''); } catch (Throwable $e) { $redirect = ''; }
        }
        return ['client_id' => (string)getenv('ECW_GOOGLE_CLIENT_ID'),
            'client_secret' => (string)(getenv('ECW_GOOGLE_CLIENT_SECRET') ?: ''), 'redirect_uri' => $redirect];
    }
    $google = ecw_config()['ecw_google'] ?? null;
    if (!is_array($google) || ($google['client_id'] ?? '') === '') {
        throw new RuntimeException('Gmail integration is not configured on this server.');
    }
    return $google;
}

/** Is a provider ('microsoft' | 'google') usable on this server? */
function ecw_provider_configured(string $provider): bool
{
    if ($provider === 'microsoft') {
        return GraphProductConfig::isConfigured();
    }
    try {
        $c = ecw_google_config();
    } catch (Throwable $e) {
        return false;
    }
    return ($c['client_id'] ?? '') !== '' && ($c['client_secret'] ?? '') !== '' && ($c['redirect_uri'] ?? '') !== '';
}

/** Human name of a provider, for messages. */
function ecw_provider_label(string $provider): string
{
    return $provider === 'google' ? 'Gmail' : 'Microsoft Outlook';
}

function ecw_mailbox(int $mailboxId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('mailboxes') . '` WHERE id = ?');
    $st->execute([$mailboxId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function ecw_mailbox_set_status(int $mailboxId, string $status, ?string $message = null): void
{
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('mailboxes') . '`
            SET sync_status = ?, status_message = ?, status_changed_at = ?
          WHERE id = ? AND (sync_status NOT IN (\'disconnected\', \'needs_reauth\') OR sync_status = ? OR ? = \'disconnected\')'
    )->execute([$status, $message, ecw_now(), $mailboxId, $status, $status]);

    ecw_log("mailbox {$mailboxId} -> {$status}" . ($message !== null ? ": {$message}" : ''));
}

/**
 * A valid access token for this mailbox, refreshing if it is close to expiry.
 *
 * @throws RuntimeException when the grant is dead; the mailbox is left in
 *         needs_reauth so the UI can ask the user to reconnect.
 */
function ecw_mailbox_access_token(int $mailboxId, bool $force = false): string
{
    $mb = ecw_mailbox($mailboxId);
    if ($mb === null) {
        throw new RuntimeException("ecw: mailbox {$mailboxId} not found");
    }

    $label = ecw_provider_label((string)($mb['provider'] ?? 'microsoft'));
    if (in_array($mb['sync_status'], [ECW_MAILBOX_DISCONNECTED, ECW_MAILBOX_NEEDS_REAUTH], true)) {
        throw new RuntimeException($label . ' needs to be reconnected.');
    }

    $expiresAt = $mb['token_expires_at'] ?? null;
    $fresh     = $expiresAt !== null && strtotime((string)$expiresAt . ' UTC') - time() > ECW_TOKEN_SKEW_SECONDS;

    if (!$force && $fresh) {
        $token = ecw_decrypt($mb['access_token_encrypted'] ?? null);
        if ($token !== null && $token !== '') {
            return $token;
        }
    }

    $refreshToken = ecw_decrypt($mb['refresh_token_encrypted'] ?? null);
    if ($refreshToken === null || $refreshToken === '') {
        ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_NEEDS_REAUTH, 'No refresh token stored. Reconnect the mailbox.');
        throw new RuntimeException("ecw: mailbox {$mailboxId} has no refresh token");
    }

    $provider = ($mb['provider'] ?? 'microsoft') === 'google'
        ? new GmailProvider(ecw_google_config())
        : new MicrosoftGraphProvider(ecw_graph_config() + ['account_tenant' => $mb['provider_tenant_id'] ?? 'organizations', 'shared_address' => (string)($mb['shared_address'] ?? '')]);
    try {
        $tokens = $provider->refreshTokens($refreshToken);
    } catch (GraphException $e) {
        if ($e->needsReauth()) {
            ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_NEEDS_REAUTH, 'Authorization expired or revoked. Reconnect the mailbox.');
        } else {
            ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_TEMP_ERROR, 'Token refresh failed, will retry.');
        }
        throw $e;
    }

    ecw_mailbox_store_tokens($mailboxId, $tokens);
    if (in_array(ecw_mailbox($mailboxId)['sync_status'] ?? 'disconnected', ['disconnected', 'needs_reauth'], true)) {
        throw new RuntimeException($label . ' needs to be reconnected.');
    }
    ecw_log("mailbox {$mailboxId} access token refreshed");

    return $tokens['access_token'];
}

/** Persist tokens, encrypted. A null refresh_token keeps the existing one. */
function ecw_mailbox_store_tokens(int $mailboxId, array $tokens): void
{
    $sql = 'UPDATE `' . ecw_table('mailboxes') . '`
               SET access_token_encrypted = ?, token_expires_at = ?, granted_scopes = ?';
    $params = [
        ecw_encrypt((string)$tokens['access_token']),
        (string)$tokens['expires_at'],
        (string)($tokens['scope'] ?? ''),
    ];

    if (!empty($tokens['refresh_token'])) {
        $sql      .= ', refresh_token_encrypted = ?';
        $params[]  = ecw_encrypt((string)$tokens['refresh_token']);
    }

    $sql     .= " WHERE id = ? AND sync_status <> 'disconnected'";
    $params[] = $mailboxId;

    ecw_db()->prepare($sql)->execute($params);
}

/** A provider bound to this mailbox, with transparent token refresh. */
function ecw_provider_for_mailbox(int $mailboxId): MailProvider
{
    $mb = ecw_mailbox($mailboxId);
    $tokens = static fn(): string => ecw_mailbox_access_token($mailboxId);
    if (($mb['provider'] ?? 'microsoft') === 'google') {
        return new GmailProvider(ecw_google_config() + ['account_email' => (string)($mb['email_address'] ?? '')], $tokens);
    }
    return new MicrosoftGraphProvider(ecw_graph_config() + ['shared_address' => (string)($mb['shared_address'] ?? '')], $tokens);
}

/**
 * An attachment's bytes from its mailbox ($a: id, mailbox_id, provider_message_id,
 * provider_attachment_id). Outlook gives an email a new id when it moves to another
 * folder, and its attachments new ids with it; the sync keeps the email's row and the
 * attachment ids it had, so an old one answers "not found". Then the email's attachments
 * are listed again, each stored one gets its new id, and the download is tried once more.
 */
function ecw_download_attachment(array $a): string
{
    $provider = ecw_provider_for_mailbox((int)$a['mailbox_id']);
    $messageId = (string)$a['provider_message_id'];
    try {
        return $provider->downloadAttachment($messageId, (string)$a['provider_attachment_id']);
    } catch (GraphException $e) {
        if ($e->status !== 404 || empty($a['id'])) throw $e;
        $fresh = ecw_attachment_ids_refresh($provider->listAttachments($messageId), (int)$a['id']);
        if ($fresh === null || $fresh === (string)$a['provider_attachment_id']) throw $e;
        ecw_log('attachment ' . (int)$a['id'] . ': the email had moved, its file has a new id');
        return $provider->downloadAttachment($messageId, $fresh);
    }
}

/**
 * Gives every stored attachment of the email that holds $attachmentId the id it has now
 * in $listed (the provider's list): the same name, and the same picture id or size when
 * two share a name. @return the new id of $attachmentId, null when it was not found.
 */
function ecw_attachment_ids_refresh(array $listed, int $attachmentId): ?string
{
    $t = ecw_table('email_attachments');
    $st = ecw_db()->prepare("SELECT id, provider_attachment_id, filename, size_bytes, content_id FROM `{$t}`
                              WHERE email_message_id = (SELECT email_message_id FROM `{$t}` WHERE id = ?)");
    $st->execute([$attachmentId]);
    $update = ecw_db()->prepare("UPDATE `{$t}` SET provider_attachment_id = ? WHERE id = ?");
    $found = null;
    foreach ($st->fetchAll() as $row) {
        $same = array_values(array_filter($listed, static fn($l) => (string)$l['filename'] === (string)$row['filename']));
        if (count($same) > 1 && (string)$row['content_id'] !== '') {
            $same = array_values(array_filter($same, static fn($l) => (string)($l['content_id'] ?? '') === (string)$row['content_id']));
        }
        if (count($same) > 1) {
            $same = array_values(array_filter($same, static fn($l) => (int)$l['size_bytes'] === (int)$row['size_bytes']));
        }
        if (count($same) !== 1 || (string)$same[0]['provider_attachment_id'] === '') continue;   // not sure which one: left as it was
        $new = (string)$same[0]['provider_attachment_id'];
        if ($new !== (string)$row['provider_attachment_id']) {
            try { $update->execute([$new, (int)$row['id']]); } catch (PDOException $e) { continue; }   // another row has it already
        }
        if ((int)$row['id'] === $attachmentId) $found = $new;
    }
    return $found;
}

/** A provider with no mailbox yet — only the OAuth handshake can use this. */
function ecw_provider_unauthenticated(string $provider = 'microsoft'): MailProvider
{
    return $provider === 'google' ? new GmailProvider(ecw_google_config()) : new MicrosoftGraphProvider(ecw_graph_config());
}

/**
 * Record a provider failure against the mailbox without losing the reason.
 *
 * Transient failures keep the mailbox usable and let the queue retry; a dead
 * grant is terminal until the user reconnects.
 */
function ecw_mailbox_record_failure(int $mailboxId, Throwable $e): void
{
    if ($e instanceof GraphException && $e->needsReauth()) {
        ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_NEEDS_REAUTH, 'Authorization expired or revoked. Reconnect the mailbox.');
        return;
    }
    $reason = $e instanceof GraphException ? "Provider error {$e->status} {$e->graphCode}" : 'Sync error';
    ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_TEMP_ERROR, $reason . '. Retrying automatically.');
}
