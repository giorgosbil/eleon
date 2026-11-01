<?php declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/license.php';
$context = ecw_require_admin(); $navActive = 'settings'; $base = ecw_web_base();
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Eleon Community</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope"><h1>Eleon Community</h1>
<section class="plan-card"><h2>All installed features. No license key.</h2>
<p>Eleon is open-source email, case management, collaboration and optional AI software under AGPL-3.0. There is no software trial, paid feature tier, or license-based user or mailbox limit.</p>
<p>Administrators still control feature access and roles. Mail providers, AI models and messaging connections require their own configuration and may charge separately.</p>
<p>Self-host on your own infrastructure. Hosting, setup, migration, training and support services can be arranged separately.</p>
<p><a href="<?= $h($base) ?>settings/features.php">Manage feature access</a> · <a href="https://github.com/giorgosbil/eleon">Source code</a> · <a href="https://eleon.app/">Project website</a></p></section>
</main></div></body></html>
