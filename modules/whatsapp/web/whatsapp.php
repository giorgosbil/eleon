<?php declare(strict_types=1);
/**
 * The WhatsApp tab: the workspace's WhatsApp Business chats, opened at
 * module.php?m=whatsapp&p=whatsapp. assets/whatsapp.js fills it from the wa_*
 * API actions (api.php); messages arrive through web/webhook.php (lib/whatsapp.php).
 */
require_once dirname(__DIR__, 3) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/whatsapp.php';
$web = dirname(__DIR__, 3) . '/web';

$context   = ecw_require_login(); ecw_require_feature('whatsapp');
$wa        = ecw_wa_settings((int)$context['organization_id']);
// WhatsApp belongs to the account its number is linked to (lib/account.php): opening it switches there.
require_once dirname(__DIR__, 3) . '/lib/account.php';
$waAccount = ecw_active_account_id((int)$context['organization_id'], (int)$context['user']['id']);
if ($waAccount !== null && !ecw_wa_for_account((int)$context['organization_id'], $waAccount)
    && !ecw_account_switch((int)$context['organization_id'], (int)$context['user']['id'], (int)$wa['mailbox_id'])) {
    ecw_redirect(ecw_web_base() . 'app.php');
}
$navActive = 'whatsapp';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp — Case Workspace</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime($web . '/assets/app.css') ?>">
</head>
<body class="page-body teams-body" data-csrf="<?= $h(ecw_csrf_token()) ?>" data-user="<?= (int)$context['user']['id'] ?>">
<?php require $web . '/partials/nav.php'; ?>

<?php if (!$wa['configured']): ?>
<main class="teams-setup btn-scope">
  <div class="teams-card">
    <h2><i class="fa-brands fa-whatsapp wa-green" aria-hidden="true"></i> WhatsApp with your customers</h2>
    <p>Answer your customers' WhatsApp messages here, next to their emails. Everyone in the workspace shares one WhatsApp Business number. A chat can be linked to its case, and whoever handles the case is told when the customer writes.</p>
    <?php if (ecw_is_admin()): ?>
      <a class="button primary" href="<?= $h(ecw_module_settings_url('', 'whatsapp', 'whatsapp')) ?>">Connect WhatsApp</a>
    <?php else: ?>
      <p class="muted">An admin connects the WhatsApp Business number under Settings → WhatsApp.</p>
    <?php endif; ?>
  </div>
</main>
<?php else: ?>
<main class="teams-page wa-page" id="waPage">
  <aside class="teams-side" aria-label="WhatsApp chats">
    <div class="teams-side-head">
      <input type="search" id="waFilter" placeholder="Search chats" aria-label="Search chats">
      <button type="button" class="button primary wa-new-btn" id="waNewBtn" title="Message a new number"><i class="fa fa-pen-to-square" aria-hidden="true"></i> New</button>
    </div>
    <ul class="teams-chat-list" id="waList"><li class="muted teams-empty">Loading chats…</li></ul>
    <p class="wa-number muted"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> <?= $h($wa['verified_name'] ?: 'Your number') ?> · <?= $h($wa['display_phone']) ?></p>
  </aside>
  <section class="teams-main" aria-label="Conversation">
    <div class="teams-placeholder" id="waPlaceholder">Choose a chat on the left. To message someone new, press <strong>New</strong>.</div>
    <header class="teams-main-head" id="waHead" hidden>
      <button type="button" class="teams-win-btn" id="waBack" title="Back to chats" aria-label="Back to chats"><i class="fa fa-arrow-left" aria-hidden="true"></i></button>
      <div class="wa-head-text"><h2 id="waTitle"></h2><span class="muted" id="waPhone"></span></div>
      <span class="chat-actions-slot" data-for="waMessages"></span>
    </header>
    <div class="teams-win-list wa-list" id="waMessages" hidden></div>
    <div class="wa-compose" id="waCompose" hidden>
      <p class="wa-window muted" id="waWindow"></p>
      <div class="wa-attached" id="waAttached" hidden><span id="waAttachedName"></span> <button type="button" class="wa-link-btn" id="waAttachedRemove">Remove</button></div>
      <form class="teams-compose" id="waSendForm" autocomplete="off">
        <label class="teams-win-btn wa-attach" title="Attach a photo or file (up to 8 MB)" aria-label="Attach a file"><i class="fa fa-paperclip" aria-hidden="true"></i><input type="file" id="waFile" hidden></label>
        <textarea id="waText" rows="1" placeholder="Write a message" aria-label="Message" maxlength="4096"></textarea>
        <button type="submit" class="teams-win-btn wa-send" title="Send" aria-label="Send"><i class="fa fa-paper-plane" aria-hidden="true"></i></button>
      </form>
      <div class="wa-closed" id="waClosed" hidden>
        <span>More than 24 hours have passed since the customer last wrote. WhatsApp then only accepts an approved template.</span>
        <button type="button" class="button primary" id="waTplBtn">Send a template</button>
      </div>
    </div>
  </section>
</main>

<dialog class="teams-new" id="waTplDialog" aria-labelledby="waTplTitle">
  <form method="dialog" id="waTplForm" autocomplete="off">
    <h2 id="waTplTitle">Send a template</h2>
    <div id="waTplPhoneRow">
      <label for="waTplPhone">Phone number</label>
      <input id="waTplPhone" type="text" inputmode="tel" placeholder="+30 69…">
      <p class="muted nc-hint">With the country code. WhatsApp lets a business start a chat only with an approved template.</p>
    </div>
    <label for="waTplPick">Template</label>
    <select id="waTplPick"><option value="">Loading…</option></select>
    <div id="waTplParams"></div>
    <label>Preview</label>
    <div class="wa-preview" id="waTplPreview"></div>
    <div class="nc-result error" id="waTplError" role="alert" hidden></div>
    <div class="nc-actions">
      <button type="button" class="button" id="waTplCancel">Cancel</button>
      <button type="submit" class="button primary" id="waTplSend">Send</button>
    </div>
  </form>
</dialog>

<?php // Chat pictures open in the app's preview (teams-dock.js). ?>
<script src="assets/floating-window.js?v=<?= filemtime($web . '/assets/floating-window.js') ?>"></script>
<script src="assets/docViewers.js?v=<?= filemtime($web . '/assets/docViewers.js') ?>"></script>
<script src="assets/filePreview.js?v=<?= filemtime($web . '/assets/filePreview.js') ?>"></script>
<script src="<?= $h(ecw_module_asset_url('', 'whatsapp', 'whatsapp.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
