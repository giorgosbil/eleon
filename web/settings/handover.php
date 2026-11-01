<?php declare(strict_types=1);
/**
 * Settings → Users → Cover / hand over (admins), for one person:
 *   - holiday cover: dates and a colleague (lib/handover.php)
 *   - hand over: all their open work to a colleague, for good (someone leaving)
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/handover.php';
$context = ecw_require_admin();
$orgId = (int)$context['organization_id'];
$actorId = (int)$context['user']['id'];
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$userId = (int)($_GET['user'] ?? $_POST['user'] ?? 0);
$st = ecw_db()->prepare('SELECT u.id, u.name, u.email, u.disabled_at FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE u.id = ? AND ou.organization_id = ?');
$st->execute([$userId, $orgId]);
$person = $st->fetch();
if (!$person) { ecw_redirect($base . 'settings/users.php'); }
$name = (string)($person['name'] ?: $person['email']);
$notice = isset($_GET['disabled']) ? $name . ' is switched off. Hand their open work to a colleague so nothing is left behind.' : null;
$error = null; $done = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $form = (string)($_POST['form'] ?? '');
        if ($form === 'away') {
            $error = ecw_away_set($orgId, $actorId, $userId, (string)($_POST['from'] ?? ''), (string)($_POST['until'] ?? ''), (int)($_POST['cover'] ?? 0));
            if ($error === null) $notice = 'Cover saved. The colleague was told.';
        } elseif ($form === 'away_clear') {
            ecw_away_clear($orgId, $actorId, $userId);
            $notice = 'Cover removed.';
        } elseif ($form === 'handover') {
            try {
                $done = ecw_handover($orgId, $actorId, $userId, (int)($_POST['to'] ?? 0), !empty($_POST['share_mailboxes']));
                $notice = 'Handed over: ' . $done['cases'] . ' case' . ($done['cases'] === 1 ? '' : 's') . ', ' . $done['reminders'] . ' reminder' . ($done['reminders'] === 1 ? '' : 's')
                    . ', ' . $done['claimed'] . ' claimed conversation' . ($done['claimed'] === 1 ? '' : 's') . ($done['mailboxes'] ? ', and ' . $done['mailboxes'] . ' mailbox' . ($done['mailboxes'] === 1 ? '' : 'es') . ' shared with them' : '') . '.';
            } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
    }
}
$away = ecw_away_get($userId);
$preview = ecw_handover_preview($orgId, $userId);
$colleagues = array_filter(ecw_organization_users($orgId), static fn($u) => (int)$u['id'] !== $userId && $u['disabled_at'] === null && $u['role'] !== 'read_only');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cover and handover — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope sec-page">
  <p><a href="<?= $h($base) ?>settings/users.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> Users</a></p>
  <h1><?= $h($name) ?><?= $person['disabled_at'] !== null ? ' <span class="muted small">(switched off)</span>' : '' ?></h1>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <?php if ($person['disabled_at'] === null): ?>
  <section class="panel" id="cover">
    <h2><i class="fa fa-umbrella-beach" aria-hidden="true"></i> Holiday cover</h2>
    <p class="muted">While <?= $h($name) ?> is away, a colleague sees their cases and gets a copy of their alerts and reminders. Nothing is moved; it ends by itself. (Their Outlook out-of-office reply is set in Outlook, or under Settings → Mailbox.)</p>
    <?php if ($away['until'] && $away['until'] >= gmdate('Y-m-d')): ?>
      <p class="sec-status"><i class="fa fa-circle-check sec-ok" aria-hidden="true"></i> <?= $away['active'] ? 'Away now' : 'Away' ?> from <?= $h($away['from']) ?> to <?= $h($away['until']) ?> — <?= $h($away['cover_name'] ?? '?') ?> covers.</p>
    <?php endif; ?>
    <form method="post" class="sec-form ho-cover">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="user" value="<?= $userId ?>"><input type="hidden" name="form" value="away">
      <label>First day away <input type="date" name="from" required value="<?= $h($away['from'] ?? gmdate('Y-m-d')) ?>"></label>
      <label>Last day away <input type="date" name="until" required value="<?= $h($away['until'] ?? '') ?>"></label>
      <label>Covered by <select name="cover" class="ot-select" required><option value="">Choose…</option><?php foreach ($colleagues as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $away['cover_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= $h($c['name'] ?: $c['email']) ?></option><?php endforeach; ?></select></label>
      <p><button type="submit" class="primary">Save cover</button></p>
    </form>
    <?php if ($away['until']): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="user" value="<?= $userId ?>"><input type="hidden" name="form" value="away_clear"><button type="submit">Remove cover</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel" id="handover">
    <h2><i class="fa fa-people-arrows" aria-hidden="true"></i> Hand over everything <a href="#" class="help-link" data-help="handover"><i class="fa fa-circle-question" aria-hidden="true"></i> Help</a></h2>
    <p class="muted">For someone leaving or changing role. Moves for good to the colleague you choose:</p>
    <ul class="ho-list">
      <li><strong><?= $preview['cases'] ?></strong> open case<?= $preview['cases'] === 1 ? '' : 's' ?> where they are Responsible or Handled by</li>
      <li><strong><?= $preview['reminders'] ?></strong> open reminder<?= $preview['reminders'] === 1 ? '' : 's' ?></li>
      <li><strong><?= $preview['claimed'] ?></strong> conversation<?= $preview['claimed'] === 1 ? '' : 's' ?> they handle in shared mailboxes</li>
      <li>their case and group memberships (the colleague is added; nobody is removed)</li>
    </ul>
    <?php if ($done === null): ?>
    <form method="post" class="sec-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="user" value="<?= $userId ?>"><input type="hidden" name="form" value="handover">
      <label>Hand over to <select name="to" class="ot-select" required><option value="">Choose…</option><?php foreach ($colleagues as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $h($c['name'] ?: $c['email']) ?></option><?php endforeach; ?></select></label>
      <?php if ($preview['mailboxes'] > 0): ?>
      <label class="checkbox-label"><input type="checkbox" name="share_mailboxes" value="1" checked> Also let the colleague read <?= $h($name) ?>'s mailbox (it becomes a shared mailbox for them), so customers' emails to them are not lost.</label>
      <p class="muted small">Once their Microsoft 365 / Google account is switched off, that mailbox stops syncing, but everything already here stays. To keep receiving their mail, forward it to a shared mailbox in Microsoft 365 / Google.</p>
      <?php endif; ?>
      <p><button type="submit" class="primary" onclick="if (window.ecwArm && !window.ecwArm(this, 'Click again to hand over')) return false;">Hand over</button></p>
    </form>
    <?php endif; ?>
  </section>
</main>
</div>
</body></html>
