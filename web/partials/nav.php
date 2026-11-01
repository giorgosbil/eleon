<?php declare(strict_types=1);
/**
 * Shared application navigation.
 *
 * Included by every authenticated page so the five destinations the step names
 * — Mail, Cases, Settings, Account, Logout — are reachable from all of them,
 * and nobody has to know a filename.
 *
 * Logout is a form, not a link: see web/logout.php for why a GET logout is a
 * denial of service.
 *
 * Expects $navActive to name the current section. Deliberately no styling of
 * its own beyond .appnav in app.css — the step says reuse the existing visual
 * language rather than redesign.
 */

require_once dirname(__DIR__, 2) . '/lib/session.php';

$navContext = ecw_context();
if ($navContext === null) {
    return;
}

$navBase = ecw_web_base();
$navUser = $navContext['user'];
$navRole = $navContext['role'];
$navCsrf = ecw_csrf_token();
$navActive = $navActive ?? '';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$navItems = [
    'mail'     => ['label' => 'Mail',     'href' => $navBase . 'app.php'],
    'calendar' => ['label' => 'Calendar', 'href' => $navBase . 'calendar.php'],
    'cases'    => ['label' => 'Cases',    'href' => $navBase . 'cases.php'],
    'contacts' => ['label' => 'Contacts', 'href' => $navBase . 'contacts.php'],
    'teams'    => ['label' => 'Teams',    'href' => $navBase . 'teams.php'],
    'reports'  => ['label' => 'Reports',  'href' => $navBase . 'reports.php'],
    'settings' => ['label' => 'Settings', 'href' => $navBase . 'settings/'],
];
// Feature access: optional tabs disappear for members an admin switched them off for.
$navFeatures = ecw_features_for($navContext);
if (!$navFeatures['teams']) { unset($navItems['teams']); }
if (!$navFeatures['calendar']) { unset($navItems['calendar']); }
if (!$navFeatures['reports']) { unset($navItems['reports']); }
if (!$navFeatures['contacts']) { unset($navItems['contacts']); }
// One account at a time (lib/account.php): the picker at the top, and the pages follow it.
require_once dirname(__DIR__, 2) . '/lib/account.php';
$navAccounts = ecw_account_choices((int)$navContext['organization_id'], (int)$navUser['id']);
$navAccount  = ecw_active_account((int)$navContext['organization_id'], (int)$navUser['id']);
// Home time zone (assets/timezone.js): the person's own, else the workspace's. Times on screen follow the computer.
$navPrefs = ecw_account_prefs((int)$navUser['id']);
// The company's statuses, roles and tag colours (Settings → Case settings, lib/case_settings.php).
require_once dirname(__DIR__, 2) . '/lib/case_settings.php';
$navCaseSettings = ecw_case_settings_for_pages((int)$navContext['organization_id']);
// The zone this computer is in, kept for the person; a company still on the default UTC takes an admin's (lib/timezone.php).
require_once dirname(__DIR__, 2) . '/lib/timezone.php';
try { ecw_tz_sync((int)$navContext['organization_id'], (int)$navUser['id'], $navRole === 'admin'); } catch (Throwable $e) { ecw_log('time zone sync: ' . $e->getMessage()); }
$navOrgTz = ecw_db()->prepare('SELECT timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?');
$navOrgTz->execute([(int)$navContext['organization_id']]);
$navOrgTzName = (string)$navOrgTz->fetchColumn();
// No home chosen and no workspace zone set (UTC is the default): the browser remembers the first zone it saw (timezone.js).
$navHomeTz = (string)($navPrefs['home_tz'] ?? '') ?: ($navOrgTzName !== '' && $navOrgTzName !== 'UTC' ? $navOrgTzName : '');
$navShowHomeZone = !array_key_exists('show_home_zone', $navPrefs) || !empty($navPrefs['show_home_zone']);
// Automatic replies of the account (assets/ooo.js): the last state seen, for the "Away" badge.
$navOoo = null;
if ($navAccount !== null && $navAccount['mine']) {
    require_once dirname(__DIR__, 2) . '/lib/license.php';
    $navOoo = json_decode((string)ecw_app_setting('ooo_' . (int)$navAccount['id']), true) ?: ['status' => null, 'at' => 0];
}
$navIcons = ['mail' => 'fa-envelope', 'calendar' => 'fa-calendar-days', 'cases' => 'fa-folder-open', 'contacts' => 'fa-address-book',
             'teams' => 'ecw-teams-icon', 'reports' => 'fa-chart-column', 'settings' => 'fa-gear'];
// Tabs of optional modules (lib/modules.php): behind their feature, and only where the module says they belong.
require_once dirname(__DIR__, 2) . '/lib/modules.php';
foreach (ecw_module_nav($navBase) as $navKey => $navModule) {
    if ($navModule['feature'] !== null && empty($navFeatures[$navModule['feature']])) continue;
    $navShow = $navModule['show'] !== null ? ecw_module_callable($navModule['show'][0], (array)$navModule['show'][1]) : null;
    if ($navShow !== null && !$navShow((int)$navContext['organization_id'], $navAccount !== null ? (int)$navAccount['id'] : null)) continue;
    $navAt = array_search($navModule['after'] ?? '', array_keys($navItems), true);
    $navAt = $navAt === false ? array_search('settings', array_keys($navItems), true) : $navAt + 1;
    $navItems = array_slice($navItems, 0, (int)$navAt, true) + [$navKey => ['label' => $navModule['label'], 'href' => $navModule['href']]] + array_slice($navItems, (int)$navAt, null, true);
    $navIcons[$navKey] = $navModule['icon'];
}
require_once dirname(__DIR__, 2) . '/lib/appearance.php';
$navAppearance = ecw_load_appearance((int)$navUser['id']);
require_once dirname(__DIR__, 2) . '/lib/i18n.php';
// Greek (or another language): translate the page as it is parsed (assets/i18n.js).
echo ecw_i18n_scripts(ecw_user_language((int)$navUser['id']), $navBase);
?>
<link rel="stylesheet" href="<?= $e($navBase) ?>assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="<?= $e($navBase) ?>assets/auth.css?v=<?= filemtime(dirname(__DIR__) . '/assets/auth.css') ?>">
<link rel="stylesheet" href="<?= $e($navBase) ?>assets/appearance.css?v=<?= filemtime(dirname(__DIR__) . '/assets/appearance.css') ?>">
<script type="application/json" id="appearanceConfig"><?= json_encode($navAppearance + ['compose_stack' => ECW_COMPOSE_FONTS[$navAppearance['compose_font']], 'compose_fonts' => array_keys(ECW_COMPOSE_FONTS), 'compose_sizes' => ECW_COMPOSE_SIZES],JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $e($navBase) ?>assets/top-progress.js?v=<?= filemtime(dirname(__DIR__) . '/assets/top-progress.js') ?>"></script>
<script src="<?= $e($navBase) ?>assets/appearance.js?v=<?= filemtime(dirname(__DIR__) . '/assets/appearance.js') ?>"></script>
<?php // The installed app: "open in a new tab" opens a new app window, not a browser tab. ?>
<script src="<?= $e($navBase) ?>assets/app-window.js?v=<?= filemtime(dirname(__DIR__) . '/assets/app-window.js') ?>"></script>
<?php // Teams chat windows float over every page, so they are loaded here, once. ?>
<link rel="stylesheet" href="<?= $e($navBase) ?>assets/teams.css?v=<?= filemtime(dirname(__DIR__) . '/assets/teams.css') ?>">
<script>
// Installable app (PWA): manifest, icons and the service worker for push notifications.
(function (b) {
  var add = function (tag, attrs) { var n = document.createElement(tag); for (var k in attrs) n.setAttribute(k, attrs[k]); document.head.appendChild(n); };
  add('link', { rel: 'manifest', href: b + 'manifest.webmanifest' });
  add('link', { rel: 'icon', type: 'image/png', href: b + 'assets/icons/favicon-32.png' });
  add('link', { rel: 'apple-touch-icon', href: b + 'assets/icons/apple-touch-icon.png' });
  add('meta', { name: 'theme-color', content: '#2f5aa8' });
  add('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
  add('meta', { name: 'apple-mobile-web-app-title', content: 'Cases' });
  if ('serviceWorker' in navigator && window.isSecureContext && window.self === window.top) {
    navigator.serviceWorker.register(b + 'sw.js', { scope: b }).catch(function () {});
  }
})(<?= json_encode($navBase, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>);
</script>
<script>window.ecwWebBase = <?= json_encode($navBase, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>; window.ecwCsrf = <?= json_encode($navCsrf, JSON_HEX_TAG) ?>;
window.ecwFeatures = <?= json_encode($navFeatures, JSON_HEX_TAG) ?>; window.ecwCaseSettings = <?= json_encode($navCaseSettings, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>; window.ecwUserId = <?= (int)$navUser['id'] ?>; window.ecwAccountId = <?= $navAccount !== null ? (int)$navAccount['id'] : 0 ?>; window.ecwHomeTz = <?= json_encode($navHomeTz, JSON_HEX_TAG) ?>; window.ecwShowHomeZone = <?= $navShowHomeZone ? 'true' : 'false' ?>;
document.documentElement.classList.add(...Object.entries(window.ecwFeatures).filter(([, on]) => !on).map(([f]) => 'no-feature-' + f));
// Logging out wipes this browser's Teams cache (chats and messages) and open chat windows.
document.addEventListener('submit', (e) => {
  if (!e.target.closest('.appnav-logout')) return;
  try {
    Object.keys(localStorage).filter((k) => k.startsWith('ecw.teams.') || k.startsWith('ecw.drafttabs.')).forEach((k) => localStorage.removeItem(k));
    sessionStorage.removeItem('ecw.teams.windows');
    Object.keys(sessionStorage).filter((k) => k.startsWith('ecw.calendar.')).forEach((k) => sessionStorage.removeItem(k));
  } catch (_) {}
});</script>
<script>
// Script errors on this page go to System health → Problems (lib/errors.php), at most 5 per page.
(function (b) {
  var sent = 0, seen = {};
  function report(kind, message, where, stack) {
    message = String(message || '').slice(0, 500);
    if (!message || sent >= 5 || seen[message] || /^Script error\.?$|ResizeObserver loop|extension:\/\//.test(message + ' ' + where)) return;
    seen[message] = 1; sent++;
    try {
      fetch(b + 'api/index.php?action=client_error', { method: 'POST', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' },
        body: JSON.stringify({ kind: kind, message: message, where: where, stack: String(stack || '').slice(0, 4000), page: location.pathname + location.search }) }).catch(function () {});
    } catch (_) {}
  }
  window.addEventListener('error', function (e) {
    if (!e.message) return;   // a picture that failed to load, not a script
    var file = String(e.filename || '');
    if (file && file.indexOf(location.origin) !== 0) return;   // someone's browser extension
    report(e.error && e.error.name || 'Error', e.message, file.replace(location.origin, '') + ':' + (e.lineno || 0), e.error && e.error.stack);
  });
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason || {};
    if (r.name === 'AbortError' || /Failed to fetch|NetworkError|Load failed/.test(String(r.message || r))) return;   // offline or navigating away
    report(r.name || 'Promise', r.message || String(r), 'promise', r.stack);
  });
})(<?= json_encode($navBase, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>);
</script><script src="<?= $e($navBase) ?>assets/floating-window.js?v=<?= filemtime(dirname(__DIR__) . '/assets/floating-window.js') ?>" defer></script>
<script src="<?= $e($navBase) ?>assets/avatars.js?v=<?= filemtime(dirname(__DIR__) . '/assets/avatars.js') ?>" defer></script>
<script src="<?= $e($navBase) ?>assets/draft-dock.js?v=<?= filemtime(dirname(__DIR__) . '/assets/draft-dock.js') ?>"></script>
<script src="<?= $e($navBase) ?>assets/file-icons.js?v=<?= filemtime(dirname(__DIR__) . '/assets/file-icons.js') ?>"></script>
<script src="<?= $e($navBase) ?>assets/confirm-button.js?v=<?= filemtime(dirname(__DIR__) . '/assets/confirm-button.js') ?>"></script>
<script src="<?= $e($navBase) ?>assets/table-tools.js?v=<?= filemtime(dirname(__DIR__) . '/assets/table-tools.js') ?>" defer></script>
<script src="<?= $e($navBase) ?>assets/ooo.js?v=<?= filemtime(dirname(__DIR__) . '/assets/ooo.js') ?>" defer></script>
<?php if ($navFeatures['teams']): ?>
<script src="<?= $e($navBase) ?>assets/teams-dock.js?v=<?= filemtime(dirname(__DIR__) . '/assets/teams-dock.js') ?>" defer></script>
<script src="<?= $e($navBase) ?>assets/teams-launcher.js?v=<?= filemtime(dirname(__DIR__) . '/assets/teams-launcher.js') ?>" defer></script>
<?php endif; ?>
<link rel="stylesheet" href="<?= $e($navBase) ?>assets/collab.css?v=<?= filemtime(dirname(__DIR__) . '/assets/collab.css') ?>">
<?php foreach (ecw_module_everywhere($navBase, $navFeatures) as [$navKind, $navUrl]): ?>
<?= $navKind === 'css' ? '<link rel="stylesheet" href="' . $e($navUrl) . '">' : '<script src="' . $e($navUrl) . '" defer></script>' ?>
<?php endforeach; ?>
<script src="<?= $e($navBase) ?>assets/notifications.js?v=<?= filemtime(dirname(__DIR__) . '/assets/notifications.js') ?>" defer></script>
<script src="<?= $e($navBase) ?>assets/nav-badges.js?v=<?= filemtime(dirname(__DIR__) . '/assets/nav-badges.js') ?>" defer></script>
<script src="<?= $e($navBase) ?>assets/help.js?v=<?= filemtime(dirname(__DIR__) . '/assets/help.js') ?>" defer></script>
<nav class="appnav" aria-label="Application">
  <?php
    // Settings → Branding: the business's logo and name; the Case Workspace mark always stays, on the logo's corner.
    require_once dirname(__DIR__, 2) . '/lib/branding.php';
    $navBrand = ecw_branding((int)$navContext['organization_id']);
    $navMark = '<img class="brand-mark%s" src="' . $e($navBase) . 'assets/icons/favicon-32.png" alt="" title="Case Workspace · open-source software" width="16" height="16">';
  ?>
  <span class="appnav-brand<?= $navBrand['logo_ext'] ? ' has-logo' : '' ?>">
    <?php if ($navBrand['logo_ext']): ?>
      <span class="brand-logo-wrap"><img class="brand-logo" src="<?= $e($navBase) ?>brand-logo.php?v=<?= $e($navBrand['logo_v']) ?>" alt="<?= $e($navBrand['name'] ?: 'Logo') ?>"><?= sprintf($navMark, '') ?></span>
    <?php else: ?>
      <?= sprintf($navMark, ' solo') ?>
    <?php endif; ?>
    <?php if ($navBrand['name'] !== ''): ?><span class="brand-name"><?= $e($navBrand['name']) ?></span><?php endif; ?>
  </span>

  <ul class="appnav-links">
    <?php foreach ($navItems as $key => $item): ?>
      <li><a href="<?= $e($item['href']) ?>" data-nav="<?= $e($key) ?>"<?= $navActive === $key ? ' class="active" aria-current="page"' : '' ?>><i class="ui-icon fa <?= $e($navIcons[$key] ?? 'fa-puzzle-piece') ?>" aria-hidden="true"></i><?= $e($item['label']) ?></a></li>
    <?php endforeach; ?>
  </ul>

  <div class="appnav-user">
    <span class="tz-slot" id="tzSlot"></span>
    <?php if (count($navAccounts) > 1 && $navAccount !== null):
      $navAcctLabel = static fn(array $m): string => (string)($m['display_name'] ?: $m['email_address']);
      $navAcctInitial = static fn(array $m): string => mb_strtoupper(mb_substr(trim((string)($m['display_name'] ?: $m['email_address'])), 0, 1));
      // Each account keeps its own colour, so the one you work in is seen at a glance.
      $navAcctColor = static fn(array $m): string => ['#2f5aa8', '#1f7a4d', '#a8432f', '#7a3fa8', '#a87a1f', '#1f7a8a'][$m['id'] % 6];
    ?>
    <div class="acct-switch" id="acctSwitch" style="--acct:<?= $navAcctColor($navAccount) ?>">
      <button type="button" class="acct-btn" id="acctBtn" aria-haspopup="true" aria-expanded="false" title="Account: <?= $e($navAccount['email_address']) ?>">
        <span class="acct-dot" style="--acct:<?= $navAcctColor($navAccount) ?>"><?= $e($navAcctInitial($navAccount)) ?></span>
        <span class="acct-text"><strong><?= $e($navAcctLabel($navAccount)) ?> <span class="ooo-badge" hidden>Away</span></strong><small><?= $e($navAccount['email_address']) ?></small></span>
        <i class="fa fa-chevron-down" aria-hidden="true"></i>
      </button>
      <div class="acct-menu" id="acctMenu" role="menu" hidden>
        <div class="acct-menu-head">Work in account</div>
        <?php $navWorkspaces = count(array_unique(array_column($navAccounts, 'organization_id'))); $navLastWs = null; ?>
        <?php foreach ($navAccounts as $m): ?>
        <?php if ($navWorkspaces > 1 && $m['organization_id'] !== $navLastWs): $navLastWs = $m['organization_id']; ?>
        <div class="acct-menu-ws"><i class="fa fa-building" aria-hidden="true"></i> <?= $e($m['workspace'] !== '' ? $m['workspace'] : 'Workspace') ?></div>
        <?php endif; ?>
        <button type="button" role="menuitemradio" aria-checked="<?= $m['id'] === (int)$navAccount['id'] ? 'true' : 'false' ?>" data-account="<?= (int)$m['id'] ?>"<?= $m['id'] === (int)$navAccount['id'] ? ' class="is-active"' : '' ?>>
          <span class="acct-dot" style="--acct:<?= $navAcctColor($m) ?>"><?= $e($navAcctInitial($m)) ?></span>
          <span class="acct-text"><strong><?= $e($navAcctLabel($m)) ?><?= $m['is_shared'] ? ' <i class="fa fa-users" title="Shared mailbox" aria-hidden="true"></i>' : '' ?></strong><small><?= $e($m['email_address']) ?><?= $m['sync_status'] === 'disconnected' ? ' · not connected' : '' ?></small></span>
          <?php if ($m['id'] === (int)$navAccount['id']): ?><i class="fa fa-check" aria-hidden="true"></i><?php endif; ?>
        </button>
        <?php endforeach; ?>
        <div class="acct-menu-sep"></div>
        <?php if ($navOoo !== null): ?><button type="button" class="ooo-item" id="oooItem" role="menuitem" data-address="<?= $e($navAccount['email_address']) ?>" data-checked="<?= (int)($navOoo['at'] ?? 0) ?>" data-state="<?= $e(json_encode($navOoo)) ?>"><i class="fa fa-plane-departure" aria-hidden="true"></i> Automatic replies <span class="ooo-state"></span></button><?php endif; ?>
      </div>
    </div>
    <script>(function () {
      var b = document.getElementById('acctBtn'), m = document.getElementById('acctMenu');
      var set = function (open) { m.hidden = !open; b.setAttribute('aria-expanded', String(open)); };
      b.addEventListener('click', function (e) { e.stopPropagation(); set(m.hidden); if (!m.hidden) (m.querySelector('.is-active') || m.querySelector('button')).focus(); });
      document.addEventListener('click', function (e) { if (!m.hidden && !m.contains(e.target)) set(false); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden) { set(false); b.focus(); } });
      m.addEventListener('click', function (e) {
        var item = e.target.closest('[data-account]');
        if (!item) return;
        if (item.classList.contains('is-active')) { set(false); return; }
        m.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        // Teams chats and the calendar kept in this browser belong to the old account.
        try {
          Object.keys(localStorage).filter(function (k) { return k.indexOf('ecw.teams.') === 0; }).forEach(function (k) { localStorage.removeItem(k); });
          sessionStorage.removeItem('ecw.teams.windows');
          Object.keys(sessionStorage).filter(function (k) { return k.indexOf('ecw.calendar.') === 0; }).forEach(function (k) { sessionStorage.removeItem(k); });
        } catch (_) {}
        fetch(window.ecwWebBase + 'api/index.php?action=account_switch', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify({ account_id: Number(item.dataset.account) }) })
          .then(function (r) { if (!r.ok) throw new Error('switch'); return r.json(); })
          .then(function (r) {
            // The page reloads in the new account. Links to one item (?case=, ?mailbox=, ?thread=) go to the page's start.
            location.href = location.pathname.replace(/\/(case|case-print|thread-print)\.php$/, '/cases.php');
          })
          .catch(function () { m.querySelectorAll('button').forEach(function (x) { x.disabled = false; }); });
      });
    })();</script>
    <?php endif; ?>
    <?php require_once dirname(__DIR__, 2) . '/lib/help.php'; ?>
    <?php // Help is the last item of the profile menu (below); this button stays, hidden, for help.js and the "?" key. ?>
    <button type="button" id="helpBtn" class="notif-bell help-btn" hidden aria-label="Help" aria-expanded="false" title="Help (press ?)"
            data-page="<?= $e(ecw_help_page_key((string)($_SERVER['SCRIPT_NAME'] ?? ''))) ?>"><i class="fa fa-circle-question" aria-hidden="true"></i></button>
    <div class="notif-wrap">
      <button type="button" id="notifBell" class="notif-bell" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" title="Notifications">
        <i class="fa fa-bell" aria-hidden="true"></i><span class="notif-count" id="notifCount" hidden>0</span>
      </button>
      <div class="notif-panel" id="notifPanel" hidden role="dialog" aria-label="Notifications">
        <header><strong>Notifications</strong><button type="button" id="notifReadAll" class="link-btn">Mark all read</button></header>
        <ul id="notifList"></ul>
      </div>
    </div>
    <?php
      // The person's Outlook photo (or initials), with a shield for admins; the menu holds Log out.
      require_once dirname(__DIR__, 2) . '/lib/avatars.php';
      $navAvatarSt = ecw_db()->prepare('SELECT avatar_hash FROM `' . ecw_table('users') . '` WHERE id = ?');
      $navAvatarSt->execute([(int)$navUser['id']]);
      $navAvatar = ecw_avatar_url((int)$navUser['id'], ($navAvatarSt->fetchColumn() ?: null));
      $navName = (string)($navUser['name'] ?: $navUser['email']);
      $navInitials = '';
      foreach (array_slice(preg_split('/\s+/u', trim($navName)) ?: [], 0, 2) as $w) { $navInitials .= mb_strtoupper(mb_substr($w, 0, 1)); }
    ?>
    <div class="appnav-me" data-name="<?= $e($navName) ?>">
      <button type="button" class="appnav-me-btn" id="appnavMe" aria-haspopup="true" aria-expanded="false"
              title="<?= $e($navName . ' · ' . $navUser['email'] . ($navRole === 'admin' ? ' · admin' : '')) ?>" aria-label="Your account">
        <span class="appnav-avatar"><?php if ($navAvatar): ?><img src="<?= $e($navAvatar) ?>" alt=""><?php else: ?><?= $e($navInitials ?: '?') ?><?php endif; ?></span>
        <?php if ($navRole === 'admin'): ?><span class="appnav-shield" title="Administrator"><i class="fa fa-shield-halved" aria-hidden="true"></i></span><?php endif; ?>
      </button>
      <div class="appnav-me-menu" id="appnavMeMenu" role="menu" hidden>
        <div class="appnav-me-head">
          <span class="appnav-avatar big"><?php if ($navAvatar): ?><img src="<?= $e($navAvatar) ?>" alt=""><?php else: ?><?= $e($navInitials ?: '?') ?><?php endif; ?></span>
          <div><strong><?= $e($navName) ?></strong><small><?= $e($navUser['email']) ?></small>
            <?php if ($navRole === 'admin'): ?><span class="appnav-role"><i class="fa fa-shield-halved" aria-hidden="true"></i> Administrator</span><?php endif; ?></div>
        </div>
        <a role="menuitem" href="<?= $e($navBase) ?>account.php"><i class="fa fa-user" aria-hidden="true"></i> Account</a>
        <a role="menuitem" href="<?= $e($navBase) ?>settings/appearance.php"><i class="fa fa-palette" aria-hidden="true"></i> Appearance</a>
        <a role="menuitem" href="<?= $e($navBase) ?>settings/"><i class="fa fa-gear" aria-hidden="true"></i> Settings</a>
        <?php if (count($navAccounts) <= 1): ?><?php if ($navOoo !== null): ?><button type="button" class="ooo-item" id="oooItem" role="menuitem" data-address="<?= $e($navAccount['email_address']) ?>" data-checked="<?= (int)($navOoo['at'] ?? 0) ?>" data-state="<?= $e(json_encode($navOoo)) ?>"><i class="fa fa-plane-departure" aria-hidden="true"></i> Automatic replies <span class="ooo-state"></span></button><?php endif; ?><?php endif; ?>
        <form method="post" action="<?= $e($navBase) ?>logout.php" class="appnav-logout">
          <input type="hidden" name="csrf" value="<?= $e($navCsrf) ?>">
          <button type="submit" role="menuitem"><i class="fa fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</button>
        </form>
        <button type="button" role="menuitem" class="appnav-help" id="appnavHelp"><i class="fa fa-circle-question" aria-hidden="true"></i> Help <span class="appnav-key">?</span></button>
      </div>
    </div>
    <script>(function () {
      var b = document.getElementById('appnavMe'), m = document.getElementById('appnavMeMenu');
      var set = function (open) { m.hidden = !open; b.setAttribute('aria-expanded', String(open)); };
      b.addEventListener('click', function (e) { e.stopPropagation(); set(m.hidden); if (!m.hidden) m.querySelector('a,button').focus(); });
      document.addEventListener('click', function (e) { if (!m.hidden && !m.contains(e.target)) set(false); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden) { set(false); b.focus(); } });
      document.getElementById('appnavHelp')?.addEventListener('click', function () { set(false); document.getElementById('helpBtn')?.click(); });
      var img = b.querySelector('img'); if (img) img.addEventListener('error', function () { img.replaceWith(document.createTextNode(<?= json_encode($navInitials ?: '?', JSON_HEX_TAG) ?>)); });
    })();</script>
    <button type="button" class="appnav-hide" id="appnavHide" title="Hide this bar (more room for mail)" aria-label="Hide the top bar">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
    </button>
  </div>
</nav>
<script>
// The bar's height as --appnav-h: what stays put below it while a page scrolls (the
// settings list, a preview) sits under the bar, not behind it. 0 while it is hidden.
(function () {
  var bar = document.currentScript.previousElementSibling, root = document.documentElement;
  var set = function () { root.style.setProperty('--appnav-h', (bar.offsetParent ? bar.offsetHeight : 0) + 'px'); };
  set();
  if ('ResizeObserver' in window) new ResizeObserver(set).observe(bar);
  // The Mail tab opens the Inbox: it forgets the folder Mail remembers for this
  // tab (app.js saveListPos), which a reload or Back still brings back.
  var mail = bar.querySelector('a[data-nav="mail"]');
  if (mail) mail.addEventListener('click', function () {
    try { Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('ecw.mailpos.') === 0) sessionStorage.removeItem(k); }); } catch (_) {}
  });
})();
</script>
<button type="button" class="appnav-show" id="appnavShow" title="Show the top bar" aria-label="Show the top bar" hidden>
  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
</button>
<script>
(function () {
  // Hide / show the top bar (remembered on this device), and on a phone keep the
  // current tab in view in the sideways-scrolling bar.
  var hide = document.getElementById('appnavHide'), show = document.getElementById('appnavShow');
  var set = function (hidden) {
    document.body.classList.toggle('appnav-hidden', hidden);
    show.hidden = !hidden;
    try { localStorage.setItem('ecw.appnav.hidden', hidden ? '1' : '0'); } catch (_) {}
    window.dispatchEvent(new Event('resize'));   // panes re-fit their height
  };
  var saved = '0'; try { saved = localStorage.getItem('ecw.appnav.hidden') || '0'; } catch (_) {}
  if (saved === '1') set(true);
  hide.addEventListener('click', function () { set(true); show.focus(); });
  show.addEventListener('click', function () { set(false); hide.focus(); });
  // The tabs scroll sideways when they do not fit: the open page stays in view, the
  // mouse wheel scrolls them, and a fade shows on the side where more are hidden.
  var links = document.querySelector('.appnav-links');
  if (links) {
    var fades = function () {
      var more = links.scrollWidth - links.clientWidth > 2;
      links.classList.toggle('fade-left', more && links.scrollLeft > 2);
      links.classList.toggle('fade-right', more && links.scrollLeft + links.clientWidth < links.scrollWidth - 2);
    };
    links.addEventListener('scroll', fades, { passive: true });
    window.addEventListener('resize', fades);
    links.addEventListener('wheel', function (e) {
      if (links.scrollWidth <= links.clientWidth || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
      links.scrollLeft += e.deltaY; e.preventDefault();
    }, { passive: false });
    var active = links.querySelector('a.active');
    if (active && links.scrollWidth > links.clientWidth) {
      var a = active.getBoundingClientRect(), l = links.getBoundingClientRect();
      links.scrollLeft += a.left - l.left - (l.width - a.width) / 2;
    }
    fades();
  }
})();
</script>
<script src="<?= $e($navBase) ?>assets/ui-helpers.js?v=<?= filemtime(dirname(__DIR__) . '/assets/ui-helpers.js') ?>"></script>
<script src="<?= $e($navBase) ?>assets/timezone.js?v=<?= filemtime(dirname(__DIR__) . '/assets/timezone.js') ?>"></script>
