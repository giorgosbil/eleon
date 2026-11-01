<?php declare(strict_types=1);
/** Settings → Support agent: connect the support agent (admins). */
require_once dirname(__DIR__, 3) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/support_agent.php';
$web = dirname(__DIR__, 3) . '/web';
$context = ecw_require_admin(); ecw_require_feature('support_agent'); $navActive = 'settings'; $base = ecw_web_base();
$sa = ecw_sa_settings((int)$context['organization_id']);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Support agent — Settings</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime($web . '/assets/app.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require $web . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require $web . '/partials/settings-nav.php'; ?>
<main class="teams-setup btn-scope">
  <h1><i class="fa fa-robot" aria-hidden="true"></i> Support agent</h1>
  <p class="muted">The support agent learns your team's problems and solutions. Admins get a small <i class="fa fa-robot" aria-hidden="true"></i> button on every conversation and case: it sends the emails (with screenshots and settings files) to the agent, which drafts what they teach. Nothing is learned until an admin approves it in the agent.</p>

  <?php if ($sa['configured'] && $sa['last_ok_at'] && !$sa['last_error']): ?>
  <div class="teams-card"><p class="wa-ok"><i class="fa fa-circle-check" aria-hidden="true"></i> Connected. Last checked <?= $h(ecw_tz_local($sa['last_ok_at'])) ?>.</p></div>
  <?php elseif ($sa['last_error']): ?>
  <div class="teams-card"><p class="error"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> <?= $h($sa['last_error']) ?></p></div>
  <?php endif; ?>

  <div class="teams-card">
    <h2>1. In the support agent</h2>
    <ol>
      <li>Open the support agent → <strong>Settings → API tokens</strong>, type <em>email program</em> and press <strong>Create token</strong>.</li>
      <li>Copy the token (it starts with <code>sat_</code>; it is shown once).</li>
    </ol>
  </div>

  <div class="teams-card">
    <h2>2. Connect</h2>
    <form id="saForm" autocomplete="off">
      <label for="saUrl">Address of the support agent's API</label>
      <input type="url" id="saUrl" value="<?= $h($sa['url'] ?: $sa['suggested_url']) ?>" required>
      <p class="muted">On this computer: <code>http://localhost/support_agent/public/api.php</code>. If the email program runs in Docker: <code>http://host.docker.internal/support_agent/public/api.php</code>.</p>
      <label for="saToken">Token</label>
      <input type="password" id="saToken" placeholder="<?= $sa['has_token'] ? 'saved — leave empty to keep it' : 'sat_…' ?>" autocomplete="new-password">
      <p><button type="submit" class="primary">Save and test</button> <span id="saStatus" class="muted" role="status"></span></p>
    </form>
  </div>
</main></div>
<script>
(function () {
  const form = document.getElementById('saForm');
  const status = document.getElementById('saStatus');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    status.textContent = 'Checking…';
    try {
      const res = await fetch('<?= $h($base) ?>api/index.php?action=sa_settings_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf },
        body: JSON.stringify({ url: document.getElementById('saUrl').value, token: document.getElementById('saToken').value }) });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Not saved');
      status.textContent = data.settings.last_error ? 'Saved, but: ' + data.settings.last_error : 'Connected.';
      if (!data.settings.last_error) setTimeout(() => location.reload(), 800);
    } catch (err) { status.textContent = err.message; }
  });
})();
</script>
</body></html>
