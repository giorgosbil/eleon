<?php declare(strict_types=1);
/** Settings → Teams: post case events to a Teams channel (admins). */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/teams.php';
$context = ecw_require_admin(); ecw_require_feature('teams'); $navActive = 'settings'; $base = ecw_web_base();
$settings = ecw_teams_notify_settings((int)$context['organization_id']);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Teams notifications — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="teams-setup btn-scope">
  <h1>Teams channel notifications</h1>
  <div class="teams-card">
    <p>Post a card to a Teams channel when something happens on a case. This uses a Teams <strong>Workflows</strong> webhook, so it needs no extra Microsoft permission. Cases set to <em>Only selected people</em> are never announced.</p>
    <details class="st-fold st-fold-inline"<?= $settings['configured'] ? '' : ' open' ?>><summary><span class="st-fold-title">How to get the address</span><span class="st-fold-hint">Three steps in Teams</span></summary>
    <ol>
      <li>In Teams, open the channel → <strong>⋯ → Workflows</strong>.</li>
      <li>Choose the template <strong>“Post to a channel when a webhook request is received”</strong> and finish the steps.</li>
      <li>Copy the address it shows and paste it below.</li>
    </ol>
    </details>
    <form id="notifyForm" autocomplete="off">
      <div class="teams-notify-row">
        <input type="url" id="webhookUrl" placeholder="<?= $settings['configured'] ? 'A webhook is saved. Paste a new address to replace it.' : 'https://…logic.azure.com/workflows/…' ?>" aria-label="Webhook address">
        <?php if ($settings['configured']): ?><button type="button" id="webhookRemove" class="danger">Remove</button><?php endif; ?>
      </div>
      <fieldset class="teams-notify-events"><legend>Post when</legend>
        <?php foreach ($settings['labels'] as $key => $label): ?>
          <label><input type="checkbox" name="events" value="<?= $h($key) ?>" <?= in_array($key, $settings['events'], true) ? 'checked' : '' ?>> <?= $h($label) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="teams-notify-row">
        <button type="submit" class="primary">Save</button>
        <button type="button" id="webhookTest" <?= $settings['configured'] ? '' : 'disabled' ?>>Send test message</button>
      </div>
      <p id="notifyStatus" role="status" class="muted"></p>
    </form>
  </div>
</main>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const call = async (action, body) => {
    const r = await fetch('../api/index.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify(body || {}) });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || 'Request failed');
    return data;
  };
  const events = () => [...document.querySelectorAll('input[name="events"]:checked')].map((b) => b.value);
  $('notifyForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = $('webhookUrl').value.trim();
    try {
      await call('teams_notify_save', url ? { webhook_url: url, events: events() } : { events: events() });
      $('notifyStatus').textContent = 'Saved.';
      if (url) location.reload();
    } catch (err) { $('notifyStatus').textContent = err.message; }
  });
  $('webhookRemove')?.addEventListener('click', async (e) => {
    if (window.ecwArm && !window.ecwArm(e.currentTarget, 'Click again to remove')) return;
    try { await call('teams_notify_save', { webhook_url: '', events: events() }); location.reload(); }
    catch (err) { $('notifyStatus').textContent = err.message; }
  });
  $('webhookTest').addEventListener('click', async () => {
    $('notifyStatus').textContent = 'Sending…';
    try { await call('teams_notify_test'); $('notifyStatus').textContent = 'Test message sent. Check the channel.'; }
    catch (err) { $('notifyStatus').textContent = err.message; }
  });
})();
</script>
</body></html>
