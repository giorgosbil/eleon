<?php declare(strict_types=1);
/**
 * Settings → System health (admins): is everything that runs by itself still
 * running, who is told when it is not, and is there a newer version.
 * Checks: lib/health.php. Updates: lib/updates.php + scripts/update.ps1.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/health.php';
require_once dirname(__DIR__, 2) . '/lib/updates.php';
$context = ecw_require_admin();
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$notice = null; $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['form'] ?? '') === 'alerts') {
        $error = ecw_alert_settings_save($_POST);
        if ($error === null) $notice = 'Saved.';
    } elseif (($_POST['form'] ?? '') === 'channel') {
        $url = trim((string)($_POST['update_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://\S+$#i', $url)) $error = 'The update channel must be an https:// address.';
        else { ecw_app_setting_set('update_url', $url !== '' ? $url : null); ecw_update_check(true); $notice = 'Saved and checked.'; }
    } elseif (($_POST['form'] ?? '') === 'check') {
        ecw_update_check(true);
        $notice = 'Checked again.';
    } elseif (($_POST['form'] ?? '') === 'resolve') {
        $ids = ($_POST['all'] ?? '') === '1' ? array_column(ecw_errors_open(500), 'id') : [(int)($_POST['id'] ?? 0)];
        $n = 0;
        foreach ($ids as $id) if (ecw_error_resolve((int)$id, (int)$context['user']['id'])) $n++;
        $notice = $n === 1 ? 'Marked as fixed. If it happens again, it comes back here and you are told.' : $n . ' marked as fixed.';
    }
}
$checks = ecw_health_checks();
$overall = ecw_health_overall($checks);
$alerts = ecw_alert_settings();
$update = ecw_update_check();
$groups = [];
foreach ($checks as $c) $groups[$c['group']][] = $c;
$stateIcon = ['ok' => 'fa-circle-check sec-ok', 'warn' => 'fa-circle-exclamation sec-warn', 'fail' => 'fa-circle-xmark hl-fail'];
$changelog = (string)@file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
$problems = ecw_errors_open(50);
$sourceLabel = ['server' => 'Server', 'daemon' => 'Background', 'browser' => 'Browser'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System health — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope sec-page">
  <h1>System health <a href="#" class="help-link" data-help="health"><i class="fa fa-circle-question" aria-hidden="true"></i> Help</a></h1>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <div class="hl-overall hl-<?= $h($overall) ?>">
    <i class="fa <?= $overall === 'ok' ? 'fa-heart-pulse' : ($overall === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-xmark') ?>" aria-hidden="true"></i>
    <div><strong><?= $overall === 'ok' ? 'Everything is running.' : ($overall === 'warn' ? 'Running, with something to look at.' : 'Something needs attention.') ?></strong>
      <span class="muted small">Checked just now. Admins are told when something breaks<?= $alerts['email'] ? ', in the app and by email' : ', in the app' ?>.</span></div>
  </div>

  <?php foreach ($groups as $group => $items): ?>
  <section class="panel hl-group">
    <h2><?= $h($group) ?></h2>
    <ul class="hl-list">
      <?php foreach ($items as $c): ?>
        <li class="hl-item hl-<?= $h($c['state']) ?>">
          <i class="fa <?= $stateIcon[$c['state']] ?>" aria-hidden="true"></i>
          <div><strong><?= $h($c['label']) ?></strong> <span class="muted"><?= $h($c['detail']) ?></span>
            <?php if ($c['fix']): ?><div class="hl-fix"><i class="fa fa-wrench" aria-hidden="true"></i> <?= $h($c['fix']) ?></div><?php endif; ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>

  <section class="panel" id="problems">
    <h2><i class="fa fa-bug" aria-hidden="true"></i> Problems</h2>
    <p class="muted">Errors on the server, in the background jobs and in people’s browsers, grouped by kind.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">More about this</span></summary>
      <p class="muted small">Admins are told the first time each kind appears. Nothing is sent anywhere else; to get help, copy the details to your vendor.</p>
    </details>
    <?php if (!$problems): ?>
      <p class="hl-none"><i class="fa fa-circle-check sec-ok" aria-hidden="true"></i> No open problems.</p>
    <?php else: ?>
      <ul class="hl-problems">
        <?php foreach ($problems as $p): ?>
          <li>
            <details>
              <summary>
                <span class="hl-src hl-src-<?= $h($p['source']) ?>"><?= $h($sourceLabel[$p['source']] ?? $p['source']) ?></span>
                <strong><?= $h($p['message']) ?></strong>
                <span class="muted small"><?= (int)$p['count'] ?>× · last <?= $h(ecw_tz_local($p['last_at'])) ?><?= $p['user_name'] || $p['user_email'] ? ' · ' . $h($p['user_name'] ?: $p['user_email']) : '' ?></span>
              </summary>
              <div class="hl-problem-detail">
                <p class="small"><strong><?= $h($p['kind']) ?></strong><?= $p['location'] ? ' at ' . $h($p['location']) : '' ?> · first seen <?= $h(ecw_tz_local($p['first_at'])) ?><?= $p['last_url'] ? ' · on ' . $h($p['last_url']) : '' ?></p>
                <?php if ($p['detail']): ?><pre><?= $h($p['detail']) ?></pre><?php endif; ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="resolve"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit">Mark as fixed</button></form>
              </div>
            </details>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="resolve"><input type="hidden" name="all" value="1">
        <button type="submit">Mark all as fixed</button></form>
    <?php endif; ?>
  </section>

  <section class="panel" id="alerts">
    <h2><i class="fa fa-bell" aria-hidden="true"></i> Alerts</h2>
    <p class="muted">When something stays broken for 10 minutes (a mailbox needs reconnecting, sync or the worker stopped, backups or the off-site copy failing, the disk filling up), every admin gets a notification here — and an email, sent from an admin's connected mailbox. Again once a day while it lasts, and once when it is fixed.</p>
    <form method="post" class="sec-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="alerts">
      <label class="checkbox-label"><input type="checkbox" name="email" value="1" <?= $alerts['email'] ? 'checked' : '' ?>> Also email the admins</label>
      <label>Also email (IT partner, up to 5 addresses) <input type="text" name="extra" value="<?= $h(implode(', ', $alerts['extra'])) ?>" placeholder="it@partner.example"></label>
      <p><button type="submit" class="primary">Save</button></p>
    </form>
  </section>

  <section class="panel" id="updates">
    <h2><i class="fa fa-arrows-rotate" aria-hidden="true"></i> Version and updates</h2>
    <p>Running version <strong><?= $h($update['current']) ?></strong>.
      <?php if (!empty($update['available'])): ?><span class="hl-new">Version <?= $h($update['latest']) ?> is available.</span><?php endif; ?></p>
    <?php if (!empty($update['available']) && $update['notes']): ?><div class="hl-notes"><strong>What's new in <?= $h($update['latest']) ?></strong><p><?= nl2br($h($update['notes'])) ?></p></div><?php endif; ?>
    <details class="st-fold st-fold-inline"<?= !empty($update['available']) ? ' open' : '' ?>><summary><span class="st-fold-title">How to update</span><span class="st-fold-hint">On the server, in the app folder</span></summary>
    <div class="hl-howto">
      <code>.\scripts\update.ps1</code> <span class="muted small">(Windows)</span> &nbsp; <code>sh scripts/update.sh</code> <span class="muted small">(Linux / Mac)</span>
      <p class="muted small">It checks that the release is signed by the vendor and unchanged, makes a backup, installs, and checks that the app starts. If it does not, it puts the previous version back by itself. A release you downloaded: <code>.\scripts\update.ps1 -Zip ecw-1.2.0.zip</code>. Going back by hand: <code>.\scripts\rollback.ps1</code>.</p>
    </div>
    </details>
    <form method="post" class="sec-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="channel">
      <label>Update channel <span class="muted small">(the address your vendor gave you<?= (string)getenv('ECW_UPDATE_URL') !== '' ? '; set on the server with ECW_UPDATE_URL' : '' ?>)</span>
        <input type="text" name="update_url" value="<?= $h(ecw_update_url()) ?>" placeholder="https://updates.example.com/ecw/latest.json" <?= (string)getenv('ECW_UPDATE_URL') !== '' ? 'disabled' : '' ?>></label>
      <p><?php if ((string)getenv('ECW_UPDATE_URL') === ''): ?><button type="submit" class="primary">Save</button> <?php endif; ?><button type="submit" name="form" value="check">Check now</button>
        <span class="muted small"><?= $update['checked_at'] ? 'Last checked ' . $h(ecw_tz_local($update['checked_at'])) . '.' : '' ?> <?= !empty($update['error']) ? $h($update['error']) : '' ?></span></p>
    </form>
    <?php if ($changelog !== ''): ?><details class="st-fold st-fold-inline hl-changelog"><summary><span class="st-fold-title">Changes in this version</span></summary><pre><?= $h(mb_substr($changelog, 0, 6000)) ?></pre></details><?php endif; ?>
  </section>
</main>
</div>
</body></html>
