<?php declare(strict_types=1);
/**
 * Email Case Workspace — the mail UI shell.
 *
 * Renders the three-pane frame and nothing else: every list, message and
 * composer is filled in by app.js from the API, because the step requires the
 * mailbox to open from the local database without a per-row provider call and
 * an SSR'd first page would still have to be re-rendered by the same code on
 * the first scroll.
 *
 * The only data inlined into the page is the CSRF token and the signed-in
 * user's id — everything else is fetched.
 */

require_once dirname(__DIR__) . '/lib/session.php';

// The shared guard: a browser lands on login.php, fetch() gets a 401.
$context = ecw_require_login();
$user    = $context['user'];
// First day: nobody's mailbox is connected yet → the Get started checklist, once per session.
if (PHP_SAPI !== 'cli' && empty($_SESSION['ecw_gs_seen']) && !isset($_GET['thread']) && !isset($_GET['draft']) && !isset($_GET['compose_case'])) {
    require_once dirname(__DIR__) . '/lib/getting_started.php';
    $gsMb = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('mailboxes') . "` WHERE organization_id = ? AND connected_by_user_id = ? AND sync_status <> 'disconnected'");
    $gsMb->execute([(int)$context['organization_id'], (int)$user['id']]);
    $_SESSION['ecw_gs_seen'] = true;
    if ((int)$gsMb->fetchColumn() === 0 && !ecw_getting_started_hidden((int)$user['id'])) ecw_redirect(ecw_web_base() . 'get-started.php');
}
// A link to an email of another account (?mailbox=, ?thread=) switches to that account first (lib/account.php).
require_once dirname(__DIR__) . '/lib/account.php';
$followMailbox = (int)($_GET['mailbox'] ?? 0);
if ($followMailbox === 0 && (int)($_GET['thread'] ?? 0) > 0) {
    $ft = ecw_db()->prepare('SELECT mailbox_id FROM `' . ecw_table('email_threads') . '` WHERE id = ?');
    $ft->execute([(int)$_GET['thread']]);
    $followMailbox = (int)$ft->fetchColumn();
}
if ($followMailbox > 0) ecw_account_follow((int)$context['organization_id'], (int)$user['id'], $followMailbox);
$csrf    = ecw_csrf_token();
$navActive = 'mail';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mail — Case Workspace</title>
<link rel="stylesheet" href="assets/vendor/quill/quill.snow.css">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="stylesheet" href="assets/auth.css">
<script>
(function () {
  try {
    var root = document.documentElement.style;
    // The details pane only ever shows once a thread is open, so its stored
    // width/collapsed state is only worth restoring pre-paint when this load
    // is actually going to open one straight away (handleEntryParams in
    // app.js does that when the URL carries ?thread=) — otherwise the :root
    // default of 0px is correct and restoring a stale width would just
    // flash the pane open before app.js hides it again.
    var willOpenThread = /(?:^|[?&])thread=/.test(window.location.search);
    ['nav', 'list', 'details'].forEach(function (key) {
      if (key === 'details' && !willOpenThread) return;
      var cssVar = key === 'nav' ? '--ot-nav-w' : key === 'list' ? '--ot-list-w' : '--ot-details-w';
      var collapsed = localStorage.getItem('ot.pane.' + key + '.c') === '1';
      var stored = localStorage.getItem('ot.pane.' + key + '.w');
      // Folders/Messages collapse to a 52px icon rail (RAIL_W in paneResizerInit.js); details to 0.
      if (collapsed) { root.setProperty(cssVar, key === 'details' ? '40px' : '52px'); }
      else if (stored) { root.setProperty(cssVar, stored + 'px'); }
    });
  } catch (e) {}
})();
</script>
</head>
<?php
// ?thread_only=1: just the conversation, for the pop-up window (mail-popout.js)
// and "Open in new window". Everything else on the page is hidden.
$threadOnly = isset($_GET['thread_only']);
?>
<body data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"
      data-deals="<?= ecw_feature_allowed('deals') ? '1' : '' ?>"
      data-user="<?= (int)$user['id'] ?>" data-lang="<?php require_once dirname(__DIR__) . '/lib/i18n.php'; echo htmlspecialchars(ecw_user_language((int)$user['id']), ENT_QUOTES, 'UTF-8'); ?>"<?= $threadOnly ? ' class="thread-only"' : '' ?>>

<?php require __DIR__ . '/partials/nav.php'; ?>

<header class="topbar">

  <button type="button" id="navToggle" class="nav-toggle icon-btn" aria-label="Folders and cases" title="Folders and cases"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>

  <form class="search" id="searchForm" role="search">
    <span class="search-field">
      <button type="button" id="searchScopeBtn" class="search-scope-btn" aria-haspopup="menu" aria-expanded="false" title="Search in: All folders" aria-label="Search in: All folders"><i class="fa fa-folder-tree" aria-hidden="true"></i><i class="fa fa-caret-down search-scope-caret" aria-hidden="true"></i></button>
      <div id="searchScopeMenu" class="search-scope-menu" role="menu" hidden></div>
      <input type="search" id="searchInput" name="q" autocomplete="off"
             placeholder="Search emails, people, or filenames">
      <button type="submit" class="search-submit icon-btn" aria-label="Search" title="Search"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg></button>
    </span>
    <details class="search-filters" id="searchFilters"><summary aria-label="Search filters" title="Search filters" class="icon-btn"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg></summary>
      <div class="search-filter-panel">
        <label>From<input id="filterFrom" type="text" placeholder="name, address or @company.com"></label>
        <label>To<input id="filterTo" type="text"></label>
        <label>Cc<input id="filterCc" type="text"></label>
        <label>Subject<input id="filterSubject" type="text"></label>
        <label>Attachment name<input id="filterFilename" type="text" placeholder="drawing, *.step, offer*.pdf"></label>
        <label>File type<input id="filterExt" type="text" list="filterExtList" placeholder="step, pdf, dwg…"></label>
        <datalist id="filterExtList"><option value="step"><option value="stp"><option value="igs"><option value="dwg"><option value="dxf"><option value="pdf"><option value="docx"><option value="xlsx"><option value="pptx"><option value="zip"><option value="jpg"><option value="png"></datalist>
        <label>After<input id="filterAfter" type="date"></label>
        <label>Before<input id="filterBefore" type="date"></label>
        <label>Read<select id="filterRead"><option value="">Any</option><option value="unread">Unread</option><option value="read">Read</option></select></label>
        <label>Direction<select id="filterDirection"><option value="">Any</option><option value="received">Received</option><option value="sent">Sent by us</option></select></label>
        <label>Case<input id="filterCase" type="text" placeholder="CASE-00037 or a case name"></label>
        <label>Attachment larger than<select id="filterLarger"><option value="">Any size</option><option value="1mb">1 MB</option><option value="5mb">5 MB</option><option value="10mb">10 MB</option><option value="25mb">25 MB</option></select></label>
        <label class="search-filter-check"><input id="filterAttachment" type="checkbox"> Has attachments</label>
        <label class="search-filter-check"><input id="filterFlagged" type="checkbox"> Flagged</label>
        <button type="button" id="applySearchFilters">Apply filters</button>
        <a href="#" class="help-link" data-help="search"><i class="fa fa-circle-question" aria-hidden="true"></i> Search tips</a>
      </div>
    </details>
    <button type="button" id="searchClear" hidden>Clear</button>
  </form>

  <div class="topbar-right">
    <?php // The account is chosen at the top of the page (partials/nav.php); this list only feeds the scripts. ?>
    <select id="mailboxSelect" class="ot-select" aria-label="Mailbox" hidden></select>
    <button type="button" id="mailAssistantBtn"><i class="ecw-ai-icon" aria-hidden="true"></i> Ask AI</button>
    <button type="button" id="composeBtn" class="primary">Compose</button>
    <details class="menu">
      <summary aria-label="Settings" title="Settings" class="icon-btn"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></summary>
      <div class="menu-body">
        <label>Thread order
          <select id="threadOrder" class="ot-select">
            <option value="asc">Oldest first</option>
            <option value="desc">Newest first</option>
          </select>
        </label>
        <a href="settings/appearance.php">Appearance…</a>
        <a href="settings/email.php">Email &amp; writing…</a>
        <a id="signatureBtn" href="settings/signatures.php">Signatures…</a>
      </div>
    </details>
  </div>
</header>

<div class="nav-backdrop" id="navBackdrop" hidden></div>
<main class="panes">
  <nav class="pane nav" id="navPane" aria-label="Folders and cases">
    <div class="pane-toolbar">
      <button type="button" id="navCollapseBtn" class="pane-collapse-btn" aria-expanded="true" aria-label="Collapse folders and cases">&lsaquo;</button>
    </div>
    <div class="nav-section">
      <div class="nav-title">Mail</div>
      <button type="button" class="nav-sugg-btn" id="suggestionsBtn" hidden><i class="ecw-ai-icon" aria-hidden="true"></i><span>Suggestions</span><span class="nav-sugg-count" id="suggestionsCount"></span></button>
      <ul id="systemFolders" class="nav-list"></ul>
    </div>
    <div class="nav-section">
      <div class="nav-title nav-title-row">Folders <button type="button" id="newFolderBtn" class="nav-title-btn" title="New folder (right-click a folder for more)" aria-label="New folder"><i class="fa fa-plus" aria-hidden="true"></i></button></div>
      <ul id="userFolders" class="nav-list"></ul>
    </div>
    <div class="nav-section">
      <div class="nav-title">Cases</div>
      <ul class="nav-list">
        <li><button type="button" class="nav-item" data-filter="is:linked" title="Linked mail"><i class="ui-icon fa fa-link" aria-hidden="true"></i>Linked mail</button></li>
        <li><button type="button" class="nav-item" data-filter="is:unlinked" title="Unlinked mail"><i class="ui-icon fa fa-link-slash" aria-hidden="true"></i>Unlinked mail</button></li>
      </ul>
    </div>
    <div class="nav-status-row">
      <div class="nav-status" id="mailboxStatus"></div>
      <button type="button" class="sync-now-btn" id="syncNowBtn" title="Sync now" aria-label="Sync now"><i class="fa fa-arrows-rotate" aria-hidden="true"></i></button>
    </div>
  </nav>

  <section class="pane list" id="listPane" aria-label="Messages">
    <div class="list-rail" role="toolbar" aria-label="Messages (collapsed)">
      <button type="button" id="listExpandBtn" class="rail-btn" title="Expand messages" aria-label="Expand messages"><i class="fa fa-angles-right" aria-hidden="true"></i></button>
      <button type="button" class="rail-btn rail-filter" data-filter="is:unread" title="Unread" aria-label="Unread"><i class="fa fa-envelope" aria-hidden="true"></i></button>
      <button type="button" class="rail-btn rail-filter" data-filter="has:attachment" title="Attachments" aria-label="Attachments"><i class="fa fa-paperclip" aria-hidden="true"></i></button>
      <button type="button" class="rail-btn rail-filter" data-filter="is:unlinked" title="No case" aria-label="No case"><i class="fa fa-link-slash" aria-hidden="true"></i></button>
      <button type="button" class="rail-btn rail-filter" data-filter="is:flagged" title="Flagged" aria-label="Flagged"><i class="fa fa-flag" aria-hidden="true"></i></button>
      <span class="list-rail-title" id="listRailTitle" aria-hidden="true"></span>
    </div>
    <div class="list-header">
      <span id="listTitle">Inbox</span>
      <div class="list-header-right">
        <span id="listCount" class="muted"></span>
        <button type="button" id="listCollapseBtn" class="pane-collapse-btn" aria-expanded="true" aria-label="Collapse messages">&lsaquo;</button>
      </div>
    </div>
    <div class="quick-filters">
      <button type="button" class="chip" data-filter="is:unread">Unread</button>
      <button type="button" class="chip" data-filter="has:attachment">Attachments</button>
      <button type="button" class="chip" data-filter="is:unlinked">No case</button>
      <button type="button" class="chip" data-filter="is:flagged"><i class="fa fa-flag" aria-hidden="true"></i> Flagged</button>
    </div>
    <div class="claim-filters" id="claimFilters" role="group" aria-label="Who handles it" hidden>
      <button type="button" class="chip active" data-claim="">All</button>
      <button type="button" class="chip" data-claim="mine">Mine</button>
      <button type="button" class="chip" data-claim="unclaimed">Unassigned</button>
      <button type="button" class="chip" data-claim="others">Others</button>
    </div>
    <div class="import-bar" id="importBar" role="status" hidden><span class="ai-spin" aria-hidden="true"></span><span class="import-text">Importing your mailbox…</span></div>
    <?php // A wide list (dragged wider): one line per email in columns, like Outlook (app.css @container maillist). ?>
    <div class="list-columns" aria-hidden="true"><span>From</span><span>Subject</span><span></span><span>Received</span></div>
    <ol class="thread-list" id="threadList"></ol>
    <div class="list-footer" id="listFooter"></div>
  </section>

  <section class="pane reading" id="readingPane" aria-label="Reading pane">
    <button type="button" id="mailListBack" class="mobile-mail-back"><i class="fa fa-arrow-left" aria-hidden="true"></i> Emails</button>
    <div class="empty" id="readingEmpty">Select a conversation</div>
    <article class="thread" id="threadView" hidden>
      <header class="thread-header">
        <h1 id="threadSubject"></h1>
        <div class="thread-actions">
          <span class="mail-action-group" role="group" aria-label="Conversation">
            <button type="button" id="threadArchiveBtn" class="thread-icon-btn" title="Archive" aria-label="Archive"><i class="fa fa-box-archive" aria-hidden="true"></i></button>
            <button type="button" id="threadDeleteBtn" class="thread-icon-btn" title="Delete (Del)" aria-label="Delete"><i class="fa fa-trash-can" aria-hidden="true"></i></button>
            <button type="button" id="threadMoveBtn" class="thread-icon-btn" title="Move to folder" aria-label="Move to folder" aria-haspopup="dialog"><i class="fa fa-folder-tree" aria-hidden="true"></i></button>
            <button type="button" id="threadFlagBtn" class="thread-icon-btn" title="Flag" aria-label="Flag" aria-pressed="false"><i class="fa-regular fa-flag" aria-hidden="true"></i></button>
          </span>
          <span id="threadCase" class="case-badge" hidden></span>
          <span id="threadClaim" class="thread-claim" hidden></span>
          <span class="thread-tools">
            <button type="button" id="threadLaterBtn" title="Snooze or set a reminder" aria-haspopup="true" aria-expanded="false"><i class="fa fa-clock" aria-hidden="true"></i> Later</button>
            <div class="tool-menu btn-scope" id="threadLaterMenu" hidden role="dialog" aria-label="Snooze or remind">
              <strong>Snooze until</strong>
              <button type="button" class="menu-row" data-snooze="later">Later today <span class="muted">+3 h</span></button>
              <button type="button" class="menu-row" data-snooze="tomorrow">Tomorrow <span class="muted">9:00</span></button>
              <button type="button" class="menu-row" data-snooze="week">Next week <span class="muted">Mon 9:00</span></button>
              <button type="button" class="menu-row" data-snooze="unsnooze" hidden id="unsnoozeBtn">Unsnooze now</button>
              <hr>
              <strong>Remind me</strong>
              <label>When<input type="datetime-local" id="remindDue"></label>
              <label>Note<input type="text" id="remindNote" maxlength="255" placeholder="optional"></label>
              <label class="checkbox-label"><input type="checkbox" id="remindNoReply" checked> Only if no reply by then</label>
              <button type="button" class="primary" id="remindSave">Set reminder</button>
              <ul class="followup-list" id="threadReminders"></ul>
            </div>
          </span>
          <button type="button" id="threadFindBtn" class="thread-icon-btn" title="Find in this conversation (Ctrl+F)" aria-label="Find in this conversation"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg></button>
          <button type="button" id="threadPrintBtn" class="thread-icon-btn export-only" title="Print or save as PDF" aria-label="Print or save as PDF"><i class="fa fa-print" aria-hidden="true"></i></button>
          <button type="button" id="threadPopoutBtn" class="popout-btn" title="Open this conversation in a pop-up window" aria-label="Open in a pop-up window"><i class="fa fa-up-right-from-square" aria-hidden="true"></i> Pop out</button>
          <button type="button" id="threadUnreadBtn" title="Mark this conversation as unread"><i class="fa fa-envelope" aria-hidden="true"></i> Mark unread</button>
          <button type="button" id="threadNotesBtn" class="notes-toggle" title="Internal notes on this conversation" aria-controls="notesPane" aria-expanded="false"><i class="fa fa-comments" aria-hidden="true"></i> Notes <span class="notes-count" id="threadNotesCount"></span></button>
          <button type="button" id="detailsToggleBtn" class="details-toggle" title="Show details" aria-label="Show details" aria-controls="detailsPane" aria-expanded="false"><i class="fa fa-circle-info" aria-hidden="true"></i> Details</button>
          <button type="button" id="teamsThreadBtn" class="teams-btn" title="Chat in Teams with the people on this email"><i class="ecw-teams-icon" aria-hidden="true"></i> Teams</button>
          <button type="button" id="addToCaseBtn">Add to Case</button>
          <a id="openCaseBtn" class="thread-link-btn" href="#" title="Open the case (Ctrl+click, middle-click or right-click: in a new tab)" hidden>Open Case</a>
          <button type="button" id="removeFromCaseBtn" hidden>Remove from Case</button>
        </div>
      </header>
      <div class="presence-banner" id="presenceBanner" hidden role="status"><i class="fa fa-pen" aria-hidden="true"></i> <span id="presenceText"></span></div>
      <div class="find-host" id="threadFindHost"></div>
      <div class="shared-drafts" id="sharedDrafts" hidden></div>
      <div class="ai-sugg-host" id="threadSuggestions" hidden></div>
      <div id="messageList"></div>
    </article>
  </section>

  <section class="pane details" id="detailsPane" aria-label="Thread details" hidden>
    <div class="details-rail" role="toolbar" aria-label="Thread details (collapsed)">
      <button type="button" id="detailsExpandBtn" class="rail-btn" title="Show details" aria-label="Show details"><i class="fa fa-angles-left" aria-hidden="true"></i></button>
      <span class="list-rail-title" aria-hidden="true">Details</span>
    </div>
    <div class="pane-toolbar">
      <button type="button" id="detailsCollapseBtn" class="pane-collapse-btn" aria-expanded="true" aria-label="Collapse thread details">&rsaquo;</button>
      <button type="button" id="detailsCloseBtn" class="details-close" aria-label="Close details" title="Close details"><i class="fa fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="details-body" id="detailsBody"></div>
    <div class="notes-pane btn-scope" id="notesPane" aria-label="Internal notes" hidden></div>
  </section>
</main>

<!-- Composer -->
<div class="composer-window" id="composerOverlay" hidden>
  <div class="composer" role="dialog" aria-modal="false" aria-label="Compose message">
    <header class="composer-header">
      <span id="composerTitle">New message</span>
      <span class="composer-header-actions">
        <button type="button" id="composerShare" aria-pressed="false" hidden title="Let colleagues who see this conversation open and edit this draft" aria-label="Write together: share this draft"><i class="fa fa-user-group" aria-hidden="true"></i></button>
        <button type="button" id="composerMinimize" aria-label="Minimize to a tab at the bottom" title="Minimize — keeps the draft as a tab at the bottom">&#8211;</button>
        <button type="button" id="composerPopout" aria-label="Open in a separate window" title="Open in a separate window"><i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i></button>
        <button type="button" id="composerClose" aria-label="Close: save to Drafts or delete" title="Close: save to Drafts or delete">&times;</button>
      </span>
    </header>
    <?php // Writing together: who else is answering, who holds the shared draft (app.js / mail-extras.js). ?>
    <div class="composer-banner" id="composerLockBanner" role="status" hidden></div>
    <div class="composer-banner info" id="composerPresence" role="status" hidden></div>
    <div class="composer-banner" id="composerDraftBanner" role="status" hidden></div>

    <div class="composer-workspace"><section class="composer-edit">
    <div class="composer-fields">
      <div class="field-row">
        <label class="field"><span>To</span><input type="text" id="fieldTo" autocomplete="off"></label>
        <button type="button" id="toggleCcBcc" class="cc-bcc-toggle">Cc/Bcc</button>
      </div>
      <div id="ccBccFields" hidden>
        <label class="field"><span>Cc</span><input type="text" id="fieldCc" autocomplete="off"></label>
        <label class="field"><span>Bcc</span><input type="text" id="fieldBcc" autocomplete="off"></label>
      </div>
      <label class="field"><span>Subject</span><input type="text" id="fieldSubject"></label>
    </div>
    <?php // Attachments right under the subject, before the text, as Outlook shows them. ?>
    <div class="composer-attachments" id="composerAttachments" aria-label="Attachments"></div>

    <?php // app.js moves these to the right end of the editor's toolbar: one row of tools. ?>
    <span class="composer-tools ql-formats" id="composerTools">
      <button type="button" id="templateBtn" class="reply-assistant-btn" title="Insert a template"><i class="fa fa-file-lines" aria-hidden="true"></i><span class="tool-label">Templates</span></button>
      <button type="button" id="teamsMeetingBtn" class="reply-assistant-btn" title="Add a Teams meeting link to this email (and invite the recipients)"><i class="ecw-teams-icon" aria-hidden="true"></i><span class="tool-label">Meeting</span></button>
      <button type="button" id="replyAssistantBtn" class="reply-assistant-btn" hidden title="Draft the reply with AI"><i class="ecw-ai-icon" aria-hidden="true"></i><span class="tool-label">AI</span></button>
    </span>
    <div id="editor"></div>
    <details id="composeQuoteDetails" class="compose-quote" hidden><summary title="Expand and edit previous messages">… Previous message · edit inline</summary><p class="muted">You can add comments below the original points. Use the text-color button for red answers.</p><div id="quoteEditor"></div></details>
    <div id="composeSignaturePreview" class="compose-signature-preview" title="Signature: click to show it all" tabindex="0" role="button" aria-expanded="false"></div>

    </section><div id="composerDivider" class="composer-divider" role="separator" aria-label="Resize answer and history" aria-orientation="vertical" tabindex="0" hidden></div><aside class="composer-history" id="composerHistory" hidden aria-label="Previous messages"></aside></div>
    <progress id="sendProgress" hidden aria-label="Sending message"></progress>
    <footer class="composer-footer">
      <div class="composer-left">
        <label class="file-button" title="Attach files">
          <i class="fa fa-paperclip" aria-hidden="true"></i> Attach<input type="file" id="fileInput" multiple hidden>
        </label>
        <label class="compose-signature-select" title="Signature"><i class="fa fa-signature" aria-hidden="true"></i><select id="composeSignatureSelect" aria-label="Signature"><option value="">No signature</option></select></label>
        <span class="muted" id="draftStatus"></span>
      </div>
      <div class="composer-right">
        <button type="button" id="discardBtn">Discard</button>
        <span class="send-split">
          <button type="button" id="sendBtn" class="primary">Send</button><button type="button" id="sendLaterBtn" class="primary send-later-btn" aria-haspopup="true" aria-expanded="false" title="Send later" aria-label="Send later"><i class="fa fa-chevron-down" aria-hidden="true"></i></button>
          <div class="send-later-menu" id="sendLaterMenu" role="menu" hidden>
            <strong>Send later</strong>
            <button type="button" role="menuitem" data-later="tomorrow8"></button>
            <button type="button" role="menuitem" data-later="monday8"></button>
            <label>Pick a time<input type="datetime-local" id="sendLaterAt"></label>
            <button type="button" class="primary" id="sendLaterPick">Schedule</button>
          </div>
        </span>
      </div>
    </footer>
  </div>
</div>

<!-- Case picker -->
<div class="overlay" id="caseOverlay" hidden>
  <div class="dialog" role="dialog" aria-modal="true" aria-label="Add to case">
    <?php if (ecw_private_mailboxes()): ?><p class="muted">Linking shares this entire conversation, including future messages and attachments, with everyone who can see the case.</p><?php endif; ?>
    <h2>Add to Case</h2>
    <div class="case-suggest" id="caseSuggest" hidden><strong class="small"><i class="ecw-ai-icon" aria-hidden="true"></i> Suggested by AI: this email belongs to</strong><div id="caseSuggestList"></div></div>
    <input type="search" id="caseSearch" placeholder="Search by number, name or participant">
    <div class="case-status-filters" role="group" aria-label="Case status">
      <button type="button" data-case-status="open" aria-pressed="true">Open</button>
      <button type="button" data-case-status="waiting" aria-pressed="false">Waiting</button>
      <button type="button" data-case-status="closed" aria-pressed="false">Closed</button>
      <button type="button" data-case-status="all" aria-pressed="false">All</button>
    </div>
    <ul class="case-results" id="caseResults"></ul>
    <div class="case-new">
      <div class="case-new-line">
        <input type="text" id="newCaseName" placeholder="…or name a new case">
        <?php // The group, with its colour, on the name's line (app.js fills it; the select keeps the value). ?>
        <div class="group-pick" id="newCaseGroupPick" hidden>
          <button type="button" class="group-pick-btn" id="newCaseGroupBtn" aria-haspopup="true" aria-expanded="false" title="Group of the new case"><span class="group-dot"></span><span class="group-pick-name">No group</span><i class="fa fa-chevron-down" aria-hidden="true"></i></button>
          <div class="group-pick-menu" id="newCaseGroupMenu" role="menu" hidden></div>
        </div>
        <select id="newCaseGroup" class="ot-select" aria-label="Group of the new case" hidden><option value="0">No group</option></select>
      </div>
      <select id="newCaseTemplate" class="ot-select" aria-label="Start from a template" hidden><option value="0">No template</option></select>
      <button type="button" id="createCaseBtn">Create &amp; link</button>
    </div>
    <footer><button type="button" id="caseCancel">Cancel</button></footer>
  </div>
</div>

<!-- Signature -->
<div class="overlay" id="signatureOverlay" hidden>
  <div class="dialog wide" role="dialog" aria-modal="true" aria-label="Signature">
    <h2>Signature</h2>
    <div class="sig-grid">
      <label><span>Display name</span><input type="text" id="sigName"></label>
      <label><span>Title</span><input type="text" id="sigTitle"></label>
      <label><span>Company</span><input type="text" id="sigCompany"></label>
      <label><span>Phone</span><input type="text" id="sigPhone"></label>
    </div>
    <div id="sigEditor"></div>
    <footer>
      <button type="button" id="sigCancel">Cancel</button>
      <button type="button" id="sigSave" class="primary">Save</button>
    </footer>
  </div>
</div>

<div class="toast" id="toast" hidden></div>

<?php if (isset($_GET['composer_only'])): ?>
<style>body > :not(#composerOverlay):not(.toast):not(.undo-toast):not(script):not(style) { display:none !important; } #composerOverlay { inset:0 !important; width:100% !important; height:100vh !important; max-height:100vh; resize:none; border-radius:0 !important; } #composerOverlay .composer-header { display:none; }</style>
<?php if (isset($_GET['popout'])): ?>
<?php // Its own window: keep the title and ✕ (close = save to Drafts), drop minimize and pop-out. ?>
<style>#composerOverlay .composer-header { display:flex; } #composerMinimize, #composerPopout { display:none !important; }</style>
<?php endif; ?>
<?php endif; ?>
<script src="assets/floating-window.js?v=<?= filemtime(__DIR__ . '/assets/floating-window.js') ?>"></script>
<script src="assets/paneResizer.js?v=<?= filemtime(__DIR__ . '/assets/paneResizer.js') ?>"></script>
<script src="assets/paneResizerInit.js?v=<?= filemtime(__DIR__ . '/assets/paneResizerInit.js') ?>"></script>
<script src="assets/threadDetailsPane.js?v=<?= filemtime(__DIR__ . '/assets/threadDetailsPane.js') ?>"></script>
<script src="assets/docViewers.js?v=<?= filemtime(__DIR__ . '/assets/docViewers.js') ?>"></script>
<script src="assets/filePreview.js?v=<?= filemtime(__DIR__ . '/assets/filePreview.js') ?>"></script>
<script src="assets/vendor/quill/quill.js"></script>
<script src="assets/ai-assistant.js?v=<?= filemtime(__DIR__ . '/assets/ai-assistant.js') ?>"></script>
<?php if (ecw_feature_allowed('ai')): ?><script src="assets/translate.js?v=<?= filemtime(__DIR__ . '/assets/translate.js') ?>"></script><?php endif; ?>
<script src="assets/image-tools.js?v=<?= filemtime(__DIR__ . '/assets/image-tools.js') ?>"></script>
<?php if (ecw_feature_allowed('spelling')): ?><script src="assets/spelling.js?v=<?= filemtime(__DIR__ . '/assets/spelling.js') ?>"></script><?php endif; ?>
<script src="assets/recipient-chips.js?v=<?= filemtime(__DIR__ . '/assets/recipient-chips.js') ?>"></script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
<script src="assets/mentions.js?v=<?= filemtime(__DIR__ . '/assets/mentions.js') ?>"></script>
<script src="assets/notes-ui.js?v=<?= filemtime(__DIR__ . '/assets/notes-ui.js') ?>"></script>
<script src="assets/find-in-mail.js?v=<?= filemtime(__DIR__ . '/assets/find-in-mail.js') ?>"></script>
<script src="assets/suggestions.js?v=<?= filemtime(__DIR__ . '/assets/suggestions.js') ?>"></script>
<?php if (ecw_feature_allowed('ai')): ?><script src="assets/ai-working.js?v=<?= filemtime(__DIR__ . '/assets/ai-working.js') ?>"></script><?php endif; ?>
<script src="assets/shared-inbox.js?v=<?= filemtime(__DIR__ . '/assets/shared-inbox.js') ?>"></script>
<script src="assets/mail-extras.js?v=<?= filemtime(__DIR__ . '/assets/mail-extras.js') ?>"></script>
<script src="assets/meetings.js?v=<?= filemtime(__DIR__ . '/assets/meetings.js') ?>"></script>
<script src="assets/compose-meeting.js?v=<?= filemtime(__DIR__ . '/assets/compose-meeting.js') ?>"></script>
<script src="assets/mail-actions.js?v=<?= filemtime(__DIR__ . '/assets/mail-actions.js') ?>"></script>
<script src="assets/mail-select.js?v=<?= filemtime(__DIR__ . '/assets/mail-select.js') ?>"></script>
<script src="assets/folder-manage.js?v=<?= filemtime(__DIR__ . '/assets/folder-manage.js') ?>"></script>
<script src="assets/folder-order.js?v=<?= filemtime(__DIR__ . '/assets/folder-order.js') ?>"></script>
<script src="assets/mail-popout.js?v=<?= filemtime(__DIR__ . '/assets/mail-popout.js') ?>"></script>
<script src="assets/mail-context.js?v=<?= filemtime(__DIR__ . '/assets/mail-context.js') ?>"></script>
</body>
</html>
