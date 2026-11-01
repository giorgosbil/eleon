<?php declare(strict_types=1);
/**
 * Email Case Workspace — what the assistant can DO (lib/ai_agent.php), beyond
 * reading.
 *
 * OPEN (done at once, it changes nothing)
 *   open_email / open_case    the panel opens it: here, in a new tab or a window.
 *
 * MAKE A FILE
 *   make_spreadsheet          an .xlsx (or .csv) of emails, cases, or a table the
 *                             assistant put together; a download card in the panel.
 *
 * PROPOSE A CHANGE (the person confirms each with one click)
 *   create_case, add_to_case, update_case (status, due, Responsible, next step,
 *   group), add_note, remind_me, reply (opens the composer with a text; nothing
 *   is sent). A proposal says exactly what will happen. It runs with the
 *   person's own rights, checked again when they confirm (ecw_ai_action_run):
 *   the AI never changes anything by itself, never sends and never deletes.
 *
 * READ
 *   list_unanswered           emails sent TO the person that ask something and
 *                             that nobody here answered (the same rules as the
 *                             "Left unanswered" agent).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/ai_tools.php';   // the email filters and briefs, shared with the reading tools

const ECW_AI_SHEET_MAX_ROWS = 5000;
const ECW_AI_DOWNLOAD_HOURS = 24;

/** An email the person may see, by its ref ("M123"), or null. */
function ecw_ai_ref_message(int $org, int $user, string $ref): ?array
{
    if (!preg_match('/(\d{1,12})/', $ref, $m)) return null;
    $st = ecw_db()->prepare('SELECT m.id, m.email_thread_id, m.mailbox_id, m.subject, m.from_name, m.from_email, m.occurred_at FROM `' . ecw_table('email_messages') . '` m
                              JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id WHERE m.id = ? AND mb.organization_id = ?');
    $st->execute([(int)$m[1], $org]);
    $row = $st->fetch();
    return $row && ecw_access_message($org, $user, (int)$row['id']) ? $row : null;
}

/** A case the person may see, by number or words ("CASE-00012", "12", "Exalco"), or null. */
function ecw_ai_ref_case(int $org, int $user, string $ref): ?array
{
    require_once __DIR__ . '/ai.php';
    $ref = trim($ref);
    $refs = [];
    if (preg_match('/^\s*([A-Z]{2,10})-0*(\d{1,7})\s*$/i', $ref, $m)) $refs[] = ['prefix' => strtoupper($m[1]), 'n' => (int)$m[2]];
    if (preg_match('/^\D*(\d{1,7})\s*$/', $ref, $m)) $refs[] = ['prefix' => null, 'n' => (int)$m[1]];
    $case = $refs ? ecw_ai_find_case($org, $user, $refs) : null;
    if ($case) return $case;
    if (mb_strlen($ref) < 3) return null;
    $p = [$org, '%' . ecw_like_escape($ref) . '%'];
    $st = ecw_db()->prepare('SELECT c.id, c.case_number, c.name, c.status FROM `' . ecw_table('cases') . '` c WHERE c.organization_id = ? AND c.deleted_at IS NULL AND c.name LIKE ?
                              AND ' . ecw_case_visible_sql('c', $user, $p) . ' ORDER BY c.updated_at DESC LIMIT 2');
    $st->execute($p);
    $rows = $st->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;   // two matches: not sure which
}

/** A colleague by name or address ("me" is the person), or null. */
function ecw_ai_ref_user(int $org, int $user, string $who): ?array
{
    $who = trim($who);
    if ($who === '') return null;
    if (preg_match('/^(me|myself|εμένα|εγώ|μένα)$/iu', $who)) $who = (string)$user;
    $st = ecw_db()->prepare('SELECT u.id, u.name, u.email FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id
                              WHERE ou.organization_id = ? AND u.disabled_at IS NULL AND (u.id = ? OR LOWER(u.email) = LOWER(?) OR u.name LIKE ?) LIMIT 2');
    $st->execute([$org, ctype_digit($who) ? (int)$who : 0, $who, '%' . ecw_like_escape($who) . '%']);
    $rows = $st->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;
}

function ecw_ai_ref_group(int $org, string $name): ?array
{
    // Not someone else's personal group (hidden from everyone but its owner).
    $gp = [];
    $vis = ecw_case_group_visible_sql('g', (int)(ecw_context()['user']['id'] ?? 0), $gp);
    $st = ecw_db()->prepare('SELECT id, name FROM `' . ecw_table('case_groups') . '` g WHERE organization_id = ? AND (LOWER(name) = LOWER(?) OR name LIKE ?) AND ' . $vis . ' ORDER BY LENGTH(name) LIMIT 1');
    $st->execute(array_merge([$org, trim($name), '%' . ecw_like_escape(trim($name)) . '%'], $gp));
    return $st->fetch() ?: null;
}

function ecw_ai_date(?string $v): ?string
{
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v . ' UTC') ? $v : null;
}

/** Add a proposal for the panel. @return array what the model is told */
function ecw_ai_propose(array &$ctx, string $type, array $params, string $summary): array
{
    $id = 'A' . (count($ctx['actions'] ?? []) + 1);
    $ctx['actions'][] = ['id' => $id, 'type' => $type, 'params' => $params, 'summary' => $summary];
    return ['proposed' => true, 'action' => $id, 'summary' => $summary,
        'next' => 'Shown to the person as a card with a Confirm button. Nothing has changed yet: tell them to confirm it below.'];
}

/** The threads of email refs, each checked. @return array{0: list<int>, 1: list<string>} [thread ids, labels] */
function ecw_ai_threads_of(int $org, int $user, array $refs): array
{
    $threads = []; $labels = [];
    foreach (array_slice($refs, 0, 20) as $r) {
        $m = ecw_ai_ref_message($org, $user, (string)$r);
        if (!$m || isset($threads[(int)$m['email_thread_id']])) continue;
        $threads[(int)$m['email_thread_id']] = (int)$m['mailbox_id'];
        $labels[] = '“' . mb_strimwidth((string)$m['subject'], 0, 60, '…') . '”';
    }
    return [$threads, $labels];
}

/** One tool of this file for the agent (ecw_ai_tool_run hands these over). */
function ecw_ai_action_tool(array &$ctx, string $name, array $a): array
{
    $org = (int)$ctx['org']; $user = (int)$ctx['user'];
    if (in_array($name, ECW_AI_ACTING_TOOLS, true) && !ecw_ai_actions_on()) return ['error' => 'Files and changes by the assistant are switched off for this person (Settings → Feature access).'];
    switch ($name) {
        case 'open_email': {
            $m = ecw_ai_ref_message($org, $user, (string)($a['ref'] ?? ''));
            if (!$m) return ['error' => 'No such email that this person can see.'];
            $where = in_array($a['where'] ?? '', ['here', 'new_tab', 'window'], true) ? $a['where'] : 'here';
            $ctx['open'][] = ['kind' => 'thread', 'thread_id' => (int)$m['email_thread_id'], 'mailbox_id' => (int)$m['mailbox_id'], 'message_id' => (int)$m['id'],
                'where' => $where, 'label' => (string)$m['subject']];
            return ['opened' => true, 'where' => $where, 'subject' => $m['subject']];
        }
        case 'open_case': {
            $c = ecw_ai_ref_case($org, $user, (string)($a['case'] ?? ''));
            if (!$c) return ['error' => 'No such case (or more than one matches): ask which one.'];
            $where = in_array($a['where'] ?? '', ['here', 'new_tab', 'window'], true) ? $a['where'] : 'here';
            $ctx['open'][] = ['kind' => 'case', 'case_id' => (int)$c['id'], 'where' => $where, 'label' => $c['case_number'] . ' ' . $c['name']];
            return ['opened' => true, 'case' => $c['case_number']];
        }
        case 'list_unanswered': {
            require_once __DIR__ . '/agents.php';
            $days = max(0, min(30, (int)($a['days'] ?? 1)));
            $st = ecw_db()->prepare('SELECT m.*, mb.email_address AS mailbox_address FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('mailboxes') . "` mb ON mb.id = m.mailbox_id
                                      WHERE mb.organization_id = ? AND mb.connected_by_user_id = ? AND m.direction = 'incoming' AND m.provider_removed_at IS NULL
                                        AND m.occurred_at <= ? AND m.occurred_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
                                        AND NOT EXISTS (SELECT 1 FROM `" . ecw_table('mail_folders') . "` xf WHERE xf.id = m.mail_folder_id AND xf.folder_type IN ('junk', 'deleted', 'sent', 'archive'))
                                      ORDER BY m.occurred_at DESC LIMIT 150");
            $st->execute([$org, $user, gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
            $out = []; $seenThreads = [];
            foreach ($st->fetchAll() as $m) {
                if (isset($seenThreads[(int)$m['email_thread_id']])) continue;
                $seenThreads[(int)$m['email_thread_id']] = true;   // the newest email of each conversation only
                $asks = str_contains((string)$m['body_text'], '?') || preg_match('/\b(please|could you|can you|παρακαλ|ενημερώστε|στείλτε|θα θέλαμε)/iu', mb_substr((string)$m['body_text'], 0, 3000));
                if (!$asks || ecw_agent_is_bulk($m) || ecw_agent_from_colleague($m) || ecw_agent_addressed($m, $user) !== 'to' || ecw_agent_answered_after($m)) continue;
                $out[] = ecw_ai_tool_brief($m, $ctx, 200) + ['days_waiting' => (int)floor((time() - strtotime($m['occurred_at'] . ' UTC')) / 86400)];
                if (count($out) >= 15) break;
            }
            return ['unanswered' => $out, 'rule' => 'sent to the person (not a copy), asks something, nobody here answered after it'];
        }
        case 'make_spreadsheet':
            return ecw_ai_spreadsheet($ctx, $a);

        case 'create_case': {
            $name = mb_substr(trim((string)($a['name'] ?? '')), 0, 120);
            if ($name === '') return ['error' => 'A case needs a name.'];
            [$threads, $labels] = ecw_ai_threads_of($org, $user, (array)($a['emails'] ?? []));
            $p = ['name' => $name, 'threads' => array_keys($threads)];
            $parts = ['Create the case “' . $name . '”'];
            if ($labels) $parts[] = 'with ' . (count($labels) === 1 ? 'the conversation ' : count($labels) . ' conversations: ') . implode(', ', $labels);
            if (!empty($a['group'])) { $g = ecw_ai_ref_group($org, (string)$a['group']); if (!$g) return ['error' => 'No case group "' . $a['group'] . '".']; $p['group_id'] = (int)$g['id']; $parts[] = 'in the group ' . $g['name']; }
            if (!empty($a['responsible'])) { $u = ecw_ai_ref_user($org, $user, (string)$a['responsible']); if (!$u) return ['error' => 'Not sure who "' . $a['responsible'] . '" is: ask.']; $p['owner_user_id'] = (int)$u['id']; $parts[] = 'Responsible ' . ($u['name'] ?: $u['email']); }
            if (($due = ecw_ai_date($a['due'] ?? null)) !== null) { $p['due'] = $due; $parts[] = 'due ' . $due; }
            if (($note = mb_substr(trim((string)($a['note'] ?? '')), 0, 2000)) !== '') { $p['note'] = $note; $parts[] = 'with an internal note'; }
            return ecw_ai_propose($ctx, 'create_case', $p, implode(', ', $parts) . '.');
        }
        case 'add_to_case': {
            $c = ecw_ai_ref_case($org, $user, (string)($a['case'] ?? ''));
            if (!$c) return ['error' => 'No such case (or more than one matches): ask which one.'];
            [$threads, $labels] = ecw_ai_threads_of($org, $user, (array)($a['emails'] ?? []));
            if (!$threads) return ['error' => 'Give the refs (M…) of the emails to add.'];
            return ecw_ai_propose($ctx, 'add_to_case', ['case_id' => (int)$c['id'], 'threads' => array_keys($threads)],
                'Add ' . implode(', ', $labels) . ' to ' . $c['case_number'] . ' “' . $c['name'] . '” (everyone who can see the case can then read ' . (count($labels) === 1 ? 'it' : 'them') . ').');
        }
        case 'update_case': {
            $c = ecw_ai_ref_case($org, $user, (string)($a['case'] ?? ''));
            if (!$c) return ['error' => 'No such case (or more than one matches): ask which one.'];
            $p = ['case_id' => (int)$c['id']]; $parts = [];
            if (in_array($a['status'] ?? '', ['open', 'waiting', 'closed'], true)) { $p['status'] = $a['status']; $parts[] = 'status ' . $a['status']; }
            if (array_key_exists('due', $a)) { $due = ecw_ai_date($a['due']); $p['due'] = $due; $parts[] = $due ? 'due ' . $due : 'no due date'; }
            if (!empty($a['responsible'])) { $u = ecw_ai_ref_user($org, $user, (string)$a['responsible']); if (!$u) return ['error' => 'Not sure who "' . $a['responsible'] . '" is: ask.']; $p['owner_user_id'] = (int)$u['id']; $parts[] = 'Responsible ' . ($u['name'] ?: $u['email']); }
            if (!empty($a['group'])) { $g = ecw_ai_ref_group($org, (string)$a['group']); if (!$g) return ['error' => 'No case group "' . $a['group'] . '".']; $p['group_id'] = (int)$g['id']; $parts[] = 'group ' . $g['name']; }
            if (($next = mb_substr(trim((string)($a['next_step'] ?? '')), 0, 300)) !== '') { $p['next_step'] = $next; $parts[] = 'next step “' . $next . '”'; }
            if (!$parts) return ['error' => 'Say what to change: status, due, responsible, group or next_step.'];
            return ecw_ai_propose($ctx, 'update_case', $p, 'Change ' . $c['case_number'] . ' “' . $c['name'] . '”: ' . implode(', ', $parts) . '.');
        }
        case 'add_note': {
            $text = mb_substr(trim((string)($a['text'] ?? '')), 0, 4000);
            if ($text === '') return ['error' => 'A note needs a text.'];
            if (!empty($a['case'])) {
                $c = ecw_ai_ref_case($org, $user, (string)$a['case']);
                if (!$c) return ['error' => 'No such case: ask which one.'];
                return ecw_ai_propose($ctx, 'add_note', ['case_id' => (int)$c['id'], 'text' => $text], 'Add an internal note to ' . $c['case_number'] . ': “' . mb_strimwidth($text, 0, 140, '…') . '”.');
            }
            $m = ecw_ai_ref_message($org, $user, (string)($a['email'] ?? ''));
            if (!$m) return ['error' => 'Give the case or the email (M…) for the note.'];
            return ecw_ai_propose($ctx, 'add_note', ['thread_id' => (int)$m['email_thread_id'], 'message_id' => (int)$m['id'], 'text' => $text],
                'Add an internal note to the conversation “' . mb_strimwidth((string)$m['subject'], 0, 60, '…') . '”: “' . mb_strimwidth($text, 0, 140, '…') . '”.');
        }
        case 'remind_me': {
            // The time the person means, in their own zone (lib/timezone.php).
            require_once __DIR__ . '/timezone.php';
            $myTz = ecw_tz_for_user($user, $org);
            try { $at = (new DateTimeImmutable((string)($a['when'] ?? ''), new DateTimeZone($myTz)))->getTimestamp(); } catch (Throwable $e) { $at = false; }
            if (trim((string)($a['when'] ?? '')) === '') $at = false;
            if ($at === false || $at < time() + 60 || $at > time() + 366 * 86400) return ['error' => 'Give when, as a date and time in the future (YYYY-MM-DDTHH:MM).'];
            $p = ['at' => gmdate(DATE_ATOM, $at), 'note' => mb_substr(trim((string)($a['text'] ?? '')), 0, 300), 'if_no_reply' => !empty($a['only_if_no_reply'])];
            $about = '';
            if (!empty($a['email']) && ($m = ecw_ai_ref_message($org, $user, (string)$a['email']))) { $p['thread_id'] = (int)$m['email_thread_id']; $about = ' about “' . mb_strimwidth((string)$m['subject'], 0, 60, '…') . '”'; }
            elseif (!empty($a['case']) && ($c = ecw_ai_ref_case($org, $user, (string)$a['case']))) { $p['case_id'] = (int)$c['id']; $about = ' about ' . $c['case_number']; }
            return ecw_ai_propose($ctx, 'remind_me', $p, 'Remind you on ' . ecw_tz_format(gmdate('Y-m-d H:i:s', $at), $myTz, 'D j M Y, H:i') . $about . ($p['if_no_reply'] ? ', only if nobody has replied by then' : '') . ($p['note'] !== '' ? ': “' . $p['note'] . '”' : '') . '.');
        }
        case 'reply': {
            $m = ecw_ai_ref_message($org, $user, (string)($a['ref'] ?? ''));
            if (!$m) return ['error' => 'No such email that this person can see.'];
            $text = mb_substr(trim((string)($a['text'] ?? '')), 0, 6000);
            $mode = ($a['mode'] ?? '') === 'reply_all' ? 'reply_all' : 'reply';
            return ecw_ai_propose($ctx, 'reply', ['message_id' => (int)$m['id'], 'thread_id' => (int)$m['email_thread_id'], 'mailbox_id' => (int)$m['mailbox_id'], 'mode' => $mode, 'text' => $text],
                'Open a ' . ($mode === 'reply_all' ? 'reply to all' : 'reply') . ' to “' . mb_strimwidth((string)$m['subject'], 0, 60, '…') . '”' . ($text !== '' ? ' with this text, to check and send yourself' : '') . '. Nothing is sent.');
        }
    }
    return ['error' => 'Unknown tool ' . $name];
}

/**
 * Do a confirmed proposal, with the person's own rights (checked again here).
 * @return array{done:bool, message:string, link:?string}
 */
function ecw_ai_action_run(int $org, int $user, array $action): array
{
    $type = (string)($action['type'] ?? '');
    $p = (array)($action['params'] ?? []);
    $threadsOk = static function (array $ids) use ($org, $user): array {
        $ok = [];
        foreach (array_slice(array_map('intval', $ids), 0, 20) as $t) {
            $st = ecw_db()->prepare('SELECT m.id FROM `' . ecw_table('email_messages') . '` m WHERE m.email_thread_id = ? ORDER BY m.occurred_at DESC LIMIT 1');
            $st->execute([$t]);
            $mid = (int)$st->fetchColumn();
            if ($mid > 0 && ecw_access_message($org, $user, $mid)) $ok[] = $t;
        }
        return $ok;
    };
    $caseOk = static function (int $id) use ($org, $user): array {
        if ($id <= 0 || !ecw_case_visible($org, $user, $id)) throw new InvalidArgumentException('That case is not available.');
        $st = ecw_db()->prepare('SELECT id, case_number, name, status FROM `' . ecw_table('cases') . '` WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $st->execute([$id, $org]);
        $c = $st->fetch();
        if (!$c) throw new InvalidArgumentException('That case is not available.');
        return $c;
    };
    require_once __DIR__ . '/audit.php';
    switch ($type) {
        case 'create_case': {
            $threads = $threadsOk((array)($p['threads'] ?? []));
            $mb = null;
            if ($threads) { $st = ecw_db()->prepare('SELECT mailbox_id FROM `' . ecw_table('email_threads') . '` WHERE id = ?'); $st->execute([$threads[0]]); $mb = (int)$st->fetchColumn() ?: null; }
            $caseId = ecw_case_create($org, mb_substr(trim((string)($p['name'] ?? '')), 0, 120) ?: 'New case', $user, 'open', $mb);
            if (!empty($p['group_id'])) ecw_case_set_group($org, $caseId, (int)$p['group_id'], $user);
            require_once __DIR__ . '/timeline.php';
            $changes = [];
            if (!empty($p['owner_user_id'])) $changes['owner_user_id'] = (int)$p['owner_user_id'];
            if (!empty($p['due'])) $changes['due_at'] = ecw_ai_date($p['due']);
            if ($changes) ecw_case_update($org, $caseId, $changes, $user);
            $skipped = 0;
            foreach ($threads as $t) { try { ecw_case_link_thread($caseId, $t, null, $user); } catch (Throwable $e) { $skipped++; } }
            if (!empty($p['note'])) { require_once __DIR__ . '/notes.php'; ecw_case_note_add($org, $caseId, $user, (string)$p['note']); }
            $c = $caseOk($caseId);
            ecw_audit('case.create', 'case', $caseId, ['case' => $c['case_number'], 'via' => 'assistant'], $org, $user);
            return ['done' => true, 'message' => 'Created ' . $c['case_number'] . ($skipped ? ' (' . $skipped . ' conversation' . ($skipped === 1 ? ' was' : 's were') . ' already in another case)' : '') . '.', 'link' => 'case.php?id=' . $caseId];
        }
        case 'add_to_case': {
            $c = $caseOk((int)($p['case_id'] ?? 0));
            $added = 0; $skipped = 0;
            foreach ($threadsOk((array)($p['threads'] ?? [])) as $t) { try { ecw_case_link_thread((int)$c['id'], $t, null, $user); $added++; } catch (Throwable $e) { $skipped++; } }
            return ['done' => $added > 0, 'message' => $added ? 'Added to ' . $c['case_number'] . ($skipped ? ' (' . $skipped . ' already in another case)' : '') . '.' : 'Nothing added: already in another case.', 'link' => 'case.php?id=' . (int)$c['id']];
        }
        case 'update_case': {
            $c = $caseOk((int)($p['case_id'] ?? 0));
            require_once __DIR__ . '/timeline.php';
            $changes = [];
            if (in_array($p['status'] ?? '', ['open', 'waiting', 'closed'], true)) $changes['status'] = $p['status'];
            if (array_key_exists('due', $p)) $changes['due_at'] = ecw_ai_date($p['due']);
            if (!empty($p['owner_user_id'])) $changes['owner_user_id'] = (int)$p['owner_user_id'];
            if ($changes && !ecw_case_update($org, (int)$c['id'], $changes, $user)) throw new InvalidArgumentException('The case could not be changed.');
            if (!empty($p['group_id'])) ecw_case_set_group($org, (int)$c['id'], (int)$p['group_id'], $user);
            if (!empty($p['next_step'])) { require_once __DIR__ . '/next_actions.php'; ecw_case_next_set($org, (int)$c['id'], ['next_action' => (string)$p['next_step']], $user); }
            return ['done' => true, 'message' => $c['case_number'] . ' changed.', 'link' => 'case.php?id=' . (int)$c['id']];
        }
        case 'add_note': {
            require_once __DIR__ . '/notes.php';
            if (!empty($p['case_id'])) {
                $c = $caseOk((int)$p['case_id']);
                ecw_case_note_add($org, (int)$c['id'], $user, (string)($p['text'] ?? ''));
                return ['done' => true, 'message' => 'Note added to ' . $c['case_number'] . '.', 'link' => 'case.php?id=' . (int)$c['id']];
            }
            $t = $threadsOk([(int)($p['thread_id'] ?? 0)]);
            if (!$t) throw new InvalidArgumentException('That conversation is not available.');
            ecw_note_add($org, $user, (string)($p['text'] ?? ''), null, $t[0], !empty($p['message_id']) ? (int)$p['message_id'] : null);
            return ['done' => true, 'message' => 'Note added to the conversation.', 'link' => null];
        }
        case 'remind_me': {
            require_once __DIR__ . '/followups.php';
            $caseId = !empty($p['case_id']) ? (int)$caseOk((int)$p['case_id'])['id'] : null;
            $threadId = !empty($p['thread_id']) ? ($threadsOk([(int)$p['thread_id']])[0] ?? null) : null;
            ecw_followup_add($org, $user, $caseId, $threadId, (string)($p['at'] ?? ''), (string)($p['note'] ?? '') ?: null, !empty($p['if_no_reply']));
            return ['done' => true, 'message' => 'Reminder set.', 'link' => null];
        }
    }
    throw new InvalidArgumentException('Unknown action.');
}

// ------------------------------------------------------------------ spreadsheets

/** Make the file and add a download card. */
function ecw_ai_spreadsheet(array &$ctx, array $a): array
{
    require_once __DIR__ . '/xlsx.php';
    $org = (int)$ctx['org']; $user = (int)$ctx['user'];
    $title = mb_substr(trim((string)($a['title'] ?? '')) ?: 'Report', 0, 80);
    $format = ($a['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
    $source = (string)($a['source'] ?? 'rows');
    $headers = []; $rows = [];
    if ($source === 'emails') {
        $params = [];
        $where = ecw_ai_tool_where($org, $user, (array)($a['filters'] ?? []), $params);
        $st = ecw_db()->prepare('SELECT m.id, m.occurred_at, m.direction, m.from_name, m.from_email, m.to_json, m.subject, m.has_attachments, f.name AS folder,
                                        (SELECT c.case_number FROM `' . ecw_table('case_threads') . '` ct JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id WHERE ct.email_thread_id = m.email_thread_id AND c.deleted_at IS NULL LIMIT 1) AS case_number'
            . ecw_ai_tool_from_sql() . ' LEFT JOIN `' . ecw_table('mail_folders') . '` f ON f.id = m.mail_folder_id WHERE ' . $where . ' ORDER BY m.occurred_at DESC LIMIT ' . ECW_AI_SHEET_MAX_ROWS);
        $st->execute($params);
        $headers = ['Date', 'Direction', 'From', 'From address', 'To', 'Subject', 'Attachments', 'Folder', 'Case'];
        foreach ($st->fetchAll() as $r) {
            $to = implode('; ', array_map(static fn($x) => (string)($x['email'] ?? ''), json_decode((string)$r['to_json'], true) ?: []));
            $rows[] = [substr((string)$r['occurred_at'], 0, 16), $r['direction'] === 'outgoing' ? 'Sent' : 'Received', (string)$r['from_name'], (string)$r['from_email'], $to,
                (string)$r['subject'], (int)$r['has_attachments'] ? 'Yes' : 'No', (string)$r['folder'], (string)$r['case_number']];
        }
    } elseif ($source === 'cases') {
        $params = [$org];
        $where = 'c.organization_id = ? AND c.deleted_at IS NULL';
        $status = (string)($a['status'] ?? 'any');
        if (in_array($status, ['open', 'waiting', 'closed'], true)) { $where .= ' AND c.status = ?'; $params[] = $status; }
        if (($w = trim((string)($a['words'] ?? ''))) !== '') { $where .= ' AND (c.name LIKE ? OR c.case_number LIKE ?)'; array_push($params, '%' . ecw_like_escape($w) . '%', '%' . ecw_like_escape($w) . '%'); }
        if (!empty($a['overdue'])) { require_once __DIR__ . '/case_settings.php'; $where .= ' AND ' . ecw_case_overdue_sql($org); }   // Settings → Case settings
        $where .= ' AND ' . ecw_case_visible_sql('c', $user, $params);
        require_once __DIR__ . '/account.php';
        $where .= ' AND ' . ecw_case_account_sql('c', ecw_active_account_id($org, $user), $params, $user);
        $st = ecw_db()->prepare('SELECT c.case_number, c.name, c.status, g.name AS grp, uo.name AS owner, uh.name AS handler, c.due_at, c.created_at, c.next_action, c.waiting_on,
                                        (SELECT COUNT(*) FROM `' . ecw_table('case_threads') . '` ct WHERE ct.case_id = c.id) AS threads,
                                        (SELECT MAX(m.occurred_at) FROM `' . ecw_table('case_threads') . '` ct2 JOIN `' . ecw_table('email_messages') . '` m ON m.email_thread_id = ct2.email_thread_id WHERE ct2.case_id = c.id) AS last_activity
                                   FROM `' . ecw_table('cases') . '` c LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
                                   LEFT JOIN `' . ecw_table('users') . '` uo ON uo.id = c.owner_user_id LEFT JOIN `' . ecw_table('users') . '` uh ON uh.id = c.handler_user_id
                                  WHERE ' . $where . ' ORDER BY c.case_number LIMIT ' . ECW_AI_SHEET_MAX_ROWS);
        $st->execute($params);
        $headers = ['Case', 'Name', 'Status', 'Group', 'Responsible', 'Handled by', 'Due', 'Created', 'Last activity', 'Conversations', 'Next step', 'Waiting on'];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [$r['case_number'], $r['name'], $r['status'], (string)$r['grp'], (string)$r['owner'], (string)$r['handler'], $r['due_at'] ? substr((string)$r['due_at'], 0, 10) : '',
                substr((string)$r['created_at'], 0, 10), $r['last_activity'] ? substr((string)$r['last_activity'], 0, 16) : '', (int)$r['threads'], (string)$r['next_action'], (string)$r['waiting_on']];
        }
    } else {
        // A table the assistant put together from what it read (totals per month, a list of offers…).
        $headers = array_map(static fn($h) => mb_substr((string)$h, 0, 80), array_slice((array)($a['headers'] ?? []), 0, 30));
        foreach (array_slice((array)($a['rows'] ?? []), 0, ECW_AI_SHEET_MAX_ROWS) as $r) {
            if (!is_array($r)) continue;
            $rows[] = array_map(static fn($v) => is_scalar($v) ? (is_string($v) ? mb_substr($v, 0, 2000) : $v) : null, array_slice(array_values($r), 0, 30));
        }
        if (!$headers) return ['error' => 'Give the column headers and the rows.'];
    }
    $dir = dirname(__DIR__) . '/storage/exports/ai';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    foreach (glob($dir . '/*') ?: [] as $old) if (is_file($old) && filemtime($old) < time() - ECW_AI_DOWNLOAD_HOURS * 3600) @unlink($old);
    $token = bin2hex(random_bytes(16));
    $file = $dir . '/' . $token . '.' . $format;
    if ($format === 'csv') ecw_csv_write($file, $headers, $rows); else ecw_xlsx_write($file, $title, $headers, $rows);
    $name = trim((string)preg_replace('/[^\p{L}\p{N} _\-.]+/u', ' ', $title)) . ' ' . gmdate('Y-m-d') . '.' . $format;
    file_put_contents($dir . '/' . $token . '.json', json_encode(['user' => $user, 'org' => $org, 'name' => $name, 'format' => $format]));
    $ctx['downloads'][] = ['name' => $name, 'rows' => count($rows), 'format' => $format, 'url' => 'api/index.php?action=ai_download&token=' . $token];
    return ['made' => true, 'file' => $name, 'rows' => count($rows), 'columns' => $headers, 'next' => 'The person sees a download card. Say how many rows and what is in it.'];
}

/** The file of a download card, for its owner only. @return array{path:string, name:string, mime:string}|null */
function ecw_ai_download(int $org, int $user, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $dir = dirname(__DIR__) . '/storage/exports/ai';
    $meta = json_decode((string)@file_get_contents($dir . '/' . $token . '.json'), true);
    if (!is_array($meta) || (int)$meta['user'] !== $user || (int)$meta['org'] !== $org) return null;
    $path = $dir . '/' . $token . '.' . ($meta['format'] === 'csv' ? 'csv' : 'xlsx');
    if (!is_file($path)) return null;
    return ['path' => $path, 'name' => (string)$meta['name'], 'mime' => $meta['format'] === 'csv' ? 'text/csv; charset=utf-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
}
