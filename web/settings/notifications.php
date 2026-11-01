<?php declare(strict_types=1);
/**
 * Settings → Notifications: when and how the app tells this person something.
 * Alerts on this device (the same switch and test as the bell panel, nav-badges.js fills
 * #alertSettings) and the bubble on the installed app's taskbar icon (lib/appearance.php,
 * page 'alerts').
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/appearance.php';
$context = ecw_require_login();
$userId = (int)$context['user']['id'];
$base = ecw_web_base();
$navActive = 'settings';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid session token. Reload and try again.'); }
    ecw_appearance_save($userId, 'alerts', $_POST);
    header('Location: notifications.php?saved=1'); exit;
}
$a = ecw_load_appearance($userId);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications — Case Workspace</title><link rel="stylesheet" href="<?= $h($base) ?>assets/app.css"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide"><h1>Notifications</h1>
<?php if (isset($_GET['saved'])): ?><div class="flash ok">Saved.</div><?php endif; ?>
<form method="post" id="appearanceForm" data-page="alerts"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
  <div class="appearance-fields">
    <fieldset class="ap-group"><legend>Alerts on this computer</legend>
      <p class="muted small" style="margin:0 0 8px">A message in the bottom-right corner of the screen for new email in the Inbox and when someone mentions you. You choose it on each computer and browser (and in the installed app): it is the same switch as under the bell <i class="fa fa-bell" aria-hidden="true"></i> at the top.</p>
      <div id="alertSettings" class="alert-settings"></div>
      <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">Nothing appears?</span></summary>
        <p class="muted small" style="margin:0">In Windows: Settings → System → Notifications: notifications on, Google Chrome (or Microsoft Edge) on, and Do not disturb off.</p>
      </details>
    </fieldset>

    <fieldset class="ap-group"><legend>Quiet hours</legend>
      <p class="muted small" style="margin:0 0 8px">No alerts on your phone or computer at these times. The bell <i class="fa fa-bell" aria-hidden="true"></i> still collects everything, so nothing is lost: you see it when you open the app.</p>
      <input type="hidden" name="quiet" value="0"><label class="checkbox-label ap-quiet"><input type="checkbox" name="quiet" value="1" <?= !empty($a['quiet']) ? 'checked' : '' ?>> Quiet from
        <input type="time" name="quiet_from" value="<?= $h($a['quiet_from']) ?>" aria-label="Quiet from"> to
        <input type="time" name="quiet_to" value="<?= $h($a['quiet_to']) ?>" aria-label="Quiet until"></label>
      <input type="hidden" name="quiet_weekend" value="0"><label class="checkbox-label"><input type="checkbox" name="quiet_weekend" value="1" <?= !empty($a['quiet_weekend']) ? 'checked' : '' ?>> Quiet all weekend (Saturday and Sunday)</label>
      <p class="muted small" style="margin:8px 0 0">The times are where you are: the time zone of the computer you use (shown under Settings → Account).</p>
    </fieldset>

    <fieldset class="ap-group"><legend>The app's icon on the taskbar</legend>
      <label class="ap-badge-mode">Unread emails on the icon<select name="badge_mode" class="ot-select"><?php foreach (['number' => 'The number (5)', 'dot' => 'Only a dot', 'off' => 'Nothing'] as $v => $l): ?><option value="<?= $v ?>" <?= $a['badge_mode'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <p class="muted small ap-badge-note">For the installed app (Get started → Use it as an app). Windows draws the bubble in its own colour; an app cannot change it.</p>
    </fieldset>
  </div>
<p class="ap-actions"><button type="submit" class="primary">Save</button></p></form></main>
</div></body></html>
