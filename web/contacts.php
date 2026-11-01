<?php declare(strict_types=1);
/**
 * Contacts: the companies and people you have mail with — every case and
 * conversation with them on one page, plus the details colleagues add.
 * Data: api contacts_* (lib/contacts.php). Drawn by assets/contacts.js.
 */
require_once dirname(__DIR__) . '/lib/session.php';
$context = ecw_require_login(); ecw_require_feature('contacts'); $navActive = 'contacts'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Contacts — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="stylesheet" href="<?= $h($base) ?>assets/contacts.css?v=<?= filemtime(__DIR__ . '/assets/contacts.css') ?>">
</head>
<body class="page-body contacts-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require __DIR__ . '/partials/nav.php'; ?>
<div class="contacts-layout">
  <aside class="contacts-side" aria-label="Contacts">
    <div class="contacts-tabs" role="tablist">
      <button type="button" role="tab" class="active" aria-selected="true" data-tab="companies"><i class="fa fa-building" aria-hidden="true"></i> Companies</button>
      <button type="button" role="tab" aria-selected="false" data-tab="people"><i class="fa fa-user" aria-hidden="true"></i> People</button>
    </div>
    <input type="search" id="contactSearch" placeholder="Search names, addresses, domains…" aria-label="Search contacts" autocomplete="off">
    <ul class="contacts-list" id="contactList" aria-live="polite"></ul>
  </aside>
  <main class="contacts-main" id="contactMain" data-i18n-ctx="status">
    <div class="contacts-empty"><i class="fa fa-address-book" aria-hidden="true"></i><p>Pick a company or a person to see every case and conversation with them.</p>
      <p class="muted">Companies come from email domains. Personal addresses (Gmail, Outlook.com…) are listed under People.</p></div>
  </main>
</div>
<script src="<?= $h($base) ?>assets/contacts.js?v=<?= filemtime(__DIR__ . '/assets/contacts.js') ?>" defer></script>
</body></html>
