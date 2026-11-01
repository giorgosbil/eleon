<?php declare(strict_types=1);
/**
 * Settings → My view: how much of the app this person wants to see.
 * Simple / Standard / Everything / Custom. A profile only hides things —
 * features outside the package or switched off by the admin stay off.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
$context = ecw_require_login(); $navActive = 'settings'; $base = ecw_web_base();
$userId = (int)$context['user']['id']; $orgId = (int)$context['organization_id'];
$notice = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['ai_suggestions'])) {
        // Personal: show the AI agents' suggestions or not (lib/agents.php reads users.preferences_json).
        $pdo = ecw_db();
        $st = $pdo->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
        $st->execute([$userId]);
        $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
        $prefs['ai_suggestions_off'] = $_POST['ai_suggestions'] !== 'on';
        $pdo->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $userId]);
        $notice = $prefs['ai_suggestions_off'] ? 'AI suggestions are off for you.' : 'AI suggestions are on for you.';
    } else {
        $profile = (string)($_POST['profile'] ?? '');
        try {
            ecw_user_view_save($userId, $profile === 'default' ? null : $profile, (array)($_POST['custom'] ?? []));
            $notice = 'Saved. The app now shows ' . ($profile === 'default' ? 'your workspace’s default view' : 'the ' . ECW_PROFILES[$profile][0] . ' view') . '.';
        } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    }
}

$view = ecw_user_view($userId, $orgId, true);
$orgDefault = ecw_org_default_profile($orgId);
$isAdmin = $context['role'] === 'admin';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
/** Why a feature cannot be shown at all, or null when it can. */
$blocked = static function (string $f) use ($context): ?string {
    if (!ecw_package_has($f)) return 'In the ' . ecw_packages()[ecw_package_needed($f)]['name'] . ' package';
    if (!ecw_feature_available_for($f, $context)) return 'Switched off by your admin';
    return null;
};
$icons = ['simple' => 'fa-feather', 'standard' => 'fa-table-columns', 'everything' => 'fa-layer-group', 'custom' => 'fa-sliders'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>My view — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>">
</head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>My view</h1>
  <?php if (ecw_feature_allowed('ai')):
    $pSt = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?'); $pSt->execute([$userId]);
    $suggOff = !empty((json_decode((string)$pSt->fetchColumn(), true) ?: [])['ai_suggestions_off']); ?>
  <form method="post" class="panel lang-form">
    <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
    <label for="aiSugg"><i class="ecw-ai-icon" aria-hidden="true"></i> <strong>AI suggestions</strong></label>
    <select id="aiSugg" name="ai_suggestions" onchange="this.form.submit()">
      <option value="on"<?= !$suggOff ? ' selected' : '' ?>>Show them</option>
      <option value="off"<?= $suggOff ? ' selected' : '' ?>>Don't show them</option>
    </select>
    <span class="muted small">Ideas from the AI agents above your emails and in cases: which case an email belongs to, reminders for promises, replies that are overdue.</span>
  </form>
  <?php endif; ?>
  <p class="muted">Choose how much of the app you see. This changes only your screen. Mail, cases, notes and follow-ups are always there.</p>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <form method="post" id="viewForm">
    <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
    <fieldset class="profile-grid">
      <legend class="sr-only">View</legend>
      <?php foreach (ECW_PROFILES as $id => [$label, $desc]): ?>
        <label class="profile-card">
          <input type="radio" name="profile" value="<?= $h($id) ?>" <?= $view['chosen'] && $view['profile'] === $id ? 'checked' : '' ?>>
          <span class="profile-icon"><i class="fa <?= $h($icons[$id]) ?>" aria-hidden="true"></i></span>
          <strong><?= $h($label) ?><?php if ($id === $orgDefault): ?> <span class="profile-default">workspace default</span><?php endif; ?></strong>
          <span class="muted"><?= $h($desc) ?></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <label class="profile-follow"><input type="radio" name="profile" value="default" <?= !$view['chosen'] ? 'checked' : '' ?>> Use the workspace default (<?= $h(ECW_PROFILES[$orgDefault][0]) ?>)<?= $isAdmin ? ' — admins set it in Feature access' : '' ?></label>

    <details class="st-fold" id="customFold"<?= $view['chosen'] && $view['profile'] === 'custom' ? ' open' : '' ?>>
    <summary><span class="st-fold-title"><i class="fa fa-list-check" aria-hidden="true"></i> What you see</span><span class="st-fold-hint">Feature by feature; Custom lets you choose</span></summary>
    <section class="panel profile-features" id="customBox">
      <p class="muted" data-hint-custom>Tick what you want on screen.</p>
      <p class="muted" data-hint-preset>This is what the chosen view shows. Pick <strong>Custom</strong> to change single features.</p>
      <ul class="profile-feature-list">
        <?php foreach (ecw_feature_catalog() as $f => [$label, $desc]): $why = $blocked($f); ?>
          <li class="<?= $why ? 'locked' : '' ?>">
            <label>
              <input type="checkbox" name="custom[]" value="<?= $h($f) ?>" data-feature="<?= $h($f) ?>" <?= $why ? 'disabled' : '' ?>
                     <?= !$why && ($view['profile'] === 'custom' ? ($view['features'][$f] ?? true) : true) ? 'checked' : '' ?>>
              <span><strong><?= $h($label) ?></strong><span class="muted"><?= $h($desc) ?></span></span>
            </label>
            <?php if ($why): ?><span class="lock-chip"><i class="fa fa-lock" aria-hidden="true"></i> <?= $h($why) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    </details>
    <button type="submit" class="primary">Save view</button>
  </form>
</main>
</div>
<script>
(() => {
  // Show which features each preset includes; only Custom can be ticked by hand.
  const presets = <?= json_encode(array_map(static fn($p) => $p[2], ECW_PROFILES), JSON_HEX_TAG) ?>;
  const orgDefault = <?= json_encode($orgDefault) ?>;
  const saved = {};
  const form = document.getElementById('viewForm');
  const boxes = [...form.querySelectorAll('input[data-feature]')];
  boxes.forEach((b) => { saved[b.value] = b.checked; });
  function paint() {
    let p = form.querySelector('input[name="profile"]:checked')?.value || 'everything';
    if (p === 'default') p = orgDefault;
    const custom = p === 'custom';
    form.querySelector('[data-hint-custom]').hidden = !custom;
    form.querySelector('[data-hint-preset]').hidden = custom;
    if (custom) document.getElementById('customFold').open = true;   // Custom: the ticks are the point
    boxes.forEach((b) => {
      if (b.closest('li').classList.contains('locked')) return;
      b.disabled = !custom;
      b.checked = custom ? saved[b.value] : (presets[p] === null || presets[p].includes(b.value));
    });
  }
  boxes.forEach((b) => b.addEventListener('change', () => { saved[b.value] = b.checked; }));
  form.addEventListener('change', (e) => { if (e.target.name === 'profile') paint(); });
  // Disabled boxes are not submitted: re-enable the Custom ones just before sending.
  form.addEventListener('submit', () => { boxes.forEach((b) => { if (!b.closest('li').classList.contains('locked')) b.disabled = false; }); });
  paint();
})();
</script>
</body></html>
