<?php declare(strict_types=1);
/**
 * Email Case Workspace — the case timeline.
 *
 * Renders the frame only. The timeline is fetched from the API and drawn by
 * case.js, because hiding a column, renaming one, or switching the single
 * visible thread on a narrow screen all re-request the timeline — the gap
 * algorithm runs once, on the server, for exactly what is being shown.
 */

require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/case_settings.php';

$context = ecw_require_login();
$user    = $context['user'];
$orgId   = $context['organization_id'];
$caseId  = (int)($_GET['id'] ?? 0);
$navActive = 'cases';

$case = ecw_case_load((int)$orgId, $caseId);
// A case merged into another one: its old link leads to where everything went.
if ($case === null) {
    $gone = ecw_case_load((int)$orgId, $caseId, true);
    if ($gone !== null && !empty($gone['merged_into_case_id']) && ecw_case_visible((int)$orgId, (int)$user['id'], (int)$gone['merged_into_case_id'])) {
        ecw_redirect(ecw_web_base() . 'case.php?id=' . (int)$gone['merged_into_case_id']);
    }
}
if ($case === null || !ecw_case_visible((int)$orgId, (int)$user['id'], $caseId)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Case not found\n";
    exit;
}
// A case of another account (a link, a notification) switches to that account (lib/account.php).
require_once dirname(__DIR__) . '/lib/account.php';
$caseAcct = [(int)$orgId, $caseId];
$inAccount = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('cases') . '` c WHERE c.organization_id = ? AND c.id = ? AND '
    . ecw_case_account_sql('c', ecw_active_account_id((int)$orgId, (int)$user['id']), $caseAcct, (int)$user['id']));
$inAccount->execute($caseAcct);
if (!$inAccount->fetchColumn()) ecw_account_follow((int)$orgId, (int)$user['id'], ecw_case_account($caseId));
require_once dirname(__DIR__) . '/lib/audit.php';
ecw_audit_view('case.view', 'case', $caseId, ['case' => $case['case_number']]);
require_once dirname(__DIR__) . '/lib/security.php';
$hold = ecw_db()->prepare('SELECT legal_hold_at, legal_hold_note FROM `' . ecw_table('cases') . '`  WHERE id = ?');
$hold->execute([$caseId]);
$hold = $hold->fetch() ?: ['legal_hold_at' => null, 'legal_hold_note' => null];
$isAdmin = ($context['role'] ?? '') === 'admin';

$csrf = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($case['case_number']) ?> — <?= $h($case['name']) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="stylesheet" href="assets/case.css?v=<?= filemtime(__DIR__ . '/assets/case.css') ?>">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body class="case-body"
      data-csrf="<?= $h($csrf) ?>"
      data-user="<?= (int)$user['id'] ?>"
      data-lang="<?php require_once dirname(__DIR__) . '/lib/i18n.php'; echo $h(ecw_user_language((int)$user['id'])); ?>"
      data-case="<?= (int)$case['id'] ?>"
      data-timezone="<?php require_once dirname(__DIR__) . '/lib/timezone.php'; echo $h(ecw_tz_for_user((int)$user['id'], (int)$case['organization_id'])); ?>">

<?php require __DIR__ . '/partials/nav.php'; ?>

<header class="case-top">
  <div class="case-top-main">
    <div class="case-ident">
      <span class="case-badge" id="caseNumber"><?= $h($case['case_number']) ?></span>
      <h1 id="caseName" title="Double-click to rename"><?= $h($case['name']) ?></h1>
      <button type="button" id="caseRenameBtn" class="case-icon-btn" title="Rename case" aria-label="Rename case"><i class="fa fa-pen" aria-hidden="true"></i></button>
      <nav class="case-nav" id="caseNav" aria-label="Other cases" hidden>
        <a class="case-nav-btn" id="casePrevLink" aria-label="Previous case"><i class="fa fa-chevron-left" aria-hidden="true"></i></a>
        <span class="case-nav-pos" id="caseNavPos"></span>
        <a class="case-nav-btn" id="caseNextLink" aria-label="Next case"><i class="fa fa-chevron-right" aria-hidden="true"></i></a>
      </nav>
      <span class="case-hold-badge" id="caseHoldBadge"<?= $hold['legal_hold_at'] ? '' : ' hidden' ?> title="<?= $h($hold['legal_hold_note'] ? 'Legal hold: ' . $hold['legal_hold_note'] : 'Legal hold: this case cannot be deleted, and its emails cannot be deleted for good or taken out of it.') ?>"><i class="fa fa-scale-balanced" aria-hidden="true"></i> Legal hold</span>
    </div>
    <?php if (ecw_feature_allowed('next_actions')): require_once dirname(__DIR__) . '/lib/next_actions.php'; ?>
    <div class="cn-wrap">
      <button type="button" class="cn-pill" id="caseNext" aria-haspopup="dialog" aria-expanded="false" title="What happens next: click to change">
        <span class="cn-dot" aria-hidden="true"></span><span id="cnSummary">Next step</span>
      </button>
      <div class="cn-pop" id="cnPop" role="dialog" aria-label="What happens next" hidden>
        <label class="cn-field"><span class="cn-label">Next step</span>
          <input type="text" id="cnAction" maxlength="255" placeholder="What happens next?" autocomplete="off"></label>
        <div class="cn-row">
          <label class="cn-field"><span class="cn-label">Who</span>
            <select id="cnUser" class="ot-select"><option value="">—</option></select></label>
          <label class="cn-field"><span class="cn-label">Waiting on</span>
            <select id="cnWaiting" class="ot-select"><option value="">Nobody</option><?php foreach (ECW_WAITING_ON as $k => $label): ?><option value="<?= $h($k) ?>"><?= $h($label) ?></option><?php endforeach; ?></select></label>
          <label class="cn-field"><span class="cn-label">Look again</span>
            <input type="date" id="cnReview" class="case-due-input"></label>
        </div>
        <div class="cn-label cn-refs-label">References</div>
        <div class="cn-refs" id="cnRefs" aria-label="References"></div>
        <div class="cn-by" id="cnBy" hidden></div>
        <script type="application/json" id="cnKinds"><?= json_encode(ECW_REF_KINDS, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
      </div>
    </div>
    <?php endif; ?>
    <dl class="case-facts">
      <?php // The three groups and the company's own statuses in each (Settings → Case settings). ?>
      <div><dt>Status</dt><dd><select id="caseStatus" class="ot-select" data-i18n-ctx="status">
        <?php foreach (ecw_case_status_options((int)$orgId) as $opt): ?>
        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8') ?>" data-base="<?= $opt['base'] ?>" data-color="<?= $opt['color'] ?>"><?= $opt['custom'] ? '&nbsp;&nbsp;' : '' ?><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select></dd></div>
      <div><dt>Responsible</dt><dd><select id="caseOwner" class="ot-select"><option value="">Unassigned</option></select></dd></div>
      <?php if (ecw_local_features()): ?><div><dt>Handled by</dt><dd><select id="caseHandler" class="ot-select"><option value="">Unassigned</option></select></dd></div><?php endif; ?>
      <div><dt>Group</dt><dd><select id="caseGroup" class="ot-select"><option value="">No group</option></select></dd></div>
      <div><dt>Shared with</dt><dd><button type="button" id="caseSharingBtn" class="sharing-btn">…</button></dd></div>
      <div><dt>Due</dt><dd><input type="date" id="caseDue" class="case-due-input" aria-label="Due date"></dd></div>
      <div class="case-tags-fact"><dt>Tags</dt><dd><div class="tag-editor" id="caseTags"></div></dd></div>
      <div class="case-sla-fact" id="caseSlaFact" hidden><dt>Reply</dt><dd id="caseSla"></dd></div>
      <div><dt>Created</dt><dd id="caseCreated">—</dd></div>
      <div><dt>Last activity</dt><dd id="caseActivity">—</dd></div>
    </dl>
  </div>

  <div class="case-top-actions">
    <button type="button" id="casePanelToggle" title="Notes, follow-ups, Teams and summary"><i class="fa fa-note-sticky" aria-hidden="true"></i> Notes</button>
    <a class="case-icon-btn case-print-link" href="case-print.php?id=<?= (int)$case['id'] ?>" target="_blank" rel="noopener" title="Print or save as PDF" aria-label="Print or save as PDF"><i class="fa fa-print" aria-hidden="true"></i></a>
    <?php if (ecw_feature_allowed('export')): ?><button type="button" class="case-text-btn" id="caseExportBtn" data-href="api/index.php?action=case_export&amp;case_id=<?= (int)$case['id'] ?>" title="Export the whole case: emails (open in Outlook), files, notes, checklist and history, as a .zip"><i class="fa fa-file-export" aria-hidden="true"></i> Export</button><?php endif; ?>
    <button type="button" id="caseTeamsBtn" class="teams-btn" title="Chat in Teams with the people on this case"><i class="ecw-teams-icon" aria-hidden="true"></i> Teams</button>
    <button type="button" id="caseAssistantBtn" title="Ask AI about this case"><i class="ecw-ai-icon" aria-hidden="true"></i> Ask AI</button>
    <button type="button" id="caseFindBtn" class="case-icon-btn" title="Find in this case's emails (Ctrl+F)" aria-label="Find in this case's emails"><i class="fa fa-magnifying-glass" aria-hidden="true"></i></button>
    <button type="button" id="caseMergeBtn" class="case-icon-btn" title="Merge into another case" aria-label="Merge into another case"><i class="fa fa-code-merge" aria-hidden="true"></i></button>
    <?php if ($isAdmin): ?><button type="button" id="caseHoldBtn" class="case-icon-btn<?= $hold['legal_hold_at'] ? ' active' : '' ?>" data-held="<?= $hold['legal_hold_at'] ? '1' : '0' ?>" title="<?= $hold['legal_hold_at'] ? 'Release the legal hold' : 'Put on legal hold' ?>" aria-label="Legal hold"><i class="fa fa-scale-balanced" aria-hidden="true"></i></button><?php endif; ?>
    <button type="button" id="caseDeleteBtn" class="case-icon-btn danger" title="Delete case" aria-label="Delete case"><i class="fa fa-trash-can" aria-hidden="true"></i></button>
    <details class="menu columns-menu">
      <summary id="columnsSummary">Columns</summary>
      <div class="menu-body" id="columnToggles"></div>
    </details>
    <button type="button" id="addThreadBtn" class="primary">+ Add Thread</button>
  </div>
</header>
<?php if (ecw_feature_allowed('ai')): ?><section class="case-memory" id="caseMemory" aria-label="Solutions memory" hidden></section><?php endif; ?>

<div class="thread-picker" id="threadPicker" hidden>
  <span class="muted">Thread</span>
  <select id="threadPickerSelect" class="ot-select"></select>
</div>

<div class="case-content" id="caseContent">
  <div class="tl-scroll" id="timelineScroll">
    <div class="find-host case-find-host" id="caseFindHost"></div>
    <div class="timeline" id="timeline"></div>
    <div class="tl-empty" id="timelineEmpty" hidden>No visible threads. Link one with “+ Add Thread”.</div>
    <div class="tl-truncated" id="timelineTruncated" hidden></div>
  </div>

  <aside class="case-panel btn-scope" id="casePanel" aria-label="Notes and activity">
    <div class="case-panel-tabs" role="tablist">
      <button type="button" role="tab" data-tab="notes" class="active" aria-selected="true"><i class="fa fa-note-sticky" aria-hidden="true"></i> Notes <span class="tab-count" id="notesCount"></span></button>
      <?php if (ecw_feature_allowed('checklists')): ?><button type="button" role="tab" data-tab="checklist" aria-selected="false"><i class="fa fa-list-check" aria-hidden="true"></i> Checklist <span class="tab-count" id="checklistCount"></span></button><?php endif; ?>
      <?php if (ecw_feature_allowed('deals')): ?><button type="button" role="tab" data-tab="deals" aria-selected="false"><i class="fa fa-handshake" aria-hidden="true"></i> Deals <span class="tab-count" id="dealsCount"></span></button><?php endif; ?>
      <button type="button" role="tab" data-tab="followups" aria-selected="false"><i class="fa fa-clock" aria-hidden="true"></i> Follow-ups <span class="tab-count" id="followupsCount"></span></button>
      <?php require_once dirname(__DIR__) . '/lib/modules.php';
      $caseModuleTabs = array_values(array_filter(ecw_module_case_tabs(), static fn($t) => $t['feature'] === null || ecw_feature_allowed((string)$t['feature'])));
      foreach ($caseModuleTabs as $t): ?><button type="button" role="tab" data-tab="<?= htmlspecialchars($t['key'], ENT_QUOTES, 'UTF-8') ?>" aria-selected="false"><i class="fa <?= htmlspecialchars($t['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i> <?= htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8') ?> <span class="tab-count" data-tab-count="<?= htmlspecialchars($t['key'], ENT_QUOTES, 'UTF-8') ?>"></span></button><?php endforeach; ?>
      <button type="button" role="tab" data-tab="summary" aria-selected="false"><i class="ecw-ai-icon" aria-hidden="true"></i> Summary</button>
      <button type="button" role="tab" data-tab="history" aria-selected="false"><i class="fa fa-clock-rotate-left" aria-hidden="true"></i> History</button>
      <button type="button" class="case-panel-close" id="casePanelClose" title="Hide panel" aria-label="Hide panel"><i class="fa fa-angles-right" aria-hidden="true"></i></button>
    </div>

    <section class="case-panel-body" data-panel="notes" role="tabpanel">
      <p class="panel-hint"><i class="fa fa-lock" aria-hidden="true"></i> Internal: only people who can see this case read notes. Customers never do.</p>
      <div class="case-notes-ui" id="caseNotesUI"></div>
    </section>

    <section class="case-panel-body" data-panel="checklist" role="tabpanel" hidden>
      <div class="cl-progress" id="clProgress" hidden><div class="cl-bar"><span id="clBar"></span></div><span class="muted small" id="clProgressText"></span></div>
      <ul class="cl-list" id="clList"></ul>
      <form class="cl-add" id="clAddForm" autocomplete="off">
        <input type="text" id="clAddText" maxlength="255" placeholder="Add a step… (Enter)" aria-label="New step">
        <button type="submit" class="primary" aria-label="Add step"><i class="fa fa-plus" aria-hidden="true"></i></button>
      </form>
      <div class="cl-template" id="clTemplateRow" hidden>
        <select id="clTemplate" class="ot-select" aria-label="Case template"><option value="">Use a template…</option></select>
        <button type="button" id="clTemplateApply">Apply</button>
      </div>
      <p class="panel-hint"><a href="#" class="help-link" data-help="checklist"><i class="fa fa-circle-question" aria-hidden="true"></i> Checklists</a> Steps for this case: give each one a person and a date and they are reminded on the day. Everyone who can see the case can tick them; the History tab shows who did what.</p>
    </section>

    <?php if (ecw_feature_allowed('deals')): ?>
    <section class="case-panel-body" data-panel="deals" role="tabpanel" hidden>
      <div class="deals" id="dealsBody"><p class="muted">Loading…</p></div>
    </section>
    <?php endif; ?>

    <section class="case-panel-body" data-panel="followups" role="tabpanel" hidden>
      <ul class="followup-list" id="followupList"></ul>
      <form class="followup-form" id="followupForm" autocomplete="off">
        <strong>Remind me</strong>
        <div class="quick-times">
          <button type="button" data-in="tomorrow">Tomorrow 9:00</button>
          <button type="button" data-in="3d">In 3 days</button>
          <button type="button" data-in="week">Next Monday</button>
        </div>
        <label>When<input type="datetime-local" id="followupDue" required></label>
        <label>Note (optional)<input type="text" id="followupNote" maxlength="255" placeholder="e.g. chase the quote"></label>
        <label class="checkbox-label"><input type="checkbox" id="followupNoReply" checked> Only if nobody has replied by then</label>
        <div class="note-form-row"><span class="muted small" id="followupStatus" role="status"></span><button type="submit" class="primary">Add reminder</button></div>
      </form>
      <p class="panel-hint">When a customer replies, a <em>Waiting</em> case moves back to <em>Open</em> automatically and Responsible / Handled by are notified.</p>
    </section>

    <?php foreach ($caseModuleTabs as $t): ?>
    <section class="case-panel-body" data-panel="<?= htmlspecialchars($t['key'], ENT_QUOTES, 'UTF-8') ?>" role="tabpanel" hidden><div data-module-tab="<?= htmlspecialchars($t['key'], ENT_QUOTES, 'UTF-8') ?>"></div></section>
    <?php endforeach; ?>

    <section class="case-panel-body" data-panel="summary" role="tabpanel" hidden>
      <div class="ai-sugg-host" id="caseSuggestions" hidden></div>
      <div class="living-summary" id="livingSummary" hidden></div>
      <p class="panel-hint">The AI reads the latest emails and notes of this case and writes the status, open points and who is waiting on whom. Check it before you rely on it.</p>
      <button type="button" class="primary" id="summaryBtn"><i class="ecw-ai-icon" aria-hidden="true"></i> Summarize this case now</button>
      <div class="summary-output" id="summaryOutput" aria-live="polite"></div>
    </section>

    <section class="case-panel-body" data-panel="history" role="tabpanel" hidden>
      <ol class="history-list" id="historyList"><li class="muted">Loading…</li></ol>
    </section>
  </aside>


</div>

<!-- Add thread -->
<div class="overlay" id="addOverlay" hidden>
  <div class="dialog wide" role="dialog" aria-modal="true" aria-label="Add a thread to this case">
    <h2>Add Thread</h2>
    <?php if (ecw_private_mailboxes()): ?><p class="muted">Linking shares this entire conversation, including future messages and attachments, with everyone who can see this case (see “Shared with”).</p><?php endif; ?>
    <div class="tabs">
      <button type="button" class="tab active" data-tab="existing">Search existing email threads</button>
      <button type="button" class="tab" data-tab="new">Start new email conversation</button>
    </div>

    <div class="tab-body" data-body="existing">
      <input type="search" id="threadSearch" placeholder="Search by subject or participant address">
      <ul class="case-results" id="threadResults"></ul>
    </div>

    <div class="tab-body" data-body="new" hidden>
      <p class="muted">Opens the composer with the case number in the subject. The new
         conversation is linked to this case as soon as it is sent.</p>
      <label class="field"><span>From</span><select id="newThreadMailbox" class="ot-select"></select></label>
      <button type="button" id="startConversationBtn" class="primary">Open composer</button>
    </div>

    <footer><button type="button" id="addCancel">Close</button></footer>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- Legal hold (admins) -->
<div class="overlay" id="holdOverlay" hidden>
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="holdTitle">
    <h2 id="holdTitle"><?= $hold['legal_hold_at'] ? 'Release the legal hold?' : 'Put this case on legal hold?' ?></h2>
    <div id="holdOnText"<?= $hold['legal_hold_at'] ? ' hidden' : '' ?>>
      <p>While the hold is on, nobody can delete the case, take emails out of it, or delete its emails for good — and data retention skips it. Emails deleted in Outlook stay here.</p>
      <label for="holdNote">Reason (shown to admins and in the audit log)</label>
      <input type="text" id="holdNote" maxlength="255" placeholder="e.g. Dispute with supplier, claim no. 2026-114" style="width:100%">
    </div>
    <p id="holdOffText"<?= $hold['legal_hold_at'] ? '' : ' hidden' ?>>The case can then be deleted again and retention applies to it. <?= $hold['legal_hold_note'] ? 'Reason it was held: ' . $h($hold['legal_hold_note']) : '' ?></p>
    <footer>
      <button type="button" id="holdCancel">Cancel</button>
      <button type="button" id="holdConfirm" class="primary"><?= $hold['legal_hold_at'] ? 'Release hold' : 'Put on hold' ?></button>
    </footer>
  </div>
</div>
<?php endif; ?>

<!-- Delete case -->
<div class="overlay" id="deleteCaseOverlay" hidden>
  <div class="dialog" role="dialog" aria-modal="true" aria-label="Delete case">
    <h2>Delete this case?</h2>
    <p id="deleteCaseBody"></p>
    <p class="muted">This removes the case grouping only. Emails stay in the mailbox and are never deleted or moved.</p>
    <footer>
      <button type="button" id="deleteCaseCancel">Cancel</button>
      <button type="button" id="deleteCaseConfirm" class="danger">Delete case</button>
    </footer>
  </div>
</div>

<!-- Sharing -->
<div class="overlay" id="sharingOverlay" hidden>
  <div class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="sharingTitle">
    <h2 id="sharingTitle">Who can see this case</h2>
    <label class="radio-row"><input type="radio" name="caseVisibility" value="organization"> <span><strong>Everyone in the workspace</strong><br><span class="muted">Every member can open the case and read all its emails.</span></span></label>
    <label class="radio-row"><input type="radio" name="caseVisibility" value="members"> <span><strong>Only selected people</strong><br><span class="muted">The creator, Responsible, Handled by, the case's group, and the people ticked below. They read the whole conversation without being in To/Cc.</span></span></label>
    <div id="sharingPeopleBox">
      <input type="search" id="sharingSearch" placeholder="Find people">
      <ul class="people-list" id="sharingPeople"></ul>
    </div>
    <p class="muted" id="sharingGroupNote" hidden></p>
    <footer>
      <button type="button" id="sharingCancel">Cancel</button>
      <button type="button" id="sharingSave" class="primary">Save</button>
    </footer>
  </div>
</div>

<div class="toast" id="toast" hidden>
  <span class="toast-msg" id="toastMsg"></span>
  <button type="button" class="toast-action" id="toastAction" hidden></button>
</div>

<script src="assets/floating-window.js?v=<?= filemtime(__DIR__ . '/assets/floating-window.js') ?>"></script>
<script src="assets/docViewers.js?v=<?= filemtime(__DIR__ . '/assets/docViewers.js') ?>"></script>
<script src="assets/filePreview.js?v=<?= filemtime(__DIR__ . '/assets/filePreview.js') ?>"></script>
<script src="assets/ai-assistant.js?v=<?= filemtime(__DIR__ . '/assets/ai-assistant.js') ?>"></script>
<script src="assets/avatars.js?v=<?= filemtime(__DIR__ . '/assets/avatars.js') ?>"></script>
<script src="assets/mentions.js?v=<?= filemtime(__DIR__ . '/assets/mentions.js') ?>" defer></script>
<script src="assets/case-panel.js?v=<?= filemtime(__DIR__ . '/assets/case-panel.js') ?>" defer></script>
<script src="assets/case-sharing.js?v=<?= filemtime(__DIR__ . '/assets/case-sharing.js') ?>"></script>
<script src="assets/notes-ui.js?v=<?= filemtime(__DIR__ . '/assets/notes-ui.js') ?>"></script>
<script src="assets/case.js?v=<?= filemtime(__DIR__ . '/assets/case.js') ?>"></script>
<script src="assets/find-in-mail.js?v=<?= filemtime(__DIR__ . '/assets/find-in-mail.js') ?>"></script>
<script src="assets/suggestions.js?v=<?= filemtime(__DIR__ . '/assets/suggestions.js') ?>"></script>
<?php if (ecw_feature_allowed('checklists')): ?><script src="assets/case-checklist.js?v=<?= filemtime(__DIR__ . '/assets/case-checklist.js') ?>"></script><?php endif; ?>
<?php if ($isAdmin): ?><script src="assets/legal-hold.js?v=<?= filemtime(__DIR__ . '/assets/legal-hold.js') ?>"></script><?php endif; ?>
<?php if (ecw_feature_allowed('deals')): ?><script src="assets/case-deals.js?v=<?= filemtime(__DIR__ . '/assets/case-deals.js') ?>"></script><?php endif; ?>
<script src="assets/case-extras.js?v=<?= filemtime(__DIR__ . '/assets/case-extras.js') ?>"></script>
<?php if (ecw_feature_allowed('ai')): ?><script src="assets/case-memory.js?v=<?= filemtime(__DIR__ . '/assets/case-memory.js') ?>"></script><?php endif; ?>
<?php if (ecw_feature_allowed('ai')): ?><script src="assets/translate.js?v=<?= filemtime(__DIR__ . '/assets/translate.js') ?>"></script><?php endif; ?>
<script src="assets/case-nav.js?v=<?= filemtime(__DIR__ . '/assets/case-nav.js') ?>"></script>
<script src="assets/case-compact.js?v=<?= filemtime(__DIR__ . '/assets/case-compact.js') ?>"></script>
<?php if (ecw_feature_allowed('next_actions') && ecw_feature_allowed('ai')): ?><script src="assets/case-review.js?v=<?= filemtime(__DIR__ . '/assets/case-review.js') ?>"></script><?php endif; ?>
<?php if (ecw_feature_allowed('next_actions')): ?><script src="assets/case-next.js?v=<?= filemtime(__DIR__ . '/assets/case-next.js') ?>"></script><?php endif; ?>
</body>
</html>
