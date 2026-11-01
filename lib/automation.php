<?php declare(strict_types=1);
/**
 * Email Case Workspace — what happens automatically when new mail arrives.
 *
 * Called once per newly stored message by lib/ingest.php. It must never break
 * ingest, so every step is wrapped and logged.
 *
 * ONLY FRESH MAIL TRIGGERS ACTIONS
 * --------------------------------
 * The initial import and history backfill also insert messages. Reopening a
 * case, settling reminders or creating cases from mail that is months old would
 * be wrong, so those steps need received/sent within ECW_AUTOMATION_FRESH
 * seconds. Linking by case number is the exception: it is idempotent and right
 * for old mail too.
 *
 * Order for a fresh incoming message:
 *   1. wake the thread if it was snoozed
 *   2. link to a case, if not in one: its case number in the subject or the
 *      text ("CASE-00042"), or one of its reference numbers (an RMA, an order,
 *      a serial…) — only when it points to exactly one case
 *   3. organization routing rules, if still not in a case
 *   4. on its case: settle "if no reply" reminders, reopen a Waiting case
 *      (notifying Responsible / Handled by), emit email.received
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/followups.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/webhooks.php';

const ECW_AUTOMATION_FRESH = 86400;

function ecw_automation_after_ingest(array $mailbox, int $threadId, int $messageId, array $fields, string $direction): void
{
    $orgId = (int)$mailbox['organization_id'];
    $ownerId = $mailbox['connected_by_user_id'] !== null ? (int)$mailbox['connected_by_user_id'] : null;
    $when = (string)($fields['received_at'] ?? $fields['sent_at'] ?? '');
    $fresh = $when !== '' && strtotime($when . ' UTC') >= time() - ECW_AUTOMATION_FRESH;

    $step = static function (string $name, callable $fn): void {
        try {
            $fn();
        } catch (Throwable $e) {
            ecw_log("automation step {$name} failed: " . $e->getMessage());
        }
    };

    // Blocked senders: straight to Junk (lib/junk.php). Nothing else runs for it.
    if ($fresh && $direction === 'incoming' && !empty($fields['from_email'])) {
        require_once __DIR__ . '/junk.php';
        if (ecw_sender_is_blocked((int)$mailbox['id'], (string)$fields['from_email'])) {
            $step('blocked_sender', static fn() => ecw_junk_after_ingest((int)$mailbox['id'], $messageId, (string)$fields['from_email']));
            return;
        }
    }

    if ($fresh && $direction === 'incoming') {
        $step('unsnooze', static function () use ($threadId): void {
            // A reply wakes a snoozed conversation, here and in the mailbox.
            $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_threads') . '` WHERE id = ? AND (snoozed_until IS NOT NULL OR snooze_moves_json IS NOT NULL)');
            $st->execute([$threadId]);
            if ($st->fetchColumn()) ecw_thread_wake($threadId);
        });
    }

    $step('case_number', static fn() => ecw_automation_link_auto($orgId, $ownerId, $threadId, (string)($fields['subject'] ?? ''),
        trim(($fields['body_text'] ?? '') . "\n" . ($fields['quoted_text'] ?? ''))));

    if ($fresh && $direction === 'incoming') {
        $step('rules', static fn() => ecw_automation_route($orgId, $mailbox, $ownerId, $threadId, $messageId, $fields));
        $step('group_rules', static function () use ($orgId, $mailbox, $ownerId, $threadId, $messageId, $fields): void {
            require_once __DIR__ . '/group_rules.php';
            ecw_group_rules_after_ingest($orgId, $mailbox, $ownerId, $threadId, $messageId, $fields);
        });
        $step('case_effects', static fn() => ecw_automation_case_effects($orgId, $threadId, $fields));
    }
}

/** "[CASE-00042]" or "CASE-00042" anywhere in the subject links the thread to that case. */
function ecw_automation_link_by_number(int $orgId, ?int $ownerId, int $threadId, string $subject): void
{
    ecw_automation_link_auto($orgId, $ownerId, $threadId, $subject, '');
}

/**
 * Put a conversation that is in no case into the case it is about:
 *   1. a case number — in the subject first, otherwise in the text;
 *   2. otherwise a reference number of a case (lib/next_actions.php: an RMA,
 *      an order, a serial…), as a whole word: "RMA-889" matches "rma 889"
 *      but not "RMA-8890".
 * Only when the email points to exactly one case: two different cases in one
 * email are left for a person. Never because of the same company or people.
 * Linking shares the thread with the case's viewers, so the mailbox owner must
 * be able to see that case.
 *
 * @return array{case_id:int, reason:string}|null what it linked
 */
function ecw_automation_link_auto(int $orgId, ?int $ownerId, int $threadId, string $subject, string $text): ?array
{
    if (ecw_case_for_thread($threadId) !== null || ecw_case_for_copy($threadId) !== null) return null;
    // CASE-00042, and the company's own format when it has one (Settings → Case settings: SRV-{YYYY}-{N}).
    require_once __DIR__ . '/case_settings.php';
    $cs = ecw_case_settings($orgId);
    $forms = [];
    foreach ([['CASE-{N}', ECW_CASE_NUMBER_PAD], [(string)$cs['number_format'], (int)$cs['number_digits']]] as [$format, $pad]) {
        // Without letters before the number (just {YYYY}-{N}) any number in an email would match: not looked for.
        if (!preg_match('/[A-Za-z]/', str_replace(['{YYYY}', '{YY}'], '', $format === '' ? '' : substr($format, 0, -3)))) continue;
        $forms[] = str_replace(['\{YYYY\}', '\{YY\}'], ['\d{4}', '\d{2}'], preg_quote(substr($format, 0, -3), '/')) . '\d{' . max(1, $pad) . ',}';
    }
    $numberRe = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_unique($forms)) . ')(?![\p{N}])/iu';
    $found = null;
    foreach ([$subject, $text] as $where) {
        if ($where === '' || !preg_match_all($numberRe, $where, $m)) continue;
        $numbers = array_values(array_unique(array_map('strtoupper', $m[0])));
        if (count($numbers) !== 1) return null;   // two cases named: a person decides
        $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('cases') . '` WHERE organization_id = ? AND case_number = ? AND deleted_at IS NULL');
        $st->execute([$orgId, $numbers[0]]);
        $id = $st->fetchColumn();
        if ($id !== false) $found = ['case_id' => (int)$id, 'reason' => 'the case number ' . $numbers[0]];
        break;
    }
    if ($found === null) {
        require_once __DIR__ . '/next_actions.php';
        if (!ecw_next_actions_enabled($orgId)) return null;
        $hits = [];
        $hay = $subject . "\n" . $text;
        $flat = ecw_ref_norm($hay);
        foreach (ecw_automation_org_refs($orgId) as $r) {
            if (!str_contains($flat, $r['value_norm'])) continue;   // cheap test first
            // The reference's letters and digits, any separator between them, as a whole word.
            $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($r['value']), -1, PREG_SPLIT_NO_EMPTY);
            $re = '/(?<![\p{L}\p{N}])' . implode('[\s\-_\/.#:]*', array_map(static fn($p) => preg_quote($p, '/'), $parts)) . '(?![\p{L}\p{N}])/iu';
            if (preg_match($re, $hay)) $hits[(int)$r['case_id']] = $r['value'];
        }
        if (count($hits) !== 1) return null;
        $found = ['case_id' => (int)array_key_first($hits), 'reason' => 'the reference ' . reset($hits)];
    }
    if ($ownerId !== null && !ecw_case_visible($orgId, $ownerId, $found['case_id'])) return null;
    ecw_case_link_thread($found['case_id'], $threadId, null, null);
    ecw_case_log_event($found['case_id'], 'THREAD_AUTO_LINKED', null, 'thread', $threadId, ['reason' => $found['reason']]);
    ecw_log("thread {$threadId} linked to case {$found['case_id']} by {$found['reason']}");
    return $found;
}

/**
 * The organization's reference numbers that are safe to match on: at least 4
 * letters/digits with a digit in them, and a bare number only from 6 digits up
 * ("4471" could be a price; "RMA-889" or "SN 4471" cannot), on cases
 * that are not deleted. Cached a minute: this runs for every new email.
 */
function ecw_automation_org_refs(int $orgId): array
{
    static $cache = [];
    $version = (int)($GLOBALS['ecw_refs_version'] ?? 0);   // bumped when this process adds or removes one
    if (isset($cache[$orgId]) && $cache[$orgId][0] > time() - 60 && $cache[$orgId][2] === $version) return $cache[$orgId][1];
    $st = ecw_db()->prepare('SELECT r.case_id, r.value, r.value_norm FROM `' . ecw_table('case_refs') . '` r
                               JOIN `' . ecw_table('cases') . "` c ON c.id = r.case_id
                              WHERE c.organization_id = ? AND c.deleted_at IS NULL AND CHAR_LENGTH(r.value_norm) >= 4 AND r.value_norm REGEXP '[0-9]'
                                AND (r.value_norm REGEXP '[^0-9]' OR CHAR_LENGTH(r.value_norm) >= 6)");
    $st->execute([$orgId]);
    $cache[$orgId] = [time(), $st->fetchAll(), $version];
    return $cache[$orgId][1];
}

/**
 * Automation rules (Settings → Rules, admins): every enabled rule whose
 * conditions match this fresh incoming email runs its actions, in order,
 * until one says "stop here". Case actions leave mail that is already in a
 * case alone. Each run is recorded (rule_runs).
 */
function ecw_automation_route(int $orgId, array $mailbox, ?int $ownerId, int $threadId, int $messageId, array $fields): void
{
    require_once __DIR__ . '/license.php';
    if (!ecw_package_has('automation')) return;   // not in this installation's package
    $rules = array_values(array_filter(ecw_rules_list($orgId, 'route', null), static fn(array $r): bool => $r['enabled']));
    if ($rules === []) {
        return;
    }
    $mailboxId = (int)$mailbox['id'];
    // The text, when the mailbox sent it with the email; else the preview line.
    $body = (string)($fields['body_text'] ?? '');
    if ($body === '' && !empty($fields['body_html'])) $body = html_entity_decode(strip_tags((string)$fields['body_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($body === '') {
        $st = ecw_db()->prepare('SELECT latest_preview FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
        $st->execute([$threadId]);
        $body = (string)$st->fetchColumn();
    }
    $msg = [
        'from' => ['email' => $fields['from_email'] ?? '', 'name' => $fields['from_name'] ?? ''],
        'to' => json_decode((string)($fields['to_json'] ?? '[]'), true) ?: [],
        'cc' => json_decode((string)($fields['cc_json'] ?? '[]'), true) ?: [],
        'subject' => (string)($fields['subject'] ?? ''),
        'body' => $body,
        'has_attachments' => !empty($fields['has_attachments']),
        'mailbox_id' => $mailboxId,
        'folder_id' => ecw_rule_message_folder($messageId),
    ];
    $count = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . '` WHERE email_thread_id = ?');
    $count->execute([$threadId]);
    $firstOfConversation = (int)$count->fetchColumn() <= 1;
    $me = $ownerId !== null ? ecw_rule_me($ownerId) : ['emails' => [], 'name' => ''];

    foreach ($rules as $rule) {
        $acts = ecw_rule_route_actions($rule['actions']);
        if ($acts['first_only'] && !$firstOfConversation) continue;
        if (!ecw_rule_matches($rule['conditions'], $msg, $me)) continue;
        $did = [];
        foreach ($acts['list'] as $a) {
            try {
                $r = ecw_rule_run_action($orgId, $mailbox, $ownerId, $threadId, $messageId, $fields, $rule, $a);
                if ($r !== null) $did[] = $r;
            } catch (Throwable $e) {
                $did[] = ($a['type'] ?? '?') . ' failed: ' . mb_strimwidth($e->getMessage(), 0, 120, '…');
                ecw_log("rule {$rule['id']} action " . ($a['type'] ?? '?') . " failed on thread {$threadId}: " . $e->getMessage());
            }
        }
        try {
            ecw_db()->prepare('INSERT INTO `' . ecw_table('rule_runs') . '` (rule_id, thread_id, message_id, did_json, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$rule['id'], $threadId, $messageId, json_encode($did, JSON_UNESCAPED_UNICODE), ecw_now()]);
        } catch (Throwable $e) { /* before migration 027 */ }
        ecw_log("rule {$rule['id']} applied to thread {$threadId}: " . implode('; ', $did));
        if ($acts['stop']) break;
    }
}

/**
 * Run one action of a rule. @return ?string what it did (null: nothing to do here)
 * $earlier: run on an email from before (Settings → Rules → Run on earlier
 * emails, lib/rule_backfill.php): nobody is emailed, notified or sent a webhook
 * about old mail; cases, tags, moves and flags happen as for new mail.
 */
function ecw_rule_run_action(int $orgId, array $mailbox, ?int $ownerId, int $threadId, int $messageId, array $fields, array $rule, array $a, bool $earlier = false): ?string
{
    $mailboxId = (int)$mailbox['id'];
    $case = ecw_case_for_thread($threadId);
    $case = ($case !== null && $case['deleted_at'] === null) ? $case : null;
    // A colleague's copy of this email is in a case already: no second column, no second case.
    $case ??= in_array($a['type'] ?? '', ['add_to_case', 'create_case'], true) ? ecw_case_for_copy($threadId) : null;
    switch ($a['type'] ?? '') {
        case 'add_to_case':
            if ($case !== null) return null;   // already in a case
            if ($ownerId !== null && !ecw_case_visible($orgId, $ownerId, (int)$a['case_id'])) return null;
            ecw_case_link_thread((int)$a['case_id'], $threadId, null, null);
            return 'added to case ' . (int)$a['case_id'];

        case 'create_case':
            if ($case !== null) return null;
            // Started from this email already by a copy of the app on another PC (lib/auto_case_guard.php).
            require_once __DIR__ . '/auto_case_guard.php';
            if (($elsewhere = ecw_auto_case_started_elsewhere($mailboxId, $messageId)) !== null) return null;
            $name = trim((string)($fields['subject'] ?? '')) ?: '(no subject)';
            $caseId = ecw_case_create($orgId, mb_strimwidth($name, 0, 120, '…'), $ownerId);
            ecw_auto_case_mark($mailboxId, $messageId, $caseId);
            // No Responsible in the rule: a group with auto-assign picks the member with the fewest open cases.
            if (empty($a['owner_user_id']) && !empty($a['group_id'])) {
                require_once __DIR__ . '/case_extras.php';
                if (($auto = ecw_case_auto_owner((int)$a['group_id'])) !== null) {
                    $a['owner_user_id'] = $auto;
                    ecw_case_log_event($caseId, 'CASE_ASSIGNED', null, null, null, ['field' => 'owner_user_id', 'from' => $ownerId, 'to' => $auto, 'auto' => true]);
                }
            }
            ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET group_id = ?, owner_user_id = COALESCE(?, owner_user_id) WHERE id = ?')
                ->execute([$a['group_id'] ?? null, $a['owner_user_id'] ?? null, $caseId]);
            // The group decides who sees its cases (Settings → Case groups).
            if (!empty($a['group_id'])) { require_once __DIR__ . '/cases.php'; ecw_case_apply_group_sharing($caseId, (int)$a['group_id']); }
            ecw_case_link_thread($caseId, $threadId, null, null);
            if ($earlier) return 'opened case ' . $caseId;
            if (!empty($a['owner_user_id'])) {
                ecw_notify($orgId, (int)$a['owner_user_id'], null, 'assigned',
                    'New case from mail (rule “' . $rule['name'] . '”): ' . $name, 'case.php?id=' . $caseId, $caseId);
            }
            if (($public = ecw_webhook_case($caseId)) !== null) {
                ecw_webhook_emit($orgId, 'case.created', ['case' => $public, 'source' => 'rule']);
            }
            return 'opened case ' . $caseId;

        case 'set_owner':
            if ($case === null) return null;
            $to = (int)$a['user_id'];
            if ((int)($case['owner_user_id'] ?? 0) === $to) return null;
            ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET owner_user_id = ? WHERE id = ?')->execute([$to, (int)$case['id']]);
            ecw_case_log_event((int)$case['id'], 'CASE_ASSIGNED', null, null, null, ['field' => 'owner_user_id', 'from' => $case['owner_user_id'], 'to' => $to, 'rule' => $rule['name']]);
            if (!$earlier) ecw_notify($orgId, $to, null, 'assigned', $case['case_number'] . ' ' . $case['name'] . ' is yours now (rule “' . $rule['name'] . '”).', 'case.php?id=' . (int)$case['id'], (int)$case['id']);
            return 'Responsible set';

        case 'tag':
            if ($case === null) return null;
            require_once __DIR__ . '/case_extras.php';
            $tags = ecw_case_tags((int)$case['id']);
            if (in_array($a['tag'], $tags, true)) return null;
            ecw_case_tags_save((int)$case['id'], array_merge($tags, [$a['tag']]), null);
            return 'tagged ' . $a['tag'];

        case 'move':
            if ((int)($a['mailbox_id'] ?? 0) !== $mailboxId) return null;   // a folder of another mailbox
            require_once __DIR__ . '/mail_actions.php';
            $r = ecw_thread_move($mailboxId, $threadId, (string)(int)$a['folder_id']);
            return $r['moved'] ? 'moved to ' . $r['to_name'] : null;

        case 'flag':
            require_once __DIR__ . '/mail_actions.php';
            return ecw_thread_flag($mailboxId, $threadId, true) > 0 ? 'flagged' : null;

        case 'mark_read':
            require_once __DIR__ . '/mail.php';
            ecw_thread_mark_read($mailboxId, $threadId, true);
            return 'marked read';

        case 'snooze':
            ecw_thread_snooze($mailboxId, $threadId, gmdate('Y-m-d H:i:s', time() + (int)$a['hours'] * 3600));
            return 'snoozed ' . (int)$a['hours'] . ' h';

        case 'notify':
            if ($earlier) return null;
            $to = (int)$a['user_id'];
            if (ecw_access_mailbox($orgId, $to, $mailboxId, $threadId) === null) return null;   // they could not open it
            ecw_notify($orgId, $to, null, 'rule',
                'Rule “' . $rule['name'] . '”: ' . ($fields['from_name'] ?: $fields['from_email'] ?? 'someone') . ' — ' . ((string)($fields['subject'] ?? '') ?: '(no subject)'),
                'app.php?thread=' . $threadId . '&mailbox=' . $mailboxId, $case !== null ? (int)$case['id'] : null, $threadId);
            return 'notified user ' . $to;

        case 'auto_reply':
            if ($earlier) return null;   // never answer old mail
            return ecw_rule_auto_reply($orgId, $mailbox, $ownerId, $threadId, $messageId, $fields, $rule, (int)$a['template_id'], $case);

        case 'webhook':
            if ($earlier) return null;
            ecw_webhook_emit($orgId, 'rule.matched', [
                'rule' => $rule['name'],
                'email' => ['from' => $fields['from_email'] ?? null, 'subject' => $fields['subject'] ?? null, 'received_at' => $fields['received_at'] ?? null],
                'case' => $case !== null ? ecw_webhook_case((int)$case['id']) : null,
            ]);
            return 'sent to webhook';
    }
    return null;
}

/**
 * An automatic reply from a shared template. Never to robots (no-reply,
 * mailer-daemon…), never to our own mailboxes, at most once per conversation
 * per rule and once a day per sender — so two systems can never ping-pong.
 */
function ecw_rule_auto_reply(int $orgId, array $mailbox, ?int $ownerId, int $threadId, int $messageId, array $fields, array $rule, int $templateId, ?array $case): ?string
{
    $to = strtolower(trim((string)($fields['from_email'] ?? '')));
    if ($to === '' || preg_match('/(^|[._+-])(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounces?|notifications?)([._+-]|@)/i', $to)) return null;
    $own = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ? AND LOWER(email_address) = ?');
    $own->execute([$orgId, $to]);
    if ($own->fetchColumn()) return null;
    $runs = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('rule_runs') . '` r JOIN `' . ecw_table('rules') . "` ru ON ru.id = r.rule_id
                                WHERE ru.organization_id = ? AND r.did_json LIKE ? AND (r.thread_id = ? OR r.created_at >= ?)");
    $runs->execute([$orgId, '%"auto-replied to ' . addcslashes($to, '%_\\') . '"%', $threadId, gmdate('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$runs->fetchColumn() > 0) return null;

    $st = ecw_db()->prepare('SELECT subject, body_html FROM `' . ecw_table('templates') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$templateId, $orgId]);
    $t = $st->fetch();
    if (!$t) return null;
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    require_once __DIR__ . '/compose.php';
    $name = trim((string)($fields['from_name'] ?? ''));
    if (str_contains($name, '@')) $name = '';   // the address used as a display name
    $fill = [
        '{{first_name}}' => $h(ecw_first_name($name, $to)), '{{name}}' => $h($name),
        '{{my_name}}' => $h($mailbox['display_name'] ?? ''), '{{case_number}}' => $h($case['case_number'] ?? ''),
        '{{case_name}}' => $h($case['name'] ?? ''), '{{date}}' => $h((static function () use ($orgId) { require_once __DIR__ . '/timezone.php'; return ecw_tz_format(gmdate('Y-m-d H:i:s'), ecw_tz_company($orgId), 'j M Y'); })()),   // today at the company
    ];
    require_once __DIR__ . '/compose.php';
    $subject = ecw_prefix_subject((string)($fields['subject'] ?? ''), 'Re:');
    $draftId = ecw_draft_save($orgId, (int)$mailbox['id'], $ownerId, [
        'mode' => 'reply', 'in_reply_to_message_id' => $messageId, 'subject' => $subject,
        'body_html' => strtr((string)$t['body_html'], $fill), 'to' => [['email' => $to, 'name' => $name]],
    ]);
    ecw_draft_schedule($orgId, $draftId, null);   // the sync daemon sends it within seconds
    return 'auto-replied to ' . $to;
}
function ecw_automation_case_effects(int $orgId, int $threadId, array $fields): void
{
    $case = ecw_case_for_thread($threadId);
    ecw_followups_settle_replied($threadId, $case !== null ? (int)$case['id'] : null);
    if ($case === null || $case['deleted_at'] !== null) {
        return;
    }
    $caseId = (int)$case['id'];
    $from = (string)($fields['from_name'] ?: $fields['from_email'] ?? '');

    if ($case['status'] === 'waiting') {
        ecw_db()->prepare("UPDATE `" . ecw_table('cases') . "` SET status = 'open', sub_status = NULL, status_changed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'waiting'")->execute([$caseId]);
        ecw_case_log_event($caseId, 'CASE_REOPENED', null, 'email_thread', $threadId, ['reason' => 'reply received']);
        $notified = [];
        foreach (['owner_user_id', 'handler_user_id'] as $col) {
            $uid = isset($case[$col]) ? (int)$case[$col] : 0;
            if ($uid > 0 && !isset($notified[$uid])) {
                $notified[$uid] = true;
                ecw_notify($orgId, $uid, null, 'reopened',
                    $case['case_number'] . ' ' . $case['name'] . ' is open again: ' . $from . ' replied.',
                    'case.php?id=' . $caseId, $caseId, $threadId);
            }
        }
    }

    $public = ecw_webhook_case($caseId);
    if ($public !== null) {
        ecw_webhook_emit($orgId, 'email.received', [
            'case' => $public,
            'email' => ['from' => $fields['from_email'] ?? null, 'subject' => $fields['subject'] ?? null,
                'received_at' => $fields['received_at'] ?? null],
        ]);
    }
}
