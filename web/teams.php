<?php declare(strict_types=1);
/**
 * Email Case Workspace — the Teams tab: your Teams chats next to your mail.
 *
 * Renders the frame; assets/teams.js fills it from the teams_* API actions.
 * Chats are read live from Microsoft as the signed-in user (lib/teams.php).
 */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/teams.php';

$context   = ecw_require_login(); ecw_require_feature('teams');
$status    = ecw_teams_status((int)$context['user']['id']);
$navActive = 'teams';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teams — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body class="page-body teams-body" data-csrf="<?= $h(ecw_csrf_token()) ?>" data-user="<?= (int)$context['user']['id'] ?>">
<?php require __DIR__ . '/partials/nav.php'; ?>

<?php if (!$status['enabled']): ?>
<main class="teams-setup btn-scope">
  <?php if (isset($_GET['enabled'])): ?>
    <div class="teams-card"><strong>Teams could not be enabled.</strong> Microsoft did not grant chat access. Your organization may require an administrator to approve the Chat.ReadWrite permission, or the permission is missing from the app registration (steps below).</div>
  <?php endif; ?>
  <div class="teams-card">
    <h2><i class="ecw-teams-icon" aria-hidden="true"></i> Use Microsoft Teams here</h2>
    <p>Read and answer your Teams chats next to your mail and cases. Open a chat in a small floating window and drag it anywhere. It stays open while you move between Mail, Cases and Teams.</p>
    <?php if (!$status['connected']): ?>
      <p class="muted">First connect your Microsoft Outlook mailbox under Settings → Mailbox.</p>
      <a class="button primary" href="settings/mailbox.php">Connect Outlook</a>
    <?php else: ?>
      <p>This asks Microsoft for one extra permission: <strong>read and send your chat messages</strong> (Chat.ReadWrite). Your mail keeps working exactly as now, even if you skip it.</p>
      <a class="button primary" href="oauth/start.php?teams=1">Enable Teams for <?= $h($status['email']) ?></a>
    <?php endif; ?>
  </div>
  <div class="teams-card">
    <h2>One-time setup (whoever manages the Microsoft app registration)</h2>
    <ol>
      <li>Open <strong>Microsoft Entra admin center → App registrations →</strong> your Case Workspace app.</li>
      <li><strong>API permissions → Add a permission → Microsoft Graph → Delegated</strong> → add <code>Chat.ReadWrite</code>.</li>
      <li>If your organization requires it, click <strong>Grant admin consent</strong>.</li>
      <li>Come back here and click <em>Enable Teams</em>.</li>
    </ol>
    <p class="muted">Without this, "Chat in Teams" buttons still work: they open the Teams app instead.</p>
  </div>
  <?php if (ecw_is_admin()): ?><p><a href="settings/teams.php">Teams channel notifications →</a></p><?php endif; ?>
</main>
<?php else: ?>
<main class="teams-page" id="teamsPage">
  <aside class="teams-side" aria-label="Chats">
    <form class="teams-side-head" id="newChatForm" autocomplete="off">
      <input type="search" id="chatFilter" placeholder="Search chats" aria-label="Search chats">
      <button type="button" class="button primary teams-new-btn" id="newChatBtn" title="Start a new chat"><i class="fa fa-pen-to-square" aria-hidden="true"></i> New chat</button>
    </form>
    <ul class="teams-chat-list" id="chatList"><li class="muted teams-empty">Loading chats…</li></ul>
    <?php if (ecw_is_admin()): ?><a class="small-link" style="padding:8px 12px" href="settings/teams.php">Channel notifications</a><?php endif; ?>
  </aside>
  <section class="teams-main" aria-label="Conversation">
    <div class="teams-placeholder" id="chatPlaceholder">Choose a chat on the left, or press <strong>New chat</strong> to start one.</div>
    <header class="teams-main-head" id="chatHead" hidden>
      <button type="button" class="teams-win-btn" id="chatBack" title="Back to chats" aria-label="Back to chats"><i class="fa fa-arrow-left" aria-hidden="true"></i></button>
      <h2 id="chatTitle"></h2>
      <span class="chat-actions-slot" data-for="chatMessages"></span>
      <button type="button" class="button" id="chatPopOut" title="Pop out" aria-label="Pop out"><i class="fa fa-up-right-from-square" aria-hidden="true"></i><span class="btn-label"> Pop out</span></button>
      <a class="button" id="chatOpenTeams" target="_blank" rel="noopener" title="Open in Teams" aria-label="Open in Teams"><i class="ecw-teams-icon" aria-hidden="true"></i><span class="btn-label"> Open in Teams</span></a>
    </header>
    <div class="teams-win-list" id="chatMessages" hidden></div>
    <div id="chatComposer" hidden></div>
  </section>
</main>
<dialog class="teams-new" id="newChatDialog" aria-labelledby="newChatTitle">
  <form method="dialog" id="newChatDialogForm" autocomplete="off">
    <h2 id="newChatTitle">New chat</h2>
    <label for="ncTo">To</label>
    <div class="nc-to" id="ncToBox">
      <span id="ncChips"></span>
      <input id="ncTo" type="text" placeholder="Name or email" aria-describedby="ncHint" role="combobox" aria-expanded="false" aria-controls="ncSuggest" aria-autocomplete="list">
    </div>
    <ul class="nc-suggest" id="ncSuggest" role="listbox" hidden></ul>
    <p class="muted nc-hint" id="ncHint">One person: a one-to-one chat. Several: a group chat.</p>
    <div id="ncTopicRow" hidden>
      <label for="ncTopic">Group name <span class="muted">(optional)</span></label>
      <input id="ncTopic" type="text" maxlength="250" placeholder="e.g. Pump delivery – Athens">
    </div>
    <label for="ncMessage">First message <span class="muted">(optional)</span></label>
    <textarea id="ncMessage" rows="3" maxlength="4000" placeholder="Hi…"></textarea>
    <div class="nc-result" id="ncResult" role="status" hidden></div>
    <div class="nc-actions">
      <button type="button" class="button" id="ncCancel">Cancel</button>
      <button type="submit" class="button primary" id="ncStart">Start chat</button>
    </div>
  </form>
</dialog>
<?php // Chat pictures open in the app's preview (teams-dock.js). ?>
<script src="assets/floating-window.js?v=<?= filemtime(__DIR__ . '/assets/floating-window.js') ?>"></script>
<script src="assets/docViewers.js?v=<?= filemtime(__DIR__ . '/assets/docViewers.js') ?>"></script>
<script src="assets/filePreview.js?v=<?= filemtime(__DIR__ . '/assets/filePreview.js') ?>"></script>
<script src="assets/teams.js?v=<?= filemtime(__DIR__ . '/assets/teams.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
