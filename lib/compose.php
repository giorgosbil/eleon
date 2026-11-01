<?php declare(strict_types=1);
/**
 * Email Case Workspace — composing, drafts and sending.
 *
 * REPLY RECIPIENTS
 * ----------------
 * Reply     → the original sender.
 * Reply all → sender + original To + original Cc, minus this mailbox, deduplicated.
 *
 * Two details that are easy to get wrong and annoying to live with:
 *
 * 1. Replying to a message WE sent must not address us. When the original is
 *    outgoing, "the sender" is this mailbox, and a naive reply mails the user
 *    their own message back. The reply goes to that message's To instead —
 *    which is what every mail client does and what the user means by "reply"
 *    while reading their own sent mail.
 *
 * 2. The mailbox is excluded by address, after normalization, from To AND Cc.
 *    Reply-all that keeps you on the Cc line means every thread you are part of
 *    doubles your own inbox.
 *
 * The step also requires the final recipient list to be shown before sending;
 * that is why this returns recipients as data for the UI to render, rather than
 * quietly assembling them at send time.
 *
 * QUOTING
 * -------
 * The outgoing mail carries a standard attribution line plus the original body
 * in a blockquote, because it has to be readable in Outlook, Gmail and
 * everything else. Our own collapsed-quote UI is a display concern and never
 * changes what is actually sent.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/sanitize.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/signature.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/cases.php';

/**
 * The composer emits one <p> per Enter. Outlook and Gmail give <p> a default
 * 1em bottom margin, so a single Enter looks like a blank line and a deliberate
 * blank line (<p><br></p>) looks like two. Margin-less divs render the way the
 * composer showed it: Enter = new line, empty line = one gap.
 */
function ecw_outgoing_paragraphs(string $html, ?int $userId = null): string
{
    require_once __DIR__ . '/appearance.php';
    $html = (string)preg_replace_callback(
        '~<p((?:\s[^>]*)?)>~i',
        static function (array $m): string {
            $attrs = $m[1];
            if (preg_match('~\sstyle="([^"]*)"~i', $attrs, $s)) {
                $style = 'margin:0;' . ltrim($s[1], '; ');
                return '<div' . preg_replace('~\sstyle="[^"]*"~i', ' style="' . $style . '"', $attrs, 1) . '>';
            }
            return '<div' . $attrs . ' style="margin:0">';
        },
        $html
    );
    $html = ecw_compose_font_fallbacks(str_ireplace('</p>', '</div>', $html));
    // The mail carries the writer's font (Settings → Email & writing), or the
    // recipient's client falls back to its own default (often Times New Roman).
    // Anything with its own font-family, such as quoted history, keeps it.
    // Classic Outlook: table cells (signatures) need the font written on them (ecw_outgoing_cell_fonts),
    // and a font the PC lacks a Windows one in its place (ecw_outlook_font_fallbacks).
    if ($html === '') return '';
    $css = ecw_compose_font_css($userId);
    $cell = implode(';', array_filter(explode(';', $css), static fn($d) => (bool)preg_match('~^\s*font-(family|size)\s*:~i', $d)));
    return ecw_outlook_font_fallbacks('<div style="' . $css . '">' . ecw_outgoing_cell_fonts($html, $cell) . '</div>');
}
require_once __DIR__ . '/provider/MicrosoftGraphProvider.php';

const ECW_DRAFT_STORAGE = __DIR__ . '/../storage/drafts';
/**
 * The files of one email together, at most: what mail servers commonly accept in one message
 * (Microsoft 365's default send limit is 35 MB once the files are encoded, about 25 MB of files).
 * Large files reach Outlook in pieces (MicrosoftGraphProvider upload session) and Gmail through
 * its upload address. Enforced at UPLOAD time, so the person learns it while attaching.
 */
const ECW_MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

/**
 * Everything the composer needs to open in reply/forward mode.
 *
 * @param string $mode new|reply|reply_all|forward
 */
function ecw_compose_context(int $mailboxId, ?int $messageId, string $mode, ?int $userId = null): array
{
    $mailbox = ecw_mailbox($mailboxId);
    if ($mailbox === null) {
        throw new RuntimeException("ecw: mailbox {$mailboxId} not found");
    }
    $self = strtolower(trim((string)$mailbox['email_address']));

    $base = [
        'mode' => $mode, 'to' => [], 'cc' => [], 'bcc' => [],
        'subject' => '', 'quoted_html' => '', 'in_reply_to_message_id' => null,
        'signature_html' => $userId !== null ? ecw_signature_html_for($userId, $mailboxId) : '',
    ];

    if ($mode === 'new' || $messageId === null) {
        return $base;
    }

    $st = ecw_db()->prepare(
        'SELECT id, subject, from_email, from_name, to_json, cc_json, direction,
                occurred_at, body_html, body_text, quoted_html, quoted_text, provider_message_id
           FROM `' . ecw_table('email_messages') . '`
          WHERE id = ? AND mailbox_id = ?'
    );
    $st->execute([$messageId, $mailboxId]);
    $msg = $st->fetch();
    if ($msg === false) {
        throw new RuntimeException("ecw: message {$messageId} not found in mailbox {$mailboxId}");
    }

    $to  = json_decode((string)($msg['to_json'] ?? '[]'), true) ?: [];
    $cc  = json_decode((string)($msg['cc_json'] ?? '[]'), true) ?: [];
    $from = ['email' => (string)$msg['from_email'], 'name' => $msg['from_name']];

    // The "Sent:" time: in the zone of this computer, else the workspace's (Settings → Response times).
    $tzSt = ecw_db()->prepare('SELECT timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?');
    $tzSt->execute([(int)$mailbox['organization_id']]);
    $base['in_reply_to_message_id'] = (int)$msg['id'];
    // The zone of the computer writing the reply (assets/timezone.js), as Outlook does when travelling.
    $quoteTz = (string)($tzSt->fetchColumn() ?: 'UTC');
    $deviceTz = (string)($_COOKIE['ecw_tz'] ?? '');
    if ($deviceTz !== '' && preg_match('#^[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+){0,2}$#', $deviceTz)) { try { $quoteTz = (new DateTimeZone($deviceTz))->getName(); } catch (Throwable $e) {} }
    $base['quoted_html'] = ecw_quote_block($msg, $quoteTz);

    if ($mode === 'forward') {
        $base['subject'] = ecw_prefix_subject((string)$msg['subject'], 'Fw:');
        return $base;
    }

    $base['subject'] = ecw_prefix_subject((string)$msg['subject'], 'Re:');

    // Replying to our own sent message answers its recipients, not ourselves.
    $primary = ($msg['direction'] === 'outgoing') ? $to : [$from];

    if ($mode === 'reply') {
        $base['to'] = ecw_clean_recipients($primary, $self);
        return $base;
    }

    $base['to'] = ecw_clean_recipients(array_merge($primary, ($msg['direction'] === 'outgoing') ? [] : $to), $self);
    $base['cc'] = ecw_clean_recipients($cc, $self, array_column($base['to'], 'email'));

    return $base;
}

/** Normalize, drop this mailbox, drop duplicates, drop anything already used. */
function ecw_clean_recipients(array $recipients, string $selfEmail, array $exclude = []): array
{
    $seen = array_flip(array_map('strtolower', $exclude));
    $out  = [];

    foreach ($recipients as $r) {
        $email = strtolower(trim((string)($r['email'] ?? '')));
        if ($email === '' || !str_contains($email, '@')) {
            continue;
        }
        if ($email === $selfEmail || isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        $out[] = ['email' => $email, 'name' => $r['name'] ?? null];
    }

    return $out;
}

/** "Re: x" — never "Re: Re: x". */
function ecw_prefix_subject(string $subject, string $prefix): string
{
    $clean = ecw_normalize_subject($subject);
    return trim($prefix . ' ' . $clean);
}

/**
 * The earlier email under a reply or forward, the way Outlook writes it: a thin
 * line across, then From / Sent / To / Cc / Subject, then the email itself.
 * Outlook, Gmail and phones all show that line, and recognise the header as the
 * start of the history. Only a <div> with a top border survives the compose
 * sanitizer and the editor (an <hr> or a styled <blockquote> does not), so the
 * line is that.
 */
function ecw_quote_block(array $msg, string $timezone = 'UTC'): string
{
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $person = static fn(array $p): string => trim((string)($p['name'] ?? '')) !== '' && strcasecmp(trim((string)$p['name']), (string)($p['email'] ?? '')) !== 0
        ? $p['name'] . ' <' . ($p['email'] ?? '') . '>' : (string)($p['email'] ?? '');
    $list = static fn($json): string => implode('; ', array_map($person, array_filter((array)(json_decode((string)$json, true) ?: []), static fn($r) => !empty($r['email']))));
    try {
        $tz = new DateTimeZone($timezone ?: 'UTC');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $sent = '';
    if (!empty($msg['occurred_at'])) {
        $d = new DateTimeImmutable((string)$msg['occurred_at'], new DateTimeZone('UTC'));
        $sent = $d->setTimezone($tz)->format('l, j F Y H:i') . ($tz->getName() === 'UTC' ? ' UTC' : '');
    }
    $header = '<b>From:</b> ' . $h($person(['name' => $msg['from_name'] ?? '', 'email' => $msg['from_email'] ?? '']))
        . ($sent !== '' ? '<br><b>Sent:</b> ' . $h($sent) : '');
    $to = $list($msg['to_json'] ?? null);
    $cc = $list($msg['cc_json'] ?? null);
    if ($to !== '') $header .= '<br><b>To:</b> ' . $h($to);
    if ($cc !== '') $header .= '<br><b>Cc:</b> ' . $h($cc);
    $header .= '<br><b>Subject:</b> ' . $h($msg['subject'] ?? '');

    $body = (string)($msg['body_html'] ?? '');
    if (trim($body) === '' && trim((string)($msg['body_text'] ?? '')) !== '') {
        $body = '<pre>' . htmlspecialchars((string)$msg['body_text'], ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    // Nested Outlook HTML documents make HTMLPurifier discard the answer
    // preceding their <body>. Normalize the original to a fragment first.
    $resolver = !empty($msg['id']) ? static fn(string $cid): ?string => ecw_inline_attachment_url((int)$msg['id'], $cid) : null;
    $body = ecw_sanitize_email_html($body, true, $resolver);
    // Stored history was split off during download; include it in the editor.
    if (!empty($msg['quoted_html'])) $body .= ecw_sanitize_email_html($msg['quoted_html'], true, $resolver);
    elseif (!empty($msg['quoted_text'])) $body .= '<pre>' . htmlspecialchars($msg['quoted_text'], ENT_QUOTES, 'UTF-8') . '</pre>';

    return '<div id="appendonsend"></div>'
        . '<div style="border-top:1px solid #E1E1E1;margin:18px 0 0 0;padding:6px 0 0 0;font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#000000">'
        . $header . '</div>'
        . '<div style="margin:12px 0 0 0">' . $body . '</div>';
}

// ------------------------------------------------------------------- drafts

function ecw_draft_save(int $organizationId, int $mailboxId, ?int $userId, array $data, ?int $draftId = null): int
{
    $pdo = ecw_db();

    $fields = [
        'organization_id'        => $organizationId,
        'mailbox_id'             => $mailboxId,
        'user_id'                => $userId,
        'mode'                   => (string)($data['mode'] ?? 'new'),
        'reply_to_local_message_id' => $data['in_reply_to_message_id'] ?? null,
        'case_id'                   => $data['case_id'] ?? null,
        'subject'                => $data['subject'] ?? null,
        // Sanitized on the way IN, so a stored draft can never be a stored XSS
        // waiting for the next person who opens it.
        'body_html'              => ecw_sanitize_compose_html($data['body_html'] ?? ''),
        'to_json'                => json_encode(ecw_normalize_address_list($data['to'] ?? [])),
        'cc_json'                => json_encode(ecw_normalize_address_list($data['cc'] ?? [])),
        'bcc_json'               => json_encode(ecw_normalize_address_list($data['bcc'] ?? [])),
    ];
    // The composer's parts, kept apart so a reopened draft is restored exactly.
    if (isset($data['parts']) && is_array($data['parts'])) {
        $p = $data['parts'];
        $fields['parts_json'] = json_encode([
            'answer'        => ecw_sanitize_compose_html((string)($p['answer'] ?? '')),
            'signature'     => ecw_sanitize_signature_html((string)($p['signature'] ?? '')),
            'quote'         => ecw_sanitize_compose_html((string)($p['quote'] ?? '')),
            'quote_changed' => !empty($p['quote_changed']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if ($draftId !== null && ecw_draft_load($organizationId, $draftId) !== null) {
        // Saving a failed draft (or one whose schedule was cancelled) makes it a draft again,
        // copied to the mailbox's Drafts folder by the drafts_push job. A scheduled email opened
        // and closed again ("keep_schedule", sent by the composer while it is scheduled) stays
        // scheduled, with its changes: it goes at its time as it is now.
        $wasScheduled = ($existing = ecw_draft_load($organizationId, $draftId)) !== null && in_array($existing['send_state'] ?? null, ['scheduled', 'sending'], true);
        if (!($wasScheduled && !empty($data['keep_schedule']))) {
            $fields['send_at'] = null;
            $fields['send_state'] = null;
            $fields['provider_push_pending'] = 1;
            // No longer scheduled: a copy Outlook holds is taken back now, before it can go.
            if (!empty($existing['outlook_internet_id'])) {
                try { ecw_provider_for_mailbox((int)$existing['mailbox_id'])->cancelScheduled((string)$existing['outlook_internet_id']); ecw_draft_held_copy_gone($existing); }
                catch (Throwable $e) { ecw_log('scheduled email ' . $draftId . ' not taken back from Outlook: ' . $e->getMessage()); }
                $fields['outlook_internet_id'] = null;
                $fields['outlook_held_id'] = null;
                $fields['outlook_schedule_dirty'] = 0;
            }
        } elseif (!empty($existing['outlook_internet_id'])) {
            // Still scheduled, but changed: the copy Outlook holds is replaced (ecw_drafts_outlook_followup).
            foreach (['subject', 'body_html', 'to_json', 'cc_json', 'bcc_json', 'attachments_json'] as $k) {
                if (array_key_exists($k, $fields) && (string)$fields[$k] !== (string)($existing[$k] ?? '')) { $fields['outlook_schedule_dirty'] = 1; break; }
            }
        }
        // A shared draft edited by a colleague stays its author's draft.
        unset($fields['user_id']);
        $fields['last_edited_by_user_id'] = $userId;
        $set    = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($fields))) . ', `version` = `version` + 1';
        $params = array_merge(array_values($fields), [$draftId, $organizationId]);
        $pdo->prepare('UPDATE `' . ecw_table('drafts') . "` SET {$set} WHERE id = ? AND organization_id = ?")
            ->execute($params);
        return $draftId;
    }

    // Copied to the mailbox's Drafts folder (drafts_push), except a draft the AI
    // prepared on its own: it stays here until a person opens and changes it, so
    // Outlook never fills with drafts nobody wrote (and, deleted, with their copies
    // in Deleted Items).
    $fields['provider_push_pending'] = empty($data['keep_local']) ? 1 : 0;
    $cols = array_keys($fields);
    $pdo->prepare('INSERT INTO `' . ecw_table('drafts') . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
    return (int)$pdo->lastInsertId();
}

/**
 * Recipient suggestions while typing in To/Cc/Bcc: colleagues first, then the
 * people this user has emailed with (most frequent first). Only mailboxes the
 * user may read are searched — with private mailboxes, their own — so nobody
 * is offered another person's contacts. Matches the start of the address or of
 * any word of the name.
 *
 * @return array<int,array{email:string,name:?string,colleague:bool}>
 */
function ecw_address_suggest(int $orgId, int $userId, string $q, int $limit = 8): array
{
    $q = trim($q);
    if ($q === '' || mb_strlen($q) > 100) {
        return [];
    }
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q));
    $db = ecw_db();
    $out = [];

    $st = $db->prepare(
        'SELECT u.email, u.name FROM `' . ecw_table('users') . '` u
           JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
          WHERE ou.organization_id = ? AND u.disabled_at IS NULL AND u.id <> ?
            AND (LOWER(u.email) LIKE ? OR LOWER(u.name) LIKE ? OR LOWER(u.name) LIKE ?)
          ORDER BY u.name LIMIT ' . $limit
    );
    $st->execute([$orgId, $userId, $like . '%', $like . '%', '% ' . $like . '%']);
    foreach ($st->fetchAll() as $r) {
        $out[strtolower($r['email'])] = ['email' => strtolower($r['email']), 'name' => $r['name'] ?: null, 'colleague' => true];
    }

    require_once __DIR__ . '/shared_mailboxes.php';
    $mbParams = [$orgId];
    $mb = $db->prepare('SELECT mb.id, mb.email_address FROM `' . ecw_table('mailboxes') . '` mb WHERE mb.organization_id = ?'
        . (ecw_private_mailboxes() ? ' AND ' . ecw_mailbox_visible_sql('mb', $userId, $mbParams) : ''));
    $mb->execute($mbParams);
    $boxes = $mb->fetchAll();
    $ids = array_map('intval', array_column($boxes, 'id'));
    $own = array_map('strtolower', array_column($boxes, 'email_address'));
    if ($ids !== [] && count($out) < $limit) {
        $st = $db->prepare(
            'SELECT LOWER(p.email) AS email, MAX(p.name) AS name, COUNT(*) AS n
               FROM `' . ecw_table('message_participants') . '` p
               JOIN `' . ecw_table('email_messages') . '` m ON m.id = p.email_message_id
              WHERE m.mailbox_id IN (' . implode(',', $ids) . ')
                AND (LOWER(p.email) LIKE ? OR LOWER(p.name) LIKE ? OR LOWER(p.name) LIKE ?)
              GROUP BY LOWER(p.email)
              ORDER BY n DESC
              LIMIT ' . ($limit * 2)
        );
        $st->execute([$like . '%', $like . '%', '% ' . $like . '%']);
        foreach ($st->fetchAll() as $r) {
            $email = (string)$r['email'];
            if (isset($out[$email]) || in_array($email, $own, true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[$email] = ['email' => $email, 'name' => $r['name'] ?: null, 'colleague' => false];
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return array_values(array_slice($out, 0, $limit));
}

/**
 * The first name to greet someone with, for {{first_name}} in templates.
 * Mail often carries the address itself as the display name, so a "name"
 * with an @ in it is ignored. "Smith, John" becomes John. With no usable
 * name, an address like john.smith@ gives John; anything else gives ''.
 */
function ecw_first_name(string $name, string $email = ''): string
{
    $name = trim(preg_replace('/\s+/u', ' ', trim($name, " \t\"'")) ?? '');
    if ($name !== '' && !str_contains($name, '@') && strcasecmp($name, $email) !== 0) {
        if (preg_match('/^([^,]+),\s*(\S.*)$/u', $name, $m)) $name = $m[2];
        $first = explode(' ', $name)[0];
        return preg_match('/\p{L}/u', $first) ? $first : '';
    }
    $local = strtolower(explode('@', $email)[0] ?? '');
    if (preg_match('/^([a-z\x{00C0}-\x{024F}\x{0370}-\x{03FF}]{2,})[._-][a-z\x{00C0}-\x{024F}\x{0370}-\x{03FF}]{2,}/u', $local, $m)
        && !preg_match('/^(info|sales|support|contact|office|admin|hello|team|mail|no|noreply|accounts?|billing|hr|jobs)$/', $m[1])) {
        return mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
    }
    return '';
}

/**
 * The best known full name for an address the user has mail with: the name
 * edited on the contact card, else the most used real display name. '' when
 * unknown or when the address is not in mail this user can see.
 */
function ecw_recipient_name(int $orgId, int $userId, string $email): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
    $db = ecw_db();
    require_once __DIR__ . '/shared_mailboxes.php';
    $mbParams = [$orgId];
    $mb = $db->prepare('SELECT mb.id FROM `' . ecw_table('mailboxes') . '` mb WHERE mb.organization_id = ?' . (ecw_private_mailboxes() ? ' AND ' . ecw_mailbox_visible_sql('mb', $userId, $mbParams) : ''));
    $mb->execute($mbParams);
    $ids = array_map('intval', $mb->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) return '';
    $st = $db->prepare('SELECT p.name, COUNT(*) AS n FROM `' . ecw_table('message_participants') . '` p
                          JOIN `' . ecw_table('email_messages') . '` m ON m.id = p.email_message_id
                         WHERE m.mailbox_id IN (' . implode(',', $ids) . ') AND LOWER(p.email) = ?
                         GROUP BY p.name ORDER BY n DESC LIMIT 10');
    $st->execute([$email]);
    $names = $st->fetchAll();
    if ($names === []) return '';
    $card = $db->prepare('SELECT edited_name FROM `' . ecw_table('email_addresses') . '` WHERE organization_id = ? AND normalized_email = ?');
    $card->execute([$orgId, $email]);
    $edited = trim((string)$card->fetchColumn());
    if ($edited !== '') return $edited;
    foreach ($names as $r) {
        $n = trim((string)$r['name'], " \t\"'");
        if ($n !== '' && !str_contains($n, '@')) return $n;
    }
    return '';
}

/** The user's unsent drafts, newest first, for the Drafts list. */
function ecw_drafts_list(int $organizationId, int $userId, int $limit = 100): array
{
    $st = ecw_db()->prepare(
        'SELECT d.id, d.mailbox_id, d.mode, d.subject, d.to_json, d.body_html, d.parts_json, d.case_id,
                d.reply_to_local_message_id, d.attachments_json, d.updated_at, mb.email_address,
                d.send_at, d.send_state, d.send_error, d.provider_draft_id, d.outlook_internet_id
           FROM `' . ecw_table('drafts') . '` d
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = d.mailbox_id
          WHERE d.organization_id = ? AND d.user_id = ? AND d.mailbox_id <=> ?
          ORDER BY d.updated_at DESC, d.id DESC
          LIMIT ' . max(1, min(200, $limit))
    );
    // The drafts of the account chosen at the top (lib/account.php).
    require_once __DIR__ . '/account.php';
    $st->execute([$organizationId, $userId, ecw_active_account_id($organizationId, $userId)]);
    return array_map(static function (array $d): array {
        $parts = json_decode((string)($d['parts_json'] ?? ''), true);
        $answer = is_array($parts) ? (string)$parts['answer'] : (string)$d['body_html'];
        $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>'], ' ', $answer)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return [
            'id'          => (int)$d['id'],
            'mailbox_id'  => (int)$d['mailbox_id'],
            'from'        => $d['email_address'],
            'mode'        => $d['mode'],
            'subject'     => (string)($d['subject'] ?? ''),
            'to'          => array_column(json_decode((string)($d['to_json'] ?? '[]'), true) ?: [], 'email'),
            'preview'     => mb_strimwidth($text, 0, 140, '…'),
            'attachments' => count(json_decode((string)($d['attachments_json'] ?? '[]'), true) ?: []),
            'case_id'     => $d['case_id'] === null ? null : (int)$d['case_id'],
            'updated_at'  => $d['updated_at'],
            'send_at'     => $d['send_state'] === 'scheduled' ? $d['send_at'] : null,
            // Held by Outlook: it goes at its time even when this PC is off.
            'held_in_outlook' => $d['send_state'] === 'scheduled' && !empty($d['outlook_internet_id']),
            'send_state'  => $d['send_state'],
            'send_error'  => $d['send_state'] === 'failed' ? $d['send_error'] : null,
            'in_mailbox'  => !empty($d['provider_draft_id']),
        ];
    }, $st->fetchAll());
}

/**
 * The draft replies in a conversation, shown in it the way Outlook does
 * ("Draft", Edit, Delete): the reader's own, and colleagues' shared ones.
 * Not those already on their way (sending); scheduled and failed ones are.
 */
function ecw_thread_drafts(int $organizationId, int $userId, int $threadId): array
{
    $st = ecw_db()->prepare('SELECT d.id, d.user_id, d.mode, d.to_json, d.body_html, d.parts_json, d.attachments_json, d.updated_at, d.send_state, d.send_at, d.send_error,
                                    d.shared_at, d.editing_user_id, d.editing_at, d.reply_to_local_message_id, u.name, u.email, eu.name AS e_name, eu.email AS e_email
                               FROM `' . ecw_table('drafts') . '` d
                               JOIN `' . ecw_table('email_messages') . '` m ON m.id = d.reply_to_local_message_id
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = d.user_id
                               LEFT JOIN `' . ecw_table('users') . "` eu ON eu.id = d.editing_user_id
                              WHERE d.organization_id = ? AND m.email_thread_id = ? AND (d.user_id = ? OR d.shared_at IS NOT NULL)
                                AND (d.send_state IS NULL OR d.send_state IN ('scheduled', 'failed'))
                              ORDER BY d.updated_at, d.id");
    $st->execute([$organizationId, $threadId, $userId]);
    return array_map(static function (array $d) use ($userId): array {
        $parts = json_decode((string)($d['parts_json'] ?? ''), true);
        $answer = is_array($parts) ? (string)($parts['answer'] ?? '') : (string)$d['body_html'];
        $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>'], ' ', $answer)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $editing = !empty($d['editing_at']) && strtotime($d['editing_at'] . ' UTC') >= time() - 120
            && $d['editing_user_id'] !== null && (int)$d['editing_user_id'] !== $userId;
        return [
            'id' => (int)$d['id'], 'mine' => (int)$d['user_id'] === $userId,
            'author' => (string)($d['name'] ?: $d['email'] ?: 'A colleague'), 'shared' => $d['shared_at'] !== null,
            'mode' => (string)$d['mode'], 'to' => array_column(json_decode((string)($d['to_json'] ?? '[]'), true) ?: [], 'email'),
            'preview' => mb_strimwidth($text, 0, 300, '…'), 'updated_at' => (string)$d['updated_at'],
            'send_state' => $d['send_state'], 'send_at' => $d['send_state'] === 'scheduled' ? $d['send_at'] : null,
            'send_error' => $d['send_state'] === 'failed' ? $d['send_error'] : null,
            'reply_to' => (int)$d['reply_to_local_message_id'],
            'editing' => $editing ? (string)($d['e_name'] ?: $d['e_email']) : null,
            // Its files, opened in the preview from the card (api draft_file).
            'files' => array_map(static fn($a) => ['id' => (string)$a['id'], 'filename' => (string)$a['filename'],
                'mime_type' => (string)($a['mime_type'] ?? ''), 'size' => (int)($a['size'] ?? 0)],
                array_values(array_filter(json_decode((string)($d['attachments_json'] ?? '[]'), true) ?: [], static fn($a) => !empty($a['id'])))),
        ];
    }, $st->fetchAll());
}

function ecw_draft_load(int $organizationId, int $draftId): ?array
{
    $st = ecw_db()->prepare('SELECT * FROM `' . ecw_table('drafts') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$draftId, $organizationId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function ecw_draft_delete(int $organizationId, int $draftId): void
{
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft === null) {
        return;
    }
    // Sent or discarded: the copy in the mailbox's Drafts folder goes too, and a copy Outlook
    // holds to send later is taken back (deleting a scheduled email must not let it go out).
    require_once __DIR__ . '/drafts_sync.php';
    ecw_draft_delete_remote($draft);
    if (!empty($draft['outlook_internet_id'])) {
        try { ecw_provider_for_mailbox((int)$draft['mailbox_id'])->cancelScheduled((string)$draft['outlook_internet_id']); ecw_draft_held_copy_gone($draft); }
        catch (Throwable $e) {
            ecw_log('scheduled email ' . $draftId . ' not taken back from Outlook: ' . $e->getMessage());
            throw new RuntimeException('Outlook could not be reached to cancel the scheduled email. Try again in a moment.');
        }
    }
    foreach (json_decode((string)($draft['attachments_json'] ?? '[]'), true) ?: [] as $a) {
        if (!empty($a['path']) && is_file($a['path'])) {
            @unlink($a['path']);
        }
    }
    @rmdir(ECW_DRAFT_STORAGE . '/' . $draftId);
    ecw_db()->prepare('DELETE FROM `' . ecw_table('drafts') . '` WHERE id = ? AND organization_id = ?')
        ->execute([$draftId, $organizationId]);
}

/** Attach an uploaded file to a draft. Bytes stay on disk until send. */
function ecw_draft_attach(int $organizationId, int $draftId, string $tmpPath, string $filename, string $mimeType): array
{
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft === null) {
        throw new RuntimeException('ecw: draft not found');
    }

    $size = (int)filesize($tmpPath);
    $mb = (int)(ECW_MAX_ATTACHMENT_BYTES / 1024 / 1024);
    if ($size > ECW_MAX_ATTACHMENT_BYTES) {
        throw new RuntimeException('This file is larger than ' . $mb . ' MB, more than an email can carry. Share it as a link instead.');
    }
    $already = array_sum(array_map(static fn($a) => (int)($a['size'] ?? 0), json_decode((string)($draft['attachments_json'] ?? '[]'), true) ?: []));
    if ($already + $size > ECW_MAX_ATTACHMENT_BYTES) {
        throw new RuntimeException('With this file the email would carry over ' . $mb . ' MB of files, more than most mail servers accept. Send it in a second email, or share it as a link.');
    }

    $dir = ECW_DRAFT_STORAGE . '/' . $draftId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('ecw: draft storage is not writable');
    }

    // The stored name is generated, never the user's: an uploaded filename is
    // attacker-controlled and must not decide a path.
    $stored = $dir . '/' . bin2hex(random_bytes(8));
    if (!@rename($tmpPath, $stored) && !@copy($tmpPath, $stored)) {
        throw new RuntimeException('ecw: could not store the attachment');
    }

    $attachments   = json_decode((string)($draft['attachments_json'] ?? '[]'), true) ?: [];
    $entry = [
        'id'        => bin2hex(random_bytes(4)),
        'filename'  => ecw_safe_filename($filename),
        'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        'size'      => $size,
        'path'      => $stored,
    ];
    $attachments[] = $entry;

    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET attachments_json = ?, provider_push_pending = 1 WHERE id = ?')
        ->execute([json_encode($attachments), $draftId]);

    unset($entry['path']);
    return $entry;
}

function ecw_safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string)preg_replace('/[^\p{L}\p{N}._ \-]/u', '_', $name);
    return mb_substr(trim($name) !== '' ? $name : 'attachment', 0, 200);
}

function ecw_normalize_address_list($list): array
{
    $out = [];
    foreach ((array)$list as $entry) {
        if (is_string($entry)) {
            $entry = ['email' => $entry];
        }
        $email = strtolower(trim((string)($entry['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['email' => $email, 'name' => $entry['name'] ?? null];
        }
    }
    return $out;
}

// -------------------------------------------------------------------- send

/**
 * Send a draft through the provider, then record the result.
 *
 * The sent copy is NOT written locally as an invented row. The provider files
 * it in Sent Items, delta sync picks it up, and it becomes a message the same
 * way every other message does — the step's "avoid inventing a separate
 * local-only sent-message system". All that is stored here is enough to
 * recognise it when it arrives.
 */
function ecw_draft_send(int $organizationId, int $draftId): array
{
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft === null) {
        throw new RuntimeException('ecw: draft not found');
    }
    $mailboxId = (int)$draft['mailbox_id'];
    $result = ecw_provider_for_mailbox($mailboxId)->sendMessage(ecw_draft_outgoing($draft));
    ecw_draft_delete($organizationId, $draftId);
    ecw_log("sent message from mailbox {$mailboxId}, internet id " . (string)($result['internet_message_id'] ?? '?'));
    ecw_draft_after_sent($organizationId, $draft, $result);
    return $result;
}

/** The email a draft becomes, ready for the provider (sanitized, files and pasted pictures attached). */
function ecw_draft_outgoing(array $draft): array
{
    $mailboxId = (int)$draft['mailbox_id'];
    $to  = json_decode((string)($draft['to_json'] ?? '[]'), true) ?: [];
    $cc  = json_decode((string)($draft['cc_json'] ?? '[]'), true) ?: [];
    $bcc = json_decode((string)($draft['bcc_json'] ?? '[]'), true) ?: [];

    if ($to === [] && $cc === [] && $bcc === []) {
        throw new RuntimeException('ecw: a message needs at least one recipient');
    }

    // Sanitize again at the boundary. The stored draft was sanitized on save,
    // but "sanitize immediately before it leaves the system" is the invariant
    // that survives someone later adding a different write path.
    $bodyHtml = ecw_outgoing_paragraphs(ecw_sanitize_compose_html((string)($draft['body_html'] ?? '')), isset($draft['user_id']) ? (int)$draft['user_id'] : null);

    $attachments = [];
    foreach (json_decode((string)($draft['attachments_json'] ?? '[]'), true) ?: [] as $a) {
        if (empty($a['path']) || !is_file($a['path'])) {
            continue;
        }
        $attachments[] = [
            'filename'      => $a['filename'],
            'mime_type'     => $a['mime_type'],
            'content_bytes' => base64_encode((string)file_get_contents($a['path'])),
            'is_inline'     => false,
            'content_id'    => null,
        ];
    }

    // Quill embeds pasted images as data: URIs. Left alone they make a
    // multi-megabyte body that some servers reject outright; as cid: inline
    // attachments they are ordinary embedded images everywhere.
    $bodyHtml = ecw_embed_quoted_images($mailboxId, $bodyHtml);
    [$bodyHtml, $inline] = ecw_extract_inline_images($bodyHtml);
    $attachments = array_merge($attachments, $inline);

    return [
        'subject'                      => (string)($draft['subject'] ?? ''),
        'body_html'                    => $bodyHtml,
        'to'                           => $to,
        'cc'                           => $cc,
        'bcc'                          => $bcc,
        'attachments'                  => $attachments,
        'reply_to_provider_message_id' => ecw_provider_id_for_reply($mailboxId, $draft['reply_to_local_message_id'] ?? null),
        // Kept on the email in Outlook: any copy of the app can see it already went (ecw_draft_sent_elsewhere).
        'send_key'                     => $draft['send_key'] ?? null,
    ];
}

/**
 * Was this scheduled email already sent, or handed to Outlook, by another copy of the app?
 * A database copied to a second PC (a backup put on a laptop) holds the same scheduled emails:
 * each copy thought they were still waiting, and both sent them. Outlook is asked for the email
 * with this draft's send key. Only Outlook mailboxes can be asked; a Gmail one is not (null).
 * Throws when Outlook cannot be reached: then nothing may be sent (it might go twice).
 *
 * @return ?array{state:string, id:?string, internet_id:?string} sent / held, or null: not yet
 */
function ecw_draft_sent_elsewhere(array $draft): ?array
{
    if (empty($draft['send_key'])) return null;
    $provider = ecw_provider_for_mailbox((int)$draft['mailbox_id']);
    if (!method_exists($provider, 'sentWithKey')) return null;
    $found = $provider->sentWithKey((string)$draft['send_key']);
    return in_array($found['state'], ['sent', 'held'], true) ? $found : null;
}

/** The scheduled email went, or is held by Outlook, from another copy of the app: this one sends nothing. */
function ecw_draft_went_elsewhere(int $organizationId, array $draft, array $found): void
{
    $t = ecw_table('drafts');
    if ($found['state'] === 'held') {
        // Outlook holds it and sends it at its time: this copy only follows it (ecw_drafts_outlook_followup).
        ecw_db()->prepare("UPDATE `{$t}` SET send_state = 'scheduled', outlook_internet_id = ?, outlook_held_id = ?, outlook_schedule_dirty = 0 WHERE id = ?")
            ->execute([$found['internet_id'], $found['id'], (int)$draft['id']]);
        ecw_log('scheduled email ' . $draft['id'] . ' is already held by Outlook (from another copy of the app): not sent again');
        return;
    }
    ecw_draft_delete($organizationId, (int)$draft['id']);
    ecw_log('scheduled email ' . $draft['id'] . ' was already sent (from another copy of the app): not sent again');
    ecw_draft_after_sent($organizationId, $draft, ['provider_message_id' => $found['id'], 'internet_message_id' => $found['internet_id']]);
}

/**
 * After an email went out (sent now, or by Outlook at its scheduled time): its Sent copy comes
 * in now and joins the draft's case, or the case its number names.
 */
function ecw_draft_after_sent(int $organizationId, array $draft, array $result): void
{
    $mailboxId = (int)$draft['mailbox_id'];
    $caseId = $draft['case_id'] ?? null;

    // Pull the Sent copy in now so the thread updates immediately instead of at
    // the next delta round.
    if (!empty($result['provider_message_id'])) {
        try {
            $ingested = ecw_ingest_sent_copy($mailboxId, (string)$result['provider_message_id']);

            // A conversation started from inside a case joins that case as soon
            // as there is a thread to join. The thread does not exist until now
            // — that is the whole reason the draft carried the case id.
            if (!empty($caseId) && !empty($ingested['thread_id'])) {
                try {
                    ecw_case_link_thread((int)$caseId, (int)$ingested['thread_id'], null, $draft['user_id'] === null ? null : (int)$draft['user_id']);
                } catch (RuntimeException $e) {
                    // Already linked elsewhere. The mail is sent either way.
                    ecw_log('could not link the new thread to its case: ' . $e->getMessage());
                }
            } elseif (!empty($ingested['thread_id'])) {
                // "CASE-00005" written in the subject or the text: join that case now,
                // not when the full body arrives with the next sync round.
                try {
                    require_once __DIR__ . '/automation.php';
                    $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", (string)($draft['body_html'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    ecw_automation_link_auto($organizationId, $draft['user_id'] === null ? null : (int)$draft['user_id'], (int)$ingested['thread_id'], (string)($draft['subject'] ?? ''), $plain);
                } catch (Throwable $e) {
                    ecw_log('could not link the sent email by its case number: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            // Not fatal: delta sync will collect it. The mail is already sent.
            ecw_log('could not ingest the sent copy immediately: ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------- undo send / send later

/** Seconds a sent email can still be taken back (the "Undo" in the toast). */
const ECW_UNDO_SEND_SECONDS = 10;

/**
 * Queue a draft to be sent: in ECW_UNDO_SEND_SECONDS (normal Send, so Undo
 * works), or at $sendAtUtc (Send later). The sync daemon sends it when due
 * (ecw_drafts_send_due). @return string the UTC send time
 */
function ecw_draft_schedule(int $organizationId, int $draftId, ?string $sendAtUtc = null): string
{
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft === null) throw new RuntimeException('Draft not found.');
    $to  = json_decode((string)($draft['to_json'] ?? '[]'), true) ?: [];
    $cc  = json_decode((string)($draft['cc_json'] ?? '[]'), true) ?: [];
    $bcc = json_decode((string)($draft['bcc_json'] ?? '[]'), true) ?: [];
    if ($to === [] && $cc === [] && $bcc === []) throw new InvalidArgumentException('Add at least one recipient.');
    $at = $sendAtUtc ?? gmdate('Y-m-d H:i:s', time() + ECW_UNDO_SEND_SECONDS);
    if (strtotime($at . ' UTC') > time() + 366 * 86400) throw new InvalidArgumentException('Choose a time within the next year.');
    // Its send key (ecw_draft_sent_elsewhere): kept once given, so every copy of the database
    // made from now on knows this email by the same key.
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . "` SET send_at = ?, send_state = 'scheduled', send_error = NULL, send_key = COALESCE(send_key, ?), outlook_hold_tried_at = NULL WHERE id = ? AND organization_id = ?")
        ->execute([$at, bin2hex(random_bytes(16)), $draftId, $organizationId]);
    // Send later on Outlook: Microsoft holds it and sends it at its time, this PC on or off.
    if ($sendAtUtc !== null && strtotime($at . ' UTC') > time() + ECW_OUTLOOK_HOLD_AFTER) ecw_draft_hold_in_outlook($organizationId, $draftId);
    return $at;
}

/**
 * Outlook's held copy was taken back (cancelScheduled): it leaves the Drafts list at once. Left
 * there (the list reads Outlook once a minute), it showed as a second email, "Open in Outlook".
 */
function ecw_draft_held_copy_gone(array $draft): void
{
    if (empty($draft['outlook_held_id'])) return;
    require_once __DIR__ . '/drafts_sync.php';
    ecw_mailbox_draft_forget((string)$draft['outlook_held_id']);
}

/**
 * Send later further ahead than this (seconds) is handed to Outlook. Nearer, and the normal
 * Send with its Undo, the app sends itself (it is on: the person just pressed the button).
 */
const ECW_OUTLOOK_HOLD_AFTER = 120;
/** A hand-over to Outlook that failed is tried again after this many seconds. */
const ECW_OUTLOOK_HOLD_RETRY = 300;

/**
 * Hand a scheduled draft to Outlook: the email is sent now with Outlook's own send time
 * (PR_DEFERRED_SEND_TIME), and Exchange holds it in the Outbox until then, then sends it, even
 * when this PC is off or the app is stopped. Gmail has no such thing: those stay with the app.
 * Not handed over (a refusal, no connection): it stays scheduled in the app, sent when the app runs.
 * @return bool handed to Outlook
 */
function ecw_draft_hold_in_outlook(int $organizationId, int $draftId): bool
{
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft === null || ($draft['send_state'] ?? null) !== 'scheduled' || empty($draft['send_at'])) return false;
    $mailbox = ecw_mailbox((int)$draft['mailbox_id']);
    if ($mailbox === null || ($mailbox['provider'] ?? 'microsoft') !== 'microsoft') return false;
    try {
        // Already handed over or sent by another copy of the app: followed, never a second one.
        if (($found = ecw_draft_sent_elsewhere($draft)) !== null) { ecw_draft_went_elsewhere($organizationId, $draft, $found); return true; }
        $provider = ecw_provider_for_mailbox((int)$draft['mailbox_id']);
        $result = $provider->sendMessage(ecw_draft_outgoing($draft) + ['defer_until' => (string)$draft['send_at']]);
        if (empty($result['internet_message_id'])) throw new RuntimeException('Outlook gave no Message-ID for the scheduled email');
    } catch (Throwable $e) {
        ecw_log('scheduled email ' . $draftId . ' not handed to Outlook, the app sends it: ' . $e->getMessage());
        return false;
    }
    // The app's own copy in Outlook's Drafts would now be a second one: it goes.
    require_once __DIR__ . '/drafts_sync.php';
    try { ecw_draft_delete_remote($draft); } catch (Throwable $e) { ecw_log('draft copy not removed: ' . $e->getMessage()); }
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET outlook_internet_id = ?, outlook_held_id = ?, outlook_schedule_dirty = 0, provider_draft_id = NULL, provider_push_pending = 0 WHERE id = ?')
        ->execute([(string)$result['internet_message_id'], $result['held_id'] ?? null, $draftId]);
    ecw_log('scheduled email ' . $draftId . ' is held by Outlook until ' . $draft['send_at']);
    return true;
}

/**
 * Emails Outlook holds (ecw_draft_hold_in_outlook), every few seconds from the sync daemon:
 *   - changed in the app since: the held copy is replaced by the new one;
 *   - its time passed: once Outlook sent it, its Sent copy comes in and the draft goes;
 *     still not sent half an hour later, or deleted in Outlook: the person is told.
 * @return int drafts looked at
 */
function ecw_drafts_outlook_followup(int $limit = 10): int
{
    $t = ecw_table('drafts');
    // Not handed to Outlook yet (no connection then, an antivirus in between, changed and not
    // handed over again): tried again every few minutes. Left with the app alone, it went only
    // if this PC was on at its time.
    $retry = ecw_db()->prepare("SELECT d.id, d.organization_id FROM `{$t}` d JOIN `" . ecw_table('mailboxes') . "` mb ON mb.id = d.mailbox_id
                                 WHERE d.send_state = 'scheduled' AND d.outlook_internet_id IS NULL AND mb.provider = 'microsoft'
                                   AND d.send_at > ? AND (d.outlook_hold_tried_at IS NULL OR d.outlook_hold_tried_at < ?) ORDER BY d.send_at LIMIT 3");
    $retry->execute([gmdate('Y-m-d H:i:s', time() + ECW_OUTLOOK_HOLD_AFTER), gmdate('Y-m-d H:i:s', time() - ECW_OUTLOOK_HOLD_RETRY)]);
    foreach ($retry->fetchAll() as $r) {
        ecw_db()->prepare("UPDATE `{$t}` SET outlook_hold_tried_at = ?, updated_at = updated_at WHERE id = ?")->execute([gmdate('Y-m-d H:i:s'), (int)$r['id']]);
        ecw_draft_hold_in_outlook((int)$r['organization_id'], (int)$r['id']);
    }
    $st = ecw_db()->prepare("SELECT id, organization_id FROM `{$t}` WHERE send_state = 'scheduled' AND outlook_internet_id IS NOT NULL
                               AND ((outlook_schedule_dirty = 1 AND send_at > ?) OR send_at <= ?) ORDER BY send_at LIMIT " . max(1, $limit));
    $st->execute([gmdate('Y-m-d H:i:s', time() + 60), gmdate('Y-m-d H:i:s', time() - 60)]);
    $n = 0;
    foreach ($st->fetchAll() as $row) {
        $draft = ecw_draft_load((int)$row['organization_id'], (int)$row['id']);
        if ($draft === null) continue;
        $n++;
        try {
            $provider = ecw_provider_for_mailbox((int)$draft['mailbox_id']);
            $internet = (string)$draft['outlook_internet_id'];
            if (strtotime((string)$draft['send_at'] . ' UTC') > time()) {
                // Changed after it was handed over: the held copy makes way for the new one.
                $gone = $provider->cancelScheduled($internet);
                if ($gone === 'sent') { ecw_draft_outlook_sent((int)$row['organization_id'], $draft, $internet); continue; }
                ecw_draft_held_copy_gone($draft);
                ecw_db()->prepare("UPDATE `{$t}` SET outlook_internet_id = NULL, outlook_held_id = NULL, outlook_schedule_dirty = 0 WHERE id = ?")->execute([(int)$draft['id']]);
                ecw_draft_hold_in_outlook((int)$row['organization_id'], (int)$draft['id']);
                continue;
            }
            $where = $provider->scheduledState($internet);
            if ($where['state'] === 'sent') { ecw_draft_outlook_sent((int)$row['organization_id'], $draft, $internet, $where['id']); continue; }
            $late = time() - strtotime((string)$draft['send_at'] . ' UTC');
            if (($where['state'] === 'held' && $late > 1800) || ($where['state'] === 'missing' && $late > 600)) {
                $why = $where['state'] === 'held' ? 'Outlook has not sent it yet: it is still in your Outbox.' : 'It is no longer in Outlook (deleted there?), and not in Sent Items.';
                ecw_db()->prepare("UPDATE `{$t}` SET send_state = 'failed', send_at = NULL, send_error = ?, outlook_internet_id = NULL, outlook_held_id = NULL WHERE id = ?")->execute([$why, (int)$draft['id']]);
                if ($draft['user_id'] !== null) {
                    require_once __DIR__ . '/notifications.php';
                    ecw_notify((int)$draft['organization_id'], (int)$draft['user_id'], null, 'send_failed',
                        'Your scheduled email "' . (trim((string)$draft['subject']) ?: '(no subject)') . '": ' . $why, 'app.php?draft=' . (int)$draft['id']);
                }
            }
        } catch (Throwable $e) {
            ecw_log('scheduled email ' . $row['id'] . ' not checked at Outlook: ' . $e->getMessage());
        }
    }
    return $n;
}

/** Outlook sent a held email: the draft goes, and its Sent copy comes in (and joins its case). */
function ecw_draft_outlook_sent(int $organizationId, array $draft, string $internetId, ?string $sentId = null): void
{
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET outlook_internet_id = NULL, outlook_held_id = NULL WHERE id = ?')->execute([(int)$draft['id']]);
    ecw_draft_delete($organizationId, (int)$draft['id']);
    ecw_log('scheduled email ' . $draft['id'] . ' was sent by Outlook, internet id ' . $internetId);
    ecw_draft_after_sent($organizationId, $draft, ['provider_message_id' => $sentId, 'internet_message_id' => $internetId]);
}

/** Undo send / cancel a scheduled send. False when it already went out. */
function ecw_draft_unschedule(int $organizationId, int $draftId): bool
{
    // Held by Outlook: it is taken back there first. Already sent by Outlook: too late.
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft !== null && !empty($draft['outlook_internet_id'])) {
        $gone = ecw_provider_for_mailbox((int)$draft['mailbox_id'])->cancelScheduled((string)$draft['outlook_internet_id']);
        if ($gone === 'sent') return false;
        ecw_draft_held_copy_gone($draft);
        ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET outlook_internet_id = NULL, outlook_held_id = NULL, outlook_schedule_dirty = 0, provider_push_pending = 1 WHERE id = ?')->execute([$draftId]);
    }
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . "` SET send_at = NULL, send_state = NULL WHERE id = ? AND organization_id = ? AND send_state = 'scheduled'");
    $st->execute([$draftId, $organizationId]);
    return $st->rowCount() > 0;
}

/**
 * Send what is due. Called every few seconds by the sync daemon. Each draft is
 * claimed first (scheduled -> sending) so two runs never send it twice. A
 * failure keeps the draft, marks it failed and tells its author.
 */
function ecw_drafts_send_due(int $limit = 10): int
{
    $t = ecw_table('drafts');
    // Not the ones Outlook holds: Outlook sends those (ecw_drafts_outlook_followup tidies up after).
    $st = ecw_db()->prepare("SELECT id, organization_id, user_id, subject FROM `{$t}` WHERE send_state = 'scheduled' AND outlook_internet_id IS NULL AND send_at <= ? ORDER BY send_at LIMIT " . max(1, $limit));
    $st->execute([ecw_now()]);
    $sent = 0;
    foreach ($st->fetchAll() as $d) {
        $claim = ecw_db()->prepare("UPDATE `{$t}` SET send_state = 'sending' WHERE id = ? AND send_state = 'scheduled'");
        $claim->execute([(int)$d['id']]);
        if ($claim->rowCount() === 0) continue;
        // Sent already, or held by Outlook, from another copy of the app: not again. Outlook
        // not reachable: it waits for the next run (sending would fail, or could go twice).
        $row = ecw_draft_load((int)$d['organization_id'], (int)$d['id']);
        try {
            $found = $row === null ? null : ecw_draft_sent_elsewhere($row);
        } catch (Throwable $e) {
            // Only a connection problem waits (no network, an antivirus in between, Microsoft busy);
            // anything else (signed out, mailbox gone) goes on: the send fails as before, its author is told.
            $transient = $e instanceof GraphException ? ($e->status === 0 || $e->isTransient())
                : str_contains($e->getMessage(), 'transport error');   // GraphHttp: no connection, or a certificate refused
            if ($transient) {
                ecw_db()->prepare("UPDATE `{$t}` SET send_state = 'scheduled' WHERE id = ? AND send_state = 'sending'")->execute([(int)$d['id']]);
                ecw_log('scheduled email ' . $d['id'] . ' waits: Outlook could not be asked whether it already went: ' . $e->getMessage());
                continue;
            }
            $found = null;
        }
        if ($found !== null) { ecw_draft_went_elsewhere((int)$d['organization_id'], $row, $found); continue; }
        try {
            ecw_draft_send((int)$d['organization_id'], (int)$d['id']);
            $sent++;
        } catch (Throwable $e) {
            $msg = preg_replace('/^ecw:\s*/', '', $e->getMessage()) ?? $e->getMessage();
            ecw_log('scheduled send failed for draft ' . $d['id'] . ': ' . $msg);
            ecw_db()->prepare("UPDATE `{$t}` SET send_state = 'failed', send_at = NULL, send_error = ? WHERE id = ?")
                ->execute([mb_substr($msg, 0, 500), (int)$d['id']]);
            if ($d['user_id'] !== null) {
                require_once __DIR__ . '/notifications.php';
                ecw_notify((int)$d['organization_id'], (int)$d['user_id'], null, 'send_failed',
                    'Your email “' . (trim((string)$d['subject']) ?: '(no subject)') . '” could not be sent: ' . mb_strimwidth($msg, 0, 120, '…') . ' It is in Drafts.',
                    'app.php?draft=' . (int)$d['id']);
            }
        }
    }
    ecw_drafts_outlook_followup();
    // A run that died mid-send leaves "sending" behind: put it back after 10 minutes.
    ecw_db()->prepare("UPDATE `{$t}` SET send_state = 'failed', send_error = 'Sending was interrupted. Check Sent Items before sending again.' WHERE send_state = 'sending' AND updated_at < ?")
        ->execute([gmdate('Y-m-d H:i:s', time() - 600)]);
    return $sent;
}

/** The provider id of the message being replied to, so Graph can thread it. */
function ecw_provider_id_for_reply(int $mailboxId, $localMessageId): ?string
{
    if (empty($localMessageId)) {
        return null;
    }
    $st = ecw_db()->prepare(
        'SELECT provider_message_id FROM `' . ecw_table('email_messages') . '` WHERE id = ? AND mailbox_id = ?'
    );
    $st->execute([(int)$localMessageId, $mailboxId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (string)$id;
}

/** Fetch one just-sent message and ingest it into the Sent folder. */
function ecw_ingest_sent_copy(int $mailboxId, string $providerMessageId): array
{
    $provider = ecw_provider_for_mailbox($mailboxId);
    $message  = $provider->getMessage($providerMessageId);

    $st = ecw_db()->prepare(
        'SELECT id FROM `' . ecw_table('mail_folders') . "` WHERE mailbox_id = ? AND folder_type = 'sent' LIMIT 1"
    );
    $st->execute([$mailboxId]);
    $folderId = $st->fetchColumn();

    return ecw_ingest_message($mailboxId, $folderId === false ? null : (int)$folderId, 'sent', $message);
}

/**
 * Replace data: image URIs with cid: references plus inline attachments.
 *
 * @return array{0:string, 1:array} rewritten html, inline attachments
 */
function ecw_extract_inline_images(string $html): array
{
    if (!str_contains($html, 'data:image/')) {
        return [$html, []];
    }

    $attachments = [];
    $html = (string)preg_replace_callback(
        '#src="data:(image/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\s]+)"#i',
        static function (array $m) use (&$attachments): string {
            $mime = strtolower($m[1]);
            $data = preg_replace('/\s+/', '', $m[2]);
            $cid  = 'ecw' . bin2hex(random_bytes(6));
            $ext  = explode('/', $mime)[1] ?? 'png';

            $attachments[] = [
                'filename'      => $cid . '.' . preg_replace('/[^a-z0-9]/', '', $ext),
                'mime_type'     => $mime,
                'content_bytes' => $data,
                'is_inline'     => true,
                'content_id'    => $cid,
            ];
            return 'src="cid:' . $cid . '"';
        },
        $html
    );

    return [$html, $attachments];
}

/** Remove only an attachment belonging to this organization's editable draft. */
function ecw_draft_detach(int $organizationId, int $draftId, string $attachmentId): void
{
    $draft = ecw_draft_load($organizationId, $draftId);
    if ($draft === null) { throw new RuntimeException('Draft not found.'); }
    $attachments = json_decode((string)($draft['attachments_json'] ?? '[]'), true) ?: [];
    $kept = []; $removed = null;
    foreach ($attachments as $entry) {
        if ((string)$entry['id'] === $attachmentId) { $removed = $entry; } else { $kept[] = $entry; }
    }
    if ($removed === null) { throw new RuntimeException('Attachment not found.'); }
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET attachments_json = ?, provider_push_pending = 1 WHERE id = ? AND organization_id = ?')
        ->execute([json_encode($kept), $draftId, $organizationId]);
    $path = realpath((string)($removed['path'] ?? ''));
    $root = realpath(ECW_DRAFT_STORAGE . '/' . $draftId);
    if ($path !== false && $root !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) { @unlink($path); }
}

/**
 * Is this the app's own address? Pictures copied from an open email carry the
 * full link the browser used: localhost, the office network (192.168.x.x),
 * Tailscale (100.64.0.0/10) or the public ECW_BASE_URL. Any other host is
 * someone else's website and is left alone.
 */
function ecw_is_own_host(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    if ($host === '' || $host === 'localhost' || $host === 'host.docker.internal' || str_ends_with($host, '.local') || str_ends_with($host, '.ts.net')) return true;
    $base = parse_url((string)getenv('ECW_BASE_URL'), PHP_URL_HOST);
    if (is_string($base) && strcasecmp($base, $host) === 0) return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;   // 10.x, 172.16-31.x, 192.168.x, 127.x
        $l = ip2long($host);
        if ($l !== false && ($l & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) return true;   // Tailscale / CGNAT
    }
    return false;
}

/**
 * Internal preview URLs must become inline attachments before leaving the app:
 * a quoted image, or a picture copied from an open email into the reply. The
 * recipient could never load a link to this server.
 */
function ecw_embed_quoted_images(int $mailboxId, string $html): string
{
    $cache = [];
    return (string)preg_replace_callback('~(<img\b[^>]*\bsrc=)([\'"])(.*?)\2~is', static function (array $m) use ($mailboxId, &$cache): string {
        $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        if (!$parts) return $m[0];
        $path = (string)($parts['path'] ?? '');
        if (isset($parts['host']) && !ecw_is_own_host((string)$parts['host'])) return $m[0];
        if ($path !== 'api/index.php' && $path !== 'api/' && !preg_match('~/api/(index\.php)?$~', $path)) return $m[0];
        parse_str($parts['query'] ?? '', $params);
        if (($params['action'] ?? '') !== 'attachment') return $m[0];
        $id = (int)($params['id'] ?? 0);
        if (!isset($cache[$id])) {
            // Any mailbox of this workspace: a picture copied from another shared mailbox works too.
            $st = ecw_db()->prepare('SELECT a.id, a.local_path, a.provider_attachment_id, m.provider_message_id, m.mailbox_id FROM `' . ecw_table('email_attachments') . '` a
                                       JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
                                       JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
                                      WHERE a.id = ? AND mb.organization_id = (SELECT organization_id FROM `' . ecw_table('mailboxes') . '` WHERE id = ?)');
            $st->execute([$id, $mailboxId]);
            $a = $st->fetch();
            if (!$a) throw new RuntimeException('A picture in this email is no longer available. Remove it, or paste it again, and send.');
            $bytes = !empty($a['local_path']) && is_file($a['local_path']) ? file_get_contents($a['local_path']) : ecw_download_attachment($a);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/webp','image/avif'], true) || strlen($bytes)>3*1024*1024) throw new RuntimeException('Quoted image cannot be embedded. Remove it before sending.');
            $cache[$id] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
        return $m[1] . $m[2] . $cache[$id] . $m[2];
    }, $html);
}
