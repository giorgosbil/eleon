<?php declare(strict_types=1);
/**
 * Settings → License (admins): which package this installation has, the trial,
 * user and mailbox use, entering a license key, and what each package includes.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/license.php';
$context = ecw_require_admin(); $navActive = 'settings'; $base = ecw_web_base();
if (ECW_COMMUNITY_EDITION) {
    require __DIR__ . '/community.php';
    exit;
}
$notice = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'install') {
        $r = ecw_license_install((string)($_POST['license_key'] ?? ''));
        $r['ok'] ? $notice = 'License installed: ' . ecw_packages()[$r['payload']['package']]['name'] . ' for ' . ($r['payload']['customer'] ?? 'you') . '.' : $error = $r['error'];
    } elseif (($_POST['action'] ?? '') === 'remove') {
        ecw_license_remove();
        $notice = 'License removed.';
    }
}

$s = ecw_license_state(true);
$usersUsed = ecw_license_users_used();
$mailboxesUsed = ecw_license_mailboxes_used();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$limit = static fn(?int $n): string => $n === null ? 'no limit' : 'of ' . $n;
$fmt = static fn(?string $d): string => $d ? date('j M Y', strtotime($d)) : '';
$order = array_keys(ecw_packages());
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>License — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>">
</head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>License</h1>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <section class="plan-card plan-<?= $h($s['package']) ?>">
    <div class="plan-main">
      <span class="plan-label">Your package</span>
      <strong class="plan-name"><?= $h($s['name']) ?></strong>
      <span class="plan-sub">
        <?php if ($s['source'] === 'trial'): ?>
          Free trial with everything unlocked — <strong><?= (int)$s['trial_days_left'] ?> day<?= $s['trial_days_left'] === 1 ? '' : 's' ?> left</strong> (until <?= $h($fmt($s['trial_ends'])) ?>). After that it stays on Free unless a license key is entered.
        <?php elseif ($s['source'] === 'license'): ?>
          Licensed to <strong><?= $h($s['customer'] ?? '—') ?></strong><?= $s['license_id'] ? ' · ' . $h($s['license_id']) : '' ?> · <?= $s['expires'] ? 'renews by ' . $h($fmt($s['expires'])) : 'no end date' ?>
        <?php elseif ($s['source'] === 'expired'): ?>
          The license for <?= $h($s['customer'] ?? 'this workspace') ?> ended on <?= $h($fmt($s['expires'])) ?>. The Free features keep working and nothing was deleted. Enter a new key to get the rest back.
        <?php else: ?>
          <?php if ($s['trial_ends'] !== null): ?>The free package. The trial ended on <?= $h($fmt($s['trial_ends'])) ?>. Everything you made is still here.
          <?php else: ?>The free, open-source package, with no user or mailbox limit. A Pro or Business key adds the paid modules.<?php endif; ?>
        <?php endif; ?>
      </span>
    </div>
    <dl class="plan-usage">
      <div><dt>Users</dt><dd><strong><?= $usersUsed ?></strong> <?= $h($limit($s['users'])) ?></dd>
        <?php if ($s['users']): ?><span class="plan-bar"><span style="width:<?= min(100, (int)round($usersUsed / max(1, $s['users']) * 100)) ?>%"></span></span><?php endif; ?></div>
      <div><dt>Mailboxes</dt><dd><strong><?= $mailboxesUsed ?></strong> <?= $h($limit($s['mailboxes'])) ?></dd>
        <?php if ($s['mailboxes']): ?><span class="plan-bar"><span style="width:<?= min(100, (int)round($mailboxesUsed / max(1, $s['mailboxes']) * 100)) ?>%"></span></span><?php endif; ?></div>
    </dl>
  </section>

  <details class="st-fold"<?= $s['source'] !== 'license' || $error !== null ? ' open' : '' ?>>
  <summary><span class="st-fold-title"><i class="fa fa-key" aria-hidden="true"></i> <?= $s['source'] === 'license' ? 'Replace the license key' : 'Enter a license key' ?></span></summary>
  <section class="panel">
    <form method="post" class="license-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
      <input type="hidden" name="action" value="install">
      <textarea name="license_key" rows="3" required spellcheck="false" autocomplete="off" placeholder="ECW1.…" aria-label="License key"></textarea>
      <div class="license-actions">
        <button type="submit" class="primary">Activate</button>
        <span class="muted">The key is checked on this server. Nothing is sent anywhere.</span>
      </div>
    </form>
    <?php if (in_array($s['source'], ['license', 'expired'], true)): ?>
      <form method="post" class="license-remove">
        <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
        <input type="hidden" name="action" value="remove">
        <button type="submit" class="link-btn" data-confirm="Click again to remove the license">Remove license key</button>
      </form>
    <?php endif; ?>
  </section>
  </details>

  <details class="st-fold">
  <summary><span class="st-fold-title"><i class="fa fa-layer-group" aria-hidden="true"></i> What each package includes</span><span class="st-fold-hint">Compare the packages</span></summary>
  <div class="plans">
    <?php foreach (ecw_packages() as $id => $p):
      $prev = array_search($id, $order, true) > 0 ? ecw_packages()[$order[array_search($id, $order, true) - 1]] : null;
      $new = $prev ? array_values(array_diff($p['features'], $prev['features'])) : $p['features']; ?>
      <article class="plan<?= $id === $s['package'] ? ' current' : '' ?>">
        <header>
          <strong><?= $h($p['name']) ?></strong>
          <?php if ($id === $s['package']): ?><span class="plan-chip">Current</span><?php endif; ?>
        </header>
        <p class="muted"><?= $h($p['pitch']) ?></p>
        <p class="plan-limits"><?= $id === 'free' ? 'No user or mailbox limit' : 'Users and mailboxes as licensed' ?></p>
        <ul>
          <?php if ($prev): ?><li class="plan-inherit">Everything in <?= $h($prev['name']) ?>, plus:</li>
          <?php else: foreach (ECW_PACKAGE_ALWAYS as $line): ?><li><?= $h($line) ?></li><?php endforeach; endif; ?>
          <?php foreach ($new as $f): ?><li><?= $h(ecw_feature_catalog()[$f][0]) ?></li><?php endforeach; ?>
          <?php foreach (ECW_PACKAGE_EXTRAS[$id] ?? [] as $line): ?><li><?= $h($line) ?></li><?php endforeach; ?>
          <?php foreach (ECW_PACKAGE_COMING[$id] ?? [] as $line): ?><li class="plan-coming"><?= $h($line) ?> <span>coming</span></li><?php endforeach; ?>
        </ul>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="muted">Nothing is ever locked away when a package changes: mail and cases always stay. A smaller package only hides the extra features, and the user and mailbox limits only stop adding new ones.</p>
  </details>
</main>
</div>
<script>
document.querySelectorAll('button[data-confirm]').forEach((b) => b.addEventListener('click', (e) => {
  if (window.ecwArm && !window.ecwArm(b, b.dataset.confirm)) e.preventDefault();
}));
</script>
</body></html>
