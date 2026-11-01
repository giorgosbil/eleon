<?php declare(strict_types=1);
/**
 * Email Case Workspace — Gmail / Google Workspace provider.
 *
 * Speaks the Gmail REST API (curl through GraphHttp, which is a generic JSON
 * client despite its name) and Google OAuth 2.0, and returns the normalized
 * shapes defined in MailProvider. Everything above the interface — sync,
 * ingest, cases, UI — is unchanged.
 *
 * HOW GMAIL MAPS ONTO THE INTERFACE
 * ---------------------------------
 * Folders     Gmail labels. INBOX, SENT, DRAFT and TRASH map to our folder
 *             types; user labels are 'custom'. A message can carry several
 *             labels but the local schema stores one folder per message, so
 *             only Inbox and Sent are synchronized by default (as for Outlook).
 * Delta       users.history.list from a stored historyId. The opaque cursor is
 *             "h:<historyId>" and a page in progress is "h:<start>|p:<token>".
 *             An expired historyId (404) asks the sync for a full resync, which
 *             re-reads the newest page; history backfill covers the rest.
 * Threads     Gmail's threadId is the provider_conversation_id, so threading
 *             is exact.
 * Attachments Gmail's attachmentId is NOT stable across reads, so the stored
 *             provider_attachment_id is the MIME partId; download looks the
 *             current attachmentId up again.
 * Sending     A raw RFC 5322 message built here (users.messages.send). Replies
 *             carry threadId plus In-Reply-To/References so they thread for
 *             every recipient, not only in Gmail.
 * Push        Not used. Gmail push needs Google Cloud Pub/Sub; the sync daemon
 *             polls instead, which is what the local installation does anyway.
 *
 * Scopes: openid email profile + gmail.modify (read, send, labels; no
 * permanent delete). gmail.modify is a Google "restricted" scope.
 */

require_once __DIR__ . '/MailProvider.php';
require_once __DIR__ . '/GraphHttp.php';

final class GmailProvider implements MailProvider
{
    public const API      = 'https://gmail.googleapis.com/gmail/v1/users/me';
    /** Sending a large email: Google's upload address (up to 35 MB). */
    public const UPLOAD_SEND = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=multipart';
    /** Up to this size an email goes as base64 inside one JSON request; above, to UPLOAD_SEND. */
    public const SIMPLE_SEND_BYTES = 4 * 1024 * 1024;
    public const AUTH     = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN    = 'https://oauth2.googleapis.com/token';
    public const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const REVOKE   = 'https://oauth2.googleapis.com/revoke';

    /** Gmail system labels we expose as folders, in our vocabulary. */
    private const SYSTEM = [
        'INBOX' => ['inbox', 'Inbox'],
        'SENT'  => ['sent', 'Sent'],
        'DRAFT' => ['drafts', 'Drafts'],
        'TRASH' => ['deleted', 'Trash'],
        'SPAM'  => ['junk', 'Spam'],
    ];

    private const META_HEADERS = ['From', 'To', 'Cc', 'Bcc', 'Subject', 'Date', 'Message-ID', 'In-Reply-To', 'References'];

    private GraphHttp $http;

    public function __construct(private array $config, ?callable $tokenSupplier = null)
    {
        $this->http = new GraphHttp($tokenSupplier);
    }

    public function key(): string
    {
        return 'google';
    }

    public function maxSubscriptionSeconds(): int
    {
        return 0;
    }

    /** @return string[] */
    public function scopes(): array
    {
        // gmail.settings.basic: automatic replies (vacation responder).
        return ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/gmail.settings.basic'];
    }

    // ------------------------------------------------------------------ OAuth

    public function authorizationUrl(string $state, string $redirectUri, ?string $verifier = null, ?string $nonce = null, bool $teams = false): string
    {
        return self::AUTH . '?' . http_build_query(array_filter([
            'client_id'             => (string)($this->config['client_id'] ?? ''),
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', $this->scopes()),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $verifier === null ? null : rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => $verifier === null ? null : 'S256',
            // offline + consent: Google only returns a refresh token on consent,
            // and without one the mailbox could not sync in the background.
            'access_type'           => 'offline',
            'prompt'                => 'consent select_account',
            'include_granted_scopes' => 'true',
        ], static fn($v) => $v !== null));
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $verifier = null): array
    {
        return $this->tokenRequest(array_filter([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'client_secret' => (string)($this->config['client_secret'] ?? ''),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $redirectUri,
        ], static fn($v) => $v !== null));
    }

    public function refreshTokens(string $refreshToken): array
    {
        return $this->tokenRequest([
            'client_id'     => (string)($this->config['client_id'] ?? ''),
            'client_secret' => (string)($this->config['client_secret'] ?? ''),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function tokenRequest(array $fields): array
    {
        $res = $this->http->form(self::TOKEN, $fields);
        $b   = $res['body'];
        if (!isset($b['access_token'])) {
            throw new GraphException('ecw: Google token endpoint returned no access_token (' . (string)($b['error'] ?? 'unknown') . ')',
                (int)$res['status'], (string)($b['error'] ?? ''));
        }
        return [
            'access_token'  => (string)$b['access_token'],
            'id_token'      => (string)($b['id_token'] ?? ''),
            // Google omits refresh_token on refresh; the stored one stays valid.
            'refresh_token' => isset($b['refresh_token']) ? (string)$b['refresh_token'] : null,
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + (int)($b['expires_in'] ?? 3600)),
            'scope'         => (string)($b['scope'] ?? ''),
        ];
    }

    /** Best effort: tell Google to forget the grant (used on disconnect). */
    public static function revoke(string $token): void
    {
        try {
            (new GraphHttp())->form(self::REVOKE, ['token' => $token]);
        } catch (Throwable $e) {
            // The local disconnect already happened; nothing to do.
        }
    }

    public function account(): array
    {
        $b = $this->http->request('GET', self::USERINFO)['body'];
        return [
            'account_id'   => (string)($b['sub'] ?? ''),
            'email'        => strtolower((string)($b['email'] ?? '')),
            'display_name' => (string)($b['name'] ?? ''),
            'email_verified' => (bool)($b['email_verified'] ?? false),
        ];
    }

    /** The Google profile photo, or null. Only Google's image hosts are fetched. */
    public function photo(): ?string
    {
        $url = (string)($this->http->request('GET', self::USERINFO)['body']['picture'] ?? '');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!str_starts_with($url, 'https://') || !str_ends_with($host, '.googleusercontent.com')) {
            return null;
        }
        $url = (string)preg_replace('/=s\d+(-c)?$/', '', $url) . '=s96-c';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 1048576]);
        $bytes = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ($status === 200 && is_string($bytes) && $bytes !== '' && str_starts_with($type, 'image/')) ? $bytes : null;
    }

    // ---------------------------------------------------------------- Folders

    public function listFolders(): array
    {
        $labels = $this->http->request('GET', self::API . '/labels')['body']['labels'] ?? [];
        $out = [];
        foreach ($labels as $l) {
            $id = (string)($l['id'] ?? '');
            if (isset(self::SYSTEM[$id])) {
                [$type, $name] = self::SYSTEM[$id];
                $out[] = ['provider_folder_id' => $id, 'parent_provider_folder_id' => null, 'name' => $name, 'folder_type' => $type, 'is_system' => true];
            } elseif (($l['type'] ?? '') === 'user' && !str_starts_with((string)($l['name'] ?? ''), 'Case/')) {   // case labels are not folders
                $out[] = ['provider_folder_id' => $id, 'parent_provider_folder_id' => null, 'name' => (string)($l['name'] ?? $id), 'folder_type' => 'custom', 'is_system' => false];
            }
        }
        return $out;
    }

    public function createFolder(string $name, ?string $parentProviderFolderId): array
    {
        $res = $this->http->request('POST', self::API . '/labels', [
            'name' => $name, 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show',
        ], [], [409]);
        if ((int)$res['status'] === 409) {
            foreach ($this->listFolders() as $f) {
                if (strcasecmp($f['name'], $name) === 0) {
                    return $f;
                }
            }
        }
        return ['provider_folder_id' => (string)($res['body']['id'] ?? ''), 'parent_provider_folder_id' => null,
            'name' => $name, 'folder_type' => 'custom', 'is_system' => false];
    }

    public function renameFolder(string $providerFolderId, string $name): void
    {
        $this->http->request('PATCH', self::API . '/labels/' . rawurlencode($providerFolderId), ['name' => $name]);
    }

    public function deleteFolder(string $providerFolderId): string
    {
        $this->http->request('DELETE', self::API . '/labels/' . rawurlencode($providerFolderId), null, [], [404]);
        return 'kept';
    }

    // --------------------------------------------------------------- Messages

    public function deltaPage(string $providerFolderId, ?string $cursor, ?string $nextLink, int $pageSize): array
    {
        $state = $this->parseCursor($nextLink ?? $cursor);
        if ($state === null) {
            // First round (or after an expired history id): baseline first, so
            // nothing that arrives while we list is missed, then the newest page.
            $historyId = (string)($this->http->request('GET', self::API . '/profile')['body']['historyId'] ?? '');
            $page = $this->listMessages($providerFolderId, null, min($pageSize, 50));
            return ['messages' => $page['messages'], 'next_link' => null, 'cursor' => 'h:' . $historyId,
                'needs_full_resync' => false, 'resync_url' => null];
        }

        $q = 'startHistoryId=' . rawurlencode($state['start']) . '&labelId=' . rawurlencode($providerFolderId)
            . '&maxResults=' . max(1, min($pageSize, 500))
            . '&historyTypes=messageAdded&historyTypes=messageDeleted&historyTypes=labelAdded&historyTypes=labelRemoved'
            . ($state['page'] !== null ? '&pageToken=' . rawurlencode($state['page']) : '');
        $res = $this->http->request('GET', self::API . '/history?' . $q, null, [], [404]);
        if ((int)$res['status'] === 404) {
            return ['messages' => [], 'next_link' => null, 'cursor' => null, 'needs_full_resync' => true, 'resync_url' => null];
        }
        $body = $res['body'];

        // Fold the history into one final state per message.
        $changed = [];
        foreach ((array)($body['history'] ?? []) as $h) {
            foreach ((array)($h['messagesAdded'] ?? []) as $e) {
                $changed[(string)$e['message']['id']] = 'fetch';
            }
            foreach ((array)($h['messagesDeleted'] ?? []) as $e) {
                $changed[(string)$e['message']['id']] = 'removed';
            }
            foreach ((array)($h['labelsAdded'] ?? []) as $e) {
                $changed[(string)$e['message']['id']] = 'fetch';   // into this label, or read/unread changed
            }
            foreach ((array)($h['labelsRemoved'] ?? []) as $e) {
                $changed[(string)$e['message']['id']] = 'fetch';
            }
        }
        // Archiving (removing INBOX) is not deleting in Gmail: the message stays
        // in All Mail and keeps its place in the thread here. Only a message
        // that is gone, in Trash or in Spam is reported as removed.
        $messages = [];
        foreach ($changed as $id => $what) {
            $m = $what === 'removed' ? null : $this->fetch($id, 'metadata');
            $labels = (array)($m['labelIds'] ?? []);
            $binned = static fn(string $l): bool => in_array($l, $labels, true) && $providerFolderId !== $l;
            if ($m === null || $binned('TRASH') || $binned('SPAM')) {
                $messages[] = ['provider_message_id' => $id, 'removed' => true, 'removed_reason' => 'deleted'];
            } else {
                $messages[] = $this->normalizeMessage($m);
            }
        }

        $next = isset($body['nextPageToken']) ? 'h:' . $state['start'] . '|p:' . $body['nextPageToken'] : null;
        return [
            'messages'          => $messages,
            'next_link'         => $next,
            'cursor'            => $next === null ? 'h:' . (string)($body['historyId'] ?? $state['start']) : null,
            'needs_full_resync' => false,
            'resync_url'        => null,
        ];
    }

    /** @return array{start:string,page:?string}|null */
    private function parseCursor(?string $raw): ?array
    {
        if ($raw === null || !preg_match('/^h:(\d+)(?:\|p:(.+))?$/', $raw, $m)) {
            return null;
        }
        return ['start' => $m[1], 'page' => $m[2] ?? null];
    }

    public function listMessages(string $providerFolderId, ?string $nextLink, int $pageSize, ?string $receivedBefore = null): array
    {
        $q = 'labelIds=' . rawurlencode($providerFolderId) . '&maxResults=' . max(1, min($pageSize, 500))
            . ($nextLink !== null ? '&pageToken=' . rawurlencode($nextLink) : '');
        if ($receivedBefore !== null && ($ts = strtotime($receivedBefore . ' UTC')) !== false) {
            $q .= '&q=' . rawurlencode('before:' . $ts);
        }
        $body = $this->http->request('GET', self::API . '/messages?' . $q)['body'];
        $messages = [];
        foreach ((array)($body['messages'] ?? []) as $ref) {
            $m = $this->fetch((string)$ref['id'], 'metadata');
            if ($m !== null) {
                $messages[] = $this->normalizeMessage($m);
            }
        }
        return ['messages' => $messages, 'next_link' => isset($body['nextPageToken']) ? (string)$body['nextPageToken'] : null];
    }

    public function getMessage(string $providerMessageId): array
    {
        $m = $this->fetch($providerMessageId, 'full');
        if ($m === null) {
            throw new GraphException('ecw: Gmail message not found', 404, 'notFound');
        }
        return $this->normalizeMessage($m);
    }

    /** Raw Gmail message JSON, or null when it no longer exists. */
    private function fetch(string $id, string $format): ?array
    {
        $q = 'format=' . $format;
        if ($format === 'metadata') {
            foreach (self::META_HEADERS as $h) {
                $q .= '&metadataHeaders=' . rawurlencode($h);
            }
        }
        $res = $this->http->request('GET', self::API . '/messages/' . rawurlencode($id) . '?' . $q, null, [], [404]);
        return (int)$res['status'] === 404 ? null : $res['body'];
    }

    public function listAttachments(string $providerMessageId): array
    {
        $m = $this->fetch($providerMessageId, 'full');
        if ($m === null) {
            return [];
        }
        $out = [];
        foreach ($this->parts($m['payload'] ?? []) as $p) {
            $a = $this->attachmentInfo($p);
            if ($a !== null) {
                $out[] = $a;
            }
        }
        return $out;
    }

    public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
    {
        $m = $this->fetch($providerMessageId, 'full');
        foreach ($m === null ? [] : $this->parts($m['payload'] ?? []) as $p) {
            if ((string)($p['partId'] ?? '') !== $providerAttachmentId) {
                continue;
            }
            return $this->partBytes($providerMessageId, $p);
        }
        throw new GraphException('ecw: Gmail attachment not found', 404, 'notFound');
    }

    // -------------------------------------------------------------- Mutations

    /**
     * @param array $draft ['subject','body_html','to'[],'cc'[],'bcc'[],
     *                      'reply_to_provider_message_id','attachments'[]]
     */
    public function sendMessage(array $draft): array
    {
        $headers = [];
        $threadId = null;
        $replyTo = $draft['reply_to_provider_message_id'] ?? null;
        if ($replyTo) {
            $orig = $this->fetch((string)$replyTo, 'metadata');
            if ($orig !== null) {
                $threadId = (string)($orig['threadId'] ?? '') ?: null;
                $h = $this->headers($orig);
                $msgId = $h['message-id'] ?? null;
                if ($msgId) {
                    $headers['In-Reply-To'] = $msgId;
                    $headers['References'] = trim(($h['references'] ?? '') . ' ' . $msgId);
                }
            }
        }
        $raw = $this->buildMime($draft, $headers);
        if (strlen($raw) <= self::SIMPLE_SEND_BYTES) {
            $res = $this->http->request('POST', self::API . '/messages/send', array_filter([
                'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
                'threadId' => $threadId,
            ], static fn($v) => $v !== null));
        } else {
            // A large email (big files): Google's upload address takes the email as it is, with
            // the conversation it belongs to beside it (multipart: JSON, then message/rfc822).
            $boundary = 'ecw' . bin2hex(random_bytes(12));
            $meta = json_encode($threadId !== null ? ['threadId' => $threadId] : new stdClass());
            $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
                . "--{$boundary}\r\nContent-Type: message/rfc822\r\n\r\n{$raw}\r\n--{$boundary}--";
            $res = $this->http->raw('POST', self::UPLOAD_SEND, $body, 'multipart/related; boundary=' . $boundary);
        }
        $sentId = (string)($res['body']['id'] ?? '');

        // Gmail assigns its own Message-ID; read it back so local threading matches.
        $internetId = null;
        if ($sentId !== '') {
            $sent = $this->fetch($sentId, 'metadata');
            $internetId = $sent !== null ? ($this->headers($sent)['message-id'] ?? null) : null;
        }
        return ['provider_message_id' => $sentId ?: null, 'internet_message_id' => $internetId, 'draft_id_consumed' => null];
    }

    /**
     * Gmail has labels, not folders: Trash has its own calls, "ARCHIVE" means
     * "take Inbox off", and a move takes the old label off as well.
     */
    public function moveMessage(string $providerMessageId, string $providerFolderId, ?string $fromProviderFolderId = null): array
    {
        $base = self::API . '/messages/' . rawurlencode($providerMessageId);
        if ($fromProviderFolderId === 'TRASH' && $providerFolderId !== 'TRASH') {
            $this->http->request('POST', $base . '/untrash', []);
        }
        if ($providerFolderId === 'TRASH') {
            $this->http->request('POST', $base . '/trash', []);
            return ['provider_message_id' => $providerMessageId];
        }
        $remove = [];
        if ($providerFolderId !== 'INBOX') $remove[] = 'INBOX';
        if ($fromProviderFolderId !== null && !in_array($fromProviderFolderId, ['INBOX', 'TRASH', 'SENT', 'DRAFT', $providerFolderId], true)) $remove[] = $fromProviderFolderId;
        $body = ['removeLabelIds' => array_values(array_unique($remove))];
        if ($providerFolderId !== 'ARCHIVE') $body['addLabelIds'] = [$providerFolderId];
        $res = $this->http->request('POST', $base . '/modify', $body);
        return ['provider_message_id' => (string)($res['body']['id'] ?? $providerMessageId)];
    }

    /** Permanent delete needs the full https://mail.google.com/ scope, which this app does not ask for. */
    public function deleteMessage(string $providerMessageId): void
    {
        throw new InvalidArgumentException('Gmail empties Trash by itself after 30 days. Deleting from Trash sooner needs more Gmail permission than this app asks for.');
    }

    // ---------------------------------------------------------------- Drafts
    // A Gmail draft is a whole MIME message: every save replaces it.

    public function saveDraft(array $draft, ?string $providerDraftId): array
    {
        $headers = []; $threadId = null;
        if (!empty($draft['reply_to_provider_message_id'])) {
            $orig = $this->fetch((string)$draft['reply_to_provider_message_id'], 'metadata');
            if ($orig !== null) {
                $threadId = (string)($orig['threadId'] ?? '') ?: null;
                $h = $this->headers($orig);
                if (!empty($h['message-id'])) { $headers['In-Reply-To'] = $h['message-id']; $headers['References'] = trim(($h['references'] ?? '') . ' ' . $h['message-id']); }
            }
        }
        $draft['attachments'] = array_map(static fn($a) => $a + ['is_inline' => false, 'content_id' => null], (array)($draft['attachments'] ?? []));
        $message = array_filter(['raw' => rtrim(strtr(base64_encode($this->buildMime($draft, $headers)), '+/', '-_'), '='), 'threadId' => $threadId], static fn($v) => $v !== null);
        $id = null;
        if ($providerDraftId) {
            $res = $this->http->request('PUT', self::API . '/drafts/' . rawurlencode($providerDraftId), ['id' => $providerDraftId, 'message' => $message], [], [404]);
            if ((int)$res['status'] !== 404) $id = $providerDraftId;
        }
        if ($id === null) $id = (string)($this->http->request('POST', self::API . '/drafts', ['message' => $message])['body']['id'] ?? '');
        return ['id' => $id, 'attachments' => []];
    }

    public function deleteDraft(string $providerDraftId): void
    {
        $this->http->request('DELETE', self::API . '/drafts/' . rawurlencode($providerDraftId), null, [], [404]);
    }

    public function listDrafts(int $limit = 25): array
    {
        $res = $this->http->request('GET', self::API . '/drafts?maxResults=' . max(1, min(20, $limit)));
        $out = [];
        foreach ((array)($res['body']['drafts'] ?? []) as $d) {
            $m = $this->fetch((string)($d['message']['id'] ?? ''), 'metadata');
            if ($m === null) continue;
            $h = $this->headers($m);
            $out[] = [
                'id' => (string)$d['id'], 'subject' => isset($h['subject']) ? $this->decodeHeader($h['subject']) : '',
                'to' => array_column($this->parseAddresses($h['to'] ?? ''), 'email'),
                'updated_at' => isset($m['internalDate']) ? gmdate('Y-m-d H:i:s', intdiv((int)$m['internalDate'], 1000)) : null,
                'preview' => html_entity_decode((string)($m['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'has_attachments' => strtolower((string)($m['payload']['mimeType'] ?? '')) === 'multipart/mixed',
            ];
        }
        return $out;
    }

    public function getDraft(string $providerDraftId): array
    {
        $d = $this->http->request('GET', self::API . '/drafts/' . rawurlencode($providerDraftId) . '?format=full')['body'];
        $m = (array)($d['message'] ?? []);
        $n = $this->normalizeMessage($m);
        $atts = [];
        foreach ($this->parts($m['payload'] ?? []) as $p) {
            $info = $this->attachmentInfo($p);
            if ($info === null || $this->isInline($p)) continue;
            $atts[] = ['filename' => (string)($info['filename'] ?? 'attachment'), 'mime_type' => (string)($info['mime_type'] ?? 'application/octet-stream'),
                'bytes' => $this->partBytes((string)($m['id'] ?? ''), $p), 'provider_attachment_id' => (string)($p['partId'] ?? '')];
        }
        $body = $n['body_html'] ?? null;
        if ($body === null) $body = nl2br(htmlspecialchars((string)($n['body_text'] ?? ''), ENT_QUOTES, 'UTF-8'));
        return ['subject' => (string)($n['subject'] ?? ''), 'to' => $n['to'], 'cc' => $n['cc'], 'bcc' => $n['bcc'], 'body_html' => (string)$body, 'attachments' => $atts];
    }

    // ------------------------------------------------- Meetings, automatic replies

    public function meetingInfo(string $providerMessageId): array
    {
        throw new RuntimeException('Answer this invitation in Gmail or Google Calendar.');
    }

    public function respondMeeting(string $eventId, string $action, string $comment = ''): void
    {
        throw new RuntimeException('Answer this invitation in Gmail or Google Calendar.');
    }

    /** Gmail's vacation responder has one message (used for everyone it answers). */
    public function getAutoReply(): array
    {
        $v = $this->http->request('GET', self::API . '/settings/vacation')['body'];
        $on = !empty($v['enableAutoReply']);
        $scheduled = $on && (!empty($v['startTime']) || !empty($v['endTime']));
        $t = static fn($ms) => $ms ? gmdate('Y-m-d H:i:s', intdiv((int)$ms, 1000)) : null;
        return [
            'status' => !$on ? 'off' : ($scheduled ? 'scheduled' : 'on'), 'start' => $t($v['startTime'] ?? null), 'end' => $t($v['endTime'] ?? null),
            'internal' => (string)($v['responseBodyHtml'] ?? nl2br(htmlspecialchars((string)($v['responseBodyPlainText'] ?? ''), ENT_QUOTES, 'UTF-8'))),
            'external' => '', 'audience' => !empty($v['restrictToDomain']) ? 'none' : (!empty($v['restrictToContacts']) ? 'contacts' : 'all'),
            'subject' => (string)($v['responseSubject'] ?? ''),
        ];
    }

    public function setAutoReply(array $setting): void
    {
        $body = [
            'enableAutoReply' => $setting['status'] !== 'off',
            'responseSubject' => (string)($setting['subject'] ?? 'Automatic reply'),
            'responseBodyHtml' => (string)$setting['internal'],
            'restrictToContacts' => $setting['audience'] === 'contacts',
            'restrictToDomain' => $setting['audience'] === 'none',
        ];
        if ($setting['status'] === 'scheduled') {
            $body['startTime'] = (string)(strtotime($setting['start'] . ' UTC') * 1000);
            $body['endTime'] = (string)(strtotime($setting['end'] . ' UTC') * 1000);
        }
        $this->http->request('PUT', self::API . '/settings/vacation', $body);
    }

    public function setFlag(string $providerMessageId, bool $flagged): void
    {
        $this->http->request('POST', self::API . '/messages/' . rawurlencode($providerMessageId) . '/modify',
            $flagged ? ['addLabelIds' => ['STARRED']] : ['removeLabelIds' => ['STARRED']]);
    }

    /** Case categories are labels named "Case/<name>" (created the first time). */
    private array $caseLabels = [];
    private function caseLabelId(string $name, bool $create): ?string
    {
        $full = 'Case/' . $name;
        if ($this->caseLabels === []) {
            foreach ((array)($this->http->request('GET', self::API . '/labels')['body']['labels'] ?? []) as $l) {
                $this->caseLabels[mb_strtolower((string)($l['name'] ?? ''))] = (string)$l['id'];
            }
        }
        $key = mb_strtolower($full);
        if (isset($this->caseLabels[$key])) return $this->caseLabels[$key];
        if (!$create) return null;
        $made = $this->createFolder($full, null);
        return $this->caseLabels[$key] = (string)$made['provider_folder_id'];
    }

    public function setCategories(string $providerMessageId, array $add, array $remove): void
    {
        $addIds = array_values(array_filter(array_map(fn($n) => $this->caseLabelId((string)$n, true), $add)));
        $removeIds = array_values(array_filter(array_map(fn($n) => $this->caseLabelId((string)$n, false), $remove)));
        if ($addIds === [] && $removeIds === []) return;
        $this->http->request('POST', self::API . '/messages/' . rawurlencode($providerMessageId) . '/modify',
            array_filter(['addLabelIds' => $addIds, 'removeLabelIds' => $removeIds]));
    }

    public function markRead(string $providerMessageId, bool $isRead): void
    {
        $this->http->request('POST', self::API . '/messages/' . rawurlencode($providerMessageId) . '/modify',
            $isRead ? ['removeLabelIds' => ['UNREAD']] : ['addLabelIds' => ['UNREAD']]);
    }

    public function createSubscription(string $providerFolderId, string $notificationUrl, string $clientState, string $expiresAtUtc): array
    {
        throw new RuntimeException('Gmail mailboxes are kept up to date by polling; push subscriptions are not used.');
    }

    public function renewSubscription(string $subscriptionId, string $expiresAtUtc): array
    {
        throw new RuntimeException('Gmail mailboxes have no push subscriptions.');
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        // Nothing to delete: no subscriptions are ever created for Gmail.
    }

    // ------------------------------------------------------------ Normalizing

    /** Gmail message JSON (format metadata or full) => normalized shape. */
    public function normalizeMessage(array $m): array
    {
        $h = $this->headers($m);
        $labels = (array)($m['labelIds'] ?? []);
        $from = $this->parseAddresses($h['from'] ?? '')[0] ?? ['email' => '', 'name' => null];
        $received = isset($m['internalDate']) ? gmdate('Y-m-d H:i:s', intdiv((int)$m['internalDate'], 1000)) : null;
        $sentTs = isset($h['date']) ? strtotime($h['date']) : false;

        $bodyHtml = null;
        $bodyText = null;
        $hasAttachments = false;
        $payload = $m['payload'] ?? [];
        if (isset($payload['parts']) || isset($payload['body']['data']) || isset($payload['body']['attachmentId'])) {
            foreach ($this->parts($payload) as $p) {
                if ($this->attachmentInfo($p) !== null) {
                    $hasAttachments = $hasAttachments || !$this->isInline($p);
                    continue;
                }
                $type = strtolower((string)($p['mimeType'] ?? ''));
                if ($type === 'text/html' && $bodyHtml === null) {
                    $bodyHtml = $this->partText((string)($m['id'] ?? ''), $p);
                } elseif ($type === 'text/plain' && $bodyText === null) {
                    $bodyText = $this->partText((string)($m['id'] ?? ''), $p);
                }
            }
        } else {
            // format=metadata carries no parts: multipart/mixed is the usual sign of attachments.
            $hasAttachments = strtolower((string)($payload['mimeType'] ?? '')) === 'multipart/mixed';
        }

        $email = strtolower((string)($this->config['account_email'] ?? ''));
        return [
            'provider_message_id'      => (string)($m['id'] ?? ''),
            'removed'                  => false,
            'internet_message_id'      => $h['message-id'] ?? null,
            'in_reply_to_message_id'   => $h['in-reply-to'] ?? null,
            'references'               => isset($h['references']) ? array_values(array_unique(preg_match_all('/<[^<>]+>/', $h['references'], $r) ? $r[0] : [])) : [],
            'provider_conversation_id' => isset($m['threadId']) ? (string)$m['threadId'] : null,
            'subject'                  => isset($h['subject']) ? $this->decodeHeader($h['subject']) : null,
            'from_email'               => $from['email'] !== '' ? $from['email'] : null,
            'from_name'                => $from['name'],
            'to'                       => $this->parseAddresses($h['to'] ?? ''),
            'cc'                       => $this->parseAddresses($h['cc'] ?? ''),
            'bcc'                      => $this->parseAddresses($h['bcc'] ?? ''),
            'sent_at'                  => $sentTs !== false ? gmdate('Y-m-d H:i:s', $sentTs) : $received,
            'received_at'              => $received,
            'provider_created_at'      => $received,
            'provider_modified_at'     => null,
            'is_read'                  => !in_array('UNREAD', $labels, true),
            'is_flagged'               => in_array('STARRED', $labels, true),
            'is_draft'                 => in_array('DRAFT', $labels, true),
            'is_sent'                  => in_array('SENT', $labels, true),   // direction hint, see ecw_ingest_direction()
            'has_attachments'          => $hasAttachments,
            'web_link'                 => isset($m['threadId'])
                ? 'https://mail.google.com/mail/' . ($email !== '' ? '?authuser=' . rawurlencode($email) : '') . '#all/' . rawurlencode((string)$m['threadId'])
                : null,
            'body_html'                => $bodyHtml,
            'body_text'                => $bodyText,
            'attachments'              => [],
        ];
    }

    /** @return array<string,string> lower-cased header name => first value */
    private function headers(array $m): array
    {
        $out = [];
        foreach ((array)($m['payload']['headers'] ?? []) as $h) {
            $name = strtolower((string)($h['name'] ?? ''));
            if ($name !== '' && !isset($out[$name])) {
                $out[$name] = trim((string)($h['value'] ?? ''));
            }
        }
        return $out;
    }

    private function decodeHeader(string $value): string
    {
        $decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
        return $decoded !== false ? $decoded : mb_decode_mimeheader($value);
    }

    /** "A <a@x>, \"B, Jr\" <b@y>, c@z" => [['email','name'], ...] */
    public function parseAddresses(string $raw): array
    {
        $raw = $this->decodeHeader($raw);
        $out = [];
        $buf = '';
        $quote = false;
        $angle = 0;
        foreach (mb_str_split($raw . ',') as $ch) {
            if ($ch === '"') {
                $quote = !$quote;
            } elseif (!$quote && $ch === '<') {
                $angle++;
            } elseif (!$quote && $ch === '>') {
                $angle = max(0, $angle - 1);
            }
            if ($ch === ',' && !$quote && $angle === 0) {
                $part = trim($buf);
                $buf = '';
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^(.*)<([^<>]+)>\s*$/u', $part, $m)) {
                    $name = trim(trim($m[1]), "\" \t");
                    $email = strtolower(trim($m[2]));
                } else {
                    $name = '';
                    $email = strtolower(trim($part, " \t\"<>"));
                }
                if (str_contains($email, '@')) {
                    $out[] = ['email' => $email, 'name' => $name !== '' ? $name : null];
                }
                continue;
            }
            $buf .= $ch;
        }
        return $out;
    }

    /** Depth-first list of every MIME part, the payload itself included. */
    private function parts(array $payload): array
    {
        $out = [$payload];
        foreach ((array)($payload['parts'] ?? []) as $child) {
            array_push($out, ...$this->parts($child));
        }
        return $out;
    }

    private function partHeader(array $part, string $name): ?string
    {
        foreach ((array)($part['headers'] ?? []) as $h) {
            if (strcasecmp((string)($h['name'] ?? ''), $name) === 0) {
                return trim((string)($h['value'] ?? ''));
            }
        }
        return null;
    }

    private function isInline(array $part): bool
    {
        $disposition = strtolower((string)$this->partHeader($part, 'Content-Disposition'));
        return $this->partHeader($part, 'Content-ID') !== null && !str_starts_with($disposition, 'attachment');
    }

    private function attachmentInfo(array $part): ?array
    {
        $filename = (string)($part['filename'] ?? '');
        $cid = $this->partHeader($part, 'Content-ID');
        $isPart = isset($part['body']['attachmentId']) || isset($part['body']['data']);
        if (!$isPart || ($filename === '' && $cid === null) || str_starts_with(strtolower((string)($part['mimeType'] ?? '')), 'multipart/')) {
            return null;
        }
        return [
            'provider_attachment_id' => (string)($part['partId'] ?? ''),
            'filename'               => $filename !== '' ? $this->decodeHeader($filename) : 'inline',
            'mime_type'              => (string)($part['mimeType'] ?? 'application/octet-stream'),
            'size_bytes'             => (int)($part['body']['size'] ?? 0),
            'is_inline'              => $this->isInline($part),
            'content_id'             => $cid !== null ? trim($cid, '<> ') : null,
        ];
    }

    private function partBytes(string $messageId, array $part): string
    {
        if (isset($part['body']['data'])) {
            return $this->b64url((string)$part['body']['data']);
        }
        $att = $this->http->request('GET', self::API . '/messages/' . rawurlencode($messageId) . '/attachments/'
            . rawurlencode((string)$part['body']['attachmentId']))['body'];
        return $this->b64url((string)($att['data'] ?? ''));
    }

    private function partText(string $messageId, array $part): string
    {
        $bytes = $this->partBytes($messageId, $part);
        $type = (string)$this->partHeader($part, 'Content-Type');
        $charset = preg_match('/charset="?([A-Za-z0-9._-]+)"?/i', $type, $m) ? strtoupper($m[1]) : 'UTF-8';
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }

    private function b64url(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }

    // -------------------------------------------------------------- MIME out

    /** RFC 5322 message. Every header value is stripped of CR/LF (no header injection). */
    public function buildMime(array $draft, array $extraHeaders = []): string
    {
        $clean = static fn(string $v): string => trim(str_replace(["\r", "\n"], ' ', $v));
        $enc = static fn(string $v): string => preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
        $addr = static function (array $list) use ($clean, $enc): string {
            return implode(', ', array_filter(array_map(static function ($r) use ($clean, $enc) {
                $email = $clean((string)($r['email'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }
                $name = $clean((string)($r['name'] ?? ''));
                return $name !== '' ? $enc('"' . str_replace('"', '', $name) . '"') . ' <' . $email . '>' : $email;
            }, $list)));
        };

        $html = (string)($draft['body_html'] ?? '');
        $text = trim(html_entity_decode(strip_tags((string)preg_replace(['~<br\s*/?>~i', '~</(p|div|li|tr|h\d)>~i'], ["\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $b = static fn(): string => '=_' . bin2hex(random_bytes(12));
        $part = static fn(string $type, string $body, array $more = []): string =>
            "Content-Type: {$type}\r\nContent-Transfer-Encoding: base64\r\n" . implode('', array_map(static fn($h) => $h . "\r\n", $more))
            . "\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
        $multipart = static function (string $subtype, array $children) use ($b): string {
            $boundary = $b();
            return "Content-Type: multipart/{$subtype}; boundary=\"{$boundary}\"\r\n\r\n"
                . implode('', array_map(static fn($c) => "--{$boundary}\r\n" . $c, $children)) . "--{$boundary}--\r\n";
        };

        $body = $multipart('alternative', [$part('text/plain; charset=UTF-8', $text), $part('text/html; charset=UTF-8', $html)]);
        $inline = $files = [];
        foreach ((array)($draft['attachments'] ?? []) as $a) {
            $name = str_replace(['"', "\r", "\n"], '', (string)($a['filename'] ?? 'file'));
            $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', (string)($a['mime_type'] ?? '')) ? (string)$a['mime_type'] : 'application/octet-stream';
            $bytes = (string)base64_decode((string)($a['content_bytes'] ?? ''));
            if (!empty($a['is_inline']) && !empty($a['content_id'])) {
                $inline[] = $part($mime . '; name="' . $enc($name) . '"', $bytes,
                    ['Content-ID: <' . $clean((string)$a['content_id']) . '>', 'Content-Disposition: inline; filename="' . $enc($name) . '"']);
            } else {
                $files[] = $part($mime . '; name="' . $enc($name) . '"', $bytes, ['Content-Disposition: attachment; filename="' . $enc($name) . '"']);
            }
        }
        if ($inline !== []) {
            $body = $multipart('related', array_merge([$body], $inline));
        }
        if ($files !== []) {
            $body = $multipart('mixed', array_merge([$body], $files));
        }

        $headers = array_filter([
            'To'      => $addr((array)($draft['to'] ?? [])),
            'Cc'      => $addr((array)($draft['cc'] ?? [])),
            'Bcc'     => $addr((array)($draft['bcc'] ?? [])),
            'Subject' => $enc($clean((string)($draft['subject'] ?? ''))),
            'MIME-Version' => '1.0',
        ] + array_map($clean, $extraHeaders), static fn($v) => $v !== '');
        $out = '';
        foreach ($headers as $k => $v) {
            $out .= $k . ': ' . $v . "\r\n";
        }
        return $out . $body;
    }
}
