<?php declare(strict_types=1);
/** Settings → Integrations: outgoing webhooks (admins). */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/webhooks.php';
$context = ecw_require_admin(); $navActive = 'settings'; $base = ecw_web_base();
$settings = ecw_webhook_settings((int)$context['organization_id']);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Integrations — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="teams-setup btn-scope">
  <h1>Integrations</h1>
  <div class="teams-card">
    <h2><i class="fa fa-plug" aria-hidden="true"></i> Webhook to your other systems</h2>
    <p>Send case events to a CRM, ERP, Zapier, Make or Power Automate.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">Technical details</span><span class="st-fold-hint">For whoever sets up the other system</span></summary>
      <p class="muted small">Each event is an HTTPS <code>POST</code> with JSON, signed in the header <code>X-ECW-Signature: sha256=…</code> (HMAC of the body with your secret). Members-only cases are never sent, and full email bodies are never included.</p>
    </details>
    <p class="muted"><?= $settings['configured'] ? 'Sending to ' . $h($settings['url_hint']) : 'Not connected.' ?></p>
    <form id="hookForm" autocomplete="off">
      <div class="teams-notify-row">
        <input type="url" id="hookUrl" placeholder="<?= $settings['configured'] ? 'Paste a new address to replace it (a new secret is generated)' : 'https://…' ?>" aria-label="Webhook address">
        <?php if ($settings['configured']): ?><button type="button" id="hookRemove" class="danger">Disconnect</button><?php endif; ?>
      </div>
      <fieldset class="teams-notify-events"><legend>Send when</legend>
        <?php foreach ($settings['labels'] as $key => $label): ?>
          <label><input type="checkbox" name="events" value="<?= $h($key) ?>" <?= in_array($key, $settings['events'], true) ? 'checked' : '' ?>> <code><?= $h($key) ?></code> · <?= $h($label) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="teams-notify-row">
        <button type="submit" class="primary">Save</button>
        <button type="button" id="hookTest" <?= $settings['configured'] ? '' : 'disabled' ?>>Send test event</button>
      </div>
      <p id="hookStatus" role="status" class="muted"></p>
      <p id="hookSecret" hidden class="teams-card" style="background:var(--accent-soft)"></p>
    </form>
  </div>
  <div class="teams-card">
    <h2><i class="ecw-teams-icon" aria-hidden="true"></i> Microsoft Teams</h2>
    <p>Channel notifications are set up under <a href="teams.php">Settings → Teams</a>. SharePoint/OneDrive filing and calendar meetings need extra Microsoft permissions and are not enabled yet.</p>
  </div>
</main>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const call = async (action, body) => {
    const r = await fetch('../api/index.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify(body || {}) });
    const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || 'Request failed'); return d;
  };
  const events = () => [...document.querySelectorAll('input[name="events"]:checked')].map((b) => b.value);
  $('hookForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = $('hookUrl').value.trim();
    try {
      const d = await call('webhook_save', url ? { url, events: events() } : { events: events() });
      $('hookStatus').textContent = 'Saved.';
      if (d.secret) {
        const box = $('hookSecret'); box.hidden = false; box.textContent = '';
        const strong = document.createElement('strong'); strong.textContent = 'Signing secret (shown once, copy it now): ';
        const code = document.createElement('code'); code.textContent = d.secret;
        box.append(strong, code);
        $('hookTest').disabled = false; $('hookUrl').value = '';
      }
    } catch (err) { $('hookStatus').textContent = err.message; }
  });
  $('hookRemove')?.addEventListener('click', async (e) => {
    if (window.ecwArm && !window.ecwArm(e.currentTarget, 'Click again to disconnect')) return;
    try { await call('webhook_save', { url: '', events: events() }); location.reload(); } catch (err) { $('hookStatus').textContent = err.message; }
  });
  $('hookTest').addEventListener('click', async () => {
    $('hookStatus').textContent = 'Sending…';
    try { await call('webhook_test'); $('hookStatus').textContent = 'Test event delivered.'; } catch (err) { $('hookStatus').textContent = err.message; }
  });
})();
</script>
</body></html>
