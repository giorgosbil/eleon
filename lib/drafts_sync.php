<?php declare(strict_types=1);
/**
 * Drafts kept in step with the mailbox's own Drafts folder (Outlook / Gmail).
 *
 *  - App → mailbox: saving a draft marks it (provider_push_pending) and queues
 *    the drafts_push job ~20 s later, so typing does not make a call per key.
 *    The job creates or updates the matching draft; attachments are copied once.
 *  - Mailbox → app: the Drafts list also shows drafts started in Outlook/Gmail
 *    (ecw_mailbox_drafts). Opening one imports it (ecw_draft_import) and links
 *    it, so later edits update that same draft.
 *  - Sending or discarding deletes the mailbox copy (ecw_draft_delete calls
 *    ecw_draft_delete_remote).
 *
 * Only drafts of mailboxes the person connected themselves are shown — never a
 * colleague's.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/mailbox.php';

const ECW_DRAFT_PUSH_DELAY = 20;

function ecw_drafts_push_soon(int $mailboxId): void
{
    require_once __DIR__ . '/sync/queue.php';
    try { ecw_queue_enqueue($mailboxId, null, 'drafts_push', 'user', ECW_DRAFT_PUSH_DELAY); }
    catch (Throwable $e) { ecw_log('drafts_push enqueue failed: ' . $e->getMessage()); }
}

/** The drafts_push job. @return array{pushed:int, failed:int} */
function ecw_drafts_push(int $mailboxId): array
{
    require_once __DIR__ . '/compose.php';
    $t = ecw_table('drafts');
    $st = ecw_db()->prepare("SELECT * FROM `{$t}` WHERE mailbox_id = ? AND provider_push_pending = 1 AND (send_state IS NULL OR send_state = 'failed') ORDER BY updated_at LIMIT 20");
    $st->execute([$mailboxId]);
    $rows = $st->fetchAll();
    if ($rows === []) return ['pushed' => 0, 'failed' => 0];
    $provider = ecw_provider_for_mailbox($mailboxId);
    $pushed = $failed = 0;
    foreach ($rows as $d) {
        try {
            $attachments = [];
            foreach (json_decode((string)($d['attachments_json'] ?? '[]'), true) ?: [] as $a) {
                if (empty($a['path']) || !is_file($a['path'])) continue;
                $attachments[] = [
                    'local_id' => (string)$a['id'], 'filename' => (string)$a['filename'], 'mime_type' => (string)$a['mime_type'],
                    'provider_attachment_id' => $a['provider_attachment_id'] ?? null,
                    'content_bytes' => base64_encode((string)file_get_contents($a['path'])),
                ];
            }
            $res = $provider->saveDraft([
                'subject' => (string)($d['subject'] ?? ''),
                // Same paragraph handling as sending, or Outlook shows every Enter as a blank line.
                'body_html' => ecw_outgoing_paragraphs(ecw_sanitize_compose_html((string)($d['body_html'] ?? '')), isset($d['user_id']) ? (int)$d['user_id'] : null),
                'to' => json_decode((string)($d['to_json'] ?? '[]'), true) ?: [],
                'cc' => json_decode((string)($d['cc_json'] ?? '[]'), true) ?: [],
                'bcc' => json_decode((string)($d['bcc_json'] ?? '[]'), true) ?: [],
                'reply_to_provider_message_id' => ecw_provider_id_for_reply($mailboxId, $d['reply_to_local_message_id'] ?? null),
                'attachments' => $attachments,
            ], $d['provider_draft_id'] ?: null);
            // Always remember the link (so the next push updates, never duplicates);
            // clear "pending" only if nobody edited the draft meanwhile.
            ecw_db()->beginTransaction();
            $cur = ecw_db()->prepare("SELECT attachments_json, updated_at FROM `{$t}` WHERE id = ? FOR UPDATE");
            $cur->execute([(int)$d['id']]);
            $now = $cur->fetch();
            if ($now !== false) {
                $list = json_decode((string)($now['attachments_json'] ?? '[]'), true) ?: [];
                foreach ($list as &$a) if (isset($res['attachments'][(string)$a['id']])) $a['provider_attachment_id'] = $res['attachments'][(string)$a['id']];
                unset($a);
                $unchanged = (string)$now['updated_at'] === (string)$d['updated_at'];
                ecw_db()->prepare("UPDATE `{$t}` SET provider_draft_id = ?, attachments_json = ?, provider_push_pending = ?, updated_at = updated_at WHERE id = ?")
                    ->execute([$res['id'], json_encode($list), $unchanged ? 0 : 1, (int)$d['id']]);
            }
            ecw_db()->commit();
            $pushed++;
        } catch (Throwable $e) {
            if (ecw_db()->inTransaction()) ecw_db()->rollBack();
            $failed++;
            ecw_log('draft ' . $d['id'] . ' could not be copied to the mailbox: ' . $e->getMessage());
        }
    }
    return ['pushed' => $pushed, 'failed' => $failed];
}

/** Remove the mailbox copy of a draft (sent or discarded). Best effort. */
function ecw_draft_delete_remote(array $draft): void
{
    if (empty($draft['provider_draft_id'])) return;
    ecw_mailbox_draft_forget((string)$draft['provider_draft_id']);
    try { ecw_provider_for_mailbox((int)$draft['mailbox_id'])->deleteDraft((string)$draft['provider_draft_id']); }
    catch (Throwable $e) { ecw_log('mailbox draft not removed: ' . $e->getMessage()); }
}

/**
 * A mailbox draft that is gone (deleted here, sent, or deleted in Outlook):
 * out of the cached list at once, and ignored for 10 minutes in case the
 * mailbox still lists it for a moment. Without this a deleted draft came back
 * as an "Outlook draft" for up to a minute, and opening it failed.
 */
function ecw_mailbox_draft_forget(string $providerDraftId): void
{
    if ($providerDraftId === '') return;
    // Kept in the database (app setting), not only the session: the API often closes the
    // session before this runs, and a draft forgotten there came back in the list for ever.
    require_once __DIR__ . '/license.php';
    $gone = ecw_mailbox_drafts_gone();
    $gone[$providerDraftId] = time();
    arsort($gone);
    ecw_app_setting_set('drafts_gone', json_encode(array_slice($gone, 0, 500, true)));
    if (session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) {
        foreach ((array)($_SESSION['ecw_mailbox_drafts'] ?? []) as $k => $cache) {
            if (!is_array($cache) || empty($cache['rows'])) continue;
            $_SESSION['ecw_mailbox_drafts'][$k]['rows'] = array_values(array_filter($cache['rows'], static fn($r) => ($r['id'] ?? '') !== $providerDraftId));
        }
    }
}

/** Mailbox drafts known to be gone (provider id => when), for 30 days. */
function ecw_mailbox_drafts_gone(): array
{
    require_once __DIR__ . '/license.php';
    $gone = json_decode((string)ecw_app_setting('drafts_gone'), true);
    return array_filter(is_array($gone) ? $gone : [], static fn($t) => time() - (int)$t < 30 * 86400);
}

/**
 * Drafts started in Outlook / Gmail that are not in the app yet, of the
 * account chosen at the top (lib/account.php), cached a minute per session.
 */
function ecw_mailbox_drafts(int $organizationId, int $userId): array
{
    require_once __DIR__ . '/account.php';
    $account = ecw_active_account_id($organizationId, $userId);
    $st = ecw_db()->prepare('SELECT id, provider, email_address FROM `' . ecw_table('mailboxes') . "` WHERE organization_id = ? AND connected_by_user_id = ? AND sync_status NOT IN ('disconnected', 'needs_reauth') AND id <=> ?");
    $st->execute([$organizationId, $userId, $account]);
    $linked = array_flip(ecw_db()->query('SELECT provider_draft_id FROM `' . ecw_table('drafts') . '` WHERE provider_draft_id IS NOT NULL AND organization_id = ' . $organizationId)->fetchAll(PDO::FETCH_COLUMN));
    // The app's own scheduled emails that Outlook holds: already in the list as the app's drafts.
    $heldHere = array_flip(ecw_db()->query('SELECT outlook_internet_id FROM `' . ecw_table('drafts') . '` WHERE outlook_internet_id IS NOT NULL AND organization_id = ' . $organizationId)->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    $gone = ecw_mailbox_drafts_gone();
    foreach ($st->fetchAll() as $mb) {
        $key = (int)$mb['id'];
        $cache = $_SESSION['ecw_mailbox_drafts'][$key] ?? null;
        // Read again when one of the app's drafts changed since: a scheduled email Outlook holds
        // is replaced there when it is edited, and the old reading showed that one as a second email.
        $changed = ecw_db()->prepare('SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM `' . ecw_table('drafts') . '` WHERE mailbox_id = ?');
        $changed->execute([$key]);
        if (!is_array($cache) || time() - (int)$cache['at'] > 60 || (int)$changed->fetchColumn() >= (int)$cache['at']) {
            try { $cache = ['at' => time(), 'rows' => ecw_provider_for_mailbox($key)->listDrafts(25)]; }
            catch (Throwable $e) { ecw_log('mailbox drafts not listed: ' . $e->getMessage()); $cache = ['at' => time(), 'rows' => []]; }
            $_SESSION['ecw_mailbox_drafts'][$key] = $cache;
        }
        foreach ($cache['rows'] as $r) {
            if (isset($linked[$r['id']]) || isset($gone[$r['id']]) || (($r['internet_id'] ?? '') !== '' && isset($heldHere[$r['internet_id']]))) continue;
            // Scheduled in Outlook: it goes by itself at send_at. Opened in Outlook, never imported here
            // (the app would then hold a second copy that could be sent as well).
            $scheduled = !empty($r['send_at']);
            $out[] = [
                'id' => null, 'provider_id' => $r['id'], 'mailbox_id' => $key, 'from' => $mb['email_address'],
                'from_mailbox_only' => true, 'provider_label' => $mb['provider'] === 'google' ? 'Gmail' : 'Outlook',
                'mode' => 'new', 'subject' => $r['subject'], 'to' => $r['to'], 'preview' => mb_strimwidth($r['preview'], 0, 140, '…'),
                'attachments' => $r['has_attachments'] ? 1 : 0, 'case_id' => null, 'updated_at' => $r['updated_at'],
                'send_at' => $scheduled ? $r['send_at'] : null, 'send_state' => $scheduled ? 'scheduled' : null, 'send_error' => null, 'in_mailbox' => true,
                'scheduled_in_mailbox' => $scheduled, 'web_link' => (string)($r['web_link'] ?? ''), 'conversation_id' => (string)($r['conversation_id'] ?? ''),
            ];
        }
    }
    return $out;
}

/**
 * Emails scheduled in Outlook (Schedule send / Delay delivery) that belong to this
 * conversation, for its timeline: same Outlook conversation, same mailbox. From the
 * Drafts list's cached read (a minute), so opening conversations costs no extra calls.
 */
function ecw_thread_mailbox_scheduled(int $organizationId, int $userId, int $threadId): array
{
    $st = ecw_db()->prepare('SELECT t.mailbox_id, t.provider_conversation_id FROM `' . ecw_table('email_threads') . '` t
                               JOIN `' . ecw_table('mailboxes') . "` mb ON mb.id = t.mailbox_id
                              WHERE t.id = ? AND mb.organization_id = ? AND mb.connected_by_user_id = ? AND mb.provider = 'microsoft'");
    $st->execute([$threadId, $organizationId, $userId]);
    $t = $st->fetch();
    if (!$t || (string)$t['provider_conversation_id'] === '') return [];
    $out = [];
    foreach (ecw_mailbox_drafts($organizationId, $userId) as $d) {
        if (empty($d['scheduled_in_mailbox']) || (int)$d['mailbox_id'] !== (int)$t['mailbox_id'] || $d['conversation_id'] !== (string)$t['provider_conversation_id']) continue;
        $out[] = [
            'id' => 'mbx:' . $d['provider_id'], 'remote' => true, 'mine' => true, 'author' => '', 'shared' => false, 'mode' => 'reply',
            'to' => $d['to'], 'preview' => $d['preview'], 'updated_at' => $d['updated_at'], 'send_state' => 'scheduled', 'send_at' => $d['send_at'],
            'send_error' => null, 'reply_to' => 0, 'editing' => null, 'provider_label' => $d['provider_label'], 'web_link' => $d['web_link'],
        ];
    }
    return $out;
}

/** Bring a mailbox draft into the app, linked to it. @return int the local draft id */
function ecw_draft_import(int $organizationId, int $userId, int $mailboxId, string $providerDraftId): int
{
    require_once __DIR__ . '/compose.php';
    $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('drafts') . '` WHERE organization_id = ? AND provider_draft_id = ?');
    $st->execute([$organizationId, $providerDraftId]);
    if (($existing = $st->fetchColumn()) !== false) return (int)$existing;

    foreach ((array)($_SESSION['ecw_mailbox_drafts'][$mailboxId]['rows'] ?? []) as $r) {
        if (($r['id'] ?? '') === $providerDraftId && !empty($r['send_at'])) {
            throw new RuntimeException('This email is scheduled in Outlook and will be sent from there. Open it in Outlook to change it.');
        }
    }
    $remote = ecw_provider_for_mailbox($mailboxId)->getDraft($providerDraftId);
    $draftId = ecw_draft_save($organizationId, $mailboxId, $userId, [
        'mode' => 'new', 'subject' => $remote['subject'], 'body_html' => $remote['body_html'],
        'parts' => ecw_draft_import_parts((string)$remote['body_html']),
        'to' => $remote['to'], 'cc' => $remote['cc'], 'bcc' => $remote['bcc'],
    ]);
    $map = [];
    foreach ($remote['attachments'] as $a) {
        if (strlen($a['bytes']) > ECW_MAX_ATTACHMENT_BYTES) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'ecwdraft');
        file_put_contents($tmp, $a['bytes']);
        try { $entry = ecw_draft_attach($organizationId, $draftId, $tmp, $a['filename'], $a['mime_type']); $map[$entry['id']] = $a['provider_attachment_id']; }
        catch (RuntimeException $e) { ecw_log('draft import: "' . $a['filename'] . '" left out: ' . $e->getMessage()); }   // over the files-per-email limit
        finally { if (is_file($tmp)) @unlink($tmp); }
    }
    $row = ecw_draft_load($organizationId, $draftId);
    $list = json_decode((string)($row['attachments_json'] ?? '[]'), true) ?: [];
    foreach ($list as &$a) if (isset($map[$a['id']])) $a['provider_attachment_id'] = $map[$a['id']];
    unset($a);
    ecw_db()->prepare('UPDATE `' . ecw_table('drafts') . '` SET provider_draft_id = ?, provider_push_pending = 0, attachments_json = ? WHERE id = ?')
        ->execute([$providerDraftId, json_encode($list), $draftId]);
    unset($_SESSION['ecw_mailbox_drafts'][$mailboxId]);
    return $draftId;
}

/**
 * A draft written in Outlook, in the composer's three parts: what was written (the editor),
 * the signature and the earlier messages (both shown and sent exactly as they are). The editor
 * only knows simple tables: a signature laid out in a table, pasted into it, came out as a black
 * grid, its pictures moved. So from the first table on, the rest goes with the signature.
 * @return array{answer:string, signature:string, quote:string, quote_changed:bool}
 */
function ecw_draft_import_parts(string $html): array
{
    require_once __DIR__ . '/body.php';
    $split = ecw_split_quoted($html, '');
    $sig = ecw_split_signature_html((string)($split['body_html'] ?? ''));
    $answer = (string)$sig['body_html'];
    $signature = (string)$sig['signature_html'];
    if (stripos($answer, '<table') !== false) {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $answer . '</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $body = $doc->getElementsByTagName('body')->item(0);
        // Outlook wraps everything in one or two <div>s: go in while there is a single block.
        while ($body !== null) {
            $blocks = array_values(array_filter(iterator_to_array($body->childNodes), static fn($n) => !($n instanceof DOMText && trim($n->nodeValue) === '')));
            if (count($blocks) === 1 && $blocks[0] instanceof DOMElement && strtolower($blocks[0]->nodeName) === 'div') { $body = $blocks[0]; continue; }
            break;
        }
        $before = ''; $rest = ''; $inRest = false;
        foreach ($body?->childNodes ?? [] as $n) {
            if (!$inRest && $n instanceof DOMElement && (strtolower($n->nodeName) === 'table' || $n->getElementsByTagName('table')->length > 0)) $inRest = true;
            if ($inRest) $rest .= $doc->saveHTML($n); else $before .= $doc->saveHTML($n);
        }
        if ($rest !== '') { $answer = $before; $signature = $rest . $signature; }
    }
    return ['answer' => $answer, 'signature' => $signature, 'quote' => (string)($split['quoted_html'] ?? ''), 'quote_changed' => false];
}
