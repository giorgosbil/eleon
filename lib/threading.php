<?php declare(strict_types=1);
/**
 * Email Case Workspace — thread resolution.
 *
 * The step's signal order, strongest first:
 *   1. provider conversation id
 *   2/3/4. Internet Message-ID, In-Reply-To, References
 *   5. normalized subject — weak fallback only
 *
 * WHY SUBJECT IS FENCED IN
 * ------------------------
 * "Do not rely only on subject" is easy to agree with and easy to violate by
 * accident: a bare subject match merges every "Invoice" and every "Re: Quote"
 * in the mailbox into one thread, and in this product that then merges unrelated
 * conversations into one case column. The fallback therefore fires only when
 * there is no conversation id and no reference chain at all, and even then it
 * requires a recent (ECW_SUBJECT_WINDOW_DAYS) message with a genuinely shared
 * participant. A generic subject between two unrelated correspondents does not
 * match.
 *
 * MERGING
 * -------
 * When a reference chain lands on a thread that has no conversation id and the
 * incoming message has one, the id is written onto that thread. This is how a
 * thread first assembled from headers becomes provider-identified without
 * creating a second thread for the same conversation.
 */

require_once __DIR__ . '/db.php';

const ECW_SUBJECT_WINDOW_DAYS = 30;

/** Strip reply/forward prefixes in the languages this product actually sees. */
function ecw_normalize_subject(?string $subject): string
{
    $s = trim((string)$subject);
    if ($s === '') {
        return '';
    }
    // Repeated because "Re: Fwd: Re: x" is normal after three hand-offs.
    do {
        $before = $s;
        $s = (string)preg_replace(
            '/^\s*(re|aw|antw|antwort|fw|fwd|wg|weitergeleitet|tr|rv|sv|vs)\s*(\[\d+\])?\s*:\s*/i',
            '',
            $s
        );
    } while ($s !== $before);

    return trim((string)preg_replace('/\s+/', ' ', $s));
}

/**
 * Find or create the thread a message belongs to.
 *
 * @param array $msg a normalized provider message
 * @return int email_thread id
 */
function ecw_resolve_thread(int $mailboxId, array $msg): int
{
    $pdo = ecw_db();
    $threads = ecw_table('email_threads');

    $conversationId   = $msg['provider_conversation_id'] ?? null;
    $normalizedSubject = ecw_normalize_subject($msg['subject'] ?? null);

    // 1. provider conversation id — strongest signal
    if ($conversationId !== null && $conversationId !== '') {
        $st = $pdo->prepare("SELECT id FROM `{$threads}` WHERE mailbox_id = ? AND provider_conversation_id = ?");
        $st->execute([$mailboxId, $conversationId]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }

    // 2-4. the reference chain: In-Reply-To first, then References newest-first,
    // because the nearest ancestor is the most likely to be stored locally.
    $candidates = [];
    if (!empty($msg['in_reply_to_message_id'])) {
        $candidates[] = (string)$msg['in_reply_to_message_id'];
    }
    foreach (array_reverse((array)($msg['references'] ?? [])) as $ref) {
        $candidates[] = (string)$ref;
    }

    if ($candidates !== []) {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $st = $pdo->prepare(
            'SELECT email_thread_id FROM `' . ecw_table('email_messages') . "`
              WHERE mailbox_id = ? AND internet_message_id IN ({$in})
              LIMIT 1"
        );
        $st->execute(array_merge([$mailboxId], $candidates));
        $threadId = $st->fetchColumn();
        if ($threadId !== false) {
            $threadId = (int)$threadId;
            ecw_thread_adopt_conversation_id($threadId, $conversationId);
            return $threadId;
        }
    }

    // 5. weak fallback: same normalized subject, recent, shared participant.
    if ($normalizedSubject !== '' && ($conversationId === null || $conversationId === '') && $candidates === []) {
        $threadId = ecw_thread_by_subject($mailboxId, $normalizedSubject, $msg);
        if ($threadId !== null) {
            ecw_thread_adopt_conversation_id($threadId, $conversationId);
            return $threadId;
        }
    }

    $st = $pdo->prepare(
        "INSERT INTO `{$threads}` (mailbox_id, provider_conversation_id, normalized_subject, message_count)
         VALUES (?, ?, ?, 0)"
    );
    $st->execute([$mailboxId, ($conversationId !== '' ? $conversationId : null), $normalizedSubject]);
    return (int)$pdo->lastInsertId();
}

/** Only ever fills a NULL; never overwrites an existing conversation id. */
function ecw_thread_adopt_conversation_id(int $threadId, ?string $conversationId): void
{
    if ($conversationId === null || $conversationId === '') {
        return;
    }
    ecw_db()->prepare(
        'UPDATE `' . ecw_table('email_threads') . '`
            SET provider_conversation_id = ?
          WHERE id = ? AND provider_conversation_id IS NULL'
    )->execute([$conversationId, $threadId]);
}

/**
 * The fenced-in subject fallback. Requires a shared participant so that two
 * unrelated "Invoice" threads never merge.
 */
function ecw_thread_by_subject(int $mailboxId, string $normalizedSubject, array $msg): ?int
{
    $participants = [];
    if (!empty($msg['from_email'])) {
        $participants[] = strtolower((string)$msg['from_email']);
    }
    foreach (array_merge((array)($msg['to'] ?? []), (array)($msg['cc'] ?? [])) as $r) {
        if (!empty($r['email'])) {
            $participants[] = strtolower((string)$r['email']);
        }
    }
    $participants = array_values(array_unique($participants));
    if ($participants === []) {
        return null;
    }

    $cutoff = gmdate('Y-m-d H:i:s', time() - ECW_SUBJECT_WINDOW_DAYS * 86400);
    $anchor = $msg['received_at'] ?? $msg['sent_at'] ?? null;
    if ($anchor !== null) {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime($anchor . ' UTC') - ECW_SUBJECT_WINDOW_DAYS * 86400);
    }

    $in = implode(',', array_fill(0, count($participants), '?'));
    $st = ecw_db()->prepare(
        'SELECT t.id
           FROM `' . ecw_table('email_threads') . '` t
           JOIN `' . ecw_table('email_messages') . "` m ON m.email_thread_id = t.id
          WHERE t.mailbox_id = ?
            AND t.normalized_subject = ?
            AND t.latest_message_at >= ?
            AND m.from_email IN ({$in})
          ORDER BY t.latest_message_at DESC
          LIMIT 1"
    );
    $st->execute(array_merge([$mailboxId, $normalizedSubject, $cutoff], $participants));
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Recompute everything the thread list renders, after messages land in a thread.
 *
 * The single writer of the thread-list cache (migration 004). Doing it here,
 * once per arriving message, is what lets the list query read one row per thread
 * and never open a message body — see that migration's header for the cost of
 * the alternative.
 *
 * Deliberately two statements rather than one correlated UPDATE: the preview
 * has to be derived in PHP (strip markup, collapse whitespace, cut to 255) and
 * an UPDATE that tried to do that in SQL would be unreadable and still wrong on
 * HTML entities.
 */
function ecw_thread_refresh_counters(int $threadId): void
{
    $pdo      = ecw_db();
    $messages = ecw_table('email_messages');

    $st = $pdo->prepare(
        "SELECT COUNT(*) AS message_count,
                MAX(occurred_at) AS latest_message_at,
                SUM(CASE WHEN is_read = 0 AND direction = 'incoming' THEN 1 ELSE 0 END) AS unread_count,
                MAX(has_attachments) AS has_attachments,
                SUM(is_flagged) AS flagged_count
           FROM `{$messages}`
          WHERE email_thread_id = ? AND provider_removed_at IS NULL"
    );
    $st->execute([$threadId]);
    $agg = $st->fetch() ?: [];

    // The newest message supplies who/what the list row shows. Body columns are
    // read here, for one message, so the list query never has to.
    $st = $pdo->prepare(
        "SELECT id, from_email, from_name, direction, body_text, body_html
           FROM `{$messages}`
          WHERE email_thread_id = ? AND provider_removed_at IS NULL
          ORDER BY occurred_at DESC, id DESC LIMIT 1"
    );
    $st->execute([$threadId]);
    $latest = $st->fetch();

    $pdo->prepare(
        'UPDATE `' . ecw_table('email_threads') . '`
            SET message_count = ?, latest_message_at = ?, unread_count = ?, has_attachments = ?,
                latest_message_id = ?, latest_from_email = ?, latest_from_name = ?,
                latest_direction = ?, latest_preview = ?, flagged_count = ?
          WHERE id = ?'
    )->execute([
        (int)($agg['message_count'] ?? 0),
        $agg['latest_message_at'] ?? null,
        (int)($agg['unread_count'] ?? 0),
        (int)($agg['has_attachments'] ?? 0) > 0 ? 1 : 0,
        $latest['id'] ?? null,
        $latest['from_email'] ?? null,
        $latest['from_name'] ?? null,
        $latest['direction'] ?? null,
        $latest !== false ? ecw_preview_text((string)($latest['body_text'] ?? ''), (string)($latest['body_html'] ?? '')) : null,
        (int)($agg['flagged_count'] ?? 0),
        $threadId,
    ]);
}

/** A one-line plain-text snippet for the thread list. Never markup. */
function ecw_preview_text(string $bodyText, string $bodyHtml, int $length = 255): ?string
{
    $source = trim($bodyText);
    if ($source === '' && $bodyHtml !== '') {
        // <br> and </p> are sentence boundaries; without this the preview runs
        // words together across lines.
        $source = (string)preg_replace('#<(br|/p|/div|/li)[^>]*>#i', ' ', $bodyHtml);
        $source = html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $source = trim((string)preg_replace('/\s+/u', ' ', $source));
    if ($source === '') {
        return null;
    }

    return mb_substr($source, 0, $length);
}
