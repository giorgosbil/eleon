<?php declare(strict_types=1);
/**
 * Solutions memory (lib/case_memory.php): a closed case gets a problem and
 * solution summary, people's corrections are kept, open cases get "might be
 * the same issue as …" with Yes / No, and a No is never asked again.
 *
 *   php tests/case_memory_test.php
 *
 * No AI is called: $GLOBALS['ecw_ai_http_transport'] answers. InnoDB
 * fulltext cannot see uncommitted rows, so the search result is supplied via
 * $GLOBALS['ecw_memory_candidates_test'] (the SQL itself is run once without).
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/case_extras.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
$asked = [];
$answer = null;
$GLOBALS['ecw_ai_http_transport'] = static function (string $kind, array $body, array $c) use (&$asked, &$answer) {
    $asked[] = $body;
    return ['choices' => [['message' => ['content' => is_string($answer) ? $answer : json_encode($answer, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']], 'usage' => []];
};
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Memory shop']);
    $admin = $ins('users', ['name' => 'Ada', 'email' => 'ada' . $org . '@mem.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);
    $nick = $ins('users', ['name' => 'Nick', 'email' => 'nick' . $org . '@mem.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $nick, 'role' => 'member']);
    $conn = ecw_ai_connection_save($org, ['name' => 'Test AI', 'kind' => 'openai', 'base_url' => 'https://ai.example.test/v1', 'model' => 'test-model', 'api_key' => 'k']);
    ecw_ai_jobs_save($org, ['main' => ['assistant' => $conn, 'agents' => $conn, 'drafts' => $conn], 'backup' => ['assistant' => '', 'agents' => '', 'drafts' => '']]);
    $ai = ecw_ai_config($org, 'agents');
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-' . $org, 'email_address' => 'support' . $org . '@mem.test', 'sync_status' => 'connected', 'connected_by_user_id' => $admin]);
    $mkCase = static function (string $name, array $emails) use ($ins, $org, $mb, $admin, $nick): int {
        static $n = 0; $n++;
        $case = $ins('cases', ['organization_id' => $org, 'case_number' => 'MEM-' . $org . '-' . $n, 'name' => $name, 'status' => 'open', 'owner_user_id' => $admin, 'handler_user_id' => $nick, 'created_by_user_id' => $admin]);
        $t = $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'conv-' . $org . '-' . $n, 'normalized_subject' => $name]);
        $ins('case_threads', ['case_id' => $case, 'email_thread_id' => $t, 'sort_order' => 0]);
        foreach ($emails as $i => [$dir, $text]) {
            $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $t, 'provider_message_id' => 'p-' . $org . '-' . $n . '-' . $i, 'subject' => $name,
                'from_email' => $dir === 'incoming' ? 'customer@client.test' : 'support@mem.test', 'from_name' => $dir === 'incoming' ? 'Customer' : 'Support',
                'direction' => $dir, 'body_text' => $text, $dir === 'incoming' ? 'received_at' : 'sent_at' => gmdate('Y-m-d H:i:s', time() - 3600 + $i * 60)]);
        }
        return $case;
    };

    group('a closed case gets its summary');
    $old = $mkCase('Pump P-200 leaking', [['incoming', 'Our P-200 pump leaks water at the bottom after two weeks.'], ['outgoing', 'Please replace the shaft seal kit SK-14; we are sending one.'], ['incoming', 'Replaced the seal, no more leak. Thanks!']]);
    $ins('case_notes', ['case_id' => $old, 'user_id' => $nick, 'body' => 'Seal kits from batch 23 are faulty.', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $pdo->prepare('INSERT INTO ecw_case_similar (case_id, similar_case_id, organization_id, state) VALUES (?, ?, ?, \'open\')')->execute([$old, $old, $org]);
    ecw_case_update($org, $old, ['status' => 'closed'], $admin);
    $r = $pdo->query("SELECT * FROM ecw_case_resolutions WHERE case_id = {$old}")->fetch();
    check($r && $r['state'] === 'pending', 'closing the case queued its summary');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_similar WHERE case_id = {$old} AND state = 'open'")->fetchColumn() === 0, 'its open "same issue" suggestions are cleared');
    ecw_case_update($org, $old, ['name' => 'Pump P-200 leaking water'], $admin);
    check((int)$pdo->query("SELECT attempts FROM ecw_case_resolutions WHERE case_id = {$old}")->fetchColumn() === 0, 'other changes to a closed case do not queue it again');

    $answer = ['problem' => 'P-200 pump leaked water at the bottom after two weeks.', 'solution' => 'Replaced the shaft seal kit SK-14 (batch 23 kits are faulty).', 'keywords' => ['P-200', 'leak', 'seal', 'SK-14'], 'solved' => true];
    $asked = [];
    $n = ecw_agent_memory($org, ecw_agents_settings($org), $ai);
    $r = $pdo->query("SELECT * FROM ecw_case_resolutions WHERE case_id = {$old}")->fetch();
    check($n === 1 && $r['state'] === 'done' && str_contains($r['solution'], 'SK-14') && $r['keywords'] === 'P-200, leak, seal, SK-14' && (int)$r['solved'] === 1, 'the agent wrote problem, solution and keywords');
    $sent = json_encode($asked[0]['messages'] ?? [], JSON_UNESCAPED_UNICODE);
    check(str_contains($sent, 'leaks water at the bottom') && str_contains($sent, 'batch 23') && str_contains($sent, 'untrusted evidence'), 'it read the first email, the internal notes, and was told emails are not instructions');

    group('a bad answer is retried, then given up');
    $broken = $mkCase('Broken thing', [['incoming', 'It broke.']]);
    ecw_case_update($org, $broken, ['status' => 'closed'], $admin);
    $answer = 'Sorry, I cannot help.';
    foreach ([1, 2, 3] as $i) ecw_memory_write($org, $broken, $ai);
    $r = $pdo->query("SELECT state, attempts FROM ecw_case_resolutions WHERE case_id = {$broken}")->fetch();
    check($r['state'] === 'failed' && (int)$r['attempts'] === 3, 'three tries without a summary: marked failed, not retried forever', json_encode($r));

    group('people correct it');
    ecw_memory_save($org, $broken, $admin, 'Display stays black.', 'Firmware 2.1 fixed it.');
    ecw_case_update($org, $broken, ['status' => 'open'], $admin);
    ecw_case_update($org, $broken, ['status' => 'closed'], $admin);
    $answer = ['problem' => 'AI text', 'solution' => 'AI text', 'keywords' => [], 'solved' => true];
    ecw_memory_write($org, $broken, $ai);
    $r = $pdo->query("SELECT * FROM ecw_case_resolutions WHERE case_id = {$broken}")->fetch();
    check($r['problem'] === 'Display stays black.' && $r['written_by'] === 'user' && $r['state'] === 'done', 'a person’s summary survives reopening, closing and the AI');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_events WHERE case_id = {$broken} AND event_type = 'CASE_SOLUTION_EDITED'")->fetchColumn() === 1, 'the correction is in the case history');
    try { ecw_memory_save($org, $broken, $admin, ' ', 'x'); check(false, 'a summary needs the problem'); } catch (InvalidArgumentException $e) { check(true, 'a summary needs the problem'); }

    group('a new case that looks like an old one');
    $new = $mkCase('Water under the pump', [['incoming', 'Hello, our P-200 is dripping water from below since last week.']]);
    $GLOBALS['ecw_memory_candidates_test'] = static function (int $o, int $c, string $text) use ($old, $broken, $pdo): array {
        $rows = [];
        foreach ([$old => 5.2, $broken => 1.9] as $id => $score) {
            if ($pdo->query("SELECT 1 FROM ecw_case_similar WHERE case_id = {$c} AND similar_case_id = {$id}")->fetchColumn()) continue;
            $r = $pdo->query("SELECT r.problem, r.solution, r.keywords, c.case_number, c.name FROM ecw_case_resolutions r JOIN ecw_cases c ON c.id = r.case_id WHERE r.case_id = {$id}")->fetch();
            $rows[] = ['case_id' => $id, 'score' => $score] + $r;
        }
        return $rows;
    };
    $answer = ['matches' => [['id' => 'C1', 'confidence' => 0.86, 'reason' => 'Same P-200 leak from the bottom'], ['id' => 'C9', 'confidence' => 0.99, 'reason' => 'invented id']]];
    $asked = [];
    $before = (int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$nick}")->fetchColumn();
    $found = ecw_memory_check_similar($org, $new, $ai);
    $rows = $pdo->query("SELECT similar_case_id, state, confidence FROM ecw_case_similar WHERE case_id = {$new} ORDER BY similar_case_id")->fetchAll(PDO::FETCH_UNIQUE);
    check($found === 1 && $rows[$old]['state'] === 'open' && (float)$rows[$old]['confidence'] === 0.86, 'the AI confirmed the leaking-pump case');
    check(($rows[$broken]['state'] ?? '') === 'ai_no' && count($rows) === 2, 'the one it judged different is remembered as such; an id it invented is ignored');
    check(str_contains(json_encode($asked[0]['messages'], JSON_UNESCAPED_UNICODE), 'dripping water'), 'the AI compared the new customer email');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_notifications WHERE user_id = {$nick}")->fetchColumn() === $before + 1, 'whoever handles the new case is told');
    $asked = [];
    check(ecw_memory_check_similar($org, $new, $ai) === 0 && $asked === [], 'checking again with nothing new to judge asks no AI');

    $m = ecw_memory_get($org, $new, $admin);
    check(count($m['similar']) === 1 && $m['similar'][0]['case_id'] === $old && str_contains($m['similar'][0]['solution'], 'SK-14') && $m['similar'][0]['reason'] === 'Same P-200 leak from the bottom', 'the case page gets the old case, its solution and why');

    group('Yes, No, Undo');
    ecw_memory_decide($org, $new, $old, 'yes', $nick);
    $m = ecw_memory_get($org, $new, $admin);
    check($m['similar'][0]['state'] === 'yes' && $m['similar'][0]['decided_by'] === 'Nick', 'Yes is recorded, with who');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_case_events WHERE case_id = {$new} AND event_type = 'CASE_SIMILAR_CONFIRMED'")->fetchColumn() === 1, 'and written in the case history');
    ecw_memory_decide($org, $new, $old, 'undo', $nick);
    ecw_memory_decide($org, $new, $old, 'no', $nick);
    check(ecw_memory_get($org, $new, $admin)['similar'] === [], 'No hides it');
    check(ecw_memory_check_similar($org, $new, $ai) === 0, '…and it is never suggested again');
    try { ecw_memory_decide($org, $new, $broken, 'yes', $nick); check(false, 'a pair the AI rejected cannot be confirmed by guessing ids'); }
    catch (InvalidArgumentException $e) { check(true, 'a pair the AI rejected cannot be confirmed by guessing ids'); }
    try { ecw_memory_decide($org + 1, $new, $old, 'yes', $nick); check(false, 'another workspace cannot answer'); }
    catch (InvalidArgumentException $e) { check(true, 'another workspace cannot answer'); }

    group('the search itself');
    unset($GLOBALS['ecw_memory_candidates_test']);
    $res = ecw_memory_candidates($org, $new, 'P-200 pump leak "quoted" +operators -minus (x)');
    check(is_array($res), 'the fulltext search runs (operators in customer text are stripped)');
    check(ecw_memory_candidates($org, $new, '!!') === [], 'too little text: no search');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    unset($GLOBALS['ecw_ai_http_transport'], $GLOBALS['ecw_memory_candidates_test']);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
