<?php declare(strict_types=1);
/**
 * Email Case Workspace — automation rules on earlier emails.
 *
 * Settings → Rules → "Run on earlier emails…": an admin picks a date range,
 * sees what matches (how many emails and conversations, a few examples), and
 * starts it. The background worker (bin/worker.php) then runs the rule on
 * each conversation, a few at a time, with a progress bar on the page.
 *
 * WHAT IS THE SAME AS FOR NEW MAIL
 *   The rule's conditions (Folder too: "the email is in Support") and its
 *   actions: open a case, add to a case, Responsible, tag, move, flag, read,
 *   snooze. One run per conversation: its earliest matching email, as when
 *   that email arrived. A conversation the rule already handled is skipped,
 *   so running it twice changes nothing.
 *
 * WHAT IS DIFFERENT
 *   Nobody is emailed, notified or sent a webhook about old mail (automatic
 *   replies, Notify and Webhook are left out). Received emails only, like the
 *   rules for new mail. Junk and Deleted Items are left out unless the rule's
 *   Folder condition names one of them. Optionally the cases it opens are
 *   closed straight away (history only), closed on the email's date.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/automation.php';

const ECW_RULE_BACKFILL_SCAN = 20000;   // emails looked at, at most
const ECW_RULE_BACKFILL_MAX  = 5000;    // conversations in one run, at most

/** One automation rule of the organization, as ecw_rules_list() gives it. */
function ecw_rule_backfill_rule(int $orgId, int $ruleId): ?array
{
    foreach (ecw_rules_list($orgId, 'route', null) as $r) if ((int)$r['id'] === $ruleId) return $r;
    return null;
}

/** "YYYY-MM-DD", or an InvalidArgumentException in plain words. */
function ecw_rule_backfill_date(string $v, string $what): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) || !strtotime($v . ' 00:00:00 UTC')) throw new InvalidArgumentException('Choose the ' . $what . ' date.');
    return $v;
}

/**
 * The earlier emails the rule matches, one per conversation (its earliest
 * matching email), oldest first.
 * @return array{items:list<array>, emails:int, conversations:int, already:int, capped:bool}
 */
function ecw_rule_backfill_scan(int $orgId, array $rule, string $from, string $to): array
{
    $conds = $rule['conditions'];
    $acts = ecw_rule_route_actions($rule['actions']);
    $all = ($conds['match'] ?? 'all') !== 'any';
    $M = ecw_table('email_messages');
    $where = ['mb.organization_id = ?', 'm.occurred_at >= ?', 'm.occurred_at < ?', 'm.provider_removed_at IS NULL', "m.direction = 'incoming'"];
    $params = [$orgId, $from . ' 00:00:00', gmdate('Y-m-d', strtotime($to . ' 00:00:00 UTC') + 86400) . ' 00:00:00'];
    $folderNamed = false;
    $needBody = false;
    foreach ($conds['items'] ?? [] as $c) {
        if ($c['field'] === 'folder') $folderNamed = true;
        if ($c['field'] === 'body') $needBody = true;
        if (!$all) continue;
        // Narrow the scan in SQL where a condition must hold anyway (the match itself is ecw_rule_matches below).
        if ($c['field'] === 'mailbox') { $where[] = 'm.mailbox_id = ?'; $params[] = (int)$c['value']; }
        elseif ($c['field'] === 'folder') { $where[] = 'm.mail_folder_id = ?'; $params[] = (int)$c['value']; }
        elseif ($c['field'] === 'subject' && $c['op'] === 'contains') { $where[] = 'm.subject LIKE ?'; $params[] = '%' . ecw_like_escape((string)$c['value']) . '%'; }
        elseif ($c['field'] === 'from' && $c['op'] === 'domain') { $where[] = 'm.from_email LIKE ?'; $params[] = '%@' . ecw_like_escape((string)$c['value']); }
        elseif ($c['field'] === 'attachment') { $where[] = 'm.has_attachments = 1'; }
    }
    if (!$folderNamed) $where[] = "(f.folder_type IS NULL OR f.folder_type NOT IN ('junk', 'deleted'))";
    if ($acts['first_only']) $where[] = "m.id = (SELECT MIN(fm.id) FROM `{$M}` fm WHERE fm.email_thread_id = m.email_thread_id)";

    $st = ecw_db()->prepare(
        'SELECT m.id, m.email_thread_id, m.mailbox_id, m.mail_folder_id, m.subject, m.from_email, m.from_name, m.to_json, m.cc_json, m.has_attachments, m.occurred_at,
                ' . ($needBody ? 'm.body_text' : "''") . ' AS body_text, f.name AS folder_name, mb.email_address AS mailbox_address, mb.connected_by_user_id
           FROM `' . $M . '` m
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
           LEFT JOIN `' . ecw_table('mail_folders') . '` f ON f.id = m.mail_folder_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY m.occurred_at, m.id LIMIT ' . (ECW_RULE_BACKFILL_SCAN + 1)
    );
    $st->execute($params);
    $rows = $st->fetchAll();
    $capped = count($rows) > ECW_RULE_BACKFILL_SCAN;
    if ($capped) array_pop($rows);

    $me = [];
    $byThread = [];
    $emails = 0;
    foreach ($rows as $r) {
        $owner = $r['connected_by_user_id'] !== null ? (int)$r['connected_by_user_id'] : 0;
        $me[$owner] ??= $owner > 0 ? ecw_rule_me($owner) : ['emails' => [], 'name' => ''];
        $msg = [
            'from' => ['email' => (string)$r['from_email'], 'name' => (string)$r['from_name']],
            'to' => json_decode((string)$r['to_json'], true) ?: [],
            'cc' => json_decode((string)$r['cc_json'], true) ?: [],
            'subject' => (string)$r['subject'],
            'body' => (string)$r['body_text'],
            'has_attachments' => (int)$r['has_attachments'] === 1,
            'mailbox_id' => (int)$r['mailbox_id'],
            'folder_id' => $r['mail_folder_id'] !== null ? (int)$r['mail_folder_id'] : null,
        ];
        if (!ecw_rule_matches($conds, $msg, $me[$owner])) continue;
        $emails++;
        $byThread[(int)$r['email_thread_id']] ??= $r;   // oldest first: the first matching email of the conversation
    }

    // Conversations the rule already handled (new mail, or an earlier run) are skipped.
    $already = [];
    if ($byThread !== []) {
        foreach (array_chunk(array_keys($byThread), 1000) as $chunk) {
            $q = ecw_db()->prepare('SELECT DISTINCT thread_id FROM `' . ecw_table('rule_runs') . '` WHERE rule_id = ? AND thread_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $q->execute(array_merge([(int)$rule['id']], $chunk));
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $already[(int)$t] = true;
        }
    }
    $items = array_values(array_filter($byThread, static fn($r) => !isset($already[(int)$r['email_thread_id']])));
    return ['items' => $items, 'emails' => $emails, 'conversations' => count($byThread), 'already' => count($already), 'capped' => $capped];
}

/** What a run would do, for the page to show before anyone presses Run. */
function ecw_rule_backfill_preview(int $orgId, int $ruleId, string $from, string $to): array
{
    $rule = ecw_rule_backfill_rule($orgId, $ruleId);
    if ($rule === null) throw new InvalidArgumentException('Rule not found.');
    $from = ecw_rule_backfill_date($from, 'start');
    $to = ecw_rule_backfill_date($to, 'end');
    if ($from > $to) [$from, $to] = [$to, $from];
    $scan = ecw_rule_backfill_scan($orgId, $rule, $from, $to);
    $types = array_column(ecw_rule_route_actions($rule['actions'])['list'], 'type');
    $sample = array_map(static fn($r) => ['subject' => (string)$r['subject'], 'from' => (string)($r['from_name'] ?: $r['from_email']), 'at' => $r['occurred_at'],
        'folder' => (string)($r['folder_name'] ?? ''), 'mailbox' => (string)$r['mailbox_address']], array_slice(array_reverse($scan['items']), 0, 8));
    return [
        'from' => $from, 'to' => $to,
        'emails' => $scan['emails'], 'conversations' => $scan['conversations'], 'already' => $scan['already'],
        'to_run' => min(count($scan['items']), ECW_RULE_BACKFILL_MAX), 'too_many' => count($scan['items']) > ECW_RULE_BACKFILL_MAX, 'capped' => $scan['capped'],
        'sample' => $sample,
        'opens_cases' => in_array('create_case', $types, true),
        'left_out' => array_values(array_intersect($types, ['auto_reply', 'notify', 'webhook'])),
    ];
}

/** Start a run. @return int its id */
function ecw_rule_backfill_start(int $orgId, int $userId, int $ruleId, string $from, string $to, bool $closeCases): int
{
    $rule = ecw_rule_backfill_rule($orgId, $ruleId);
    if ($rule === null) throw new InvalidArgumentException('Rule not found.');
    $from = ecw_rule_backfill_date($from, 'start');
    $to = ecw_rule_backfill_date($to, 'end');
    if ($from > $to) [$from, $to] = [$to, $from];
    $busy = ecw_db()->prepare("SELECT 1 FROM `" . ecw_table('rule_backfills') . "` WHERE rule_id = ? AND status = 'running'");
    $busy->execute([$ruleId]);
    if ($busy->fetchColumn()) throw new InvalidArgumentException('This rule is already running on earlier emails. Wait for it to finish.');
    $items = array_slice(ecw_rule_backfill_scan($orgId, $rule, $from, $to)['items'], 0, ECW_RULE_BACKFILL_MAX);
    if ($items === []) throw new InvalidArgumentException('No earlier emails match this rule in those dates.');
    ecw_db()->prepare('INSERT INTO `' . ecw_table('rule_backfills') . '` (organization_id, rule_id, user_id, date_from, date_to, close_cases, items_json, total, status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'running\', ?)')
        ->execute([$orgId, $ruleId, $userId, $from, $to, $closeCases ? 1 : 0,
            json_encode(array_map(static fn($r) => [(int)$r['email_thread_id'], (int)$r['id']], $items)), count($items), ecw_now()]);
    $id = (int)ecw_db()->lastInsertId();
    require_once __DIR__ . '/audit.php';
    ecw_audit('rule.backfill_started', 'rule', $ruleId, ['from' => $from, 'to' => $to, 'conversations' => count($items), 'close_cases' => $closeCases], $orgId, $userId);
    return $id;
}

/** The latest run of a rule, for the progress bar. */
function ecw_rule_backfill_status(int $orgId, int $ruleId): ?array
{
    $st = ecw_db()->prepare('SELECT id, date_from, date_to, close_cases, total, done, acted, failed, status, created_at, finished_at FROM `' . ecw_table('rule_backfills') . '`
                              WHERE organization_id = ? AND rule_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$orgId, $ruleId]);
    $r = $st->fetch();
    if (!$r) return null;
    foreach (['id', 'total', 'done', 'acted', 'failed', 'close_cases'] as $k) $r[$k] = (int)$r[$k];
    return $r;
}

function ecw_rule_backfill_cancel(int $orgId, int $ruleId): bool
{
    $st = ecw_db()->prepare("UPDATE `" . ecw_table('rule_backfills') . "` SET status = 'cancelled', finished_at = ? WHERE organization_id = ? AND rule_id = ? AND status = 'running'");
    $st->execute([ecw_now(), $orgId, $ruleId]);
    return $st->rowCount() > 0;
}

/** One worker round: the next few conversations of the oldest run. @return int conversations handled */
function ecw_rule_backfill_due(int $batch = 15): int
{
    $T = ecw_table('rule_backfills');
    $job = ecw_db()->query("SELECT * FROM `{$T}` WHERE status = 'running' ORDER BY id LIMIT 1")->fetch();
    if (!$job) return 0;
    $orgId = (int)$job['organization_id'];
    $rule = ecw_rule_backfill_rule($orgId, (int)$job['rule_id']);
    $items = json_decode((string)$job['items_json'], true) ?: [];
    $done = (int)$job['done']; $acted = 0; $failed = 0; $n = 0;
    if ($rule !== null) {
        foreach (array_slice($items, $done, $batch) as [$threadId, $messageId]) {
            $n++;
            try {
                if (ecw_rule_backfill_one($orgId, $rule, (int)$threadId, (int)$messageId, (bool)(int)$job['close_cases'])) $acted++;
            } catch (Throwable $e) {
                $failed++;
                ecw_log("rule {$rule['id']} on earlier thread {$threadId} failed: " . $e->getMessage());
            }
        }
    }
    $finished = $rule === null || $done + $n >= count($items);
    ecw_db()->prepare("UPDATE `{$T}` SET done = done + ?, acted = acted + ?, failed = failed + ?, status = IF(? AND status = 'running', 'done', status), finished_at = IF(?, ?, finished_at) WHERE id = ?")
        ->execute([$n, $acted, $failed, $finished ? 1 : 0, $finished ? 1 : 0, ecw_now(), (int)$job['id']]);
    if ($finished && $rule !== null && $job['user_id'] !== null) {
        $st = ecw_db()->prepare("SELECT acted, failed, total FROM `{$T}` WHERE id = ?");
        $st->execute([(int)$job['id']]);
        $res = $st->fetch();
        ecw_notify($orgId, (int)$job['user_id'], null, 'rule',
            'Rule “' . $rule['name'] . '” ran on ' . (int)$res['total'] . ' earlier conversation' . ((int)$res['total'] === 1 ? '' : 's') . ': '
            . (int)$res['acted'] . ' changed' . ((int)$res['failed'] > 0 ? ', ' . (int)$res['failed'] . ' could not be done' : '') . '.', 'settings/rules.php');
    }
    return $n;
}

/** The rule on one earlier conversation. @return bool whether anything changed */
function ecw_rule_backfill_one(int $orgId, array $rule, int $threadId, int $messageId, bool $closeCases): bool
{
    $done = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('rule_runs') . '` WHERE rule_id = ? AND thread_id = ? LIMIT 1');
    $done->execute([(int)$rule['id'], $threadId]);
    if ($done->fetchColumn()) return false;   // handled meanwhile (new mail in the conversation)
    $st = ecw_db()->prepare('SELECT m.*, mb.id AS mbid FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
                              WHERE m.id = ? AND m.email_thread_id = ? AND mb.organization_id = ? AND m.provider_removed_at IS NULL');
    $st->execute([$messageId, $threadId, $orgId]);
    $fields = $st->fetch();
    if (!$fields) return false;   // removed since
    $mailbox = ecw_mailbox((int)$fields['mbid']);
    if ($mailbox === null) return false;
    $ownerId = $mailbox['connected_by_user_id'] !== null ? (int)$mailbox['connected_by_user_id'] : null;
    $did = [];
    foreach (ecw_rule_route_actions($rule['actions'])['list'] as $a) {
        try {
            $r = ecw_rule_run_action($orgId, $mailbox, $ownerId, $threadId, $messageId, $fields, $rule, $a, true);
            if ($r !== null) $did[] = $r;
            if ($r !== null && $closeCases && preg_match('/^opened case (\d+)$/', $r, $mm)) {
                // History only: closed on the date of the email.
                ecw_db()->prepare("UPDATE `" . ecw_table('cases') . "` SET status = 'closed', closed_at = ? WHERE id = ?")->execute([(string)$fields['occurred_at'], (int)$mm[1]]);
                ecw_case_log_event((int)$mm[1], 'CASE_STATUS_CHANGED', null, null, null, ['from' => 'open', 'to' => 'closed', 'rule' => $rule['name'], 'earlier_email' => true]);
            }
        } catch (Throwable $e) {
            $did[] = ($a['type'] ?? '?') . ' failed: ' . mb_strimwidth($e->getMessage(), 0, 120, '…');
            ecw_log("rule {$rule['id']} action " . ($a['type'] ?? '?') . " failed on earlier thread {$threadId}: " . $e->getMessage());
        }
    }
    $did[] = 'earlier email';
    ecw_db()->prepare('INSERT INTO `' . ecw_table('rule_runs') . '` (rule_id, thread_id, message_id, did_json, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int)$rule['id'], $threadId, $messageId, json_encode($did, JSON_UNESCAPED_UNICODE), ecw_now()]);
    return count($did) > 1;
}
