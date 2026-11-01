<?php declare(strict_types=1);
/**
 * Email Case Workspace — Microsoft 365 / Outlook provider.
 *
 * Delegated OAuth: the user authorizes once, and every call runs as that user
 * against /me. (The outreach subsystem in this repo uses client credentials
 * against a fixed mailbox; the HTTP and threading lessons carry over, the
 * token model does not.)
 *
 * SCOPES
 * ------
 * Fixed delegated scopes, verified against Microsoft documentation. These
 * are the minimum that satisfies the four stated needs:
 *   openid profile User.Read  — signed-in identity, for the mailbox row
 *   offline_access            — refresh token, so the user signs in once
 *   Mail.ReadWrite            — read, move, mark read, create folders
 *   Mail.Send                 — send
 * Mail.ReadWrite is required rather than Mail.Read because the product moves
 * messages between folders and flips read state.
 *
 * SENDING: DRAFT THEN SEND, NEVER /sendMail
 * -----------------------------------------
 * POST /me/sendMail returns 202 with an empty body — no id, nothing to
 * correlate, so the sent message cannot be tied back to the local row except
 * by polling Sent Items and guessing. Creating a draft returns the full
 * resource including internetMessageId before anything leaves the building;
 * POST /send then delivers it. The draft's id DIES at that moment (Exchange
 * issues the Sent copy a new one), so the sent copy is re-found by its
 * internetMessageId, which survives the move. This is the single most
 * expensive lesson in bussiness/outreach/graph_client.php — do not "simplify"
 * it back to /sendMail.
 *
 * DELTA ORDER
 * -----------
 * Delta makes no ordering guarantee, so it cannot serve "newest first". The
 * initial import and the historical backfill use listMessages() with
 * $orderby=receivedDateTime desc instead, and delta takes over for keeping up.
 */

require_once __DIR__ . '/MailProvider.php';
require_once __DIR__ . '/GraphHttp.php';
require_once __DIR__ . '/../log.php';

final class MicrosoftGraphProvider implements MailProvider
{
    /**
     * Fields for BULK sync (delta and the newest-first walk).
     *
     * internetMessageHeaders is deliberately absent, and that single omission is
     * the difference between a mailbox that syncs in minutes and one that syncs
     * in half an hour. Measured against this tenant on 2026-09-18:
     *
     *     200 messages WITH    internetMessageHeaders : 10.4 s
     *     200 messages WITHOUT internetMessageHeaders :  1.8 s
     *
     * Graph has to crack open each message's MIME headers to serve that field,
     * so it costs roughly 6x and it costs it per message, forever, on every
     * round.
     *
     * Nothing is lost. The headers only feed threading signals 3 and 4
     * (In-Reply-To, References), and signal 1 — conversationId — is present on
     * 100% of threads in this mailbox, because Exchange assigns it to every
     * message it stores. The headers are still fetched, per message, by
     * getMessage() below, which is what runs when a body is loaded; ingest
     * persists In-Reply-To at that point, so the timeline's "Replying to"
     * reference fills in for messages anyone actually opens.
     */
    public const SELECT = [
        'id', 'internetMessageId', 'conversationId', 'subject',
        'from', 'sender', 'toRecipients', 'ccRecipients', 'bccRecipients',
        'sentDateTime', 'receivedDateTime', 'createdDateTime', 'lastModifiedDateTime',
        'isRead', 'isDraft', 'hasAttachments', 'webLink', 'flag',
    ];

    /** Fields for ONE message, where the extra header cost is paid once. */
    public const SELECT_FULL = [...self::SELECT, 'internetMessageHeaders', 'body'];

    /**
     * Fields for the import and delta pages: the list fields plus the body, so a
     * conversation opens complete instead of fetching each email's text one by
     * one later ("Loading message"). Still no internetMessageHeaders: they are
     * what made list calls slow; threading uses conversationId.
     */
    public const SELECT_LIST = [...self::SELECT, 'body'];

    /** Graph well-known folder name => our folder_type vocabulary. */
    /** PR_DEFERRED_SEND_TIME: when Outlook will send a scheduled email (UTC). */
    private const DEFERRED_SEND_TIME = 'SystemTime 0x3FEF';
    /**
     * The app's own hidden property on an email it sends or hands to Outlook (lib/compose.php
     * send_key). It stays on the copy in Sent Items, so any copy of the app can ask Outlook
     * whether that scheduled email already went (sentWithKey) and never send it twice.
     */
    private const SEND_KEY = 'String {8a3f7c2e-5b41-4d9e-9c6a-2f1e0b7d4a63} Name EcwSendKey';
    /** The case a copy of the app started by itself from an email (caseMarkOf, markCase). */
    private const CASE_KEY = 'String {8a3f7c2e-5b41-4d9e-9c6a-2f1e0b7d4a63} Name EcwAutoCase';

    private const WELL_KNOWN = [
        'inbox'        => 'inbox',
        'sentitems'    => 'sent',
        'drafts'       => 'drafts',
        'deleteditems' => 'deleted',
        'archive'      => 'archive',
        'junkemail'    => 'junk',
        'scheduled'    => 'snoozed',   // Outlook's own snooze folder ("Snoozed")
        // Outlook's own bookkeeping folders: never mail people work with, so
        // they are not synced and not shown.
        'conversationhistory' => 'system',
        'outbox'              => 'system',
        'syncissues'          => 'system',
        'conflicts'           => 'system',
        'localfailures'       => 'system',
        'serverfailures'      => 'system',
    ];

    /** Graph caps a message subscription at 4230 minutes. */
    private const MAX_SUBSCRIPTION_SECONDS = 4230 * 60;

    /** Graph refuses an inline base64 attachment above roughly this size: larger ones go up in pieces. */
    public const MAX_SIMPLE_ATTACHMENT_BYTES = 3 * 1024 * 1024;
    /** One piece of a large attachment: a multiple of 320 KiB, under Graph's 4 MiB per request. */
    public const UPLOAD_CHUNK_BYTES = 10 * 320 * 1024;
    /** Tries per piece when a piece broke off (the same byte range again is safe). */
    private const UPLOAD_TRIES = 3;

    private GraphHttp $http;

    public function __construct(private array $config, ?callable $tokenSupplier = null)
    {
        $this->http = new GraphHttp($tokenSupplier);
    }

    public function key(): string
    {
        return 'microsoft';
    }

    public function maxSubscriptionSeconds(): int
    {
        return self::MAX_SUBSCRIPTION_SECONDS;
    }

    /** The tenant ID Microsoft uses for every personal account (Outlook.com, Hotmail, Live). */
    public const PERSONAL_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /**
     * Sign-in authority. A connected mailbox uses its own tenant (personal
     * accounts: "consumers"). Connecting a new one uses "organizations", or
     * "common" (work, school and personal) when personal accounts are allowed.
     */
    private function tenant(): string
    {
        $t = (string)($this->config['account_tenant'] ?? '');
        if (strcasecmp($t, self::PERSONAL_TENANT) === 0) return 'consumers';
        if ($t !== '' && !($t === 'organizations' && !empty($this->config['personal_accounts']))) return $t;
        return !empty($this->config['personal_accounts']) ? 'common' : 'organizations';
    }

    /**
     * A Microsoft 365 shared mailbox (info@, sales@) is reached through the
     * signed-in person's own token, who needs "Full Access" (and "Send As")
     * on it in Exchange. Graph then serves it under /users/{address}.
     */
    public const SHARED_SCOPES = ['https://graph.microsoft.com/Mail.ReadWrite.Shared', 'https://graph.microsoft.com/Mail.Send.Shared'];

    /** Where this mailbox lives in Graph: the person's own (/me) or a shared one. */
    public function root(): string
    {
        $shared = (string)($this->config['shared_address'] ?? '');
        return $shared !== '' ? '/users/' . rawurlencode($shared) : '/me';
    }

    /** @return string[] */
    public function scopes(): array
    {
        return array_merge([
            'openid', 'profile', 'offline_access',
            'https://graph.microsoft.com/User.Read',
            'https://graph.microsoft.com/Mail.ReadWrite',
            'https://graph.microsoft.com/Mail.Send',
        ], (string)($this->config['shared_address'] ?? '') !== '' ? self::SHARED_SCOPES : []);
    }

    // ------------------------------------------------------------------ OAuth

    public function authorizationUrl(string $state, string $redirectUri, ?string $verifier = null, ?string $nonce = null, bool $teams = false, bool $calendar = false, bool $settings = false): string
    {
        $q = http_build_query([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            // MailboxSettings.ReadWrite: automatic replies (out of office), asked for only when turned on.
            'scope'         => implode(' ', array_merge($this->scopes(), $teams ? self::TEAMS_SCOPES : [], $calendar ? ['https://graph.microsoft.com/Calendars.ReadWrite'] : [], $settings ? ['https://graph.microsoft.com/MailboxSettings.ReadWrite'] : [])),
            'state'         => $state,
            'nonce'         => $nonce,
            'code_challenge' => $verifier === null ? null : rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => $verifier === null ? null : 'S256',
            // Without this, a user who already consented is bounced straight
            // through and a second mailbox can never be connected from the
            // same browser session.
            'prompt'        => 'select_account',
        ]);
        return GraphHttp::LOGIN_BASE . '/' . $this->tenant() . '/oauth2/v2.0/authorize?' . $q;
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $verifier = null): array
    {
        return $this->tokenRequest([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'client_secret' => (string)($this->config['client_secret'] ?? ''),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $redirectUri,
            'scope'         => implode(' ', $this->scopes()),
        ]);
    }

    /** Signing in to the app itself (lib/sso.php): who you are, no mail access. */
    public const SIGN_IN_SCOPES = ['openid', 'profile', 'email', 'https://graph.microsoft.com/User.Read'];

    public function signInUrl(string $state, string $redirectUri, string $verifier, string $nonce, ?string $loginHint = null): string
    {
        $q = http_build_query([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => implode(' ', self::SIGN_IN_SCOPES),
            'state'         => $state,
            'nonce'         => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt'        => 'select_account',
            'login_hint'    => $loginHint,
        ]);
        return GraphHttp::LOGIN_BASE . '/' . $this->tenant() . '/oauth2/v2.0/authorize?' . $q;
    }

    public function exchangeSignIn(string $code, string $redirectUri, string $verifier): array
    {
        return $this->tokenRequest([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'client_secret' => (string)($this->config['client_secret'] ?? ''),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $redirectUri,
            'scope'         => implode(' ', self::SIGN_IN_SCOPES),
        ]);
    }

    public function refreshTokens(string $refreshToken): array
    {
        return $this->refreshTokensForScopes($refreshToken, $this->scopes());
    }

    /**
     * Same refresh token, different resource scopes. Teams uses this so that
     * mail refreshes never ask for a scope the user has not consented to.
     *
     * @param string[] $scopes
     */
    public function refreshTokensForScopes(string $refreshToken, array $scopes): array
    {
        return $this->tokenRequest([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'client_secret' => (string)($this->config['client_secret'] ?? ''),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope'         => implode(' ', $scopes),
        ]);
    }

    /** Teams chat scopes, requested only when the user opts in to Teams. */
    public const TEAMS_SCOPES = ['https://graph.microsoft.com/Chat.ReadWrite'];

    /** Raw Graph access for lib/teams.php, which speaks Teams rather than mail. */
    public function http(): GraphHttp
    {
        return $this->http;
    }

    private function tokenRequest(array $fields): array
    {
        $url = GraphHttp::LOGIN_BASE . '/' . $this->tenant() . '/oauth2/v2.0/token';
        $res = $this->http->form($url, $fields);
        $b   = $res['body'];

        if (!isset($b['access_token'])) {
            throw new GraphException(
                'ecw: token endpoint returned no access_token (' . (string)($b['error'] ?? 'unknown') . ')',
                (int)$res['status'],
                (string)($b['error'] ?? '')
            );
        }

        // expires_in is a duration; everything downstream compares absolute
        // UTC datetimes, so convert once, here.
        $expiresIn = (int)($b['expires_in'] ?? 3600);

        return [
            'access_token'  => (string)$b['access_token'],
            'id_token' => (string)($b['id_token'] ?? ''),
            // A refresh_token is absent from a refresh response when the
            // tenant does not rotate them. Keeping the old one is correct;
            // overwriting it with null would silently disconnect the mailbox.
            'refresh_token' => isset($b['refresh_token']) ? (string)$b['refresh_token'] : null,
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + $expiresIn),
            'scope'         => (string)($b['scope'] ?? ''),
        ];
    }

    public function account(): array
    {
        $shared = strtolower(trim((string)($this->config['shared_address'] ?? '')));
        if ($shared !== '') {
            // Opening its Inbox proves the signed-in person really has access to it.
            $this->http->request('GET', $this->root() . '/mailFolders/inbox?$select=id');
            return ['account_id' => 'shared:' . $shared, 'email' => $shared, 'display_name' => (string)($this->config['shared_name'] ?? '')];
        }
        $res = $this->http->request('GET', '/me?$select=id,mail,userPrincipalName,displayName');
        $b   = $res['body'];
        return [
            'account_id'   => (string)($b['id'] ?? ''),
            'email'        => (string)($b['mail'] ?? $b['userPrincipalName'] ?? ''),
            'display_name' => (string)($b['displayName'] ?? ''),
        ];
    }

    /**
     * The signed-in user's own profile photo (96x96 JPEG), or null when they
     * have none. Only /me: User.Read covers it, so no extra consent is needed.
     * Not on MailProvider — callers check method_exists().
     */
    public function photo(): ?string
    {
        $res = $this->http->request('GET', '/me/photos/96x96/$value', null, [], [404]);
        if ((int)$res['status'] === 404) {
            return null;
        }
        $raw = (string)($res['body']['raw'] ?? '');
        return $raw === '' ? null : $raw;
    }

    // ---------------------------------------------------------------- Folders

    public function listFolders(): array
    {
        $wellKnownIds = $this->wellKnownFolderIds();
        $out   = [];
        $queue = [[null, $this->root() . '/mailFolders?$top=100&$select=id,displayName,parentFolderId,childFolderCount,totalItemCount']];
        $seen  = 0;

        while ($queue !== []) {
            [$parentId, $url] = array_shift($queue);
            $res  = $this->http->request('GET', $url);
            $body = $res['body'];

            foreach ((array)($body['value'] ?? []) as $f) {
                $id   = (string)($f['id'] ?? '');
                $type = $wellKnownIds[$id] ?? 'custom';
                $out[] = [
                    'provider_folder_id'        => $id,
                    'parent_provider_folder_id' => $parentId ?? ($f['parentFolderId'] ?? null),
                    'name'                      => (string)($f['displayName'] ?? ''),
                    'folder_type'               => $type,
                    'is_system'                 => $type !== 'custom',
                    // How many emails Outlook says it holds: shown next to ours, to see a folder is complete.
                    'total_count'               => isset($f['totalItemCount']) ? (int)$f['totalItemCount'] : null,
                ];
                if ((int)($f['childFolderCount'] ?? 0) > 0 && $seen < 500) {
                    $queue[] = [$id, $this->root() . '/mailFolders/' . rawurlencode($id)
                        . '/childFolders?$top=100&$select=id,displayName,parentFolderId,childFolderCount,totalItemCount'];
                }
                $seen++;
            }

            if (isset($body['@odata.nextLink'])) {
                $queue[] = [$parentId, (string)$body['@odata.nextLink']];
            }
        }

        return $out;
    }

    /** @return array<string,string> provider folder id => our folder_type */
    private function wellKnownFolderIds(): array
    {
        $map = [];
        foreach (self::WELL_KNOWN as $graphName => $ourType) {
            try {
                $res = $this->http->request('GET', $this->root() . '/mailFolders/' . $graphName . '?$select=id');
                $id  = (string)($res['body']['id'] ?? '');
                if ($id !== '') {
                    $map[$id] = $ourType;
                }
            } catch (GraphException $e) {
                // Archive in particular does not exist in every mailbox. A
                // missing well-known folder is not a sync failure — the folder
                // simply lands as 'custom'.
                if ($e->needsReauth()) {
                    throw $e;
                }
                ecw_log("well-known folder {$graphName} unavailable: " . $e->getMessage());
            }
        }
        return $map;
    }

    public function createFolder(string $name, ?string $parentProviderFolderId): array
    {
        $path = $parentProviderFolderId === null
            ? $this->root() . '/mailFolders'
            : $this->root() . '/mailFolders/' . rawurlencode($parentProviderFolderId) . '/childFolders';
        $res = $this->http->request('POST', $path, ['displayName' => $name]);
        return [
            'provider_folder_id'        => (string)($res['body']['id'] ?? ''),
            'parent_provider_folder_id' => $parentProviderFolderId,
            'name'                      => $name,
            'folder_type'               => 'custom',
            'is_system'                 => false,
        ];
    }

    public function renameFolder(string $providerFolderId, string $name): void
    {
        $this->http->request('PATCH', $this->root() . '/mailFolders/' . rawurlencode($providerFolderId), ['displayName' => $name]);
    }

    /**
     * How many EMAILS a folder holds (folder totalItemCount also counts calendar
     * items, contacts and tasks in it, which are never synced). One small call.
     */
    public function folderMessageCount(string $providerFolderId): ?int
    {
        $res = $this->http->request('GET', $this->root() . '/mailFolders/' . rawurlencode($providerFolderId) . '/messages?$top=1&$select=id&$count=true', null, [], [404]);
        return $res['status'] === 404 || !isset($res['body']['@odata.count']) ? null : (int)$res['body']['@odata.count'];
    }

    public function deleteFolder(string $providerFolderId): string
    {
        $this->http->request('DELETE', $this->root() . '/mailFolders/' . rawurlencode($providerFolderId), null, [], [404]);
        return 'removed';
    }

    // --------------------------------------------------------------- Messages

    public function deltaPage(string $providerFolderId, ?string $cursor, ?string $nextLink, int $pageSize): array
    {
        $url = $nextLink
            ?? $cursor
            ?? $this->root() . '/mailFolders/' . rawurlencode($providerFolderId) . '/messages/delta?$select='
               . implode(',', self::SELECT_LIST);

        $res = $this->http->request(
            'GET',
            $url,
            null,
            ['Prefer: odata.maxpagesize=' . max(1, min($pageSize, 100))],
            [410]
        );

        if ($res['status'] === 410) {
            // The stored delta token expired. Graph hands back a fresh starting
            // URL in Location; the folder must be re-read from scratch. This is
            // an instruction, not a failure — nothing local is thrown away,
            // because every message re-arrives and upserts onto its existing row.
            return [
                'messages'          => [],
                'next_link'         => null,
                'cursor'            => null,
                'needs_full_resync' => true,
                'resync_url'        => $res['headers']['location'] ?? null,
            ];
        }

        $body = $res['body'];
        return [
            'messages'          => array_map([$this, 'normalizeMessage'], (array)($body['value'] ?? [])),
            'next_link'         => isset($body['@odata.nextLink']) ? (string)$body['@odata.nextLink'] : null,
            'cursor'            => isset($body['@odata.deltaLink']) ? (string)$body['@odata.deltaLink'] : null,
            'needs_full_resync' => false,
            'resync_url'        => null,
        ];
    }

    public function listMessages(string $providerFolderId, ?string $nextLink, int $pageSize, ?string $receivedBefore = null): array
    {
        if ($nextLink !== null) {
            $url = $nextLink;
        } else {
            $params = [
                '$select'  => implode(',', self::SELECT_LIST),
                '$orderby' => 'receivedDateTime desc',
                // $top on a list call accepts 200 without complaint; the delta
                // path keeps its own 100 cap below.
                '$top'     => (string)max(1, min($pageSize, 200)),
            ];
            if ($receivedBefore !== null) {
                // Backfill walks older than the oldest message already stored.
                $params['$filter'] = 'receivedDateTime lt ' . str_replace(' ', 'T', $receivedBefore) . 'Z';
            }
            $url = $this->root() . '/mailFolders/' . rawurlencode($providerFolderId) . '/messages?'
                 . http_build_query($params);
        }

        $res  = $this->http->request('GET', $url);
        $body = $res['body'];
        return [
            'messages'  => array_map([$this, 'normalizeMessage'], (array)($body['value'] ?? [])),
            'next_link' => isset($body['@odata.nextLink']) ? (string)$body['@odata.nextLink'] : null,
        ];
    }

    public function getMessage(string $providerMessageId): array
    {
        $res = $this->http->request(
            'GET',
            $this->root() . '/messages/' . rawurlencode($providerMessageId) . '?$select='
                . implode(',', self::SELECT_FULL),
            null,
            ['Prefer: outlook.body-content-type="html"']
        );
        return $this->normalizeMessage($res['body']);
    }

    /**
     * Attachment metadata, without downloading any bytes.
     *
     * THE $select IS NOT ARBITRARY. /messages/{id}/attachments is a collection
     * of microsoft.graph.attachment, but contentId lives on the DERIVED type
     * microsoft.graph.fileAttachment, so naming it bare is rejected outright:
     *
     *   HTTP 400 BadRequest: Could not find a property named 'contentId' on
     *   type 'microsoft.graph.attachment'.
     *
     * That 400 is what this code shipped with, and it failed every body fetch
     * that touched a message with an attachment. The cast form below is the one
     * that works. Dropping $select entirely also works and is the obvious fix —
     * but then every response carries contentBytes, so listing the attachments
     * on a 20 MB mail downloads 20 MB to read a few names.
     *
     * This was already learned once, in bussiness/outreach/graph_client.php.
     */
    public function listAttachments(string $providerMessageId): array
    {
        $res = $this->http->request(
            'GET',
            $this->root() . '/messages/' . rawurlencode($providerMessageId)
                . '/attachments?$select=id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId'
        );
        $out = [];
        foreach ((array)($res['body']['value'] ?? []) as $a) {
            $out[] = [
                'provider_attachment_id' => (string)($a['id'] ?? ''),
                'filename'               => (string)($a['name'] ?? ''),
                'mime_type'              => (string)($a['contentType'] ?? ''),
                'size_bytes'             => (int)($a['size'] ?? 0),
                'is_inline'              => (bool)($a['isInline'] ?? false),
                'content_id'             => isset($a['contentId']) ? (string)$a['contentId'] : null,
            ];
        }
        return $out;
    }

    public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
    {
        $res = $this->http->request(
            'GET',
            $this->root() . '/messages/' . rawurlencode($providerMessageId)
                . '/attachments/' . rawurlencode($providerAttachmentId) . '/$value'
        );
        return (string)($res['body']['raw'] ?? '');
    }

    // -------------------------------------------------------------- Mutations

    /**
     * Draft, patch, send, then re-find the sent copy. See the class docblock.
     *
     * @param array $draft ['subject','body_html','to'[],'cc'[],'bcc'[],
     *                      'reply_to_provider_message_id','attachments'[]]
     */
    public function sendMessage(array $draft): array
    {
        $recipients = static fn(array $list): array => array_values(array_map(
            static fn($r) => ['emailAddress' => array_filter([
                'address' => $r['email'] ?? null,
                'name'    => $r['name'] ?? null,
            ], static fn($v) => $v !== null && $v !== '')],
            $list
        ));

        $payload = array_filter([
            'subject'       => $draft['subject'] ?? null,
            'body'          => isset($draft['body_html'])
                ? ['contentType' => 'HTML', 'content' => (string)$draft['body_html']]
                : null,
            'toRecipients'  => $recipients($draft['to'] ?? []),
            'ccRecipients'  => $recipients($draft['cc'] ?? []),
            'bccRecipients' => $recipients($draft['bcc'] ?? []),
            // Send later held by Outlook (defer_until, UTC): Exchange keeps the email and sends it
            // at that time by itself, this PC on or off (Outlook's own "Schedule send").
            'singleValueExtendedProperties' => array_values(array_filter([
                !empty($draft['defer_until']) ? ['id' => self::DEFERRED_SEND_TIME, 'value' => str_replace(' ', 'T', (string)$draft['defer_until']) . 'Z'] : null,
                !empty($draft['send_key']) ? ['id' => self::SEND_KEY, 'value' => (string)$draft['send_key']] : null,
            ])),
        ], static fn($v) => $v !== null && $v !== []);

        $replyTo = $draft['reply_to_provider_message_id'] ?? null;
        if ($replyTo) {
            // createReply makes Exchange populate In-Reply-To / References /
            // conversationId itself. Graph rejects those headers on a plain
            // create, so this is the only way a reply actually threads.
            $created = $this->http->request('POST', $this->root() . '/messages/' . rawurlencode((string)$replyTo) . '/createReply');
            $draftId = (string)($created['body']['id'] ?? '');
            $patched = $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($draftId), $payload);
            $resource = $patched['body'];
        } else {
            $created  = $this->http->request('POST', $this->root() . '/messages', $payload);
            $draftId  = (string)($created['body']['id'] ?? '');
            $resource = $created['body'];
        }

        $internetMessageId = (string)($resource['internetMessageId'] ?? '');

        // Attachments go on the DRAFT. A sent message is immutable, so this is
        // the only window — and it has to happen before /send, not after.
        // A file that cannot go up stops the email; the half-made draft is removed, so trying again
        // does not leave one more in Drafts each time.
        try {
            foreach ((array)($draft['attachments'] ?? []) as $attachment) {
                $this->attachToDraft($draftId, $attachment);
            }
        } catch (\Throwable $e) {
            try { $this->http->request('DELETE', $this->root() . '/messages/' . rawurlencode($draftId), null, [], [404]); } catch (\Throwable $ignored) {}
            throw $e;
        }

        if (!empty($draft['defer_until'])) {
            // Held until its time: in the Outbox (or Drafts), not in Sent yet. If the hand-over
            // broke off, Outlook is asked whether it took the email before anyone tries again:
            // held = it did; not held = the unsent copy is removed and the error goes up, so the
            // caller may keep the schedule itself without it ever going twice.
            try {
                $this->http->request('POST', $this->root() . '/messages/' . rawurlencode($draftId) . '/send');
            } catch (\Throwable $e) {
                $where = $internetMessageId !== '' ? $this->scheduledState($internetMessageId) : ['state' => 'missing', 'id' => null];
                if ($where['state'] !== 'held' || $where['id'] === $draftId) {
                    try { $this->http->request('DELETE', $this->root() . '/messages/' . rawurlencode($draftId), null, [], [404]); } catch (\Throwable $ignored) {}
                    throw $e;
                }
            }
            $held = null;
            try { $held = $internetMessageId !== '' ? $this->scheduledState($internetMessageId)['id'] : null; } catch (\Throwable $ignored) {}
            return [
                'provider_message_id' => null,
                'internet_message_id' => $internetMessageId !== '' ? $internetMessageId : null,
                'held_id'             => $held,
                'draft_id_consumed'   => $draftId,
            ];
        }

        $this->http->request('POST', $this->root() . '/messages/' . rawurlencode($draftId) . '/send');

        // The draft id is dead now. Find the Sent copy by its Message-ID,
        // which survived the move, so the local row can carry the real id.
        $sentId = $internetMessageId !== '' ? $this->findSent($internetMessageId) : null;

        return [
            'provider_message_id' => $sentId,
            'internet_message_id' => $internetMessageId !== '' ? $internetMessageId : null,
            'draft_id_consumed'   => $draftId,
        ];
    }

    /** Attach a file to a draft before it is sent (see sendMessage). */
    private function attachToDraft(string $draftId, array $attachment): void
    {
        $this->addAttachment($draftId, $attachment);
    }

    /**
     * Add a file to a draft: up to MAX_SIMPLE_ATTACHMENT_BYTES as base64 inside one JSON request
     * (Graph's simple path), above that through an upload session: Graph gives an address of its
     * own and the bytes go there in pieces of UPLOAD_CHUNK_BYTES, each with its byte range.
     *
     * @param array $attachment ['filename','mime_type','content_bytes' (base64),'is_inline','content_id']
     * @return string|null the attachment's id in Outlook, when Graph says it
     */
    private function addAttachment(string $messageId, array $attachment): ?string
    {
        $name = (string)($attachment['filename'] ?? 'attachment');
        $type = (string)(($attachment['mime_type'] ?? '') ?: 'application/octet-stream');
        $base64 = (string)($attachment['content_bytes'] ?? '');
        if ((int)(strlen($base64) * 3 / 4) <= self::MAX_SIMPLE_ATTACHMENT_BYTES) {
            $payload = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $name,
                'contentType'  => $type,
                'contentBytes' => $base64,
                'isInline'     => !empty($attachment['is_inline']),
            ];
            if (!empty($attachment['content_id'])) $payload['contentId'] = (string)$attachment['content_id'];
            $res = $this->http->request('POST', $this->root() . '/messages/' . rawurlencode($messageId) . '/attachments', $payload);
            return ($id = (string)($res['body']['id'] ?? '')) !== '' ? $id : null;
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) throw new RuntimeException('ecw: "' . $name . '" could not be read to send');
        $total = strlen($bytes);
        $item = ['attachmentType' => 'file', 'name' => $name, 'size' => $total, 'contentType' => $type, 'isInline' => !empty($attachment['is_inline'])];
        if (!empty($attachment['content_id'])) $item['contentId'] = (string)$attachment['content_id'];
        $session = $this->http->request('POST', $this->root() . '/messages/' . rawurlencode($messageId) . '/attachments/createUploadSession', ['AttachmentItem' => $item]);
        $uploadUrl = (string)($session['body']['uploadUrl'] ?? '');
        // Graph's own address, carrying its own authorization: never send the sign-in token to it.
        if (!preg_match('#^https://[a-z0-9.-]+\.(office\.com|office365\.com|outlook\.com|microsoft\.com)/#i', $uploadUrl)) {
            throw new RuntimeException('ecw: Outlook did not give an upload address for "' . $name . '"');
        }

        $start = 0;
        $last = null;
        try {
            while ($start < $total) {
                $piece = substr($bytes, $start, self::UPLOAD_CHUNK_BYTES);
                $end = $start + strlen($piece) - 1;
                for ($try = 1; ; $try++) {
                    try {
                        $last = $this->http->raw('PUT', $uploadUrl, $piece, 'application/octet-stream', ['Content-Range: bytes ' . $start . '-' . $end . '/' . $total], false);
                        break;
                    } catch (GraphException $e) {
                        if ($try >= self::UPLOAD_TRIES || !$e->isTransient()) throw $e;
                    } catch (RuntimeException $e) {
                        if ($try >= self::UPLOAD_TRIES) throw $e;   // the line broke off: the same range again
                    }
                    usleep(defined('ECW_TESTING') ? 0 : 1000000 * $try);
                }
                // Outlook says where to go on (normally right after this piece).
                $next = $last['body']['nextExpectedRanges'][0] ?? null;
                $start = is_string($next) && preg_match('/^(\d+)-/', $next, $m) ? (int)$m[1] : $end + 1;
            }
        } catch (Throwable $e) {
            try { $this->http->raw('DELETE', $uploadUrl, '', 'application/octet-stream', [], false, [404]); } catch (Throwable $ignored) {}
            throw new RuntimeException('ecw: "' . $name . '" could not be uploaded to Outlook: ' . $e->getMessage(), 0, $e);
        }
        // Done: 201, with the new attachment's address in Location (…/Attachments('id')). Without
        // it, the draft's file of that name and size (else saving the draft again uploads it again).
        $location = (string)($last['headers']['location'] ?? '');
        if (preg_match("#attachments\('([^']+)'\)#i", $location, $m)) return $m[1];
        $list = $this->http->request('GET', $this->root() . '/messages/' . rawurlencode($messageId) . '/attachments?$select=id,name,size', null, [], [404]);
        foreach (array_reverse((array)($list['body']['value'] ?? [])) as $att) {
            if ((string)($att['name'] ?? '') === $name && (int)($att['size'] ?? -1) >= $total) return (string)$att['id'];
        }
        return null;
    }

    /**
     * Where an email Outlook holds to send later is now: 'held' (Outbox or Drafts, with its id),
     * 'sent' (in Sent Items, with its id) or 'missing' (deleted, or not filed yet).
     *
     * @return array{state:string, id:?string}
     */
    public function scheduledState(string $internetMessageId): array
    {
        $filter = rawurlencode("internetMessageId eq '" . str_replace("'", "''", $internetMessageId) . "'");
        foreach (['outbox' => 'held', 'drafts' => 'held', 'sentitems' => 'sent'] as $folder => $state) {
            try {
                $res = $this->http->request('GET', $this->root() . '/mailFolders/' . $folder . '/messages?$select=id&$top=1&$filter=' . $filter);
            } catch (GraphException $e) {
                if ($folder === 'sentitems') throw $e;
                continue;   // no Outbox to read (some shared mailboxes)
            }
            $id = (string)($res['body']['value'][0]['id'] ?? '');
            if ($id !== '') return ['state' => $state, 'id' => $id];
        }
        return ['state' => 'missing', 'id' => null];
    }

    /**
     * The email this app sent or handed over with this send key, wherever it is now: 'sent'
     * (Sent Items), 'held' (Outbox or Drafts, to go at its time) or 'none'. Any copy of the app
     * asks this before sending a scheduled email, so a second copy never sends it again.
     *
     * @return array{state:string, id:?string, internet_id:?string}
     */
    public function sentWithKey(string $key): array
    {
        $filter = rawurlencode("singleValueExtendedProperties/Any(ep: ep/id eq '" . self::SEND_KEY . "' and ep/value eq '" . str_replace("'", "''", $key) . "')");
        foreach (['sentitems' => 'sent', 'outbox' => 'held', 'drafts' => 'held'] as $folder => $state) {
            try {
                $res = $this->http->request('GET', $this->root() . '/mailFolders/' . $folder . '/messages?$select=id,internetMessageId&$top=1&$filter=' . $filter);
            } catch (GraphException $e) {
                if ($folder === 'sentitems') throw $e;
                continue;   // no Outbox to read (some shared mailboxes)
            }
            $m = $res['body']['value'][0] ?? null;
            if (is_array($m) && !empty($m['id'])) return ['state' => $state, 'id' => (string)$m['id'], 'internet_id' => (string)($m['internetMessageId'] ?? '') ?: null];
        }
        return ['state' => 'none', 'id' => null, 'internet_id' => null];
    }

    /**
     * The case a copy of this app started by itself from this email (a hidden property on the
     * email in Outlook), or null. Two PCs with copies of the app read the same mailbox: the one
     * that comes second sees the mark and does not start the same case again.
     */
    public function caseMarkOf(string $providerMessageId): ?string
    {
        $res = $this->http->request('GET', $this->root() . '/messages/' . rawurlencode($providerMessageId)
            . '?$select=id&$expand=' . rawurlencode("singleValueExtendedProperties(\$filter=id eq '" . self::CASE_KEY . "')"), null, [], [404]);
        if ((int)$res['status'] === 404) return null;
        foreach ((array)($res['body']['singleValueExtendedProperties'] ?? []) as $p) {
            if (($p['id'] ?? '') !== '' && strcasecmp((string)$p['id'], self::CASE_KEY) === 0 && (string)($p['value'] ?? '') !== '') return (string)$p['value'];
        }
        return null;
    }

    /** Mark the email with the case started from it (see caseMarkOf). */
    public function markCase(string $providerMessageId, string $caseNumber): void
    {
        $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($providerMessageId),
            ['singleValueExtendedProperties' => [['id' => self::CASE_KEY, 'value' => $caseNumber]]], [], [404]);
    }

    /** Cancel an email Outlook holds to send later. @return string cancelled | sent | missing */
    public function cancelScheduled(string $internetMessageId): string
    {
        $where = $this->scheduledState($internetMessageId);
        if ($where['state'] !== 'held') return $where['state'];
        $this->http->request('POST', $this->root() . '/messages/' . rawurlencode((string)$where['id']) . '/permanentDelete', null, [], [404]);
        return 'cancelled';
    }

    private function findSent(string $internetMessageId, int $tries = 5): ?string
    {
        $filter = "internetMessageId eq '" . str_replace("'", "''", $internetMessageId) . "'";
        for ($i = 0; $i < $tries; $i++) {
            $res = $this->http->request(
                'GET',
                $this->root() . '/mailFolders/sentitems/messages?$select=id&$top=1&$filter=' . rawurlencode($filter)
            );
            $id = (string)($res['body']['value'][0]['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
            // Exchange files the sent copy asynchronously; a miss here is
            // normal on the first try, not an error.
            if ($i < $tries - 1) {
                sleep(1);
            }
        }
        ecw_log('sent copy not found yet by internetMessageId; delta sync will attach it later');
        return null;
    }

    public function moveMessage(string $providerMessageId, string $providerFolderId, ?string $fromProviderFolderId = null): array
    {
        $res = $this->http->request(
            'POST',
            $this->root() . '/messages/' . rawurlencode($providerMessageId) . '/move',
            ['destinationId' => $providerFolderId]
        );
        // A move reissues the message id — the caller must persist the new one.
        return [
            'provider_message_id' => (string)($res['body']['id'] ?? ''),
            'internet_message_id' => isset($res['body']['internetMessageId'])
                ? (string)$res['body']['internetMessageId'] : null,
        ];
    }

    /**
     * Many moves at once (a whole conversation): 20 per Graph $batch call instead of one call
     * per email. Each move reissues the message id: the new ids come back by old id.
     *
     * @param string[] $ids
     * @return array{moved: array<string,string>, failed: string[]}
     */
    public function moveMany(array $ids, string $providerFolderId): array
    {
        $out = ['moved' => [], 'failed' => []];
        foreach (array_chunk(array_values($ids), 20) as $chunk) {
            $requests = [];
            foreach ($chunk as $i => $id) {
                $requests[] = ['id' => (string)$i, 'method' => 'POST', 'url' => $this->root() . '/messages/' . rawurlencode($id) . '/move',
                    'headers' => ['Content-Type' => 'application/json'], 'body' => ['destinationId' => $providerFolderId]];
            }
            try {
                $res = $this->http->request('POST', '/$batch', ['requests' => $requests]);
            } catch (\Throwable $e) {
                // The batch call itself failed: one by one, as before.
                foreach ($chunk as $id) {
                    try { $out['moved'][$id] = $this->moveMessage($id, $providerFolderId)['provider_message_id'] ?: $id; }
                    catch (\Throwable $e2) { $out['failed'][] = $id; }
                }
                continue;
            }
            $seen = [];
            foreach ((array)($res['body']['responses'] ?? []) as $r) {
                $i = (int)($r['id'] ?? -1);
                if (!isset($chunk[$i])) continue;
                $seen[$i] = true;
                $status = (int)($r['status'] ?? 0);
                if ($status >= 200 && $status < 300) $out['moved'][$chunk[$i]] = (string)($r['body']['id'] ?? '') ?: $chunk[$i];
                else $out['failed'][] = $chunk[$i];
            }
            // Throttled or missing answers: those emails one by one.
            foreach ($chunk as $i => $id) {
                if (isset($seen[$i]) && !in_array($id, $out['failed'], true)) continue;
                $out['failed'] = array_values(array_diff($out['failed'], [$id]));
                try { $out['moved'][$id] = $this->moveMessage($id, $providerFolderId)['provider_message_id'] ?: $id; }
                catch (\Throwable $e2) { $out['failed'][] = $id; }
            }
        }
        return $out;
    }

    /** From Deleted Items, Graph's DELETE moves it to Recoverable Items — Outlook's own "permanently delete". */
    public function deleteMessage(string $providerMessageId): void
    {
        $this->http->request('DELETE', $this->root() . '/messages/' . rawurlencode($providerMessageId), null, [], [404]);
    }

    /** Delete for good from any folder (Junk Email's "Empty folder"), to Recoverable Items like Outlook on the web. */
    public function permanentlyDelete(string $providerMessageId): void
    {
        $this->http->request('POST', $this->root() . '/messages/' . rawurlencode($providerMessageId) . '/permanentDelete', null, [], [404]);
    }

    /**
     * Many deletes at once ("Empty folder"): 20 per Graph $batch call instead of one call per email.
     * $permanent: permanentDelete (Junk Email); else DELETE (from Deleted Items: for good).
     * Already gone (404) counts as done. Throttled (429) or failed ones are returned to try again.
     *
     * @param string[] $ids
     * @return array{done:string[], retry:string[], failed:string[]}
     */
    public function deleteMany(array $ids, bool $permanent = false): array
    {
        $out = ['done' => [], 'retry' => [], 'failed' => []];
        foreach (array_chunk(array_values($ids), 20) as $chunk) {
            $requests = [];
            foreach ($chunk as $i => $id) {
                $url = $this->root() . '/messages/' . rawurlencode($id) . ($permanent ? '/permanentDelete' : '');
                $requests[] = $permanent
                    ? ['id' => (string)$i, 'method' => 'POST', 'url' => $url, 'headers' => ['Content-Type' => 'application/json'], 'body' => new \stdClass()]
                    : ['id' => (string)$i, 'method' => 'DELETE', 'url' => $url];
            }
            try {
                $res = $this->http->request('POST', '/$batch', ['requests' => $requests]);
            } catch (\Throwable $e) {
                // The batch call itself failed: one by one, as before.
                foreach ($chunk as $id) {
                    try { $permanent ? $this->permanentlyDelete($id) : $this->deleteMessage($id); $out['done'][] = $id; }
                    catch (\InvalidArgumentException $e2) { throw $e2; }
                    catch (\Throwable $e2) { $out['failed'][] = $id; }
                }
                continue;
            }
            $seen = [];
            foreach ((array)($res['body']['responses'] ?? []) as $r) {
                $i = (int)($r['id'] ?? -1);
                if (!isset($chunk[$i])) continue;
                $seen[$i] = true;
                $status = (int)($r['status'] ?? 0);
                if (($status >= 200 && $status < 300) || $status === 404) $out['done'][] = $chunk[$i];
                elseif ($status === 429 || $status >= 500) $out['retry'][] = $chunk[$i];
                else $out['failed'][] = $chunk[$i];
            }
            foreach ($chunk as $i => $id) if (!isset($seen[$i])) $out['retry'][] = $id;
        }
        return $out;
    }

    public function markRead(string $providerMessageId, bool $isRead): void
    {
        $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($providerMessageId), ['isRead' => $isRead]);
    }

    // ---------------------------------------------------------------- Drafts

    private function draftPayload(array $draft): array
    {
        $recipients = static fn(array $list): array => array_values(array_map(
            static fn($r) => ['emailAddress' => array_filter(['address' => $r['email'] ?? null, 'name' => $r['name'] ?? null], static fn($v) => $v !== null && $v !== '')],
            $list
        ));
        return [
            'subject'       => (string)($draft['subject'] ?? ''),
            'body'          => ['contentType' => 'HTML', 'content' => (string)($draft['body_html'] ?? '')],
            'toRecipients'  => $recipients($draft['to'] ?? []),
            'ccRecipients'  => $recipients($draft['cc'] ?? []),
            'bccRecipients' => $recipients($draft['bcc'] ?? []),
        ];
    }

    public function saveDraft(array $draft, ?string $providerDraftId): array
    {
        $draft['attachments'] = (array)($draft['attachments'] ?? []);
        $payload = $this->draftPayload($draft);
        $id = null;
        if ($providerDraftId) {
            $res = $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($providerDraftId), $payload, [], [404]);
            if ((int)$res['status'] !== 404) $id = $providerDraftId;   // deleted in Outlook: make a new one
        }
        if ($id === null) {
            $replyTo = $draft['reply_to_provider_message_id'] ?? null;
            if ($replyTo) {
                $created = $this->http->request('POST', $this->root() . '/messages/' . rawurlencode((string)$replyTo) . '/createReply', null, [], [404]);
                $id = (int)$created['status'] === 404 ? null : (string)($created['body']['id'] ?? '');
                if ($id) $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($id), $payload);
            }
            if (!$id) $id = (string)($this->http->request('POST', $this->root() . '/messages', $payload)['body']['id'] ?? '');
            foreach ($draft['attachments'] as &$a) { $a['provider_attachment_id'] = null; }   // a new draft has none yet
            unset($a);
        }
        // Attachments: add new ones, remove the ones taken off in the app.
        $map = [];
        $keep = [];
        foreach ((array)($draft['attachments'] ?? []) as $a) {
            if (!empty($a['provider_attachment_id'])) { $map[$a['local_id']] = $a['provider_attachment_id']; $keep[$a['provider_attachment_id']] = true; continue; }
            $pid = (string)$this->addAttachment($id, ['filename' => (string)$a['filename'], 'mime_type' => (string)($a['mime_type'] ?? ''), 'content_bytes' => (string)$a['content_bytes']]);
            if ($pid !== '') { $map[$a['local_id']] = $pid; $keep[$pid] = true; }
        }
        $existing = $this->http->request('GET', $this->root() . '/messages/' . rawurlencode($id) . '/attachments?$select=id,isInline', null, [], [404]);
        foreach ((array)($existing['body']['value'] ?? []) as $att) {
            if (empty($att['isInline']) && !isset($keep[(string)$att['id']])) {
                $this->http->request('DELETE', $this->root() . '/messages/' . rawurlencode($id) . '/attachments/' . rawurlencode((string)$att['id']), null, [], [404]);
            }
        }
        return ['id' => $id, 'attachments' => $map];
    }

    /**
     * Erase a draft for good. A plain DELETE moves it to Deleted Items, where the
     * folder sync found it again and it looked like an email that was sent.
     */
    public function deleteDraft(string $providerDraftId): void
    {
        try {
            $this->http->request('POST', $this->root() . '/messages/' . rawurlencode($providerDraftId) . '/permanentDelete', null, [], [404]);
        } catch (GraphException $e) {
            $this->http->request('DELETE', $this->root() . '/messages/' . rawurlencode($providerDraftId), null, [], [404]);
        }
    }

    public function listDrafts(int $limit = 25): array
    {
        // "Schedule send" (new Outlook / the web) leaves the email in Drafts, and "Delay delivery"
        // (classic Outlook) in the Outbox, both with PR_DEFERRED_SEND_TIME: read it to show when it goes.
        $query = '$select=id,internetMessageId,subject,toRecipients,lastModifiedDateTime,bodyPreview,hasAttachments,conversationId,webLink'
            . '&$expand=' . rawurlencode("singleValueExtendedProperties(\$filter=id eq '" . self::DEFERRED_SEND_TIME . "')")
            . '&$orderby=lastModifiedDateTime%20desc&$top=';
        $out = [];
        foreach (['drafts' => max(1, min(50, $limit)), 'outbox' => 25] as $folder => $top) {
            try {
                $res = $this->http->request('GET', $this->root() . '/mailFolders/' . $folder . '/messages?' . $query . $top);
            } catch (GraphException $e) {
                if ($folder === 'drafts') throw $e;
                continue;   // no Outbox to read (some shared mailboxes): Drafts is what matters
            }
            foreach ((array)($res['body']['value'] ?? []) as $m) {
                $sendAt = null;
                foreach ((array)($m['singleValueExtendedProperties'] ?? []) as $p) {
                    if (strcasecmp((string)($p['id'] ?? ''), self::DEFERRED_SEND_TIME) === 0) $sendAt = $this->isoToMysql(preg_replace('/\.\d+/', '', (string)($p['value'] ?? '')));
                }
                if ($folder === 'outbox' && $sendAt === null) continue;   // only the held ones; the rest is leaving now
                $out[] = [
                    'id' => (string)$m['id'], 'internet_id' => (string)($m['internetMessageId'] ?? ''), 'subject' => (string)($m['subject'] ?? ''),
                    'to' => array_values(array_filter(array_map(static fn($r) => strtolower((string)($r['emailAddress']['address'] ?? '')), (array)($m['toRecipients'] ?? [])))),
                    'updated_at' => $this->isoToMysql($m['lastModifiedDateTime'] ?? null), 'preview' => (string)($m['bodyPreview'] ?? ''),
                    'has_attachments' => !empty($m['hasAttachments']),
                    'send_at' => $sendAt, 'conversation_id' => (string)($m['conversationId'] ?? ''), 'web_link' => (string)($m['webLink'] ?? ''),
                ];
            }
        }
        return $out;
    }

    public function getDraft(string $providerDraftId): array
    {
        $m = $this->http->request('GET', $this->root() . '/messages/' . rawurlencode($providerDraftId) . '?$select=subject,toRecipients,ccRecipients,bccRecipients,body&$expand=attachments')['body'];
        $list = static fn($l) => array_values(array_filter(array_map(static fn($r) => ['email' => strtolower((string)($r['emailAddress']['address'] ?? '')), 'name' => $r['emailAddress']['name'] ?? null], (array)$l), static fn($r) => $r['email'] !== ''));
        $atts = [];
        foreach ((array)($m['attachments'] ?? []) as $a) {
            if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || !empty($a['isInline'])) continue;
            $atts[] = ['filename' => (string)($a['name'] ?? 'attachment'), 'mime_type' => (string)($a['contentType'] ?? 'application/octet-stream'),
                'bytes' => (string)base64_decode((string)($a['contentBytes'] ?? '')), 'provider_attachment_id' => (string)$a['id']];
        }
        $body = (string)($m['body']['content'] ?? '');
        if (strtolower((string)($m['body']['contentType'] ?? '')) !== 'html') $body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        return ['subject' => (string)($m['subject'] ?? ''), 'to' => $list($m['toRecipients'] ?? []), 'cc' => $list($m['ccRecipients'] ?? []),
            'bcc' => $list($m['bccRecipients'] ?? []), 'body_html' => $body, 'attachments' => $atts];
    }

    // ------------------------------------------------- Meetings, automatic replies

    public function meetingInfo(string $providerMessageId): array
    {
        $m = $this->http->request('GET', $this->root() . '/messages/' . rawurlencode($providerMessageId)
            . '?$expand=' . rawurlencode('microsoft.graph.eventMessage/event($select=id,subject,start,end,isAllDay,location,responseStatus,isCancelled,organizer,onlineMeeting)'),
            null, ['Prefer: outlook.timezone="UTC"'])['body'];
        $e = $m['event'] ?? null;
        if (!is_array($e) || empty($e['id'])) throw new RuntimeException('This invitation is no longer in your calendar.');
        $t = static fn($x) => isset($x['dateTime']) ? substr(str_replace('T', ' ', (string)$x['dateTime']), 0, 19) : null;
        return [
            'event_id' => (string)$e['id'], 'subject' => (string)($e['subject'] ?? ''), 'start' => $t($e['start'] ?? null), 'end' => $t($e['end'] ?? null),
            'all_day' => !empty($e['isAllDay']), 'location' => (string)($e['location']['displayName'] ?? ''),
            'response' => (string)($e['responseStatus']['response'] ?? 'none'), 'cancelled' => !empty($e['isCancelled']) || ($m['meetingMessageType'] ?? '') === 'meetingCancelled',
            'organizer' => (string)($e['organizer']['emailAddress']['name'] ?? $e['organizer']['emailAddress']['address'] ?? ''),
            'join_url' => $e['onlineMeeting']['joinUrl'] ?? null, 'kind' => (string)($m['meetingMessageType'] ?? ''),
        ];
    }

    public function respondMeeting(string $eventId, string $action, string $comment = ''): void
    {
        // The invitation's own mailbox (root()), not always /me.
        if ($action === 'remove') { $this->http->request('DELETE', $this->root() . '/events/' . rawurlencode($eventId), null, [], [404]); return; }
        $verb = ['accept' => 'accept', 'tentative' => 'tentativelyAccept', 'decline' => 'decline'][$action] ?? null;
        if ($verb === null) throw new InvalidArgumentException('Unknown response.');
        $this->http->request('POST', $this->root() . '/events/' . rawurlencode($eventId) . '/' . $verb, ['comment' => $comment, 'sendResponse' => true]);
    }

    public function getAutoReply(): array
    {
        $s = $this->http->request('GET', '/me/mailboxSettings/automaticRepliesSetting')['body'];
        $t = static fn($x) => isset($x['dateTime']) ? (new DateTimeImmutable((string)$x['dateTime'], new DateTimeZone((string)($x['timeZone'] ?? 'UTC'))))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
        return [
            'status' => ['disabled' => 'off', 'alwaysEnabled' => 'on', 'scheduled' => 'scheduled'][$s['status'] ?? 'disabled'] ?? 'off',
            'start' => $t($s['scheduledStartDateTime'] ?? null), 'end' => $t($s['scheduledEndDateTime'] ?? null),
            'internal' => (string)($s['internalReplyMessage'] ?? ''), 'external' => (string)($s['externalReplyMessage'] ?? ''),
            'audience' => ['none' => 'none', 'contactsOnly' => 'contacts', 'all' => 'all'][$s['externalAudience'] ?? 'all'] ?? 'all',
        ];
    }

    public function setAutoReply(array $setting): void
    {
        $body = [
            'status' => ['off' => 'disabled', 'on' => 'alwaysEnabled', 'scheduled' => 'scheduled'][$setting['status']] ?? 'disabled',
            'internalReplyMessage' => (string)$setting['internal'], 'externalReplyMessage' => (string)$setting['external'],
            'externalAudience' => ['none' => 'none', 'contacts' => 'contactsOnly', 'all' => 'all'][$setting['audience']] ?? 'all',
        ];
        if ($setting['status'] === 'scheduled') {
            $body['scheduledStartDateTime'] = ['dateTime' => str_replace(' ', 'T', (string)$setting['start']), 'timeZone' => 'UTC'];
            $body['scheduledEndDateTime'] = ['dateTime' => str_replace(' ', 'T', (string)$setting['end']), 'timeZone' => 'UTC'];
        }
        $this->http->request('PATCH', '/me/mailboxSettings', ['automaticRepliesSetting' => $body]);
    }

    public function setFlag(string $providerMessageId, bool $flagged): void
    {
        $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($providerMessageId), ['flag' => ['flagStatus' => $flagged ? 'flagged' : 'notFlagged']]);
    }

    /** Categories are a plain list on the message: read it, change only ours, write it back. */
    public function setCategories(string $providerMessageId, array $add, array $remove): void
    {
        $res = $this->http->request('GET', $this->root() . '/messages/' . rawurlencode($providerMessageId) . '?$select=categories');
        $current = array_values((array)($res['body']['categories'] ?? []));
        $lower = static fn(array $l): array => array_map('mb_strtolower', $l);
        $keep = array_values(array_filter($current, static fn($c) => !in_array(mb_strtolower((string)$c), $lower($remove), true)));
        foreach ($add as $a) if (!in_array(mb_strtolower((string)$a), $lower($keep), true)) $keep[] = (string)$a;
        if ($keep !== $current) {
            $this->http->request('PATCH', $this->root() . '/messages/' . rawurlencode($providerMessageId), ['categories' => $keep]);
        }
    }

    // ---------------------------------------------------------- Subscriptions

    public function createSubscription(string $providerFolderId, string $notificationUrl, string $clientState, string $expiresAtUtc): array
    {
        $res = $this->http->request('POST', '/subscriptions', [
            'changeType'         => 'created,updated,deleted',
            'notificationUrl'    => $notificationUrl,
            'resource'           => $this->root() . "/mailFolders('" . $providerFolderId . "')/messages",
            'expirationDateTime' => str_replace(' ', 'T', $expiresAtUtc) . 'Z',
            'clientState'        => $clientState,
        ]);
        $b = $res['body'];
        return [
            'subscription_id' => (string)($b['id'] ?? ''),
            'resource'        => (string)($b['resource'] ?? ''),
            'expires_at'      => $this->isoToMysql($b['expirationDateTime'] ?? null) ?? $expiresAtUtc,
        ];
    }

    public function renewSubscription(string $subscriptionId, string $expiresAtUtc): array
    {
        $res = $this->http->request('PATCH', '/subscriptions/' . rawurlencode($subscriptionId), [
            'expirationDateTime' => str_replace(' ', 'T', $expiresAtUtc) . 'Z',
        ]);
        return [
            'subscription_id' => $subscriptionId,
            'expires_at'      => $this->isoToMysql($res['body']['expirationDateTime'] ?? null) ?? $expiresAtUtc,
        ];
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        try {
            $this->http->request('DELETE', '/subscriptions/' . rawurlencode($subscriptionId));
        } catch (GraphException $e) {
            // Already gone is the desired end state, not an error.
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    // ------------------------------------------------------------ Normalizing

    /** Graph message JSON => the interface's normalized message shape. */
    public function normalizeMessage(array $m): array
    {
        $id = (string)($m['id'] ?? '');

        if (isset($m['@removed'])) {
            // Deleted or moved out of this folder. Graph sends the id and
            // nothing else, so there is no message to normalize.
            return [
                'provider_message_id' => $id,
                'removed'             => true,
                'removed_reason'      => (string)($m['@removed']['reason'] ?? 'deleted'),
            ];
        }

        $addr = static function (?array $node): array {
            $e = $node['emailAddress'] ?? [];
            return [
                'email' => strtolower(trim((string)($e['address'] ?? ''))),
                'name'  => trim((string)($e['name'] ?? '')) ?: null,
            ];
        };
        $addrList = static function ($list) use ($addr): array {
            $out = [];
            foreach ((array)$list as $r) {
                $one = $addr($r);
                if ($one['email'] !== '') {
                    $out[] = $one;
                }
            }
            return $out;
        };

        $from = $addr($m['from'] ?? $m['sender'] ?? null);

        $body     = $m['body'] ?? null;
        $bodyHtml = null;
        $bodyText = null;
        if (is_array($body)) {
            $content = (string)($body['content'] ?? '');
            if (strtolower((string)($body['contentType'] ?? '')) === 'html') {
                $bodyHtml = $content;
            } else {
                $bodyText = $content;
            }
        }

        return [
            '_source_fields'           => array_keys($m),
            'provider_message_id'      => $id,
            'removed'                  => false,
            'internet_message_id'      => isset($m['internetMessageId']) ? (string)$m['internetMessageId'] : null,
            'in_reply_to_message_id'   => $this->header($m, 'In-Reply-To'),
            'references'               => $this->parseReferences($this->header($m, 'References')),
            'provider_conversation_id' => isset($m['conversationId']) ? (string)$m['conversationId'] : null,
            'subject'                  => isset($m['subject']) ? (string)$m['subject'] : null,
            'from_email'               => $from['email'] !== '' ? $from['email'] : null,
            'from_name'                => $from['name'],
            'to'                       => $addrList($m['toRecipients'] ?? []),
            'cc'                       => $addrList($m['ccRecipients'] ?? []),
            'bcc'                      => $addrList($m['bccRecipients'] ?? []),
            'sent_at'                  => $this->isoToMysql($m['sentDateTime'] ?? null),
            'received_at'              => $this->isoToMysql($m['receivedDateTime'] ?? null),
            'provider_created_at'      => $this->isoToMysql($m['createdDateTime'] ?? null),
            'provider_modified_at'     => $this->isoToMysql($m['lastModifiedDateTime'] ?? null),
            'is_read'                  => (bool)($m['isRead'] ?? false),
            'is_flagged'               => ($m['flag']['flagStatus'] ?? '') === 'flagged',
            // Meeting invitations and replies are a subtype of message.
            'meeting_kind'             => str_contains((string)($m['@odata.type'] ?? ''), 'eventMessage') ? substr((string)$m['@odata.type'], strlen('#microsoft.graph.')) : null,
            'is_draft'                 => (bool)($m['isDraft'] ?? false),
            'has_attachments'          => (bool)($m['hasAttachments'] ?? false),
            'web_link'                 => isset($m['webLink']) ? (string)$m['webLink'] : null,
            'body_html'                => $bodyHtml,
            'body_text'                => $bodyText,
            'attachments'              => [],
        ];
    }

    private function header(array $m, string $name): ?string
    {
        foreach ((array)($m['internetMessageHeaders'] ?? []) as $h) {
            if (strcasecmp((string)($h['name'] ?? ''), $name) === 0) {
                $v = trim((string)($h['value'] ?? ''));
                return $v === '' ? null : $v;
            }
        }
        return null;
    }

    /** "<a@x> <b@y>" => ['<a@x>','<b@y>'], oldest first, as the header orders them. */
    private function parseReferences(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        preg_match_all('/<[^<>]+>/', $raw, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    public function isoToMysql(?string $iso): ?string
    {
        if ($iso === null || trim((string)$iso) === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
