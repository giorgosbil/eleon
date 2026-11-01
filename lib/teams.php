<?php declare(strict_types=1);
/**
 * Email Case Workspace — Microsoft Teams chats and channel notifications.
 *
 * CHATS ARE OPT-IN, AND MAIL NEVER DEPENDS ON THEM
 * ------------------------------------------------
 * A user enables Teams by reconnecting Microsoft with the extra Chat.ReadWrite
 * scope (web/oauth/start.php?teams=1). Mail token refreshes keep asking for mail
 * scopes only (MicrosoftGraphProvider::scopes()), so a tenant that refuses the
 * Teams consent, or a user who never enabled it, keeps syncing mail untouched.
 * The Teams access token is minted from the same refresh token with a
 * Teams-only scope list and cached in its own columns.
 *
 * NOTHING FROM TEAMS IS STORED
 * ----------------------------
 * Chats and messages are read live from Graph on request, as the signed-in
 * user, so Teams' own permissions decide what anyone can see. Linking a chat
 * into a case is a later step and will store only what is linked.
 *
 * CHANNEL NOTIFICATIONS
 * ---------------------
 * Posted to a Teams "Workflows" webhook an admin pastes in. That needs no
 * Graph permission at all. The URL is a bearer credential: stored encrypted,
 * and only Microsoft webhook hosts are accepted so it cannot be pointed at an
 * internal address (SSRF).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/sanitize.php';

final class EcwTeamsNotEnabled extends RuntimeException {}

/**
 * Teams lists some chats the user can no longer read through the API: mostly
 * meetings they were invited to but are not on the roster of (Graph reports
 * chatType "unknownFutureValue" and answers 403 AclCheckFailed). Retrying never
 * helps, so the UI stops polling and offers "Open in Teams" instead.
 */
final class EcwTeamsNoAccess extends RuntimeException {}

/**
 * Graph can start a new chat only with people in the user's own Microsoft 365
 * organization (it resolves them by user principal name). For anyone else —
 * other companies, guests, personal accounts — Teams itself must send the
 * first message; carries the deep link that opens exactly that chat in Teams.
 */
final class EcwTeamsOpenInTeams extends RuntimeException
{
    public function __construct(string $message, public readonly string $url)
    {
        parent::__construct($message);
    }
}

/**
 * Teams of the account chosen at the top (lib/account.php): that account's own
 * Microsoft sign-in, so each company's chats stay with its account. Null for a
 * shared or Gmail mailbox.
 */
function ecw_teams_mailbox(int $userId): ?array
{
    require_once __DIR__ . '/account.php';
    require_once __DIR__ . '/session.php';
    $orgId = function_exists('ecw_current_organization_id') && session_status() === PHP_SESSION_ACTIVE ? ecw_current_organization_id() : null;
    if ($orgId === null) {
        $st = ecw_db()->prepare('SELECT organization_id FROM `' . ecw_table('organization_users') . '` WHERE user_id = ? ORDER BY organization_id LIMIT 1');
        $st->execute([$userId]);
        $orgId = (int)$st->fetchColumn() ?: null;
    }
    return $orgId === null ? null : ecw_active_microsoft_mailbox((int)$orgId, $userId);
}

function ecw_teams_status(int $userId): array
{
    $mb = ecw_teams_mailbox($userId);
    return [
        'connected' => $mb !== null,
        'enabled'   => $mb !== null && (int)$mb['teams_enabled'] === 1,
        'me'        => $mb['provider_account_id'] ?? null,
        'email'     => $mb['email_address'] ?? null,
    ];
}

/**
 * A Teams-scoped access token for this mailbox's account.
 *
 * @throws EcwTeamsNotEnabled when the account has not consented to Teams
 */
function ecw_teams_token(array $mb, bool $force = false): string
{
    $expiresAt = $mb['teams_token_expires_at'] ?? null;
    if (!$force && $expiresAt !== null && strtotime((string)$expiresAt . ' UTC') - time() > 120) {
        $cached = ecw_decrypt($mb['teams_token_encrypted'] ?? null);
        if ($cached !== null && $cached !== '') {
            return $cached;
        }
    }

    $refresh = ecw_decrypt($mb['refresh_token_encrypted'] ?? null);
    if ($refresh === null || $refresh === '') {
        throw new EcwTeamsNotEnabled('Reconnect Microsoft to use Teams.');
    }

    $provider = new MicrosoftGraphProvider(ecw_graph_config() + ['account_tenant' => $mb['provider_tenant_id'] ?? 'organizations']);
    try {
        $tokens = $provider->refreshTokensForScopes(
            $refresh,
            array_merge(['offline_access'], MicrosoftGraphProvider::TEAMS_SCOPES)
        );
    } catch (GraphException $e) {
        // No consent for the Teams scope (or the grant is gone). Mark Teams off;
        // the mail sync is not touched and detects a dead grant on its own.
        if (in_array($e->graphCode, ['invalid_grant', 'consent_required', 'interaction_required', 'invalid_scope'], true) || $e->status === 400) {
            ecw_db()->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET teams_enabled = 0, teams_token_encrypted = NULL, teams_token_expires_at = NULL WHERE id = ?')
                ->execute([(int)$mb['id']]);
            throw new EcwTeamsNotEnabled('Teams is not enabled for this Microsoft account.');
        }
        throw $e;
    }

    $sql = 'UPDATE `' . ecw_table('mailboxes') . '` SET teams_enabled = 1, teams_token_encrypted = ?, teams_token_expires_at = ?';
    $params = [ecw_encrypt($tokens['access_token']), $tokens['expires_at']];
    if (!empty($tokens['refresh_token'])) {
        $sql .= ', refresh_token_encrypted = ?';
        $params[] = ecw_encrypt((string)$tokens['refresh_token']);
    }
    $params[] = (int)$mb['id'];
    ecw_db()->prepare($sql . ' WHERE id = ?')->execute($params);

    return $tokens['access_token'];
}

/** After an "Enable Teams" sign-in: confirm the consent took. Never throws. */
function ecw_teams_activate(int $mailboxId): bool
{
    try {
        $mb = ecw_mailbox($mailboxId);
        if ($mb === null) {
            return false;
        }
        ecw_teams_token($mb, true);
        ecw_log("teams enabled for mailbox {$mailboxId}");
        return true;
    } catch (Throwable $e) {
        ecw_log("teams activation failed for mailbox {$mailboxId}: " . get_class($e));
        return false;
    }
}

/** @return array{0: GraphHttp, 1: array} Graph client acting as $userId, and their mailbox row */
function ecw_teams_client(int $userId): array
{
    $mb = ecw_teams_mailbox($userId);
    if ($mb === null) {
        throw new EcwTeamsNotEnabled('Connect Microsoft Outlook first.');
    }
    if ((int)$mb['teams_enabled'] !== 1) {
        throw new EcwTeamsNotEnabled('Teams is not enabled for your account yet.');
    }
    return [new GraphHttp(static fn(): string => ecw_teams_token($mb)), $mb];
}

/** Turn a Graph failure into a sentence a person can act on. */
function ecw_teams_error_message(Throwable $e): string
{
    if ($e instanceof EcwTeamsNotEnabled) {
        return $e->getMessage();
    }
    if ($e instanceof GraphException) {
        return match (true) {
            $e->status === 403 => 'Microsoft refused access to this Teams chat.',
            $e->status === 404 => 'That Teams chat no longer exists or you are not in it.',
            $e->status === 429 => 'Teams is busy. Try again in a moment.',
            default            => 'Teams request failed (' . $e->status . ').',
        };
    }
    return 'Teams request failed.';
}

// ---------------------------------------------------------------------- chats

/** The user's recent chats, newest activity first. */
function ecw_teams_chats(int $userId): array
{
    [$http, $mb] = ecw_teams_client($userId);
    $me = (string)$mb['provider_account_id'];
    $base = '/me/chats?$top=50&$orderby=' . rawurlencode('lastMessagePreview/createdDateTime desc');
    try {
        $res = $http->request('GET', $base . '&$expand=' . rawurlencode('members,lastMessagePreview'));
    } catch (GraphException $e) {
        if ($e->status !== 400) {
            throw $e;
        }
        // Some tenants reject a double $expand: fall back to the preview only.
        $res = $http->request('GET', $base . '&$expand=lastMessagePreview');
    }
    return array_map(static fn(array $c): array => ecw_teams_chat_row($c, $me), $res['body']['value'] ?? []);
}

function ecw_teams_chat_row(array $c, string $me): array
{
    $members = [];
    foreach ($c['members'] ?? [] as $m) {
        $members[] = [
            'id'    => (string)($m['userId'] ?? ''),
            'name'  => (string)($m['displayName'] ?? ''),
            'email' => (string)($m['email'] ?? ''),
        ];
    }
    $others = array_values(array_filter($members, static fn(array $m): bool => $m['id'] !== $me));
    $title = trim((string)($c['topic'] ?? ''));
    if ($title === '') {
        $title = implode(', ', array_filter(array_map(static fn(array $m): string => $m['name'] ?: $m['email'], $others)));
    }
    $preview = $c['lastMessagePreview'] ?? null;
    $lastAt = $preview['createdDateTime'] ?? ($c['lastUpdatedDateTime'] ?? null);
    $readAt = $c['viewpoint']['lastMessageReadDateTime'] ?? null;
    $fromMe = ($preview['from']['user']['id'] ?? '') === $me;

    return [
        'id'       => (string)$c['id'],
        'type'     => (string)($c['chatType'] ?? 'oneOnOne'),
        // Graph's placeholder type for chats it will not let this user read (see EcwTeamsNoAccess).
        'limited'  => ($c['chatType'] ?? '') === 'unknownFutureValue',
        'title'    => $title !== '' ? $title : 'Chat',
        'members'  => $others,
        'web_url'  => (string)($c['webUrl'] ?? ''),
        'last_at'  => $lastAt,
        'preview'  => $preview ? ecw_teams_plain((string)($preview['body']['content'] ?? '')) : '',
        'preview_from' => $preview['from']['user']['displayName'] ?? null,
        'unread'   => $preview && !$fromMe && $lastAt !== null && ($readAt === null || strcmp((string)$lastAt, (string)$readAt) > 0),
    ];
}

/** Messages of one chat, oldest first, as display-safe rows. */
function ecw_teams_messages(int $userId, string $chatId): array
{
    [$http, $mb] = ecw_teams_client($userId);
    $rows = ecw_teams_fetch_messages($http, $mb, $chatId);

    // Best effort: opening the chat marks it read in Teams too.
    try {
        $http->request('POST', '/chats/' . rawurlencode($chatId) . '/markChatReadForUser', [
            'user' => ['id' => (string)$mb['provider_account_id'], 'tenantId' => (string)($mb['provider_tenant_id'] ?? '')],
        ]);
    } catch (Throwable $e) {
        // Not worth failing the read over.
    }
    return $rows;
}

/** The latest 50 messages, oldest first, without marking anything read. */
function ecw_teams_fetch_messages(GraphHttp $http, array $mb, string $chatId): array
{
    $me = (string)$mb['provider_account_id'];
    $res = $http->request('GET', '/chats/' . rawurlencode($chatId) . '/messages?$top=50', null, [], [403]);
    if ((int)$res['status'] === 403) {
        throw new EcwTeamsNoAccess('You are not a member of this chat in Teams any more, so it can only be opened in Teams itself.');
    }
    $rows = [];
    foreach ($res['body']['value'] ?? [] as $m) {
        if (!empty($m['deletedDateTime'])) {
            continue;
        }
        $system = ($m['messageType'] ?? 'message') !== 'message';
        $content = (string)($m['body']['content'] ?? '');
        $html = ($m['body']['contentType'] ?? 'text') === 'html'
            ? ecw_sanitize_email_html(ecw_teams_image_urls($content), false)
            : nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        $files = [];
        foreach ($m['attachments'] ?? [] as $a) {
            if (($a['contentType'] ?? '') === 'reference') {
                $url = (string)($a['contentUrl'] ?? '');
                $files[] = ['name' => (string)($a['name'] ?? 'File'), 'url' => preg_match('#^https://#i', $url) ? $url : null];
            }
        }
        $rows[] = [
            'id'      => (string)$m['id'],
            'system'  => $system,
            'from'    => (string)($m['from']['user']['displayName'] ?? ($m['from']['application']['displayName'] ?? '')),
            'mine'    => ($m['from']['user']['id'] ?? '') === $me,
            'from_id' => (string)($m['from']['user']['id'] ?? ''),
            'at'      => (string)($m['createdDateTime'] ?? ''),
            'html'    => $system ? '' : $html,
            'files'   => $files,
        ];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));
    return $rows;
}

/**
 * A picture in a Teams message lives at Graph (…/chats/{chat}/messages/{msg}/hostedContents/{id}/$value),
 * which a browser cannot open without the person's token. It is pointed at this app instead
 * (action=teams_image, ecw_teams_image), which fetches it with their own token.
 */
function ecw_teams_image_urls(string $html): string
{
    if (stripos($html, 'hostedContents') === false) return $html;
    return (string)preg_replace_callback(
        '#(\ssrc\s*=\s*)(["\'])https://graph\.microsoft\.com/(?:v1\.0|beta)/chats/([^/"\']+)/messages/([^/"\']+)/hostedContents/([^/"\']+)/\$value\2#i',
        static fn(array $m): string => $m[1] . $m[2] . 'api/index.php?action=teams_image&chat_id=' . rawurlencode(rawurldecode($m[3]))
            . '&message_id=' . rawurlencode(rawurldecode($m[4])) . '&hc=' . rawurlencode(rawurldecode($m[5])) . $m[2],
        $html
    );
}

/**
 * One picture of a Teams message, fetched with the person's own token (Teams decides whether they
 * may see it). @return array{bytes:string, mime:string}
 */
function ecw_teams_image(int $userId, string $chatId, string $messageId, string $hostedId): array
{
    foreach ([$chatId, $messageId, $hostedId] as $part) {
        if ($part === '' || strlen($part) > 1000 || !preg_match('/^[A-Za-z0-9:@._=+\/-]+$/', $part)) throw new InvalidArgumentException('That picture does not exist.');
    }
    [$http] = ecw_teams_client($userId);
    $res = $http->request('GET', '/chats/' . rawurlencode($chatId) . '/messages/' . rawurlencode($messageId) . '/hostedContents/' . rawurlencode($hostedId) . '/$value', null, [], [403, 404]);
    if (in_array((int)$res['status'], [403, 404], true)) throw new InvalidArgumentException('That picture is not available any more.');
    $bytes = (string)($res['body']['raw'] ?? '');
    $mime = '';
    foreach ((array)($res['headers'] ?? []) as $k => $v) if (strtolower((string)$k) === 'content-type') $mime = strtolower(trim(explode(';', (string)$v)[0]));
    if (!preg_match('#^image/(png|jpeg|gif|webp|bmp)$#', $mime)) {
        // Not said, or said wrongly: what the bytes are.
        $f = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$f->buffer($bytes);
    }
    if ($bytes === '' || !preg_match('#^image/(png|jpeg|gif|webp|bmp)$#', $mime)) throw new InvalidArgumentException('That picture is not available any more.');
    return ['bytes' => $bytes, 'mime' => $mime];
}

/** Send plain text (newlines kept) to a chat, as the user. */
function ecw_teams_send(int $userId, string $chatId, string $text): array
{
    $text = trim($text);
    if ($text === '' || mb_strlen($text) > 8000) {
        throw new InvalidArgumentException('Write a message (up to 8000 characters).');
    }
    [$http] = ecw_teams_client($userId);
    $res = $http->request('POST', '/chats/' . rawurlencode($chatId) . '/messages', [
        'body' => ['contentType' => 'html', 'content' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))],
    ]);
    return ['id' => (string)($res['body']['id'] ?? '')];
}

/**
 * The 1:1 chat with someone, by email. Teams returns the existing chat when
 * there already is one, so this is safe to call every time.
 */
function ecw_teams_chat_with(int $userId, string $email): array
{
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    [$http, $mb] = ecw_teams_client($userId);
    $me = (string)$mb['provider_account_id'];
    if (strcasecmp($email, (string)$mb['email_address']) === 0) {
        throw new InvalidArgumentException('That is your own address.');
    }

    // 1. An existing 1:1 chat with them — works for people outside the organization too.
    foreach (ecw_teams_chats($userId) as $chat) {
        if ($chat['type'] !== 'oneOnOne' || $chat['limited']) {
            continue;
        }
        foreach ($chat['members'] as $m) {
            if (strcasecmp((string)$m['email'], $email) === 0) {
                return ['id' => $chat['id'], 'title' => $chat['title'], 'web_url' => $chat['web_url']];
            }
        }
    }

    // 2. A new chat. Graph only finds people in this Microsoft 365 organization.
    $member = static fn(string $who): array => [
        '@odata.type'     => '#microsoft.graph.aadUserConversationMember',
        'roles'           => ['owner'],
        'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('" . str_replace("'", "''", $who) . "')",
    ];
    $res = $http->request('POST', '/chats', [
        'chatType' => 'oneOnOne',
        'members'  => [$member($me), $member($email)],
    // 401 here is not a dead token (the chat list above just used it): Graph
    // answers 401 or 404 when it cannot resolve someone outside the organization.
    ], [], [400, 401, 403, 404]);
    if ((int)$res['status'] >= 400) {
        throw new EcwTeamsOpenInTeams(
            'A new chat with ' . $email . ' can only be started from Teams (they are outside your Microsoft 365 organization, or not found there). '
            . 'Open it in Teams and send your first message there; the chat then appears here too.',
            'https://teams.microsoft.com/l/chat/0/0?users=' . rawurlencode($email)
        );
    }
    $chat = $res['body'];
    return ['id' => (string)($chat['id'] ?? ''), 'title' => $email, 'web_url' => (string)($chat['webUrl'] ?? '')];
}

/** Teams' own link to a chat with these people, with the first message and a group name filled in. */
function ecw_teams_deep_link(array $emails, string $topic = '', string $message = ''): string
{
    $url = 'https://teams.microsoft.com/l/chat/0/0?users=' . rawurlencode(implode(',', $emails));
    if (count($emails) > 1 && $topic !== '') $url .= '&topicName=' . rawurlencode(mb_substr($topic, 0, 80));
    if ($message !== '') $url .= '&message=' . rawurlencode(mb_substr($message, 0, 1000));
    return $url;
}

/**
 * Start a chat from the app: one person → their 1:1 chat (the existing one when
 * there is one), several → a new group chat with that name. The first message
 * is sent straight away. Microsoft only lets an app start chats with people in
 * the user's own Microsoft 365 organization: for anyone else this throws
 * EcwTeamsOpenInTeams with a link that opens Teams with the people and the
 * message ready, so one click there sends it.
 *
 * @param string[] $emails
 */
function ecw_teams_new_chat(int $userId, array $emails, string $topic = '', string $message = ''): array
{
    $topic = trim((string)preg_replace('/\s+/u', ' ', $topic));
    $message = trim($message);
    $list = [];
    foreach ($emails as $e) {
        $e = strtolower(trim((string)$e));
        if ($e === '') continue;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('“' . $e . '” is not an email address.');
        $list[$e] = true;
    }
    $list = array_keys($list);
    if (!$list) throw new InvalidArgumentException('Add at least one person.');
    if (count($list) > 20) throw new InvalidArgumentException('A chat started here can have up to 20 people.');
    [$http, $mb] = ecw_teams_client($userId);
    $list = array_values(array_filter($list, static fn($e) => strcasecmp($e, (string)$mb['email_address']) !== 0));
    if (!$list) throw new InvalidArgumentException('That is your own address.');

    if (count($list) === 1) {
        try {
            $chat = ecw_teams_chat_with($userId, $list[0]);
        } catch (EcwTeamsOpenInTeams $e) {
            throw new EcwTeamsOpenInTeams($e->getMessage(), ecw_teams_deep_link($list, '', $message));
        }
    } else {
        $me = (string)$mb['provider_account_id'];
        $member = static fn(string $who): array => [
            '@odata.type'     => '#microsoft.graph.aadUserConversationMember',
            'roles'           => ['owner'],
            'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('" . str_replace("'", "''", $who) . "')",
        ];
        $body = ['chatType' => 'group', 'members' => array_merge([$member($me)], array_map($member, $list))];
        if ($topic !== '') $body['topic'] = mb_substr($topic, 0, 250);
        $res = $http->request('POST', '/chats', $body, [], [400, 401, 403, 404]);
        if ((int)$res['status'] >= 400) {
            throw new EcwTeamsOpenInTeams(
                'Someone in this group is outside your Microsoft 365 organization, so Teams has to start the chat. '
                . 'Open it in Teams: the people' . ($topic !== '' ? ', the name' : '') . ($message !== '' ? ' and your message' : '') . ' are filled in — press Send there, and the chat appears here too.',
                ecw_teams_deep_link($list, $topic, $message)
            );
        }
        $chat = ['id' => (string)($res['body']['id'] ?? ''), 'title' => $topic !== '' ? $topic : implode(', ', $list), 'web_url' => (string)($res['body']['webUrl'] ?? '')];
    }
    if ($message !== '' && $chat['id'] !== '') ecw_teams_send($userId, $chat['id'], $message);
    return $chat;
}

/**
 * People to start a chat with: colleagues in the workspace, then the people
 * this user emailed most in the last 90 days. inside = same email domain as
 * their Microsoft account (Teams chats can start in the app); others open in Teams.
 */
function ecw_teams_people(int $orgId, int $userId): array
{
    $mb = ecw_teams_mailbox($userId);
    $mine = strtolower((string)($mb['email_address'] ?? ''));
    $domain = substr((string)strrchr($mine, '@'), 1);
    $out = [];
    $add = static function (string $email, string $name, string $from) use (&$out, $mine, $domain): void {
        $email = strtolower(trim($email));
        if ($email === '' || $email === $mine || isset($out[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        if (preg_match('/^(no-?reply|noreply|mailer-daemon|notifications?)@/i', $email)) return;
        $out[$email] = ['email' => $email, 'name' => trim($name) !== '' ? trim($name) : $email, 'from' => $from, 'inside' => $domain !== '' && str_ends_with($email, '@' . $domain)];
    };
    $st = ecw_db()->prepare('SELECT u.email, u.name FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
                              WHERE ou.organization_id = ? AND u.disabled_at IS NULL ORDER BY u.name');
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $r) $add((string)$r['email'], (string)$r['name'], 'colleague');
    if ($mb) {
        $st = ecw_db()->prepare("SELECT p.email, MAX(p.name) AS name, COUNT(*) AS n FROM `" . ecw_table('message_participants') . "` p
                                   JOIN `" . ecw_table('email_messages') . "` m ON m.id = p.email_message_id
                                  WHERE m.mailbox_id = ? AND m.direction = 'outgoing' AND p.role IN ('to', 'cc') AND m.occurred_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY
                                  GROUP BY p.email ORDER BY n DESC LIMIT 40");
        $st->execute([(int)$mb['id']]);
        foreach ($st->fetchAll() as $r) $add((string)$r['email'], (string)$r['name'], 'emailed');
    }
    return array_values($out);
}

function ecw_teams_plain(string $html): string
{
    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_strimwidth(trim((string)preg_replace('/\s+/u', ' ', $text)), 0, 140, '…');
}

// -------------------------------------------------- channel notifications

const ECW_TEAMS_NOTIFY_EVENTS = [
    'case_created'  => 'A case is created',
    'case_assigned' => 'Responsible or Handled by changes',
    'case_status'   => 'A case changes status',
];

/** Only Microsoft's webhook hosts: the URL is user-supplied and fetched by the server. */
function ecw_teams_webhook_valid(string $url): bool
{
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return false;
    }
    $host = strtolower($parts['host']);
    foreach (['.logic.azure.com', '.powerplatform.com', '.webhook.office.com'] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }
    return false;
}

function ecw_teams_notify_settings(int $orgId): array
{
    $st = ecw_db()->prepare('SELECT teams_webhook_encrypted, teams_notify_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $st->execute([$orgId]);
    $row = $st->fetch() ?: [];
    $events = json_decode((string)($row['teams_notify_json'] ?? ''), true);
    return [
        'configured' => !empty($row['teams_webhook_encrypted']),
        'events'     => is_array($events) ? array_values(array_intersect(array_keys(ECW_TEAMS_NOTIFY_EVENTS), $events)) : array_keys(ECW_TEAMS_NOTIFY_EVENTS),
        'labels'     => ECW_TEAMS_NOTIFY_EVENTS,
    ];
}

/** @param ?string $url null keeps the stored URL, '' removes it */
function ecw_teams_notify_save(int $orgId, ?string $url, array $events): array
{
    $events = array_values(array_intersect(array_keys(ECW_TEAMS_NOTIFY_EVENTS), array_map('strval', $events)));
    $sql = 'UPDATE `' . ecw_table('organizations') . '` SET teams_notify_json = ?';
    $params = [json_encode($events)];
    if ($url !== null) {
        $url = trim($url);
        if ($url !== '' && !ecw_teams_webhook_valid($url)) {
            throw new InvalidArgumentException('Paste the HTTPS address from the Teams Workflows "Post to a channel when a webhook request is received" template.');
        }
        $sql .= ', teams_webhook_encrypted = ?';
        $params[] = $url === '' ? null : ecw_encrypt($url);
    }
    $params[] = $orgId;
    ecw_db()->prepare($sql . ' WHERE id = ?')->execute($params);
    return ecw_teams_notify_settings($orgId);
}

/**
 * Post a case event to the organization's Teams channel. Best effort and
 * quick: a slow or broken webhook must never break the action that caused it.
 *
 * @param array $facts label => value pairs shown under the title
 */
function ecw_teams_notify(int $orgId, string $event, string $title, array $facts, ?string $link = null): bool
{
    require_once __DIR__ . '/license.php';
    if (!ecw_package_has('teams')) return false;   // Microsoft Teams is not in this installation's package
    $st = ecw_db()->prepare('SELECT teams_webhook_encrypted, teams_notify_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $st->execute([$orgId]);
    $row = $st->fetch();
    $url = $row ? ecw_decrypt($row['teams_webhook_encrypted'] ?? null) : null;
    if (!$url || !ecw_teams_webhook_valid($url)) {
        return false;
    }
    $events = json_decode((string)($row['teams_notify_json'] ?? ''), true);
    if ($event !== 'test' && is_array($events) && !in_array($event, $events, true)) {
        return false;
    }

    $card = [
        'type' => 'AdaptiveCard', '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'version' => '1.4',
        'body' => [
            ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
            ['type' => 'FactSet', 'facts' => array_map(static fn($k, $v) => ['title' => (string)$k, 'value' => (string)$v], array_keys($facts), $facts)],
        ],
    ];
    if ($link !== null) {
        $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open case', 'url' => $link]];
    }
    $payload = json_encode(['type' => 'message', 'attachments' => [[
        'contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card,
    ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (PHP_SAPI === 'cli' && defined('ECW_TESTING') && isset($GLOBALS['ecw_teams_test_sink'])) {
        $GLOBALS['ecw_teams_test_sink'][] = json_decode((string)$payload, true);
        return true;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        ecw_log("teams notification for organization {$orgId} failed (HTTP {$status})");
        return false;
    }
    return true;
}

/**
 * Notify the channel about what a case_update changed. Members-only cases are
 * never announced: their name alone could reveal what the case is about.
 */
function ecw_case_update_notify(int $orgId, ?array $before, ?array $after, array $actor): void
{
    if ($before === null || $after === null || ($after['sharing']['visibility'] ?? 'organization') !== 'organization') {
        return;
    }
    $who  = (string)($actor['name'] ?: $actor['email']);
    $name = static function (?int $id): string {
        if ($id === null) {
            return 'Unassigned';
        }
        $st = ecw_db()->prepare('SELECT name, email FROM `' . ecw_table('users') . '` WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        return $u ? (string)($u['name'] ?: $u['email']) : 'Unassigned';
    };
    $title = $after['number'] . ': ' . $after['name'];
    $link  = ecw_teams_case_link((int)$after['id']);

    $changes = [];
    if ($before['owner_user_id'] !== $after['owner_user_id']) {
        $changes['Responsible'] = $name($after['owner_user_id']);
    }
    if (($before['handler_user_id'] ?? null) !== ($after['handler_user_id'] ?? null)) {
        $changes['Handled by'] = $name($after['handler_user_id'] ?? null);
    }
    if ($changes !== []) {
        ecw_teams_notify($orgId, 'case_assigned', 'Case assigned · ' . $title, $changes + ['Changed by' => $who], $link);
    }
    if ($before['status'] !== $after['status']) {
        ecw_teams_notify($orgId, 'case_status', 'Case ' . $after['status'] . ' · ' . $title,
            ['Status' => $before['status'] . ' → ' . $after['status'], 'Changed by' => $who], $link);
    }
}

/** Absolute link to a case for a notification card. */
function ecw_teams_case_link(int $caseId): ?string
{
    $base = rtrim((string)(getenv('ECW_BASE_URL') ?: ''), '/');
    return $base === '' ? null : $base . '/other_things/web/case.php?id=' . $caseId;
}
