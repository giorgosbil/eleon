<?php declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/session.php';
$context = ecw_require_login(); $navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
require_once dirname(__DIR__, 2) . '/lib/account.php';
$sigAccount = ecw_active_account((int)$context['organization_id'], (int)$context['user']['id']);
$sigMany = count(ecw_account_choices((int)$context['organization_id'], (int)$context['user']['id'])) > 1;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Signatures — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php  require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page signature-page"><h1>Email &amp; writing</h1>
<nav class="settings-tabs" aria-label="Email &amp; writing"><a href="email.php">Writing and replies</a><a href="signatures.php" class="active" aria-current="page">Signatures</a></nav>
<p>Your default is added to new emails, replies and forwards. You can choose another signature while composing.<?= $sigMany ? ' Each account has its own default: switch account at the top to set the other.' : '' ?></p>
<form id="signatureProfilesForm" class="stack"><label>Saved signature<select id="profileSelect"><option value="">New signature</option></select></label><label>Name<input id="profileName" required maxlength="80" placeholder="Work, support, personal…"></label>
<div class="sig-editor"><div id="profileToolbar" class="quote-edit-toolbar sig-toolbar" role="toolbar" aria-label="Signature formatting"></div>
<div id="profileImageBar" class="sig-image-bar" hidden><span>Picture width</span><button type="button" id="profileImageSmaller" title="Smaller" aria-label="Smaller"><i class="fa fa-minus" aria-hidden="true"></i></button><input id="profileImageWidth" type="number" class="ot-input" min="16" max="600" step="1" aria-label="Picture width in pixels"><span>px</span><button type="button" id="profileImageBigger" title="Bigger" aria-label="Bigger"><i class="fa fa-plus" aria-hidden="true"></i></button><button type="button" id="profileImageRemove"><i class="fa fa-trash-can" aria-hidden="true"></i> Remove picture</button></div>
<div id="profileEditor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Signature content"></div>
<textarea id="profileSource" class="ot-input sig-source" hidden spellcheck="false" aria-label="Signature HTML"></textarea></div>
<p class="sig-hint">Paste a signature from Outlook, Word or a web page, or build it here. Pictures: paste, drop, or use <b>Picture</b>; click a picture to resize it.</p><label class="checkbox-label"><input id="profileDefault" type="checkbox"> <?= $sigMany && $sigAccount ? 'Use as the default signature for ' . $h($sigAccount['email_address']) : 'Use as my default signature' ?></label>
<div><button type="submit" class="primary">Save signature</button> <button id="profileDelete" type="button" disabled>Delete</button></div><p id="profileStatus" role="status"></p></form></main>
</div>
<script src="<?= $h($base) ?>assets/signatures.js?v=<?= filemtime(dirname(__DIR__).'/assets/signatures.js') ?>"></script></body></html>
