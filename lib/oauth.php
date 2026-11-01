<?php declare(strict_types=1);
/**
 * Email Case Workspace — the OAuth handshake.
 *
 * One interactive authorization, then the server keeps itself running on the
 * refresh token. Delegated permissions: every call runs as the user who
 * consented, against their own mailbox.
 *
 * STATE
 * -----
 * A random state is minted into the session and compared on return with
 * hash_equals. Without it, an attacker can hand a victim a callback URL
 * carrying their own authorization code and silently attach their mailbox to
 * the victim's organization. It is single-use: consumed on the first callback,
 * so a replayed callback URL fails.
 *
 * WHAT HAPPENS ON RETURN
 * ----------------------
 * Exchange the code, ask who signed in, upsert the mailbox for that account,
 * store both tokens encrypted, then queue folder discovery and the newest-first
 * import. The user is redirected immediately — the mailbox fills in behind them
 * rather than making them watch a sync run.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph_identity.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/sync/queue.php';

function ecw_oauth_redirect_uri(): string
{
    $configured = (string)(ecw_graph_config()['redirect_uri'] ?? '');
    if ($configured === '') {
        throw new RuntimeException('ecw: ecw_graph.redirect_uri is not configured');
    }
    return $configured;
}

/** Mint the state and hand back the URL to send the browser to. */
function ecw_oauth_begin(bool $teams = false, string $provider = 'microsoft', bool $calendar = false, bool $settings = false, ?string $sharedAddress = null): string
{
    $provider = $provider === 'google' ? 'google' : 'microsoft';
    $context = ecw_context();
    if ($context === null) { throw new RuntimeException('Sign in to connect a mailbox.'); }
    // A Microsoft 365 shared mailbox (info@…): admins only, reached through their own sign-in.
    $sharedAddress = $sharedAddress !== null ? strtolower(trim($sharedAddress)) : null;
    if ($sharedAddress !== null) {
        if ($provider !== 'microsoft' || ($context['role'] ?? '') !== 'admin') throw new InvalidArgumentException('Only an admin can connect a shared mailbox.');
        if (!filter_var($sharedAddress, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Type the shared mailbox address, e.g. info@yourcompany.com.');
        $teams = $calendar = $settings = false;
    }
    if (!ecw_provider_configured($provider)) { throw new RuntimeException(ecw_provider_label($provider) . ' integration unavailable.'); }
    // One permission screen instead of one per feature: connecting an Outlook mailbox asks at once
    // for the calendar and automatic replies too, when the company uses them (Feature access).
    // Both work for every Microsoft account. Teams chats stay their own step: personal accounts
    // (Hotmail, Outlook.com) have none, and asking for them would make that sign-in fail.
    if ($provider === 'microsoft' && $sharedAddress === null && function_exists('ecw_feature_allowed')) {
        $calendar = $calendar || ecw_feature_allowed('calendar');
        $settings = $settings || ecw_feature_allowed('auto_reply');
    }
    $state = bin2hex(random_bytes(32));
    $verifier = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $_SESSION['ecw_oauth_state'] = $state;
    $_SESSION['ecw_oauth_state_at'] = time();
    $_SESSION['ecw_oauth_handshake'] = [
        'user_id' => (int)$context['user']['id'], 'organization_id' => $context['organization_id'],
        'verifier' => $verifier, 'nonce' => $nonce, 'teams' => $teams && $provider === 'microsoft', 'provider' => $provider,
        'calendar' => $calendar && $provider === 'microsoft',
        'settings' => $settings,
        'shared_address' => $sharedAddress,
    ];
    if ($provider === 'google') {
        return ecw_provider_unauthenticated('google')->authorizationUrl($state, (string)ecw_google_config()['redirect_uri'], $verifier, $nonce);
    }
    return (new MicrosoftGraphProvider(ecw_graph_config() + ['shared_address' => (string)$sharedAddress]))->authorizationUrl($state, ecw_oauth_redirect_uri(), $verifier, $nonce, $teams, $calendar, $settings);
}

/** Display only fixed application messages; never reflect provider descriptions. */
function ecw_oauth_error_state(string $error, string $description): string
{
    // Microsoft also returns 65004 after submitting an admin approval request.
    if (preg_match('/AADSTS65004\b/i', $description)) { return 'consent_incomplete'; }
    if (in_array($error, ['consent_required', 'admin_consent_required'], true)
        || preg_match('/AADSTS(?:65001|90094|90093|900941|900981)\b/i', $description)) {
        return 'approval_required';
    }
    return $error === 'access_denied' ? 'denied' : 'error';
}

/** Single-use, time-boxed, constant-time. */
function ecw_oauth_validate_state(string $received, int $maxAgeSeconds = 900): bool
{
    ecw_session_start();
    $expected = (string)($_SESSION['ecw_oauth_state'] ?? '');
    $issuedAt = (int)($_SESSION['ecw_oauth_state_at'] ?? 0);

    unset($_SESSION['ecw_oauth_state'], $_SESSION['ecw_oauth_state_at'], $_SESSION['ecw_oauth_handshake']);

    if ($expected === '' || $received === '') {
        return false;
    }
    if ($issuedAt <= 0 || $issuedAt > time() || time() - $issuedAt > $maxAgeSeconds) {
        return false;
    }
    return hash_equals($expected, $received);
}

/** Process only a callback bound to the currently authenticated application identity. */
function ecw_oauth_callback_result(array $context, array $query): string
{
    $handshake = $_SESSION['ecw_oauth_handshake'] ?? [];
    if (!ecw_oauth_validate_state((string)($query['state'] ?? ''))
        || ($handshake['user_id'] ?? null) !== (int)$context['user']['id']
        || ($handshake['organization_id'] ?? null) !== $context['organization_id']) {
        throw new InvalidArgumentException('This connection link has expired. Start again.');
    }
    if (!empty($query['error'])) {
        $result = ecw_oauth_error_state((string)$query['error'], (string)($query['error_description'] ?? ''));
        if ($result === 'approval_required') {
            $_SESSION['ecw_outlook_approval'][$context['organization_id']] = true;
        }
        return $result;
    }
    if (!empty($handshake['approval'])) { return 'approval_return'; }
    if (empty($query['code'])) { throw new InvalidArgumentException('Incomplete connection response. Start again.'); }
    $connectedId = ecw_oauth_complete($context['organization_id'], (int)$context['user']['id'], (string)$query['code'], $handshake);
    unset($_SESSION['ecw_outlook_approval'][$context['organization_id']]);
    // Work in the account just connected (lib/account.php): its folders and first import show at once.
    require_once __DIR__ . '/account.php';
    ecw_account_reset_cache();
    ecw_account_switch((int)$context['organization_id'], (int)$context['user']['id'], $connectedId);
    return 'connected';
}

/**
 * Finish the handshake.
 *
 * @return int the mailbox id
 */
function ecw_oauth_complete(int $organizationId, ?int $userId, string $code, ?array $handshake = null): int
{
    if (($handshake['provider'] ?? 'microsoft') === 'google') {
        return ecw_oauth_complete_google($organizationId, $userId, $code, $handshake);
    }
    $shared   = (string)($handshake['shared_address'] ?? '');
    $provider = new MicrosoftGraphProvider(ecw_graph_config() + ['shared_address' => $shared]);
    $tokens   = $provider->exchangeCode($code, ecw_oauth_redirect_uri(), $handshake['verifier'] ?? null);
    if (empty($tokens['refresh_token'])) { throw new RuntimeException('Microsoft did not grant background access.'); }
    $claims = ecw_graph_identity($tokens['id_token'], (string)ecw_graph_config()['client_id'], $handshake['nonce'] ?? null, !empty(ecw_graph_config()['personal_accounts']));

    // Identify the account with the token we were just handed, before any
    // mailbox row exists to refresh from. A shared mailbox: opening its Inbox
    // with this person's token proves they have Full Access to it.
    $identified = new MicrosoftGraphProvider(ecw_graph_config() + ['shared_address' => $shared], static fn(): string => $tokens['access_token']);
    try {
        $account = $identified->account();
    } catch (GraphException $e) {
        if ($shared !== '' && in_array($e->status, [403, 404], true)) throw new RuntimeException('shared_no_access');
        throw $e;
    }

    $account['tenant_id'] = $claims['tid'];
    if ($shared === '' && !ecw_graph_same_account((string)$account['account_id'], (string)$claims['oid'])) { throw new RuntimeException('Microsoft account mismatch.'); }

    if (($account['email'] ?? '') === '') {
        throw new RuntimeException('ecw: the provider returned no address for the signed-in account');
    }

    $pdo = ecw_db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) { $pdo->beginTransaction(); }
    try {
        // Serialize reconnects in this organization, including first-time inserts.
        $lock = $pdo->prepare('SELECT id FROM `' . ecw_table('organizations') . '` WHERE id = ?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $lock->execute([$organizationId]);
        $mailboxId = ecw_mailbox_upsert($organizationId, $userId, $provider->key(), $account, $tokens);
        // Explicit reconnect is the only operation allowed to revive a disconnected row.
        $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET sync_status = ? WHERE id = ?')
            ->execute([ECW_MAILBOX_SYNCING, $mailboxId]);
        if ($shared !== '') {
            $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET is_shared = 1, shared_address = ? WHERE id = ?')->execute([$shared, $mailboxId]);
        }
        ecw_mailbox_store_tokens($mailboxId, $tokens);
        ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_SYNCING, 'Connected. Importing mail.');
        ecw_queue_enqueue($mailboxId, null, 'folders', 'initial');
        if ($ownsTransaction) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownsTransaction) { $pdo->rollBack(); }
        throw $e;
    }

    ecw_log("mailbox {$mailboxId} connected for organization {$organizationId}");
    require_once __DIR__ . '/audit.php';
    ecw_audit('mailbox.connect', 'mailbox', $mailboxId, ['address' => (string)($account['email'] ?? ''), 'provider' => 'microsoft'] + ($shared !== '' ? ['shared' => true] : []), $organizationId, $userId);
    if ($shared !== '') return $mailboxId;   // no photo, no Teams: those belong to the person, not the shared mailbox
    if ($userId !== null) {
        require_once __DIR__ . '/avatars.php';
        ecw_avatar_refresh_with($userId, $identified);   // best effort; never blocks connecting
    }
    // Teams chats exist only for work and school accounts.
    if (!empty($handshake['teams']) && strcasecmp((string)$claims['tid'], MicrosoftGraphProvider::PERSONAL_TENANT) !== 0) {
        require_once __DIR__ . '/teams.php';
        ecw_teams_activate($mailboxId);                    // best effort; mail is connected either way
    }
    return $mailboxId;
}

/**
 * Gmail: same shape as the Microsoft flow (exchange, identify, upsert, store
 * tokens, queue discovery), with Google's identity rules. No tenant: Google
 * accounts are identified by the stable `sub` claim.
 */
function ecw_oauth_complete_google(int $organizationId, ?int $userId, string $code, ?array $handshake): int
{
    require_once __DIR__ . '/google_identity.php';
    $config   = ecw_google_config();
    $provider = ecw_provider_unauthenticated('google');
    $tokens   = $provider->exchangeCode($code, (string)$config['redirect_uri'], $handshake['verifier'] ?? null);
    if (empty($tokens['refresh_token'])) {
        throw new RuntimeException('Google did not grant background access. Try connecting again.');
    }
    if (!str_contains(' ' . $tokens['scope'] . ' ', 'gmail.modify')) {
        throw new RuntimeException('Gmail access was not granted. Tick the Gmail permission on Google\'s consent screen.');
    }
    $claims = ecw_google_identity($tokens['id_token'], (string)$config['client_id'], $handshake['nonce'] ?? null);

    $identified = new GmailProvider($config, static fn(): string => $tokens['access_token']);
    $account    = $identified->account();
    if ($account['account_id'] !== $claims['sub'] || $account['email'] === '') {
        throw new RuntimeException('Google account mismatch.');
    }
    $account['tenant_id'] = null;

    $pdo = ecw_db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) { $pdo->beginTransaction(); }
    try {
        $lock = $pdo->prepare('SELECT id FROM `' . ecw_table('organizations') . '` WHERE id = ?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $lock->execute([$organizationId]);
        $mailboxId = ecw_mailbox_upsert($organizationId, $userId, 'google', $account, $tokens);
        $pdo->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET sync_status = ? WHERE id = ?')->execute([ECW_MAILBOX_SYNCING, $mailboxId]);
        ecw_mailbox_store_tokens($mailboxId, $tokens);
        ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_SYNCING, 'Connected. Importing mail.');
        ecw_queue_enqueue($mailboxId, null, 'folders', 'initial');
        if ($ownsTransaction) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownsTransaction) { $pdo->rollBack(); }
        throw $e;
    }
    ecw_log("gmail mailbox {$mailboxId} connected for organization {$organizationId}");
    require_once __DIR__ . '/audit.php';
    ecw_audit('mailbox.connect', 'mailbox', $mailboxId, ['provider' => 'google'], $organizationId, $userId);
    if ($userId !== null) {
        require_once __DIR__ . '/avatars.php';
        ecw_avatar_refresh_with($userId, $identified);
    }
    return $mailboxId;
}

/**
 * Create or re-adopt the mailbox row.
 *
 * Re-authorizing an already-connected mailbox must land on the SAME row:
 * a second row would duplicate every folder, every message and every case
 * link. Matching is by provider account id, falling back to the address for a
 * row created before an account id was known.
 */
function ecw_mailbox_upsert(int $organizationId, ?int $userId, string $provider, array $account, array $tokens): int
{
    $pdo   = ecw_db();
    $table = ecw_table('mailboxes');

    $st = $pdo->prepare(
        "SELECT id, provider_account_id, provider_tenant_id, connected_by_user_id FROM `{$table}`
          WHERE organization_id = ? AND provider = ? AND (provider_account_id = ? OR email_address = ?)
          ORDER BY (provider_account_id = ?) DESC LIMIT 1"
    );
    $st->execute([
        $organizationId, $provider, $account['account_id'], $account['email'], $account['account_id'],
    ]);
    $existing = $st->fetch();
    $existingId = $existing === false ? false : (int)$existing['id'];
    // A shared mailbox may be reconnected by any admin (it is the team's, not one person's).
    if (ecw_private_mailboxes() && $existing && (int)$existing['connected_by_user_id'] !== (int)$userId && !str_starts_with((string)$account['account_id'], 'shared:')) {
        throw new RuntimeException('This mailbox is already connected to another workspace account.');
    }

    if ($existing !== false && ((!empty($existing['provider_tenant_id']) && $existing['provider_tenant_id'] !== $account['tenant_id'])
        || (!empty($existing['provider_account_id']) && $existing['provider_account_id'] !== $account['account_id']))) {
        throw new RuntimeException('This address is already connected to another ' . ($provider === 'google' ? 'Google' : 'Microsoft') . ' identity.');
    }

    if ($existingId !== false) {
        $pdo->prepare(
            "UPDATE `{$table}`
                SET provider_account_id = ?, provider_tenant_id = ?, email_address = ?, display_name = ?,
                    connected_by_user_id = COALESCE(?, connected_by_user_id)
              WHERE id = ?"
        )->execute([
            $account['account_id'], $account['tenant_id'], $account['email'], $account['display_name'], $userId, (int)$existingId,
        ]);
        return (int)$existingId;
    }
    // A new mailbox (reconnecting an existing one is always fine): within the package's limit.
    require_once __DIR__ . '/license.php';
    if ($limit = ecw_license_mailbox_limit_problem()) throw new RuntimeException($limit);

    $pdo->prepare(
        "INSERT INTO `{$table}`
            (organization_id, connected_by_user_id, provider, provider_account_id, provider_tenant_id,
             email_address, display_name, sync_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $organizationId, $userId, $provider, $account['account_id'], $account['tenant_id'],
        $account['email'], $account['display_name'], ECW_MAILBOX_PENDING,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Remove a mailbox and everything synchronized from it, for good: folders,
 * conversations, emails, attachments (also the files on disk), drafts, sync
 * state, and AI suggestions about its mail. Microsoft is told to stop sending
 * notifications first (ecw_mailbox_disconnect). Conversations linked to cases
 * leave those cases too. Connecting the same account again starts a fresh,
 * complete sync. @return array what was removed
 */
function ecw_mailbox_remove(int $mailboxId): array
{
    $mailbox = ecw_mailbox($mailboxId);
    if ($mailbox === null) { throw new RuntimeException('Mailbox not found.'); }
    if (($mailbox['sync_status'] ?? '') !== 'disconnected') ecw_mailbox_disconnect($mailboxId);
    $pdo = ecw_db();
    $T = static fn(string $t): string => '`' . ecw_table($t) . '`';
    $count = static function (string $sql) use ($pdo, $mailboxId): int { $st = $pdo->prepare($sql); $st->execute([$mailboxId]); return (int)$st->fetchColumn(); };
    $out = [
        'address'  => (string)$mailbox['email_address'],
        'messages' => $count("SELECT COUNT(*) FROM {$T('email_messages')} WHERE mailbox_id = ?"),
        'threads'  => $count("SELECT COUNT(*) FROM {$T('email_threads')} WHERE mailbox_id = ?"),
        'in_cases' => $count("SELECT COUNT(*) FROM {$T('case_threads')} ct JOIN {$T('email_threads')} t ON t.id = ct.email_thread_id WHERE t.mailbox_id = ?"),
    ];
    // Files on disk that the database rows point at.
    $st = $pdo->prepare("SELECT a.local_path FROM {$T('email_attachments')} a JOIN {$T('email_messages')} m ON m.id = a.email_message_id WHERE m.mailbox_id = ? AND a.local_path IS NOT NULL AND a.local_path <> ''");
    $st->execute([$mailboxId]);
    $files = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->prepare("SELECT id FROM {$T('drafts')} WHERE mailbox_id = ?");
    $st->execute([$mailboxId]);
    $drafts = $st->fetchAll(PDO::FETCH_COLUMN);

    // Rows that point at its mail without a foreign key.
    $pdo->prepare("DELETE s FROM {$T('ai_suggestions')} s JOIN {$T('email_threads')} t ON t.id = s.thread_id WHERE t.mailbox_id = ?")->execute([$mailboxId]);
    $pdo->prepare("DELETE r FROM {$T('agent_runs')} r JOIN {$T('email_messages')} m ON m.id = r.subject_id WHERE r.subject_type = 'message' AND m.mailbox_id = ?")->execute([$mailboxId]);
    require_once __DIR__ . '/audit.php';
    ecw_audit('mailbox.removed', 'mailbox', $mailboxId, ['address' => $out['address'], 'messages' => $out['messages']], (int)$mailbox['organization_id']);
    // Everything else goes with the mailbox row (ON DELETE CASCADE).
    $pdo->prepare("DELETE FROM {$T('mailboxes')} WHERE id = ?")->execute([$mailboxId]);

    $removedFiles = 0;
    foreach ($files as $f) { if (is_file((string)$f) && @unlink((string)$f)) $removedFiles++; }
    require_once __DIR__ . '/compose.php';
    foreach ($drafts as $d) {
        $dir = ECW_DRAFT_STORAGE . '/' . (int)$d;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
    ecw_log("mailbox {$mailboxId} ({$out['address']}) removed: {$out['messages']} messages, {$removedFiles} files");
    return $out + ['files' => $removedFiles];
}

/** Stop syncing without destroying history. Tokens are wiped, mail is kept. */
function ecw_mailbox_disconnect(int $mailboxId): void
{
    require_once __DIR__ . '/sync/subscriptions.php';

    $mailbox = ecw_mailbox($mailboxId);
    if ($mailbox === null) { throw new RuntimeException('Mailbox not found.'); }
    require_once __DIR__ . '/audit.php';
    ecw_audit('mailbox.disconnect', 'mailbox', $mailboxId, ['address' => (string)($mailbox['email_address'] ?? '')], (int)$mailbox['organization_id']);
    if (($mailbox['provider'] ?? '') === 'google') {
        // Tell Google to forget the grant too (best effort, after reading it).
        try { $refresh = ecw_decrypt($mailbox['refresh_token_encrypted']); } catch (Throwable $e) { $refresh = null; }
        register_shutdown_function(static function () use ($refresh): void { if ($refresh) { GmailProvider::revoke($refresh); } });
    }
    $accessToken = null;
    try { $accessToken = ecw_decrypt($mailbox['access_token_encrypted']); } catch (Throwable $e) { /* Still disconnect corrupt credentials. */ }
    $st = ecw_db()->prepare('SELECT subscription_id FROM `' . ecw_table('graph_subscriptions') . '` WHERE mailbox_id = ?');
    $st->execute([$mailboxId]);
    $subscriptions = $st->fetchAll(PDO::FETCH_COLUMN);

    $pdo = ecw_db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) { $pdo->beginTransaction(); }
    try {
        ecw_db()->prepare(
            'UPDATE `' . ecw_table('mailboxes') . '`
                SET access_token_encrypted = NULL, refresh_token_encrypted = NULL, token_expires_at = NULL, granted_scopes = NULL, sync_status = \'disconnected\'
              WHERE id = ?'
        )->execute([$mailboxId]);

        ecw_db()->prepare('UPDATE `' . ecw_table('graph_subscriptions') . '` SET status = "deleted" WHERE mailbox_id = ?')
            ->execute([$mailboxId]);

        ecw_db()->prepare("UPDATE `" . ecw_table('sync_queue') . "` SET status = 'done', locked_by = NULL, locked_at = NULL WHERE mailbox_id = ? AND status IN ('queued','running','failed')")->execute([$mailboxId]);

        ecw_mailbox_set_status($mailboxId, ECW_MAILBOX_DISCONNECTED, 'Disconnected. Mail already synchronized is kept.');
        if ($ownsTransaction) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownsTransaction) { $pdo->rollBack(); }
        throw $e;
    }
    if ($accessToken !== null && $accessToken !== '' && $subscriptions !== [] && ($mailbox['provider'] ?? 'microsoft') === 'microsoft') {
        try { $provider = new MicrosoftGraphProvider(ecw_graph_config(), static fn(): string => $accessToken); }
        catch (Throwable $e) { ecw_log('Remote subscription cleanup unavailable after local disconnect.'); return; }
        foreach ($subscriptions as $subscriptionId) {
            try { $provider->deleteSubscription((string)$subscriptionId); }
            catch (Throwable $e) { ecw_log("remote subscription cleanup failed for disconnected mailbox {$mailboxId}"); }
        }
    }

}
