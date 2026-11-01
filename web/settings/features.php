<?php declare(strict_types=1);
/** Settings → Feature access (admins): which optional features normal members can use. */
require_once dirname(__DIR__, 2) . '/lib/session.php';
$context = ecw_require_admin(); $navActive = 'settings'; $base = ecw_web_base();
$orgId = (int)$context['organization_id'];
$profileNotice = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'default_profile' && ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
    try { ecw_org_default_profile_save($orgId, (string)($_POST['profile'] ?? '')); $profileNotice = 'Default view saved.'; }
    catch (InvalidArgumentException $e) { $profileNotice = $e->getMessage(); }
}
$features = ecw_features($orgId);
$license = ecw_license_state();
$defaultProfile = ecw_org_default_profile($orgId);
$st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('organization_users') . "` WHERE organization_id = ? AND role <> 'admin'");
$st->execute([(int)$context['organization_id']]);
$members = (int)$st->fetchColumn();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Feature access — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>">
<style>
  .feature-list { list-style:none; margin:16px 0; padding:0; display:grid; gap:8px; }
  .feature-row { display:flex; align-items:center; gap:14px; padding:12px 14px; border:1px solid var(--border); border-radius:10px; background:var(--bg); }
  .feature-row .feature-text { flex:1; min-width:0; }
  .feature-row .muted { display:block; font-size:12px; }
  .switch { position:relative; flex:0 0 42px; width:42px; height:24px; }
  .switch input { position:absolute; opacity:0; inset:0; margin:0; cursor:pointer; }
  .switch span { position:absolute; inset:0; border-radius:12px; background:var(--border); transition:background .15s; pointer-events:none; }
  .switch span::after { content:""; position:absolute; top:3px; left:3px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px #0003; transition:transform .15s; }
  .switch input:checked + span { background:var(--accent); }
  .switch input:checked + span::after { transform:translateX(18px); }
  .switch input:focus-visible + span { outline:2px solid var(--accent); outline-offset:2px; }
  .feature-state { font-size:12px; min-width:92px; text-align:right; }
  .feature-row.locked .feature-text { opacity:.6; }
  .feature-row.locked .switch { opacity:.4; }
</style></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>Feature access</h1>
  <p class="muted">Choose what <strong>normal members</strong> (<?= $members ?> in this workspace) can see and use. Admins always keep every feature. Mail, cases, notes, follow-ups and sharing are always on.</p>
  <form id="featureForm">
    <ul class="feature-list">
      <?php foreach (ecw_feature_catalog() as $key => [$label, $description]): $inPackage = ecw_package_has($key); ?>
        <li class="feature-row<?= $inPackage ? '' : ' locked' ?>">
          <span class="feature-text"><strong><?= $h($label) ?></strong><span class="muted"><?= $h($description) ?></span></span>
          <?php if (!$inPackage): ?>
            <a class="lock-chip" href="license.php" title="Not in your <?= $h($license['name']) ?> package"><i class="fa fa-lock" aria-hidden="true"></i> <?= $h(ecw_packages()[ecw_package_needed($key)]['name']) ?></a>
          <?php else: ?>
            <span class="feature-state muted" data-state="<?= $h($key) ?>"><?= $features[$key] ? 'Everyone' : 'Admins only' ?></span>
          <?php endif; ?>
          <label class="switch" title="Visible to normal members"><input type="checkbox" name="enabled" value="<?= $h($key) ?>" <?= $features[$key] ? 'checked' : '' ?> <?= $inPackage ? '' : 'disabled' ?> aria-label="<?= $h($label) ?> visible to normal members"><span></span></label>
        </li>
      <?php endforeach; ?>
    </ul>
    <p id="featureStatus" class="muted" role="status">Changes save automatically.<?= $license['source'] === 'trial' ? ' During the trial every package feature is unlocked.' : '' ?></p>
  </form>

  <details class="st-fold" id="default-view"<?= $profileNotice !== null ? ' open' : '' ?>>
  <summary><span class="st-fold-title"><i class="fa fa-table-columns" aria-hidden="true"></i> Default view for new people</span><span class="st-fold-hint">Now: <?= $h(ECW_PROFILES[$defaultProfile][0] ?? '') ?></span></summary>
  <section class="panel">
    <p class="muted">What people see until they choose their own view in Settings → My view. Simple suits people who only need mail and cases.</p>
    <?php if ($profileNotice !== null): ?><div class="flash ok"><?= $h($profileNotice) ?></div><?php endif; ?>
    <form method="post" class="default-view-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
      <input type="hidden" name="action" value="default_profile">
      <?php foreach (ECW_PROFILES as $id => [$label, $desc]): if ($id === 'custom') continue; ?>
        <label class="radio-row"><input type="radio" name="profile" value="<?= $h($id) ?>" <?= $defaultProfile === $id ? 'checked' : '' ?>><span><strong><?= $h($label) ?></strong><br><span class="muted"><?= $h($desc) ?></span></span></label>
      <?php endforeach; ?>
      <button type="submit" class="primary">Save default</button>
    </form>
  </section>
  </details>
</main>
</div>
<script>
(() => {
  const form = document.getElementById('featureForm');
  const status = document.getElementById('featureStatus');
  form.addEventListener('change', async (e) => {
    const box = e.target;
    if (box.name !== 'enabled') return;
    const enabled = [...form.querySelectorAll('input[name="enabled"]:checked')].map((b) => b.value);
    status.textContent = 'Saving…';
    try {
      const r = await fetch('../api/index.php?action=features_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify({ enabled }) });
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'Could not save');
      Object.entries(d.features).forEach(([k, on]) => { const s = form.querySelector('[data-state="' + k + '"]'); if (s) s.textContent = on ? 'Everyone' : 'Admins only'; });
      status.textContent = 'Saved. Members see the change on their next page load.';
    } catch (err) { box.checked = !box.checked; status.textContent = err.message; }
  });
})();
</script>
</body></html>
