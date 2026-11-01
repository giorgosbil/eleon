<?php declare(strict_types=1);
/**
 * Case groups' rules for new incoming email (Settings → Case groups). A group may say:
 *   - in the person's own words, which emails are for it ("a customer asks us to calibrate
 *     their instrument; not offers to buy instruments"): the AI reads each new email against
 *     it, with what it has learned (below), when the assistant triages the email (lib/agents.php);
 *   - words that are enough on their own, without AI ("βαθμονόμηση"): capitals and Greek
 *     accents do not matter, a word is matched from its start ("βαθμονόμ" finds "βαθμονόμηση",
 *     "rma" is not found inside "information"); checked the moment the email arrives;
 *   - the account the emails arrive in (both of the above then only look there).
 * On a match, for a conversation in no case yet:
 *   - suggest: a card "Start a case in <group>"; nothing happens by itself;
 *   - auto: the case is started in the group, the account's owner Responsible, and a card says
 *     so, with "Undo". The AI starts one by itself only when it is very sure; less sure, it suggests.
 * What the AI learns from, per group: the cases in it, and the feedback people gave
 * (case_group_feedback): a suggestion accepted ("yes"), dismissed or a started case undone ("no").
 * Personal and members groups only take the email of someone who can see the group.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/cases.php';

const ECW_GROUP_RULE_ACTIONS = ['off', 'suggest', 'auto'];
const ECW_GROUP_AI_SUGGEST = 70;   // the AI's confidence for a suggestion
const ECW_GROUP_AI_AUTO = 85;      // and for starting the case by itself

/** The rules as typed → as stored. Throws when switched on with nothing to go by. */
function ecw_group_rules_clean(int $orgId, array $r): array
{
    $words = array_values(array_unique(array_filter(array_map(static fn($w) => mb_substr(trim($w), 0, 80), preg_split('/[\r\n,;]+/u', (string)($r['words'] ?? '')) ?: []), static fn($x) => $x !== '')));
    $ai = trim((string)preg_replace('/[ \t]+/u', ' ', (string)($r['ai'] ?? '')));
    if (mb_strlen($ai) > 1500) throw new InvalidArgumentException('Keep the description for the AI under 1500 characters.');
    $mailboxId = (int)($r['mailbox_id'] ?? 0) ?: null;
    if ($mailboxId !== null) {
        $st = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('mailboxes') . '` WHERE id = ? AND organization_id = ?');
        $st->execute([$mailboxId, $orgId]);
        if (!$st->fetchColumn()) $mailboxId = null;
    }
    $action = in_array($r['action'] ?? null, ECW_GROUP_RULE_ACTIONS, true) ? (string)$r['action'] : 'off';
    if ($action !== 'off' && $words === [] && $ai === '') {
        throw new InvalidArgumentException('Describe which emails are for this group, or give words, so it knows which emails are its own.');
    }
    return ['words' => $words ? implode("\n", array_slice($words, 0, 100)) : null, 'ai' => $ai !== '' ? $ai : null, 'mailbox_id' => $mailboxId, 'action' => $action];
}

/** Lower case, without accents: "Βαθμονόμηση" → "βαθμονομηση". */
function ecw_group_rules_fold(string $s): string
{
    $s = mb_strtolower($s);
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($d)) return (string)preg_replace('/\p{Mn}+/u', '', $d);
    }
    return strtr($s, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u']);
}

/** Do the group's words match this email (in its account)? Why ("says “βαθμονόμηση”"), or null. */
function ecw_group_rule_matches(array $rules, int $mailboxId, string $subject, string $text): ?string
{
    $words = array_filter(explode("\n", (string)($rules['words'] ?? '')));
    if ($words === []) return null;
    if (($rules['mailbox_id'] ?? null) !== null && (int)$rules['mailbox_id'] !== $mailboxId) return null;
    $hay = ecw_group_rules_fold($subject . "\n" . mb_substr($text, 0, 8000));
    foreach ($words as $w) {
        $f = ecw_group_rules_fold(trim($w));
        if ($f !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($f, '/') . '/u', $hay)) return 'says “' . trim($w) . '”';
    }
    return null;
}

/** The groups with rules switched on that may take this person's email. */
function ecw_group_rules_list(int $orgId, ?int $ownerId): array
{
    $params = [$orgId];
    $visible = $ownerId !== null ? ' AND ' . ecw_case_group_visible_sql('g', $ownerId, $params) : " AND (g.default_sharing = 'company' OR g.default_sharing IS NULL)";
    $st = ecw_db()->prepare('SELECT g.id, g.name, g.match_words AS words, g.match_ai AS ai, g.match_mailbox_id AS mailbox_id, g.match_action AS action
                               FROM `' . ecw_table('case_groups') . "` g WHERE g.organization_id = ? AND g.match_action IN ('suggest', 'auto'){$visible} ORDER BY g.name");
    $st->execute($params);
    return $st->fetchAll();
}

/** The group whose words this email says: {id, name, action, why}, or null (the first by name). */
function ecw_group_rule_find(int $orgId, ?int $ownerId, int $mailboxId, string $subject, string $text): ?array
{
    foreach (ecw_group_rules_list($orgId, $ownerId) as $g) {
        $why = ecw_group_rule_matches($g, $mailboxId, $subject, $text);
        if ($why !== null) return ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'action' => (string)$g['action'], 'why' => $why];
    }
    return null;
}

/**
 * The groups the AI decides between for this email, each with what it learned:
 * [{id, name, action, description, examples: [...], not_for: [...]}].
 */
function ecw_group_rules_for_ai(int $orgId, ?int $ownerId, int $mailboxId): array
{
    $out = [];
    foreach (ecw_group_rules_list($orgId, $ownerId) as $g) {
        if (trim((string)$g['ai']) === '' || ($g['mailbox_id'] !== null && (int)$g['mailbox_id'] !== $mailboxId)) continue;
        $l = ecw_group_rules_learned((int)$g['id']);
        $out[] = ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'action' => (string)$g['action'], 'description' => (string)$g['ai'],
            'examples' => $l['examples'], 'not_for' => $l['not_for']];
    }
    return $out;
}

/**
 * What the AI has learned for a group: examples of its emails (its recent cases, suggestions
 * accepted) and emails that were not for it (suggestions dismissed, cases undone), newest first.
 * @return array{examples:list<string>, not_for:list<string>, cases:int, yes:int, no:int}
 */
function ecw_group_rules_learned(int $groupId): array
{
    $st = ecw_db()->prepare('SELECT name FROM `' . ecw_table('cases') . '` WHERE group_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 8');
    $st->execute([$groupId]);
    $examples = $st->fetchAll(PDO::FETCH_COLUMN);
    $n = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('cases') . '` WHERE group_id = ? AND deleted_at IS NULL');
    $n->execute([$groupId]);
    $fb = ecw_db()->prepare('SELECT verdict, subject, summary FROM `' . ecw_table('case_group_feedback') . '` WHERE group_id = ? ORDER BY id DESC LIMIT 60');
    $fb->execute([$groupId]);
    $notFor = [];
    $yes = 0; $no = 0;
    foreach ($fb->fetchAll() as $f) {
        $line = trim($f['subject'] . ($f['summary'] ? ' — ' . $f['summary'] : ''));
        if ($f['verdict'] === 'yes') { $yes++; if (count($examples) < 12) $examples[] = $line; }
        else { $no++; if (count($notFor) < 10) $notFor[] = $line; }
    }
    $clip = static fn(array $a) => array_values(array_unique(array_map(static fn($x) => mb_substr((string)$x, 0, 160), array_filter($a))));
    return ['examples' => $clip($examples), 'not_for' => $clip($notFor), 'cases' => (int)$n->fetchColumn(), 'yes' => $yes, 'no' => $no];
}

/** Someone said an email was ("yes") or was not ("no") for a group: the AI learns from it. */
function ecw_group_feedback(int $orgId, int $groupId, string $verdict, ?int $messageId, ?int $userId, ?string $summary = null): void
{
    $subject = '';
    if ($messageId) {
        $st = ecw_db()->prepare('SELECT subject FROM `' . ecw_table('email_messages') . '` WHERE id = ?');
        $st->execute([$messageId]);
        $subject = (string)$st->fetchColumn();
    }
    ecw_db()->prepare('INSERT INTO `' . ecw_table('case_group_feedback') . '` (organization_id, group_id, verdict, subject, summary, message_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $groupId, $verdict === 'yes' ? 'yes' : 'no', mb_substr($subject, 0, 255), $summary !== null ? mb_substr($summary, 0, 300) : null, $messageId, $userId]);
}

/** "Re: FW: Calibration of X" → "Calibration of X". */
function ecw_group_rules_case_name(string $subject): string
{
    $name = trim((string)preg_replace('/^(?:\s*(?:re|fw|fwd|aw|wg|σχετ|πρθ)\s*(?:\[\d+\])?\s*:\s*)+/iu', '', $subject));
    return mb_substr($name !== '' ? $name : 'New case', 0, 120);
}

/** "Start a case in <group>" for a card. */
function ecw_group_rules_new_case_action(array $g, string $name): array
{
    return ['type' => 'new_case', 'label' => 'Start a case in ' . mb_substr($g['name'], 0, 30) . ': ' . mb_substr($name, 0, 40), 'name' => mb_substr($name, 0, 120),
        'group_id' => (int)$g['id'], 'group_name' => $g['name'], 'why' => 'Group “' . $g['name'] . '”: ' . $g['why'] . '.'];
}

/**
 * Start the case in the group for the account's owner, and tell them with a card (with Undo).
 * @return ?int the case, or null when a copy of the app on another PC started it already (lib/auto_case_guard.php)
 */
function ecw_group_rules_start_case(int $orgId, int $ownerId, int $mailboxId, int $threadId, int $messageId, array $g, string $name, string $from): ?int
{
    require_once __DIR__ . '/agents.php';
    require_once __DIR__ . '/auto_case_guard.php';
    if (($elsewhere = ecw_auto_case_started_elsewhere($mailboxId, $messageId)) !== null) {
        ecw_log("thread {$threadId}: case {$elsewhere} was started from this email already (another copy of the app), not again");
        return null;
    }
    $caseId = ecw_case_create($orgId, $name, $ownerId, 'open', $mailboxId);
    ecw_auto_case_mark($mailboxId, $messageId, $caseId);
    ecw_case_set_group($orgId, $caseId, $g['id'], $ownerId);
    ecw_case_link_thread($caseId, $threadId, null, null);
    ecw_case_log_event($caseId, 'THREAD_AUTO_LINKED', null, 'thread', $threadId, ['reason' => 'the rules of the group ' . $g['name'] . ': ' . $g['why']]);
    ecw_log("thread {$threadId} started case {$caseId} in group {$g['id']} by its rules");
    $st = ecw_db()->prepare('SELECT case_number FROM `' . ecw_table('cases') . '` WHERE id = ?');
    $st->execute([$caseId]);
    ecw_suggest($orgId, $ownerId, 'triage', 'group', 'Case started in ' . $g['name'] . ': ' . $st->fetchColumn(), ['message_id' => $messageId, 'thread_id' => $threadId, 'case_id' => $caseId,
        'body' => 'The email from ' . $from . ' ' . $g['why'] . ', so a case was started in the group “' . $g['name'] . '”. Not right? Undo it, and the group learns from it.',
        'actions' => [['type' => 'open_url', 'label' => 'Open the case', 'url' => 'case.php?id=' . $caseId],
            ['type' => 'undo_case', 'label' => 'Undo: not for ' . mb_substr($g['name'], 0, 30), 'case_id' => $caseId, 'group_id' => (int)$g['id']]], 'confidence' => 90]);
    return $caseId;
}

/**
 * A fresh incoming email in no case: do a group's words take it? (The AI part of the rules runs
 * later, when the assistant reads the email.)
 * @return array{group_id:int, case_id:?int, action:string}|null what happened
 */
function ecw_group_rules_after_ingest(int $orgId, array $mailbox, ?int $ownerId, int $threadId, int $messageId, array $fields): ?array
{
    if (ecw_case_for_thread($threadId) !== null || ecw_case_for_copy($threadId) !== null) return null;
    $text = (string)($fields['body_text'] ?? '');
    if ($text === '' && !empty($fields['body_html'])) $text = html_entity_decode(strip_tags((string)$fields['body_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($text === '') {
        $st = ecw_db()->prepare('SELECT latest_preview FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
        $st->execute([$threadId]);
        $text = (string)$st->fetchColumn();
    }
    $subject = (string)($fields['subject'] ?? '');
    $g = ecw_group_rule_find($orgId, $ownerId, (int)$mailbox['id'], $subject, $text);
    if ($g === null || $ownerId === null) return null;
    require_once __DIR__ . '/agents.php';
    $name = ecw_group_rules_case_name($subject);
    $from = trim((string)($fields['from_name'] ?? '')) ?: (string)($fields['from_email'] ?? '');
    if ($g['action'] === 'auto') {
        $caseId = ecw_group_rules_start_case($orgId, $ownerId, (int)$mailbox['id'], $threadId, $messageId, $g, $name, $from);
        return ['group_id' => $g['id'], 'case_id' => $caseId, 'action' => $caseId !== null ? 'auto' : 'elsewhere'];
    }
    ecw_suggest($orgId, $ownerId, 'triage', 'group', 'For the group ' . $g['name'], ['message_id' => $messageId, 'thread_id' => $threadId,
        'body' => 'The email from ' . $from . ' ' . $g['why'] . ': the rules of the group “' . $g['name'] . '”.',
        'actions' => [ecw_group_rules_new_case_action($g, $name)], 'confidence' => 75]);
    return ['group_id' => $g['id'], 'case_id' => null, 'action' => 'suggest'];
}
