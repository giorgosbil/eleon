<?php declare(strict_types=1);
/** Settings → Rules: personal mail colours and (admins) organization routing. */
require_once dirname(__DIR__, 2) . '/lib/session.php';
$context = ecw_require_login(); $navActive = 'settings'; $base = ecw_web_base();
// Colour rules (everyone, "Mail colour rules") or automation (admins, "Automation rules").
if (!ecw_feature_allowed('rules') && !($context['role'] === 'admin' && ecw_feature_allowed('automation'))) ecw_require_feature('rules');
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
// Settings → My rules (?part=colors) and Team → Case rules (?part=automation) are this page,
// each showing its own part. Without a part (an older link) it shows both.
$part = in_array($_GET['part'] ?? '', ['colors', 'automation'], true) ? (string)$_GET['part'] : '';
$title = ['colors' => 'My rules', 'automation' => 'Case rules', '' => 'Rules'][$part];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $h($title) ?> — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope"<?= $part !== '' ? ' data-part="' . $h($part) . '"' : '' ?>>
  <h1><?= $h($title) ?></h1>

  <?php if (ecw_feature_allowed('ai')): ?>
  <section class="rule-wish" id="ruleWish">
    <label for="ruleWishText"><i class="ecw-ai-icon" aria-hidden="true"></i> <strong>Describe a rule</strong> <span class="muted small">— the AI writes it, you check it and save</span></label>
    <div class="rule-wish-row">
      <textarea id="ruleWishText" rows="2" maxlength="600" placeholder="e.g. Anything from @kayser-automotive.com about invoices: open a case in Accounting and tell Maria"></textarea>
      <button type="button" id="ruleWishGo" class="primary">Write the rule</button>
    </div>
    <p class="muted small" id="ruleWishStatus" role="status"></p>
  </section>
  <?php endif; ?>

  <section id="colorSection">
    <h2>Mail colours <span class="muted small">· only you see these</span></h2>
    <p class="muted">Colour emails in your list, like Outlook's conditional formatting. For example: <em>my address is in To → blue</em>.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">More about this</span></summary>
      <p class="muted small">The first matching rule wins. They apply to existing mail straight away.</p>
    </details>
    <div id="colorRules"></div>
    <button type="button" id="addColor">+ Colour rule</button>
  </section>

  <section id="routeSection" hidden>
    <h2>Automation <span class="muted small">· organization-wide, admins</span></h2>
    <p class="muted">When <strong>new</strong> email arrives and matches, do one or more things: open a case or add it to one, set the Responsible, tag, move, flag, snooze, notify someone, reply automatically, or send it to your webhook.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">More about this</span><span class="st-fold-hint">Order, folders, emails you already have, case numbers</span></summary>
      <p class="muted small">Rules run top to bottom; a rule can stop the ones below it. A rule can look at the folder an email is in (an Outlook rule may have put it there). To use a rule on emails you already have, press <em>Run on earlier emails…</em> on it and choose the dates. Emails whose subject contains a case number such as <code>[CASE-00042]</code> are always linked to that case automatically.</p>
    </details>
    <div id="routeRules"></div>
    <button type="button" id="addRoute">+ Automation rule</button>
    <button type="button" id="addIntake" title="Every new email sent to one address (e.g. support@…) opens a case">+ Emails to an address become cases</button>
  </section>

  <form class="rule-editor" id="ruleEditor" hidden autocomplete="off">
    <h3 id="ruleEditorTitle">Rule</h3>
    <label>Name <input type="text" id="ruleName" maxlength="120" required></label>
    <div class="cond-row">When <select id="ruleMatch"><option value="all">all</option><option value="any">any</option></select> of these match:</div>
    <div id="ruleConds"></div>
    <button type="button" id="addCond" class="link-btn">+ condition</button>
    <div id="colorAction" class="swatches" aria-label="Colour"></div>
    <div id="routeAction" hidden>
      <div class="cond-row"><strong>Then:</strong></div>
      <div id="ruleActions"></div>
      <button type="button" id="addAction" class="link-btn">+ action</button>
      <label class="checkbox-label"><input type="checkbox" id="ruleFirstOnly"> Only the first email of a conversation (not replies)</label>
      <label class="checkbox-label"><input type="checkbox" id="ruleStop" checked> Stop here — don't run the rules below for this email</label>
    </div>
    <label class="checkbox-label"><input type="checkbox" id="ruleDisabled"> Paused</label>
    <div><button type="submit" class="primary">Save rule</button> <button type="button" id="ruleCancel">Cancel</button> <button type="button" id="ruleDelete" class="danger" hidden>Delete</button></div>
    <p id="ruleStatus" class="muted" role="status"></p>
  </form>
</main>
</div>
<script src="<?= $h($base) ?>assets/rules.js?v=<?= filemtime(dirname(__DIR__) . '/assets/rules.js') ?>" defer></script>
</body></html>
