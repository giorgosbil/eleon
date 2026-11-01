<?php declare(strict_types=1);
/**
 * The Telegram tab: the signed-in person's own Telegram, opened at
 * module.php?m=telegram&p=telegram. Not connected yet, it walks them through
 * connecting (phone number → code → password); connected, it shows their
 * chats. assets/telegram.js does both, from the tg_* API actions (api.php).
 */
require_once dirname(__DIR__, 3) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/telegram.php';
$web = dirname(__DIR__, 3) . '/web';

$context = ecw_require_login(); ecw_require_feature('telegram');
$tg      = ecw_tg_status((int)$context['user']['id']);
$navActive = 'telegram';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Telegram — Case Workspace</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime($web . '/assets/app.css') ?>">
</head>
<body class="page-body teams-body" data-csrf="<?= $h(ecw_csrf_token()) ?>" data-user="<?= (int)$context['user']['id'] ?>" data-status="<?= $h($tg['status']) ?>">
<?php require $web . '/partials/nav.php'; ?>

<main class="teams-setup tg-setup btn-scope" id="tgConnect"<?= $tg['status'] === 'ready' ? ' hidden' : '' ?>>
  <div class="teams-card">
    <h2><i class="fa-brands fa-telegram tg-blue" aria-hidden="true"></i> Your Telegram, here</h2>
    <p>Connect your own Telegram account with your phone number. Your chats and contacts then appear here, next to your emails: answer, start new chats, send photos and files. You keep using Telegram on your phone as always; this app appears there as another device (Settings → Devices), where you can also end it.</p>
    <p class="muted"><i class="fa fa-lock" aria-hidden="true"></i> Only you see your Telegram here. Your colleagues connect their own.</p>
    <?php if (!$tg['daemon_ok']): ?>
      <p class="teams-error">The Telegram service of this server is not running, so connecting cannot finish. An admin starts it with <code>docker compose up -d</code>.</p>
    <?php endif; ?>
    <?php if (!$tg['here']): ?>
      <p class="teams-error">Your Telegram is connected on another computer that runs this app. Your chats are shown here as they were last copied; to send, use that computer.</p>
    <?php endif; ?>
  </div>

  <!-- Step 1: phone (and the api_id / api_hash, unless the server has them) -->
  <form class="teams-card" id="tgStepPhone" autocomplete="off" hidden>
    <h2>1. Your phone number</h2>
    <div id="tgApiBox"<?= $tg['needs_api'] ? '' : ' hidden' ?>>
      <p>Telegram first needs an “app id” for this server: free, once, two minutes.</p>
      <ol>
        <li>Open <a href="https://my.telegram.org" target="_blank" rel="noopener">my.telegram.org</a> on a computer and sign in with your phone number (the code arrives in Telegram).</li>
        <li>Choose <strong>API development tools</strong>. Fill in an app title (e.g. <code>Other Things</code>), a short name (e.g. <code>otherthings</code>), platform <strong>Desktop</strong>; the rest can stay empty. Press <strong>Create application</strong>.</li>
        <li>Copy <strong>App api_id</strong> and <strong>App api_hash</strong> here. Never share them.</li>
      </ol>
      <label for="tgApiId">api_id</label>
      <input type="text" id="tgApiId" inputmode="numeric" placeholder="<?= $tg['has_api'] ? 'Saved. Leave empty to keep it.' : 'e.g. 1234567' ?>">
      <label for="tgApiHash">api_hash</label>
      <input type="password" id="tgApiHash" placeholder="<?= $tg['has_api'] ? 'Saved. Leave empty to keep it.' : '32 letters and digits' ?>">
    </div>
    <label for="tgPhone">Phone number</label>
    <input type="tel" id="tgPhone" placeholder="+30 69…" value="<?= $h($tg['phone']) ?>">
    <p class="muted">With the country code. Telegram sends you a code, usually in the Telegram app.</p>
    <div class="teams-notify-row"><button type="submit" class="primary">Send me the code</button></div>
  </form>

  <!-- Step 2: the code -->
  <form class="teams-card" id="tgStepCode" autocomplete="off" hidden>
    <h2>2. The code</h2>
    <p id="tgCodeWhere" class="muted"></p>
    <label for="tgCode">Code</label>
    <input type="text" id="tgCode" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="12345">
    <p class="muted">Never give this code to anyone else.</p>
    <div class="teams-notify-row"><button type="submit" class="primary">Connect</button> <button type="button" class="button tg-restart">Another number / new code</button></div>
  </form>

  <!-- Step 3: the 2-step password -->
  <form class="teams-card" id="tgStepPassword" autocomplete="off" hidden>
    <h2>3. Your Telegram password</h2>
    <p class="muted">Your account has 2-step verification. <span id="tgPwHint"></span></p>
    <label for="tgPassword">Password</label>
    <input type="password" id="tgPassword" autocomplete="current-password">
    <p class="muted">Used once to sign in and not kept.</p>
    <div class="teams-notify-row"><button type="submit" class="primary">Connect</button> <button type="button" class="button tg-restart">Start over</button></div>
  </form>

  <div class="teams-card" id="tgWorking" hidden><p><span class="ai-spin" aria-hidden="true"></span> <span id="tgWorkingText">Asking Telegram…</span></p></div>
  <p class="teams-error" id="tgConnectError" role="alert" hidden></p>
  <p id="tgOldChats" hidden><button type="button" class="button" id="tgShowOld">See my chats as they were</button></p>
</main>

<main class="teams-page tg-page" id="tgPage"<?= $tg['status'] === 'ready' ? '' : ' hidden' ?>>
  <aside class="teams-side" aria-label="Telegram chats">
    <div class="teams-side-head">
      <input type="search" id="tgFilter" placeholder="Search chats" aria-label="Search chats">
      <button type="button" class="button primary tg-new-btn" id="tgNewBtn" title="New chat"><i class="fa fa-pen-to-square" aria-hidden="true"></i> New</button>
    </div>
    <ul class="teams-chat-list" id="tgList"><li class="muted teams-empty">Loading chats…</li></ul>
    <p class="tg-me muted"><i class="fa-brands fa-telegram" aria-hidden="true"></i> <span id="tgMe"><?= $h(trim($tg['name'] . ' · ' . $tg['phone'], ' ·')) ?></span>
      <button type="button" class="tg-link-btn" id="tgDisconnect">Disconnect</button></p>
  </aside>
  <section class="teams-main" aria-label="Conversation">
    <div class="teams-placeholder" id="tgPlaceholder">Choose a chat on the left, or press <strong>New</strong> to write to a contact.</div>
    <header class="teams-main-head" id="tgHead" hidden>
      <button type="button" class="teams-win-btn" id="tgBack" title="Back to chats" aria-label="Back to chats"><i class="fa fa-arrow-left" aria-hidden="true"></i></button>
      <div class="tg-head-text"><h2 id="tgTitle"></h2><span class="muted" id="tgSub"></span></div>
      <span class="chat-actions-slot" data-for="tgMessages"></span>
    </header>
    <div class="teams-win-list tg-list" id="tgMessages" hidden></div>
    <div class="tg-compose" id="tgCompose" hidden>
      <div class="tg-attached" id="tgAttached" hidden><span id="tgAttachedName"></span> <button type="button" class="tg-link-btn" id="tgAttachedRemove">Remove</button></div>
      <form class="teams-compose" id="tgSendForm" autocomplete="off">
        <label class="teams-win-btn tg-attach" title="Attach a photo or file (up to 8 MB)" aria-label="Attach a file"><i class="fa fa-paperclip" aria-hidden="true"></i><input type="file" id="tgFile" hidden></label>
        <textarea id="tgText" rows="1" placeholder="Write a message" aria-label="Message" maxlength="4096"></textarea>
        <button type="submit" class="teams-win-btn tg-send" title="Send" aria-label="Send"><i class="fa fa-paper-plane" aria-hidden="true"></i></button>
      </form>
    </div>
  </section>
</main>

<dialog class="teams-new" id="tgNewDialog" aria-labelledby="tgNewTitle">
  <form method="dialog" id="tgNewForm" autocomplete="off">
    <h2 id="tgNewTitle">New chat</h2>
    <input id="tgNewQ" type="search" placeholder="Search your contacts: name, @username or phone" aria-label="Search contacts">
    <ul class="nc-suggest" id="tgNewResults" role="listbox"></ul>
    <details id="tgNewNumberBox">
      <summary>Someone who is not in your contacts</summary>
      <label for="tgNewPhone">Phone number</label>
      <input id="tgNewPhone" type="tel" placeholder="+30 69…">
      <label for="tgNewFirst">First name</label>
      <input id="tgNewFirst" type="text" maxlength="64">
      <label for="tgNewLast">Last name (optional)</label>
      <input id="tgNewLast" type="text" maxlength="64">
      <p class="muted nc-hint">They are added to your Telegram contacts, as when you add a contact in the app.</p>
      <button type="button" class="button primary" id="tgNewNumberGo">Start the chat</button>
    </details>
    <div class="nc-result error" id="tgNewError" role="alert" hidden></div>
    <div class="nc-actions"><button type="button" class="button" id="tgNewCancel">Cancel</button></div>
  </form>
</dialog>

<?php // Chat pictures open in the app's preview (teams-dock.js). ?>
<script src="assets/floating-window.js?v=<?= filemtime($web . '/assets/floating-window.js') ?>"></script>
<script src="assets/docViewers.js?v=<?= filemtime($web . '/assets/docViewers.js') ?>"></script>
<script src="assets/filePreview.js?v=<?= filemtime($web . '/assets/filePreview.js') ?>"></script>
<script src="<?= $h(ecw_module_asset_url('', 'telegram', 'telegram.js')) ?>" defer></script>
</body>
</html>
