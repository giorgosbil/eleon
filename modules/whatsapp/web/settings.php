<?php declare(strict_types=1);
/** Settings → WhatsApp: connect the workspace's WhatsApp Business number (admins). */
require_once dirname(__DIR__, 3) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/whatsapp.php';
$web = dirname(__DIR__, 3) . '/web';
$context = ecw_require_admin(); ecw_require_feature('whatsapp'); $navActive = 'settings'; $base = ecw_web_base();
$wa = ecw_wa_settings((int)$context['organization_id']);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$https = str_starts_with($wa['webhook_url'], 'https://');
$waMailboxes = ecw_db()->prepare('SELECT id, email_address FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ? ORDER BY id');
$waMailboxes->execute([(int)$context['organization_id']]);
$waMailboxes = $waMailboxes->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>WhatsApp — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime($web . '/assets/app.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require $web . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require $web . '/partials/settings-nav.php'; ?>
<main class="teams-setup wa-setup btn-scope">
  <h1><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp</h1>

  <?php if ($wa['configured']): ?>
  <div class="teams-card">
    <p class="wa-ok"><i class="fa fa-circle-check" aria-hidden="true"></i> Connected: <?= $h($wa['verified_name'] ?: 'your number') ?> · <?= $h($wa['display_phone']) ?></p>
    <p class="muted"><?= $wa['last_webhook_at'] ? 'Last message from WhatsApp: ' . $h(ecw_tz_local($wa['last_webhook_at'])) . '.' : 'No message has arrived from WhatsApp yet. Finish step 3 below, then send a WhatsApp to your number to try it.' ?></p>
    <p><a class="button" href="<?= $h(ecw_module_page_url($base, 'whatsapp', 'whatsapp')) ?>">Open WhatsApp</a></p>
  </div>
  <?php endif; ?>

  <div class="teams-card">
    <h2>1. What you need from Meta</h2>
    <p class="muted">Do this on a computer, on Meta's developer website. It is not in the WhatsApp or WhatsApp Business phone app.</p>
    <ol>
      <li>Open <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> → <strong>Create app</strong> → use case <strong>“Connect with customers through WhatsApp”</strong> → choose (or create) your business portfolio.</li>
      <li>In the new app: <strong>Use cases → Customize → API Setup</strong> (in some layouts <strong>WhatsApp → API Setup</strong>). Copy the <strong>Phone number ID</strong> (under the number in the “From” list) and the <strong>WhatsApp Business Account ID</strong>. Meta gives you a free test number to start with.</li>
      <li>A <strong>permanent access token</strong>: Business settings → Users → System users → add one, give it the app and the WhatsApp account, then <em>Generate token</em> with <code>whatsapp_business_messaging</code> and <code>whatsapp_business_management</code>. (The 24-hour test token works for trying, then stops.)</li>
      <li>The <strong>App secret</strong>: the app → App settings → Basic → App secret → Show.</li>
    </ol>
  </div>

  <div class="teams-card">
    <h2>2. Connect</h2>
    <form id="waForm" autocomplete="off">
      <label for="waPhoneId">Phone number ID</label>
      <input type="text" id="waPhoneId" inputmode="numeric" value="<?= $h($wa['phone_number_id']) ?>" placeholder="e.g. 106540352242922">
      <label for="waWabaId">WhatsApp Business Account ID</label>
      <input type="text" id="waWabaId" inputmode="numeric" value="<?= $h($wa['waba_id']) ?>" placeholder="e.g. 102290129340398">
      <label for="waToken">Access token</label>
      <input type="password" id="waToken" placeholder="<?= $wa['has_token'] ? 'Saved. Paste a new token to replace it.' : 'EAAG…' ?>">
      <label for="waSecret">App secret</label>
      <input type="password" id="waSecret" placeholder="<?= $wa['has_secret'] ? 'Saved. Paste a new secret to replace it.' : '32 letters and digits' ?>">
      <?php if (count($waMailboxes) > 1): ?>
      <label for="waAccount">Account</label>
      <select id="waAccount" class="ot-select">
        <option value="0">Every account</option>
        <?php foreach ($waMailboxes as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === (int)$wa['mailbox_id'] ? ' selected' : '' ?>><?= $h($m['email_address']) ?></option><?php endforeach; ?>
      </select>
      <p class="muted">WhatsApp shows only while this account is chosen at the top.</p>
      <?php endif; ?>
      <p class="muted">The token and the secret are stored encrypted and never shown again. Saving checks them with Meta.</p>
      <div class="teams-notify-row">
        <button type="submit" class="primary" id="waSave"><?= $wa['configured'] ? 'Save changes' : 'Connect' ?></button>
        <?php if ($wa['configured']): ?><button type="button" id="waRemove" class="danger">Disconnect</button><?php endif; ?>
      </div>
      <p id="waStatus" role="status" class="muted"></p>
    </form>
  </div>

  <?php if ($wa['configured']): ?>
  <div class="teams-card">
    <h2>3. Let WhatsApp deliver messages here</h2>
    <p>In the Meta app: <strong>WhatsApp → Configuration → Webhook → Edit</strong>. Paste these two, press <em>Verify and save</em>, then under <em>Webhook fields</em> subscribe to <strong>messages</strong>.</p>
    <label>Callback URL</label>
    <div class="wa-copy"><code id="waUrl"><?= $h($wa['webhook_url']) ?></code><button type="button" data-copy="waUrl">Copy</button></div>
    <label>Verify token</label>
    <div class="wa-copy"><code id="waVerify"><?= $h($wa['verify_token']) ?></code><button type="button" data-copy="waVerify">Copy</button></div>
    <?php if (!$https): ?>
      <p class="teams-error">Meta only delivers to a public <strong>https</strong> address, and this server's address is <?= $h(parse_url($wa['webhook_url'], PHP_URL_SCHEME) . '://' . parse_url($wa['webhook_url'], PHP_URL_HOST)) ?>. Set <code>ECW_BASE_URL</code> to the public https address of this server (the same one people use to open it) and restart.</p>
    <?php endif; ?>
  </div>

  <div class="teams-card">
    <h2>How it works</h2>
    <ul>
      <li>Everyone with WhatsApp access shares this number; each customer is one chat.</li>
      <li>Within 24 hours of a customer's last message you can reply freely. After that, and to start a chat, WhatsApp only accepts an <strong>approved template</strong> (create them in WhatsApp Manager → Message templates).</li>
      <li>Link a chat to its case: whoever handles the case gets a notification when the customer writes.</li>
      <li>Meta charges per conversation, under your own Meta account.</li>
    </ul>
  </div>
  <?php endif; ?>
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
  $('waForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('waSave'); const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Checking with Meta…'; $('waStatus').textContent = '';
    try {
      await call('wa_settings_save', { phone_number_id: $('waPhoneId').value, waba_id: $('waWabaId').value, access_token: $('waToken').value, app_secret: $('waSecret').value, ...($('waAccount') ? { mailbox_id: Number($('waAccount').value) } : {}) });
      location.reload();
    } catch (err) { $('waStatus').textContent = err.message; btn.disabled = false; btn.textContent = label; }
  });
  $('waRemove')?.addEventListener('click', async (e) => {
    if (window.ecwArm && !window.ecwArm(e.currentTarget, 'Click again to disconnect')) return;
    try { await call('wa_disconnect'); location.reload(); } catch (err) { $('waStatus').textContent = err.message; }
  });
  document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($(b.dataset.copy).textContent); b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1500); } catch (_) {}
  }));
})();
</script>
</body></html>
