<?php declare(strict_types=1);
/**
 * Settings → AI (admins): the AI connections of the workspace,
 * which one does which job (with a backup), what each one used this month,
 * and Pause all AI. Logic: lib/ai.php (ecw_ai_settings, ecw_ai_http).
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/ai.php';
require_once dirname(__DIR__, 2) . '/lib/agents.php';
$context = ecw_require_admin(); ecw_require_feature('ai'); $orgId = (int)$context['organization_id'];
$navActive = 'settings'; $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $base = ecw_web_base();
$notice = null; $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { $error = 'Your session expired. Please try again.'; }
    else {
        try {
            switch ((string)($_POST['form'] ?? '')) {
                case 'pause':
                    ecw_ai_pause($orgId, ($_POST['paused'] ?? '') === '1', (int)$context['user']['id']);
                    $notice = ($_POST['paused'] ?? '') === '1' ? 'All AI is paused. Nothing is sent to any AI until you switch it back on.' : 'AI is on again.';
                    break;
                case 'connection':
                    $id = ecw_ai_connection_save($orgId, $_POST);
                    $notice = 'Saved.';
                    if (!empty($_POST['test'])) {
                        // A made-up sentence only: no email content is sent by the test.
                        $s = ecw_ai_settings($orgId);
                        $config = ecw_ai_connection_config($orgId, $id, $s);
                        ecw_rate_limit_hit('ai_minute', 'user:' . (int)$context['user']['id'], 3, 60);
                        $t = microtime(true);
                        $r = ecw_ai_complete($config + ['backups' => []], ['max_tokens' => 20, 'system' => 'Reply with the single word OK.', 'messages' => [['role' => 'user', 'content' => 'Connection test.']]]);
                        $notice = 'Saved. ' . $config['conn_name'] . ' answered in ' . number_format(microtime(true) - $t, 1) . ' s: “' . mb_substr(trim($r['text']), 0, 40) . '”';
                    }
                    break;
                case 'test':
                    $s = ecw_ai_settings($orgId);
                    $config = ecw_ai_connection_config($orgId, (string)($_POST['id'] ?? ''), $s);
                    if (!$config) throw new InvalidArgumentException('That connection no longer exists.');
                    ecw_rate_limit_hit('ai_minute', 'user:' . (int)$context['user']['id'], 3, 60);
                    $t = microtime(true);
                    $r = ecw_ai_complete($config + ['backups' => []], ['max_tokens' => 20, 'system' => 'Reply with the single word OK.', 'messages' => [['role' => 'user', 'content' => 'Connection test.']]]);
                    $notice = $config['conn_name'] . ' answered in ' . number_format(microtime(true) - $t, 1) . ' s: “' . mb_substr(trim($r['text']), 0, 40) . '”';
                    break;
                case 'delete':
                    ecw_ai_connection_delete($orgId, (string)($_POST['id'] ?? ''));
                    $notice = 'Connection removed.';
                    break;
                case 'jobs':
                    ecw_ai_jobs_save($orgId, $_POST);
                    $notice = 'Saved.';
                    break;
                case 'agents':
                    ecw_agents_settings_save($orgId, $_POST);
                    $notice = 'Saved. The agents pick it up within a minute.';
                    break;
            }
        } catch (EcwRateLimitExceeded $e) { $error = 'Too many tests in a minute. Wait a moment.'; }
        catch (InvalidArgumentException|RuntimeException $e) { $error = $e->getMessage(); }
    }
}
$s = ecw_ai_settings($orgId);
$usage = ecw_ai_usage($orgId);
$status = ecw_ai_status($orgId);
$serverKey = ecw_ai_server_key() !== '';
$editing = (string)($_GET['edit'] ?? '');
$edit = null;
foreach ($s['connections'] as $c) if ($c['id'] === $editing) $edit = $c;
// Every connection the jobs can use, the built-in one first.
$choices = ($serverKey ? ['server' => 'Built-in Claude (this server)'] : []) + array_column($s['connections'], 'name', 'id');
$jobsOf = static function (string $id) use ($s): array {
    $out = [];
    foreach ($s['jobs'] as $job => [$main, $backup]) {
        if ($main === $id) $out[] = ECW_AI_JOBS[$job];
        elseif ($backup === $id) $out[] = 'backup for ' . lcfirst(ECW_AI_JOBS[$job]);
    }
    return $out;
};
$num = static fn($n) => number_format((int)$n);
$agents = ecw_agents_settings($orgId);
// One page, three tabs (the old Settings → AI agents page sends people to "agents").
$tab = (string)($_POST['tab'] ?? $_GET['tab'] ?? '');
if (!in_array($tab, ['connections', 'agents', 'usage'], true)) $tab = ($_POST['form'] ?? '') === 'agents' ? 'agents' : 'connections';
if ($editing !== '') $tab = 'connections';
$tabUrl = static fn(string $t): string => 'ai.php' . ($t === 'connections' ? '' : '?tab=' . $t);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?><div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope ai-page"><h1>AI</h1>
<p class="muted">Ask AI in Mail and Cases, drafted replies, translations, case summaries and the background agents: which AI does it, what the agents may do, and what it all used.</p>
<?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

<form method="post" class="ai-pause<?= $s['paused'] ? ' on' : '' ?>">
  <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="pause">
  <i class="fa <?= $s['paused'] ? 'fa-circle-pause' : 'fa-circle-check' ?>" aria-hidden="true"></i>
  <div><strong><?= $s['paused'] ? 'All AI is paused' : 'AI is on' ?></strong>
    <span class="muted small"><?= $s['paused'] ? 'Nothing is sent to any AI: no Ask AI, no drafts, no agents. Since ' . $h(ecw_tz_local($s['paused_at'])) . '.' : 'Pause it any time — for example while you check what it does, or if a bill looks wrong.' ?></span></div>
  <input type="hidden" name="tab" value="<?= $h($tab) ?>">
  <button type="submit" name="paused" value="<?= $s['paused'] ? '0' : '1' ?>" class="<?= $s['paused'] ? 'primary' : 'danger' ?>"><?= $s['paused'] ? 'Switch AI back on' : 'Pause all AI' ?></button>
</form>

<nav class="ai-tabs" aria-label="AI settings">
  <?php foreach (['connections' => ['fa-plug', 'Connections'], 'agents' => ['fa-robot', 'Agents'], 'usage' => ['fa-chart-column', 'Usage']] as $t => [$ic, $label]): ?>
    <a href="<?= $h($tabUrl($t)) ?>" class="<?= $tab === $t ? 'active' : '' ?>"<?= $tab === $t ? ' aria-current="page"' : '' ?>><i class="fa <?= $ic ?>" aria-hidden="true"></i> <?= $h($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'connections'): ?>
<h2>Connections</h2>
<div class="ai-conns">
  <?php if ($serverKey): $u = $usage['server'] ?? null; ?>
    <article class="ai-conn">
      <header><strong>Built-in Claude</strong><span class="ai-kind">this server · Claude Haiku 4.5</span></header>
      <p class="small muted">Uses the Claude key configured on this server. <?= $jobsOf('server') ? 'Does: ' . $h(implode(', ', $jobsOf('server'))) . '.' : 'Not used for any job.' ?></p>
      <p class="ai-usage small"><?= $u ? $num($u['requests']) . ' requests · ' . $num($u['input_tokens'] + $u['output_tokens']) . ' tokens this month' . ((int)$u['failures'] ? ' · <span class="ai-fail">' . (int)$u['failures'] . ' failed</span>' : '') : 'Not used this month' ?></p>
      <?php if ($u && $u['last_error'] && $u['last_error_at'] > gmdate('Y-m-d H:i:s', time() - 86400)): ?><p class="ai-err small">Last error <?= $h(ecw_tz_local($u['last_error_at'])) ?>: <?= $h($u['last_error']) ?></p><?php endif; ?>
      <form method="post" class="ai-conn-actions"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="test"><input type="hidden" name="id" value="server"><button type="submit">Test</button></form>
    </article>
  <?php endif; ?>
  <?php foreach ($s['connections'] as $c): $u = $usage[$c['id']] ?? null; ?>
    <article class="ai-conn">
      <header><strong><?= $h($c['name']) ?></strong><span class="ai-kind"><?= $h($c['kind'] === 'claude' ? 'Claude' : parse_url($c['base_url'], PHP_URL_HOST)) ?> · <?= $h($c['model']) ?></span></header>
      <p class="small muted"><?= $jobsOf($c['id']) ? 'Does: ' . $h(implode(', ', $jobsOf($c['id']))) . '.' : 'Not used for any job yet.' ?><?= $c['daily_cap'] ? ' At most ' . $num($c['daily_cap']) . ' requests a day.' : '' ?></p>
      <p class="ai-usage small"><?= $u ? $num($u['requests']) . ' requests · ' . $num($u['input_tokens'] + $u['output_tokens']) . ' tokens this month' . ((int)$u['fallbacks'] ? ' · ' . (int)$u['fallbacks'] . ' as backup' : '') . ((int)$u['failures'] ? ' · <span class="ai-fail">' . (int)$u['failures'] . ' failed</span>' : '') : 'Not used this month' ?></p>
      <?php if ($u && $u['last_error'] && $u['last_error_at'] > gmdate('Y-m-d H:i:s', time() - 86400)): ?><p class="ai-err small">Last error <?= $h(ecw_tz_local($u['last_error_at'])) ?>: <?= $h($u['last_error']) ?></p><?php endif; ?>
      <div class="ai-conn-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="test"><input type="hidden" name="id" value="<?= $h($c['id']) ?>"><button type="submit">Test</button></form>
        <a class="button" href="?edit=<?= $h(rawurlencode($c['id'])) ?>#connForm">Change</a>
        <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= $h($c['id']) ?>"><button type="submit" class="danger" data-confirm="Click again to remove">Remove</button></form>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$serverKey && !$s['connections']): ?><p class="muted">No AI connected yet. Add one below.</p><?php endif; ?>
</div>

<?php $connOpen = $edit || (!$serverKey && !$s['connections']) || ($error !== null && ($_POST['form'] ?? '') === 'connection'); ?>
<details class="st-fold ai-add"<?= $connOpen ? ' open' : '' ?> id="connFold">
<summary><span class="st-fold-title"><i class="fa <?= $edit ? 'fa-pen' : 'fa-plus' ?>" aria-hidden="true"></i> <?= $edit ? 'Change “' . $h($edit['name']) . '”' : 'Add a connection' ?></span><span class="st-fold-hint">OpenAI, Gemini, Mistral, a local Ollama, or Claude with your own key</span></summary>
<form method="post" class="panel ai-form" id="connForm">
  <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="connection">
  <?php if ($edit): ?><input type="hidden" name="id" value="<?= $h($edit['id']) ?>"><?php endif; ?>
  <div class="ai-grid">
    <label>Name <input type="text" name="name" maxlength="60" required value="<?= $h($edit['name'] ?? '') ?>" placeholder="Our Claude, OpenAI, Local Ollama…"></label>
    <label>Type <select name="kind" id="connKind" class="ot-select">
      <option value="openai" <?= ($edit['kind'] ?? 'openai') === 'openai' ? 'selected' : '' ?>>OpenAI-compatible (OpenAI, Gemini, Mistral, Groq, Ollama…)</option>
      <option value="claude" <?= ($edit['kind'] ?? '') === 'claude' ? 'selected' : '' ?>>Claude, with your own key</option>
    </select></label>
  </div>
  <div class="ai-openai" id="connOpenai">
    <div class="ai-presets" role="group" aria-label="Fill in a known service"><span class="muted small">Fill in:</span>
      <?php foreach (ECW_AI_PRESETS as $key => [$label, $url, $model]): ?><button type="button" data-url="<?= $h($url) ?>" data-model="<?= $h($model) ?>"><?= $h($label) ?></button><?php endforeach; ?>
    </div>
    <label>Address <input type="url" name="base_url" maxlength="255" value="<?= $h($edit['base_url'] ?? '') ?>" placeholder="https://api.openai.com/v1"></label>
  </div>
  <div class="ai-grid">
    <label>Model <input type="text" name="model" maxlength="120" value="<?= $h($edit['model'] ?? '') ?>" placeholder="gpt-4o-mini · claude-haiku-4-5-20251001 · llama3.1:8b"></label>
    <label>API key <input type="password" name="api_key" autocomplete="new-password" placeholder="<?= ($edit['key_enc'] ?? '') !== '' ? '•••••••• stored — leave empty to keep it' : 'Local models need none' ?>"></label>
    <label>Most requests a day <input type="number" name="daily_cap" min="1" max="100000" value="<?= $h($edit['daily_cap'] ?? '') ?>" placeholder="No limit"></label>
  </div>
  <label class="checkbox-label"><input type="checkbox" name="no_think" value="1" <?= ($edit['no_think'] ?? true) ? 'checked' : '' ?>> Turn off “thinking” — much faster with local models such as Gemma or Qwen. Untick it if the service rejects the request.</label>
  <?php if (($edit['key_enc'] ?? '') !== ''): ?><label class="checkbox-label"><input type="checkbox" name="clear_key" value="1"> Remove the stored key</label><?php endif; ?>
  <div class="flash warn small ai-warning"><strong>Where the email text goes.</strong> Excerpts of your customers’ emails are sent to the connection that answers. A model on your own server keeps them in-house. Free cloud tiers and token “routers” may keep, read or train on what you send — check their data terms first. Keys are stored encrypted and never shown again.</div>
  <div class="ai-actions"><button type="submit" class="primary"><?= $edit ? 'Save' : 'Add' ?></button> <button type="submit" name="test" value="1"><?= $edit ? 'Save' : 'Add' ?> and test</button><?php if ($edit): ?> <a href="ai.php" class="button">Cancel</a><?php endif; ?></div>
  <p class="muted small">The test sends one short made-up sentence, never an email.</p>
</form>
</details>

<h2>Which AI does what</h2>
<?php if (!$choices): ?><p class="muted">Add a connection first.</p><?php else: ?>
<form method="post" class="panel ai-jobs">
  <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="jobs">
  <div class="ai-job ai-job-head"><span></span><span class="muted small">Main</span><span class="muted small">Backup</span></div>
  <?php foreach (ECW_AI_JOBS as $job => $label): [$main, $backup] = $s['jobs'][$job]; ?>
    <div class="ai-job"><strong><?= $h($label) ?></strong>
      <select name="main[<?= $h($job) ?>]" class="ot-select" aria-label="Main AI for <?= $h($label) ?>"><?php foreach ($choices as $id => $name): ?><option value="<?= $h($id) ?>" <?= $id === $main ? 'selected' : '' ?>><?= $h($name) ?></option><?php endforeach; ?></select>
      <select name="backup[<?= $h($job) ?>]" class="ot-select" aria-label="Backup for <?= $h($label) ?>"><option value="">No backup</option><?php foreach ($choices as $id => $name): ?><option value="<?= $h($id) ?>" <?= $id === $backup ? 'selected' : '' ?>><?= $h($name) ?></option><?php endforeach; ?></select>
    </div>
  <?php endforeach; ?>
  <details class="st-fold st-fold-inline">
    <summary><span class="st-fold-title">Daily limit and tips</span><span class="st-fold-hint">Today <?= $h($status['requests_today']) ?> of <?= $h($status['daily_limit']) ?> Ask AI requests</span></summary>
    <p class="muted small">A cheaper, faster model is often enough for the background agents; the best one for drafted replies. When the main one fails or reaches its daily limit, the backup answers and System health says so.</p>
    <label class="ai-number"><span>Ask AI requests per day for the whole workspace</span> <input type="number" name="daily_requests" min="1" max="5000" value="<?= $h($s['daily_requests'] ?? '') ?>" placeholder="<?= $h($status['daily_limit']) ?>"></label>
    <p class="muted small">Resets every day at <?= $h(ecw_tz_local(gmdate('Y-m-d 00:00:00'), 'H:i')) ?> your time. With the built-in Claude, the server’s allowance is the maximum.</p>
  </details>
  <div class="ai-actions"><button type="submit" class="primary">Save</button></div>
</form>
<?php endif; ?>
<?php endif; /* connections */ ?>

<?php if ($tab === 'agents'):
  $stats = ecw_agents_stats($orgId);
  $agentAi = ecw_ai_config($orgId, 'agents');
  $agentReady = ecw_ai_ready($agentAi);
  $worker = is_file(dirname(__DIR__, 2) . '/logs/.worker.lock'); ?>
<h2>What the AI agents do <a href="#" class="help-link" data-help="suggestions"><i class="fa fa-circle-question" aria-hidden="true"></i> How suggestions work</a></h2>
<p class="muted">Agents work in the background and leave <strong>suggestions</strong> that people accept or dismiss with one click. Nothing is sent, and nothing changes in a case, without that click.</p>
<details class="st-fold st-fold-inline"><summary><span class="st-fold-title">Where suggestions appear</span></summary>
  <p class="muted small">Under Mail → Suggestions, above an email, and in a case's Summary tab. The one exception to "nothing without a click" is automatic filing, if you turn it on under More options, which has an Undo. Suggestions that no longer apply (answered, case closed) go by themselves.</p>
</details>
<?php if (!$agentReady): ?><div class="cop-warn">The AI is not set up, so only the agents without AI run (No reply yet, Reply-time alerts, Morning briefing, Patterns). Set it up under <a href="ai.php">Connections</a>.</div><?php endif; ?>
<p class="muted small">Agents use: <?= $h($agentReady ? ($agentAi['conn_name'] ?? $agentAi['model'] ?? 'AI') : 'no AI') ?> · background worker <?= $worker ? 'running' : 'not seen yet' ?> · each person can turn suggestions off in <a href="view.php">My view</a>.</p>
<form method="post" class="ag-form">
  <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="agents"><input type="hidden" name="tab" value="agents">
  <div class="ag-list">
    <?php foreach (ECW_AGENTS as $key => [$label, $desc, $default, $usesAi]):
      $runs = $stats['runs'][$key] ?? []; $sug = $stats['suggestions'][$key] ?? [];
      $made = array_sum($sug); $took = (int)($sug['accepted'] ?? 0); ?>
      <label class="ag-row">
        <span class="ag-switch"><input type="checkbox" name="on[<?= $h($key) ?>]" value="1" <?= $agents['on'][$key] ? 'checked' : '' ?>><span aria-hidden="true"></span></span>
        <span class="ag-text">
          <strong><?= $h($label) ?></strong><?= $usesAi ? ' <span class="ag-ai">AI</span>' : '' ?><br>
          <span class="muted small"><?= $h($desc) ?></span>
          <?php if ($runs || $made): ?><span class="ag-stats"><?= array_sum($runs) ? $num(array_sum($runs)) . ' handled' : '' ?><?= $made ? ' · ' . $made . ' suggestion' . ($made === 1 ? '' : 's') . ', ' . $took . ' accepted' : '' ?> · last 7 days</span><?php endif; ?>
        </span>
      </label>
    <?php endforeach; ?>
  </div>
  <details class="st-fold ag-more">
  <summary><span class="st-fold-title"><i class="fa fa-sliders" aria-hidden="true"></i> More options</span><span class="st-fold-hint">Automatic filing, "No reply yet" days, briefing time, daily allowance</span></summary>
  <fieldset class="ap-group ag-options"><legend>Options</legend>
    <label class="checkbox-label"><input type="checkbox" name="auto_link" value="1" <?= $agents['auto_link'] ? 'checked' : '' ?>> File new email into its case automatically when it is obvious (one open case, two or more people in common). The suggestion then offers Undo.</label>
    <div class="ap-two">
      <label>"No reply yet" after <select name="chaser_days" class="ot-select"><?php foreach ([1, 2, 3, 4, 5, 7, 10, 14] as $d): ?><option value="<?= $d ?>" <?= $agents['chaser_days'] === $d ? 'selected' : '' ?>><?= $d ?> day<?= $d === 1 ? '' : 's' ?></option><?php endforeach; ?></select></label>
      <label>Morning briefing at <select name="digest_hour" class="ot-select"><?php for ($hh = 6; $hh <= 11; $hh++): ?><option value="<?= $hh ?>" <?= $agents['digest_hour'] === $hh ? 'selected' : '' ?>><?= sprintf('%02d:00', $hh) ?></option><?php endfor; ?></select> <span class="muted small">company time (<?= $h(str_replace('_', ' ', ecw_tz_company($orgId))) ?>)</span></label>
    </div>
    <label class="ai-number"><span>AI requests per day for the agents</span> <input type="number" name="daily_ai" min="0" max="5000" value="<?= $agents['daily_ai'] ?: '' ?>" placeholder="<?= ($agentAi['provider'] ?? '') === 'openai' ? '400' : '60' ?>"></label>
    <p class="muted small">Separate from Ask AI, so people never run out because of the agents. Empty: the default shown.</p>
  </fieldset>
  </details>
  <div class="ai-actions"><button type="submit" class="primary">Save</button></div>
</form>
<?php endif; ?>

<?php if ($tab === 'usage'):
  $stats = ecw_agents_stats($orgId);
  $rows = [];
  if ($serverKey) $rows['server'] = 'Built-in Claude';
  foreach ($s['connections'] as $c) $rows[$c['id']] = $c['name'];
  $total = ['requests' => 0, 'tokens' => 0, 'failures' => 0]; ?>
<h2>This month</h2>
<div class="panel ai-usage-panel">
  <div class="ai-usage-today">
    <div><span class="muted small">Ask AI today</span><strong><?= $num($status['requests_today']) ?> <span class="muted">of <?= $num($status['daily_limit']) ?></span></strong></div>
    <div><span class="muted small">Agents, last 7 days</span><strong><?= $num(array_sum(array_map('array_sum', $stats['runs'] ?? []))) ?> <span class="muted">handled</span></strong></div>
    <div><span class="muted small">Suggestions, last 7 days</span><strong><?= $num(array_sum(array_map('array_sum', $stats['suggestions'] ?? []))) ?> <span class="muted">made</span></strong></div>
  </div>
  <?php if (!$rows): ?><p class="muted">No AI connected yet.</p><?php else: ?>
  <table class="ai-usage-table">
    <thead><tr><th>Connection</th><th>Does</th><th class="num">Requests</th><th class="num">Tokens</th><th class="num">As backup</th><th class="num">Failed</th><th>Last used</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $id => $name): $u = $usage[$id] ?? null;
      $total['requests'] += (int)($u['requests'] ?? 0); $total['tokens'] += (int)($u['input_tokens'] ?? 0) + (int)($u['output_tokens'] ?? 0); $total['failures'] += (int)($u['failures'] ?? 0); ?>
      <tr>
        <td><strong><?= $h($name) ?></strong></td>
        <td class="muted small"><?= $h(implode(', ', $jobsOf((string)$id)) ?: '—') ?></td>
        <td class="num"><?= $num($u['requests'] ?? 0) ?></td>
        <td class="num"><?= $num((int)($u['input_tokens'] ?? 0) + (int)($u['output_tokens'] ?? 0)) ?></td>
        <td class="num"><?= $num($u['fallbacks'] ?? 0) ?></td>
        <td class="num<?= (int)($u['failures'] ?? 0) ? ' ai-fail' : '' ?>"><?= $num($u['failures'] ?? 0) ?></td>
        <td class="muted small"><?= $h(ecw_tz_local($u['last_used_at'] ?? null) ?: '—') ?></td>
      </tr>
      <?php if ($u && $u['last_error'] && $u['last_error_at'] > gmdate('Y-m-d H:i:s', time() - 86400)): ?><tr class="ai-usage-err"><td colspan="7"><span class="ai-err small">Last error <?= $h(ecw_tz_local($u['last_error_at'])) ?>: <?= $h($u['last_error']) ?></span></td></tr><?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2">Total</td><td class="num"><?= $num($total['requests']) ?></td><td class="num"><?= $num($total['tokens']) ?></td><td></td><td class="num"><?= $num($total['failures']) ?></td><td></td></tr></tfoot>
  </table>
  <p class="muted small">Counted since the 1st of the month. A local model on your own server costs nothing per request; for paid services, the tokens are what you are billed for. Limits: Ask AI under Connections → Which AI does what, the agents under Agents → Options.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
</main>
</div>
<script>
(function () {
  const kind = document.getElementById('connKind'), box = document.getElementById('connOpenai'), form = document.getElementById('connForm');
  // The connection form is on the Connections tab only.
  if (kind && box && form) {
    const sync = () => { box.hidden = kind.value !== 'openai'; form.base_url.required = kind.value === 'openai'; };
    kind.addEventListener('change', sync); sync();
    box.querySelectorAll('.ai-presets button').forEach((b) => b.addEventListener('click', () => {
      form.base_url.value = b.dataset.url;
      if (!form.model.value) form.model.value = b.dataset.model;
      if (!form.name.value) form.name.value = b.textContent.trim();
    }));
  }
  // Remove: a second click confirms.
  document.querySelectorAll('button[data-confirm]').forEach((b) => b.addEventListener('click', (e) => {
    if (b.dataset.armed === '1') return;
    e.preventDefault(); b.dataset.armed = '1'; const t = b.textContent; b.textContent = b.dataset.confirm;
    setTimeout(() => { b.dataset.armed = ''; b.textContent = t; }, 4000);
  }));
})();
</script>
</body></html>
