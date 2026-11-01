<?php declare(strict_types=1);
/**
 * What happens next on a case: the next action, who does it, who it waits on,
 * and the day to look at it again — plus the case's reference numbers and
 * whose each conversation is (customer, supplier, internal).
 *
 * Three ways it gets filled in, all visible on the case:
 *   - people, on the case page or from the case list;
 *   - a rule, in every package: we email the supplier's conversation, so the
 *     case waits on the supplier; the supplier answers, so it waits on us
 *     (ecw_case_next_after_message, called from ingest);
 *   - the "Next step" agent (lib/agents.php, Business with AI), which reads
 *     the case's emails and sets all of it, references too. What it changed is
 *     marked as AI and can be undone in one click (next_prev_json).
 *
 * A person's choice is kept until a newer email arrives: the AI never
 * overwrites a decision made after the latest email it could read.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cases.php';

const ECW_WAITING_ON = ['us' => 'Us', 'customer' => 'Customer', 'supplier' => 'Supplier', 'internal' => 'Colleague'];
const ECW_PARTY_ROLES = ['customer' => 'Customer', 'supplier' => 'Supplier', 'internal' => 'Internal'];
const ECW_REF_KINDS = ['customer_order' => 'Customer order', 'supplier_ticket' => 'Supplier ticket / RMA', 'serial' => 'Serial number',
    'claim' => 'Claim', 'part' => 'Part number', 'invoice' => 'Invoice', 'other' => 'Reference'];
/** A person waiting on someone else gets a look-again date this many days ahead when none was given. */
const ECW_NEXT_DEFAULT_REVIEW_DAYS = 3;

/** Is the feature in this installation's package and switched on for the organization? */
function ecw_next_actions_enabled(int $orgId): bool
{
    require_once __DIR__ . '/features.php';
    return ecw_package_has('next_actions') && (ecw_features($orgId)['next_actions'] ?? true);
}

/** Lower case, letters and digits only: "SN 44-71" and "sn4471" are the same reference. */
function ecw_ref_norm(string $value): string
{
    return mb_substr((string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)), 0, 120);
}

/** The case's next step, references and conversation roles, for the case page. */
function ecw_case_next_get(int $caseId): array
{
    $st = ecw_db()->prepare('SELECT c.next_action, c.next_user_id, u.name AS next_user_name, u.email AS next_user_email, c.waiting_on, c.waiting_since,
                                    c.review_on, c.next_by, c.next_at, c.next_source_message_id, c.next_prev_json, m.occurred_at AS source_at, m.subject AS source_subject
                               FROM `' . ecw_table('cases') . '` c
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = c.next_user_id
                               LEFT JOIN `' . ecw_table('email_messages') . '` m ON m.id = c.next_source_message_id
                              WHERE c.id = ?');
    $st->execute([$caseId]);
    $r = $st->fetch() ?: [];
    return [
        'next_action'   => $r['next_action'] ?? null,
        'next_user_id'  => isset($r['next_user_id']) ? (int)$r['next_user_id'] : null,
        'next_user_name' => isset($r['next_user_id']) ? (string)($r['next_user_name'] ?: $r['next_user_email']) : null,
        'waiting_on'    => $r['waiting_on'] ?? null,
        'waiting_since' => $r['waiting_since'] ?? null,
        'review_on'     => $r['review_on'] ?? null,
        'by'            => $r['next_by'] ?? null,
        'at'            => $r['next_at'] ?? null,
        'source'        => !empty($r['next_source_message_id']) ? ['message_id' => (int)$r['next_source_message_id'], 'at' => $r['source_at'], 'subject' => $r['source_subject']] : null,
        'can_undo'      => ($r['next_by'] ?? null) === 'ai' && !empty($r['next_prev_json']),
        'refs'          => ecw_case_refs($caseId),
    ];
}

/**
 * Change the next step. $changes: next_action, next_user_id, waiting_on,
 * review_on (YYYY-MM-DD or empty), any subset. $by: user, ai or rule.
 * The AI's change keeps what it replaced, for undo.
 */
function ecw_case_next_set(int $orgId, int $caseId, array $changes, ?int $actorId, string $by = 'user', ?int $sourceMessageId = null): bool
{
    $st = ecw_db()->prepare('SELECT id, next_action, next_user_id, waiting_on, waiting_since, review_on, next_by FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
    $st->execute([$caseId, $orgId]);
    $case = $st->fetch();
    if (!$case) return false;

    $fields = [];
    $params = [];
    $changed = [];
    if (array_key_exists('next_action', $changes)) {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)$changes['next_action']));
        $text = $text === '' ? null : mb_substr($text, 0, 255);
        if ($text !== $case['next_action']) { $fields[] = 'next_action = ?'; $params[] = $text; $changed['next_action'] = $text; }
    }
    if (array_key_exists('next_user_id', $changes)) {
        $uid = $changes['next_user_id'] === null || $changes['next_user_id'] === '' ? null : (int)$changes['next_user_id'];
        if ($uid !== null) {
            $m = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('organization_users') . '` ou JOIN `' . ecw_table('users') . '` u ON u.id = ou.user_id WHERE ou.organization_id = ? AND u.id = ? AND u.disabled_at IS NULL');
            $m->execute([$orgId, $uid]);
            if (!$m->fetchColumn()) throw new InvalidArgumentException('That person is not in this workspace.');
        }
        if ($uid !== ($case['next_user_id'] === null ? null : (int)$case['next_user_id'])) { $fields[] = 'next_user_id = ?'; $params[] = $uid; $changed['next_user_id'] = $uid; }
    }
    if (array_key_exists('waiting_on', $changes)) {
        $w = (string)($changes['waiting_on'] ?? '');
        $w = $w === '' ? null : $w;
        if ($w !== null && !isset(ECW_WAITING_ON[$w])) throw new InvalidArgumentException('Choose who the case is waiting on.');
        if ($w !== $case['waiting_on']) {
            $fields[] = 'waiting_on = ?'; $params[] = $w;
            $fields[] = 'waiting_since = ?'; $params[] = $w === null ? null : ecw_now();
            $changed['waiting_on'] = $w;
        }
    }
    if (array_key_exists('review_on', $changes)) {
        $d = trim((string)($changes['review_on'] ?? ''));
        $date = $d === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($d !== '' && (!$date || $date->format('Y-m-d') !== $d)) throw new InvalidArgumentException('That date is not valid.');
        $d = $date ? $date->format('Y-m-d') : null;
        if ($d !== $case['review_on']) {
            $fields[] = 'review_on = ?'; $params[] = $d;
            $fields[] = 'review_notified_at = NULL';
            $changed['review_on'] = $d;
        }
    }
    if ($fields === []) return true;

    $fields[] = 'next_by = ?'; $params[] = $by;
    $fields[] = 'next_at = ?'; $params[] = ecw_now();
    if ($by === 'ai') {
        $fields[] = 'next_prev_json = ?';
        $params[] = json_encode(['next_action' => $case['next_action'], 'next_user_id' => $case['next_user_id'], 'waiting_on' => $case['waiting_on'],
            'waiting_since' => $case['waiting_since'], 'review_on' => $case['review_on'], 'next_by' => $case['next_by']]);
        $fields[] = 'next_source_message_id = ?'; $params[] = $sourceMessageId;
    } elseif ($by === 'user') {
        // A person decided: nothing of the AI's to undo any more.
        $fields[] = 'next_prev_json = NULL';
        $fields[] = 'next_source_message_id = NULL';
    }
    $params[] = $caseId;
    ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    ecw_case_log_event($caseId, 'CASE_NEXT_CHANGED', $actorId, null, null, $changed + ['by' => $by]);
    return true;
}

/** Put back what the AI replaced. */
function ecw_case_next_undo(int $orgId, int $caseId, int $actorId): bool
{
    $st = ecw_db()->prepare('SELECT next_by, next_prev_json FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
    $st->execute([$caseId, $orgId]);
    $r = $st->fetch();
    if (!$r || $r['next_by'] !== 'ai' || empty($r['next_prev_json'])) return false;
    $p = json_decode((string)$r['next_prev_json'], true) ?: [];
    ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET next_action = ?, next_user_id = ?, waiting_on = ?, waiting_since = ?, review_on = ?, review_notified_at = NULL,
                         next_by = ?, next_at = ?, next_prev_json = NULL, next_source_message_id = NULL WHERE id = ?')
        ->execute([$p['next_action'] ?? null, $p['next_user_id'] ?? null, $p['waiting_on'] ?? null, $p['waiting_since'] ?? null, $p['review_on'] ?? null,
            'user', ecw_now(), $caseId]);
    ecw_case_log_event($caseId, 'CASE_NEXT_CHANGED', $actorId, null, null, ['undo' => 'ai']);
    return true;
}

// ---------------------------------------------------------------- references

function ecw_case_refs(int $caseId): array
{
    $st = ecw_db()->prepare('SELECT id, kind, value, source, source_message_id FROM `' . ecw_table('case_refs') . '` WHERE case_id = ? ORDER BY FIELD(kind, "customer_order", "supplier_ticket", "serial", "claim", "part", "invoice", "other"), id');
    $st->execute([$caseId]);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'label' => ECW_REF_KINDS[$r['kind']] ?? 'Reference', 'value' => $r['value'],
        'source' => $r['source'], 'source_message_id' => $r['source_message_id'] === null ? null : (int)$r['source_message_id']], $st->fetchAll());
}

/** Add a reference; the same number twice (spaces and dashes aside) is kept once. Returns its id, or null when it was there already. */
function ecw_case_ref_add(int $caseId, string $kind, string $value, ?int $actorId, string $source = 'user', ?int $messageId = null): ?int
{
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    if (!isset(ECW_REF_KINDS[$kind])) $kind = 'other';
    $norm = ecw_ref_norm($value);
    if ($norm === '' || mb_strlen($value) > 120) throw new InvalidArgumentException('Type the reference number (up to 120 characters).');
    $st = ecw_db()->prepare('INSERT IGNORE INTO `' . ecw_table('case_refs') . '` (case_id, kind, value, value_norm, source, source_message_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$caseId, $kind, $value, $norm, $source === 'ai' ? 'ai' : 'user', $messageId, $actorId]);
    if ($st->rowCount() === 0) return null;
    $id = (int)ecw_db()->lastInsertId();
    $GLOBALS['ecw_refs_version'] = (int)($GLOBALS['ecw_refs_version'] ?? 0) + 1;
    ecw_case_log_event($caseId, 'CASE_REF_ADDED', $actorId, null, null, ['kind' => $kind, 'value' => $value, 'by' => $source]);
    return $id;
}

function ecw_case_ref_delete(int $caseId, int $refId, ?int $actorId): bool
{
    $st = ecw_db()->prepare('SELECT kind, value FROM `' . ecw_table('case_refs') . '` WHERE id = ? AND case_id = ?');
    $st->execute([$refId, $caseId]);
    $r = $st->fetch();
    if (!$r) return false;
    ecw_db()->prepare('DELETE FROM `' . ecw_table('case_refs') . '` WHERE id = ?')->execute([$refId]);
    $GLOBALS['ecw_refs_version'] = (int)($GLOBALS['ecw_refs_version'] ?? 0) + 1;
    ecw_case_log_event($caseId, 'CASE_REF_REMOVED', $actorId, null, null, ['kind' => $r['kind'], 'value' => $r['value']]);
    return true;
}

// ---------------------------------------------------------- conversation roles

function ecw_case_thread_role_set(int $caseId, int $threadId, ?string $role, ?int $actorId): bool
{
    if ($role !== null && !isset(ECW_PARTY_ROLES[$role])) {
        // Or one of the company's own roles (Settings → Case settings).
        require_once __DIR__ . '/case_settings.php';
        $org = ecw_db()->prepare('SELECT organization_id FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $org->execute([$caseId]);
        if (!isset(ecw_party_roles((int)$org->fetchColumn())[$role])) throw new InvalidArgumentException('Choose customer, supplier, internal or one of the roles added in Settings → Case settings.');
    }
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('case_threads') . '` SET party_role = ? WHERE case_id = ? AND email_thread_id = ?');
    $st->execute([$role, $caseId, $threadId]);
    ecw_case_log_event($caseId, 'CASE_THREAD_ROLE', $actorId, 'thread', $threadId, ['role' => $role]);
    return true;
}

/** Whose conversation this is in its case. Unmarked: the case's first conversation is taken to be the customer's. */
function ecw_case_thread_role(int $caseId, int $threadId): ?string
{
    $st = ecw_db()->prepare('SELECT email_thread_id, party_role FROM `' . ecw_table('case_threads') . '` WHERE case_id = ? ORDER BY sort_order, id');
    $st->execute([$caseId]);
    $rows = $st->fetchAll();
    foreach ($rows as $i => $r) {
        if ((int)$r['email_thread_id'] !== $threadId) continue;
        if ($r['party_role'] !== null) return $r['party_role'];
        $anyMarked = array_filter($rows, static fn($x) => $x['party_role'] !== null);
        return $i === 0 && !$anyMarked ? 'customer' : null;
    }
    return null;
}

// --------------------------------------------------------------- the rule

/**
 * A new email in a case conversation (from ingest): we wrote to the customer
 * or supplier, so the case now waits on them; they wrote, so it waits on us.
 * Only for recent mail — a mailbox's history being synced must not rewrite cases.
 */
function ecw_case_next_after_message(int $threadId, int $messageId, string $direction, ?string $occurredAt): void
{
    if (!in_array($direction, ['incoming', 'outgoing'], true)) return;
    if ($occurredAt !== null && strtotime($occurredAt . ' UTC') < time() - 2 * 86400) return;
    $case = ecw_case_for_thread($threadId);
    if ($case === null || $case['status'] === 'closed' || $case['deleted_at'] !== null) return;
    if (!ecw_next_actions_enabled((int)$case['organization_id'])) return;
    // Only the newest email of the case says whose turn it is: an older one that
    // arrives late (a slow server, a re-sync) must not undo a later answer.
    $st = ecw_db()->prepare('SELECT m.id FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('case_threads') . "` ct ON ct.email_thread_id = m.email_thread_id
                              WHERE ct.case_id = ? AND m.provider_removed_at IS NULL AND m.direction IN ('incoming', 'outgoing')
                              ORDER BY m.occurred_at DESC, m.id DESC LIMIT 1");
    $st->execute([(int)$case['id']]);
    if ((int)$st->fetchColumn() !== $messageId) return;
    $role = ecw_case_thread_role((int)$case['id'], $threadId);
    if ($direction === 'incoming') {
        // Whoever wrote, the ball is with us now (an internal note from a colleague too).
        if (($case['waiting_on'] ?? null) !== 'us') ecw_case_next_set((int)$case['organization_id'], (int)$case['id'], ['waiting_on' => 'us'], null, 'rule');
        return;
    }
    if ($role === 'customer' || $role === 'supplier') {
        $changes = ['waiting_on' => $role];
        if (empty($case['review_on']) || $case['review_on'] < ecw_tz_company_today((int)$case['organization_id'])) {
            $changes['review_on'] = ecw_next_working_day_after(ECW_NEXT_DEFAULT_REVIEW_DAYS, (int)$case['organization_id']);
        }
        ecw_case_next_set((int)$case['organization_id'], (int)$case['id'], $changes, null, 'rule');
    }
}

/** The date $days working days (Mon–Fri) from today (at the company, when given). */
function ecw_next_working_day_after(int $days, ?int $orgId = null): string
{
    require_once __DIR__ . '/timezone.php';
    $d = new DateTimeImmutable($orgId !== null ? ecw_tz_company_today($orgId) : gmdate('Y-m-d'), new DateTimeZone('UTC'));
    while ($days > 0) {
        $d = $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $days--;
    }
    return $d->format('Y-m-d');
}

// ---------------------------------------------------------------- reminders

/** Bell notification on the look-again day (once per date). Called every minute by the daemon. */
function ecw_cases_review_notify(): int
{
    require_once __DIR__ . '/license.php';
    if (!ecw_package_has('next_actions')) return 0;
    require_once __DIR__ . '/notifications.php';
    $st = ecw_db()->prepare('SELECT id, organization_id, case_number, name, next_action, next_user_id, owner_user_id, handler_user_id, waiting_on, review_on FROM `' . ecw_table('cases') . "`
                              WHERE review_on IS NOT NULL AND review_on <= ? AND review_notified_at IS NULL AND status <> 'closed' AND deleted_at IS NULL LIMIT 50");
    require_once __DIR__ . '/timezone.php';
    $st->execute([gmdate('Y-m-d', time() + 14 * 3600)]);   // the latest today anywhere; each case waits for its company's day
    $n = 0;
    foreach ($st->fetchAll() as $c) {
        if ($c['review_on'] > ecw_tz_company_today((int)$c['organization_id'])) continue;
        if (ecw_next_actions_enabled((int)$c['organization_id'])) {
            $who = $c['next_user_id'] ?: ($c['handler_user_id'] ?: $c['owner_user_id']);
            $text = 'Look at ' . $c['case_number'] . ' “' . $c['name'] . '” today'
                . ($c['next_action'] ? ': ' . $c['next_action'] : '')
                . (in_array($c['waiting_on'], ['customer', 'supplier', 'internal'], true) ? ' (waiting on the ' . strtolower(ECW_WAITING_ON[$c['waiting_on']]) . ')' : '');
            if ($who) ecw_notify((int)$c['organization_id'], (int)$who, null, 'review', $text, 'case.php?id=' . (int)$c['id'], (int)$c['id']);
        }
        ecw_db()->prepare('UPDATE `' . ecw_table('cases') . '` SET review_notified_at = ? WHERE id = ?')->execute([ecw_now(), (int)$c['id']]);
        $n++;
    }
    return $n;
}

// --------------------------------------------------------------- the AI's answer

/**
 * Apply what the "Next step" agent read from a case's emails. $j: next_action,
 * waiting_on, review_on, source ("M123"), references [{kind, value, source}],
 * thread_roles {"T12": "supplier"}. $newestAt: the newest email it read.
 * Returns what changed: ['next' => bool, 'refs' => int, 'roles' => int].
 */
function ecw_case_next_apply_ai(int $orgId, int $caseId, array $j, ?string $newestAt, array $messageIds): array
{
    $out = ['next' => false, 'refs' => 0, 'roles' => 0];
    $st = ecw_db()->prepare('SELECT next_by, next_at, next_user_id, owner_user_id, handler_user_id, status FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$caseId, $orgId]);
    $case = $st->fetch();
    if (!$case || $case['status'] === 'closed') return $out;
    $msgOf = static function ($ref) use ($messageIds): ?int {
        $id = (int)preg_replace('/\D/', '', (string)$ref);
        return in_array($id, $messageIds, true) ? $id : null;
    };

    // A person's decision made after the newest email stays.
    $personLater = $case['next_by'] === 'user' && $case['next_at'] !== null && $newestAt !== null && $case['next_at'] > $newestAt;
    if (!$personLater) {
        $changes = [];
        $action = trim((string)($j['next_action'] ?? ''));
        if ($action !== '') $changes['next_action'] = mb_substr($action, 0, 120);
        $w = (string)($j['waiting_on'] ?? '');
        if (isset(ECW_WAITING_ON[$w])) $changes['waiting_on'] = $w;
        $review = (string)($j['review_on'] ?? '');
        $today = ecw_tz_company_today((int)$case['organization_id']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $review) && $review >= $today && $review <= gmdate('Y-m-d', time() + 120 * 86400)) $changes['review_on'] = $review;
        elseif (in_array($w, ['customer', 'supplier', 'internal'], true)) $changes['review_on'] = ecw_next_working_day_after(ECW_NEXT_DEFAULT_REVIEW_DAYS, (int)$case['organization_id']);
        if ($case['next_user_id'] === null && ($who = $case['handler_user_id'] ?: $case['owner_user_id'])) $changes['next_user_id'] = (int)$who;
        if ($changes) {
            $before = ecw_case_next_get($caseId);
            ecw_case_next_set($orgId, $caseId, $changes, null, 'ai', $msgOf($j['source'] ?? null));
            $after = ecw_case_next_get($caseId);
            $out['next'] = [$before['next_action'], $before['waiting_on'], $before['review_on']] !== [$after['next_action'], $after['waiting_on'], $after['review_on']];
        }
    }
    foreach (array_slice((array)($j['references'] ?? []), 0, 8) as $ref) {
        if (!is_array($ref) || trim((string)($ref['value'] ?? '')) === '') continue;
        try {
            if (ecw_case_ref_add($caseId, (string)($ref['kind'] ?? 'other'), (string)$ref['value'], null, 'ai', $msgOf($ref['source'] ?? null)) !== null) $out['refs']++;
        } catch (InvalidArgumentException $e) { /* not a usable reference */ }
    }
    foreach ((array)($j['thread_roles'] ?? []) as $t => $role) {
        $threadId = (int)preg_replace('/\D/', '', (string)$t);
        if (!isset(ECW_PARTY_ROLES[(string)$role])) continue;
        $st = ecw_db()->prepare('UPDATE `' . ecw_table('case_threads') . '` SET party_role = ? WHERE case_id = ? AND email_thread_id = ? AND party_role IS NULL');
        $st->execute([(string)$role, $caseId, $threadId]);
        $out['roles'] += $st->rowCount();
    }
    return $out;
}
