<?php declare(strict_types=1);
/**
 * Get started (lib/getting_started.php): the first-day checklist. Admins set
 * up the workspace; colleagues get their personal steps. Most steps are done
 * right here; the rest link to the page that does it and come back ticked.
 */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/getting_started.php';
$context = ecw_require_login();
$orgId = (int)$context['organization_id'];
$userId = (int)$context['user']['id'];
$isAdmin = ($context['role'] ?? '') === 'admin';
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$notice = null; $error = null; $invite = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'company' && $isAdmin) {
            $error = ecw_getting_started_company($orgId, $userId, (string)($_POST['name'] ?? ''), (string)($_POST['timezone'] ?? ''));
            if ($error === null) $notice = 'Saved.';
        } elseif ($do === 'invite' && $isAdmin) {
            $invite = ecw_getting_started_invite($orgId, $userId, (string)($_POST['emails'] ?? ''));
            if ($invite['errors'] && !$invite['sent'] && !$invite['links']) $error = implode(' ', $invite['errors']);
        } elseif ($do === 'templates' && $isAdmin && ecw_feature_allowed('case_templates')) {
            require_once dirname(__DIR__) . '/lib/checklists.php';
            $have = array_column(ecw_case_templates($orgId, true), 'name');
            $added = 0;
            foreach ((array)($_POST['starters'] ?? []) as $name) {
                if (!isset(ECW_TEMPLATE_STARTERS[$name]) || in_array($name, $have, true)) continue;
                ecw_case_template_save($orgId, $userId, ['name' => $name] + ECW_TEMPLATE_STARTERS[$name]);
                $added++;
            }
            $notice = $added ? $added . ' template' . ($added === 1 ? '' : 's') . ' added. Change them any time under Settings → Case templates.' : 'Nothing added.';
        } elseif ($do === 'hide') {
            ecw_getting_started_hide($userId);
            ecw_redirect($base . 'app.php');
        } elseif ($do === 'show') {
            ecw_getting_started_hide($userId, false);
        }
    }
}
$_SESSION['ecw_gs_seen'] = true;   // opened once this session: app.php does not send them here again
$steps = ecw_getting_started_steps($orgId, $userId, $isAdmin);
[$done, $total] = ecw_getting_started_progress($steps);
$st = ecw_db()->prepare('SELECT name, timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?');
$st->execute([$orgId]);
$org = $st->fetch() ?: ['name' => '', 'timezone' => 'UTC'];
$firstName = explode(' ', trim((string)($context['user']['name'] ?? '')))[0] ?: 'there';
$hidden = ecw_getting_started_hidden($userId);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Get started — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require __DIR__ . '/partials/nav.php'; ?>
<main class="page gs-page btn-scope">
  <header class="gs-head">
    <div>
      <h1>Welcome, <?= $h($firstName) ?></h1>
      <p class="muted"><?= $isAdmin ? 'A few steps to set up Case Workspace for ' . $h($org['name'] ?: 'your company') . '. Each one takes a minute; you can come back any time (Settings → Get started).' : 'A few steps and you are ready to work with your team.' ?></p>
    </div>
    <div class="gs-ring" style="--p:<?= $total ? round($done / $total * 100) : 100 ?>" role="img" aria-label="<?= $done ?> of <?= $total ?> done"><span><?= $done ?>/<?= $total ?></span></div>
  </header>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($done === $total): ?><div class="gs-done"><i class="fa fa-circle-check" aria-hidden="true"></i> <strong>All set.</strong> Everything important is in place. <a href="<?= $h($base) ?>app.php">Go to your mail</a></div><?php endif; ?>

  <ol class="gs-steps">
    <?php foreach ($steps as $i => $s): ?>
    <li class="gs-step<?= $s['done'] ? ' done' : '' ?>" id="step-<?= $h($s['key']) ?>">
      <span class="gs-num" aria-hidden="true"><?= $s['done'] ? '<i class="fa fa-check"></i>' : $i + 1 ?></span>
      <div class="gs-body">
        <h2><?= $h($s['title']) ?><?= $s['optional'] ? ' <span class="gs-opt">optional</span>' : '' ?><?= $s['done'] ? ' <span class="gs-tick">Done</span>' : '' ?></h2>
        <p class="muted"><?= $h($s['why']) ?></p>

        <?php if ($s['key'] === 'company'): ?>
          <form method="post" class="gs-inline">
            <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="company">
            <label>Company name <input type="text" name="name" value="<?= $h($org['name']) ?>" maxlength="120" required></label>
            <label>Time zone <select name="timezone" id="gsTimezone" class="ot-select" data-current="<?= $h($org['timezone']) ?>">
              <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= $h($tz) ?>" <?= $tz === ($org['timezone'] ?: 'UTC') ? 'selected' : '' ?>><?= $h(str_replace('_', ' ', $tz)) ?></option><?php endforeach; ?>
            </select></label>
            <button type="submit" class="<?= $s['done'] ? '' : 'primary' ?>">Save</button>
          </form>
        <?php elseif ($s['key'] === 'team'): ?>
          <form method="post" class="gs-inline gs-invite">
            <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="invite">
            <label>Their email addresses <span class="muted small">(one per line, or separated by commas)</span>
              <textarea name="emails" rows="2" placeholder="maria@yourcompany.com, nikos@yourcompany.com"></textarea></label>
            <button type="submit" class="<?= $s['done'] ? '' : 'primary' ?>">Invite</button>
          </form>
          <?php if ($invite): ?>
            <?php if ($invite['sent']): ?><p class="gs-result"><i class="fa fa-paper-plane" aria-hidden="true"></i> Invitation emailed to <?= $h(implode(', ', $invite['sent'])) ?>.</p><?php endif; ?>
            <?php if ($invite['links']): ?><div class="gs-result"><p>Send these links yourself (each works once, for three days):</p>
              <?php foreach ($invite['links'] as $email => $link): ?><div class="copy-row"><span class="muted small"><?= $h($email) ?></span><input type="text" readonly value="<?= $h($link) ?>" onclick="this.select()" aria-label="Invitation link for <?= $h($email) ?>"></div><?php endforeach; ?></div><?php endif; ?>
            <?php if ($invite['errors']): ?><p class="flash err"><?= $h(implode(' ', $invite['errors'])) ?></p><?php endif; ?>
          <?php endif; ?>
          <?php require_once dirname(__DIR__) . '/lib/account_security.php'; if (ecw_public_base_url() === null): ?><p class="muted small"><i class="fa fa-circle-info" aria-hidden="true"></i> Invitations are shown as links to send yourself. To have them emailed, set <code>ECW_BASE_URL</code> in the app's .env to the address people open the app at (e.g. https://cases.yourcompany.com).</p><?php endif; ?>
          <p class="muted small">Or add people with a password yourself in <a href="<?= $h($base) ?>settings/users.php">Settings → Users</a>.</p>
        <?php elseif ($s['key'] === 'templates' && !$s['done']): ?>
          <form method="post" class="gs-inline">
            <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="templates">
            <div class="gs-checks"><?php foreach (array_keys(ECW_TEMPLATE_STARTERS) as $name): ?><label class="checkbox-label"><input type="checkbox" name="starters[]" value="<?= $h($name) ?>" checked> <?= $h($name) ?></label><?php endforeach; ?></div>
            <button type="submit">Add these</button>
          </form>
        <?php elseif ($s['url'] !== null && (!$s['done'] || $s['key'] === 'mailbox')): ?>
          <p><a class="button<?= $s['done'] ? '' : ' primary' ?>" href="<?= $h($s['url']) ?>"><?= $h($s['done'] ? 'Open' : ($s['action'] ?? 'Open')) ?></a></p>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>

  <?php // The installed app (manifest.webmanifest, assets/app-window.js): for every person, on every computer. ?>
  <details class="gs-app" id="install-app">
    <summary><i class="fa fa-desktop" aria-hidden="true"></i> Use it as an app on your computer</summary>
    <ol>
      <li><strong>Install it:</strong> in Chrome or Edge, click the install icon at the right end of the address bar (a screen with an arrow), or the menu ⋮ → <em>Cast, save and share → Install page as app</em>. It gets its own window and an icon on the taskbar.</li>
      <li><strong>More windows:</strong> press <kbd>Ctrl</kbd>+<kbd>N</kbd> in the app. Ctrl+click, middle-click and "Open in new tab" inside the app open a new app window, not a browser tab.</li>
      <li><strong>Start it with Windows:</strong> right-click its icon on the taskbar → <em>Pin to taskbar</em>; in Chrome, open <code>chrome://apps</code>, right-click the app → <em>Start app when you sign in</em>.</li>
      <li><strong>Alerts for new email:</strong> click the bell at the top → <em>Turn on</em> → <em>Allow</em>, then <em>Send a test</em>. Nothing in the bottom-right corner? In Windows: Settings → System → Notifications: on, Google Chrome on, Do not disturb off.</li>
      <li><strong>Alerts with the window closed:</strong> in Chrome, Settings → System → <em>Continue running background apps when Google Chrome is closed</em> on.</li>
    </ol>
    <p class="muted small">On other computers of the office, the address must start with https:// (set up once on the server); without it Chrome does not offer to install the app or show alerts.</p>
  </details>

  <footer class="gs-foot">
    <?php if ($hidden): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="show"><button type="submit">Show this list in Settings again</button></form>
    <?php else: ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="hide"><button type="submit">I'm done — hide this list</button></form>
    <?php endif; ?>
    <a href="<?= $h($base) ?>app.php">Go to your mail</a>
  </footer>
</main>
<script>
// A workspace still on UTC: suggest the time zone of this computer.
(() => { const s = document.getElementById('gsTimezone'); if (!s || s.dataset.current !== 'UTC') return;
  try { const tz = Intl.DateTimeFormat().resolvedOptions().timeZone; if (tz && [...s.options].some((o) => o.value === tz)) s.value = tz; } catch (_) {} })();
</script>
</body></html>
