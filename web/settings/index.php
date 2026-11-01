<?php declare(strict_types=1);
/**
 * Settings overview: the groups of lib/settings_menu.php with what each page is for.
 * The sidebar beside it has the same pages and the search.
 */

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/settings_menu.php';

$context = ecw_require_login();
$base    = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$navActive = 'settings';
$menu = ecw_settings_menu($context);
$hints = ['For me' => 'Only you', 'Cases' => 'Everyone', 'Team' => 'Admins', 'Company' => 'Admins', 'System' => 'Admins'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css">
<link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css">
</head>
<body class="page-body">
<?php require dirname(__DIR__) . '/partials/nav.php'; ?>

<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide">
  <h1>Settings</h1>
  <p class="muted">Looking for one setting? Type a word in <strong>Search settings</strong> (or press <kbd>/</kbd>).</p>

  <?php foreach ($menu as $group => $items): $items = array_filter($items, static fn($it) => empty($it['search_only'])); if (!$items) continue; ?>
    <h2 class="settings-overview-group"><?= $h($group) ?> <span class="muted small" style="text-transform:none;letter-spacing:0;font-weight:400">· <?= $h($hints[$group] ?? '') ?></span></h2>
    <ul class="settings-overview">
      <?php foreach ($items as $it): ?>
        <li><a href="<?= $h($base . $it['href']) ?>"><i class="ui-icon fa <?= $h($it['icon']) ?>" aria-hidden="true"></i><span><strong><?= $h($it['label']) ?></strong><span class="muted"><?= $h($it['desc']) ?></span></span></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
</main>
</div>
</body>
</html>
