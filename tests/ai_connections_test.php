<?php declare(strict_types=1);
/**
 * AI connections, jobs, backups and Pause all AI (lib/ai.php), and the
 * "This will …" previews of suggestion actions (lib/agents.php).
 *
 *   php tests/ai_connections_test.php
 *
 * No AI is called: $GLOBALS['ecw_ai_http_transport'] answers (or fails) as each
 * connection would. Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/ai.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/health.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'AI connections']);
    $admin = $ins('users', ['name' => 'Ada', 'email' => 'ada' . uniqid() . '@ai.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);

    group('the old single setting becomes a connection');
    $pdo->prepare('UPDATE ecw_organizations SET ai_json = ? WHERE id = ?')->execute([json_encode(['provider' => 'openai', 'base_url' => 'http://ollama:11434/v1', 'model' => 'llama3.1:8b', 'key_enc' => '', 'no_think' => true]), $org]);
    $s = ecw_ai_settings($org);
    check(count($s['connections']) === 1 && $s['connections'][0]['model'] === 'llama3.1:8b' && $s['jobs']['agents'][0] === 'c1', 'an existing own AI keeps working, now as a connection doing every job');

    group('connections');
    try { ecw_ai_connection_save($org, ['name' => 'X', 'kind' => 'openai', 'base_url' => 'ftp://x', 'model' => 'm']); check(false, 'only http(s) addresses'); } catch (InvalidArgumentException $e) { check(true, 'only http(s) addresses'); }
    try { ecw_ai_connection_save($org, ['name' => 'C', 'kind' => 'claude', 'model' => '']); check(false, 'a Claude connection needs its key'); } catch (InvalidArgumentException $e) { check(true, 'a Claude connection needs its key'); }
    $claude = ecw_ai_connection_save($org, ['name' => 'Our Claude', 'kind' => 'claude', 'api_key' => 'sk-ant-test', 'daily_cap' => '1']);
    $openai = ecw_ai_connection_save($org, ['name' => 'OpenAI', 'kind' => 'openai', 'base_url' => 'https://api.openai.com/v1/', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test']);
    $s = ecw_ai_settings($org);
    $byId = array_column($s['connections'], null, 'id');
    check($byId[$claude]['model'] === ECW_AI_MODEL && $byId[$openai]['base_url'] === 'https://api.openai.com/v1' && !str_contains(json_encode($s), 'sk-test'), 'saved with sensible defaults; keys only encrypted');
    ecw_ai_connection_save($org, ['id' => $openai, 'name' => 'OpenAI', 'kind' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o', 'api_key' => '']);
    check(ecw_ai_connection_config($org, $openai, ecw_ai_settings($org))['key'] === 'sk-test', 'changing a connection without typing the key keeps the key');

    group('which AI does which job');
    ecw_ai_jobs_save($org, ['main' => ['assistant' => $claude, 'agents' => $openai, 'drafts' => $claude], 'backup' => ['assistant' => $openai, 'agents' => '', 'drafts' => $claude]]);
    $a = ecw_ai_config($org, 'assistant');
    $g = ecw_ai_config($org, 'agents');
    $d = ecw_ai_config($org, 'drafts');
    check($a['conn_id'] === $claude && $a['backups'][0]['conn_id'] === $openai, 'Ask AI: our Claude, OpenAI as backup');
    check($g['conn_id'] === $openai && $g['provider'] === 'openai' && $g['backups'] === [], 'agents: OpenAI, no backup');
    check($d['conn_id'] === $claude && $d['backups'] === [], 'a backup that is the main one itself is dropped');
    try { ecw_ai_connection_delete($org, $claude); check(false, 'a connection doing a job cannot be removed'); } catch (InvalidArgumentException $e) { check(true, 'a connection doing a job cannot be removed'); }

    group('the backup answers');
    $calls = [];
    $GLOBALS['ecw_ai_http_transport'] = static function (string $kind, array $body, array $c) use (&$calls) {
        $calls[] = [$c['conn_id'], $kind, $body];
        if ($kind === 'claude') throw new RuntimeException('Our Claude refused the key. Check it in Settings → Email assistant.', 401);
        return ['choices' => [['message' => ['content' => 'From OpenAI'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]];
    };
    $r = ecw_ai_complete($a, ['max_tokens' => 50, 'system' => 'Be brief.', 'messages' => [['role' => 'user', 'content' => 'Hello']]]);
    check($r['text'] === 'From OpenAI' && count($calls) === 2, 'the main one failed, the backup answered, the caller got a normal answer');
    $sent = $calls[1][2];
    check($sent['model'] === 'gpt-4o' && $sent['messages'][0] === ['role' => 'system', 'content' => 'Be brief.'] && $sent['messages'][1]['content'] === 'Hello', 'the request was translated for the backup (system prompt, message, its own model)');
    $u = ecw_ai_usage($org);
    check((int)$u[$claude]['failures'] === 1 && str_contains((string)$u[$claude]['last_error'], 'refused the key') && (int)$u[$openai]['fallbacks'] === 1 && (int)$u[$openai]['input_tokens'] === 12, 'counted: a failure for the main one, a backup answer with its tokens');
    $hc = array_values(array_filter(ecw_health_checks(), fn($c) => $c['key'] === 'ai.' . $org))[0] ?? null;
    check($hc !== null && $hc['state'] === 'warn' && str_contains($hc['detail'], 'Our Claude') && str_contains($hc['detail'], 'backup OpenAI is answering'), 'System health says the main AI fails and the backup answers', json_encode($hc) . ' ' . json_encode($u[$claude] ?? null));

    // The other way round: an OpenAI-format caller, a Claude backup.
    $GLOBALS['ecw_ai_http_transport'] = static function (string $kind, array $body, array $c) use (&$calls) {
        $calls[] = [$c['conn_id'], $kind, $body];
        if ($kind === 'openai') throw new RuntimeException('down', 503);
        return ['content' => [['type' => 'text', 'text' => '{"ok":true}']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 2], 'stop_reason' => 'end_turn'];
    };
    ecw_ai_jobs_save($org, ['main' => ['assistant' => $claude, 'agents' => $openai, 'drafts' => $claude], 'backup' => ['assistant' => $openai, 'agents' => $claude, 'drafts' => '']]);
    $pdo->exec("DELETE FROM ecw_rate_limits WHERE scope = 'ai_conn'");
    $calls = [];
    $j = ecw_agent_json(ecw_ai_config($org, 'agents'), 'Answer JSON.', 'Hi', 100);
    check($j === ['ok' => true] && $calls[1][1] === 'claude' && $calls[1][2]['system'] === "Answer JSON.\n\nAnswer with one JSON object only.", 'an agent asking OpenAI-style got its JSON from the Claude backup');

    group('a daily limit per connection');
    $GLOBALS['ecw_ai_http_transport'] = static function (string $kind, array $body, array $c) use (&$calls) {
        $calls[] = [$c['conn_id'], $kind];
        return $kind === 'claude' ? ['content' => [['type' => 'text', 'text' => 'Claude']], 'usage' => []] : ['choices' => [['message' => ['content' => 'OpenAI']]], 'usage' => []];
    };
    $pdo->exec("DELETE FROM ecw_rate_limits WHERE scope = 'ai_conn'");
    $calls = [];
    $first = ecw_ai_complete(ecw_ai_config($org, 'assistant'), ['max_tokens' => 10, 'system' => 's', 'messages' => [['role' => 'user', 'content' => 'x']]]);
    $second = ecw_ai_complete(ecw_ai_config($org, 'assistant'), ['max_tokens' => 10, 'system' => 's', 'messages' => [['role' => 'user', 'content' => 'x']]]);
    check($first['text'] === 'Claude' && $second['text'] === 'OpenAI', 'our Claude allows 1 a day: the second request went to the backup');

    group('Pause all AI');
    ecw_ai_pause($org, true, $admin);
    try { ecw_ai_complete(ecw_ai_config($org, 'assistant'), ['max_tokens' => 10, 'system' => 's', 'messages' => [['role' => 'user', 'content' => 'x']]]); check(false, 'nothing is sent while paused'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'paused'), 'nothing is sent while paused, and it says why'); }
    check(!ecw_agents_allowed($org), 'the agents stop too');
    $hc = array_values(array_filter(ecw_health_checks(), fn($c) => $c['key'] === 'ai.' . $org))[0] ?? null;
    check($hc !== null && str_contains($hc['detail'], 'paused'), 'System health shows it is paused');
    ecw_ai_pause($org, false, $admin);
    check(!ecw_ai_paused($org) && (int)$pdo->query("SELECT COUNT(*) FROM ecw_audit_log WHERE organization_id = {$org} AND action IN ('settings.ai_paused', 'settings.ai_resumed')")->fetchColumn() === 2, 'switched back on; both recorded in the audit log');

    group('when the AI itself is out');
    check(ecw_ai_unavailable(new RuntimeException('Our AI could not complete this request (500).', 500)) && ecw_ai_unavailable(new RuntimeException('Our AI could not be reached. Check its address in Settings → AI.', 0)),
        'a server error or an unreachable AI counts as "the AI is out" (the agents pause 10 minutes)');
    check(!ecw_ai_unavailable(new RuntimeException('Our AI refused the key.', 401)) && !ecw_ai_unavailable(new RuntimeException('busy', 429)) && !ecw_ai_unavailable(new InvalidArgumentException('x', 500)),
        'a refused key, a busy AI or a bad request do not');
    check(ECW_AGENT_AI_PAUSE === 600, 'the pause is 10 minutes');

    group('suggestions say what they will do');
    $p = ecw_suggestion_action_preview(['type' => 'remind', 'at' => '2026-10-09 07:00:00', 'if_no_reply' => true], []);
    check(str_contains($p, 'Fri 9 Oct, 07:00') && str_contains($p, 'only if nobody has replied') && str_contains($p, 'Nothing is sent'), 'a reminder: when, on what condition, and that nothing is sent');
    check(str_contains((string)ecw_suggestion_action_preview(['type' => 'draft_reply'], []), 'Nothing is sent'), 'a drafted reply is only a draft');
    check(str_contains((string)ecw_suggestion_action_preview(['type' => 'new_case', 'name' => 'Broken pump'], []), '“Broken pump”'), 'a new case names itself');
    check(ecw_suggestion_action_preview(['type' => 'open_thread'], []) === null, 'opening something needs no warning');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    unset($GLOBALS['ecw_ai_http_transport']);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
