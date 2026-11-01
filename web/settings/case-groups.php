<?php declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/session.php';
$context = ecw_require_login(); ecw_require_feature('case_groups'); $navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Case groups — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css?v=<?= filemtime(dirname(__DIR__) . '/assets/auth.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>" data-user="<?= (int)$context['user']['id'] ?>" data-admin="<?= ecw_is_admin() ? '1' : '0' ?>"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page case-groups-page btn-scope">
  <h1>Case groups</h1>
  <p class="muted">Groups such as <em>Support</em> or <em>Sales</em> organize the case list. A <strong>company</strong> group is for everyone, a <strong>members</strong> group for the people you choose, and an <strong>only me</strong> group is yours alone.</p>
  <div class="case-groups-layout">
    <section class="panel cg-list-panel" aria-labelledby="cgListTitle">
      <div class="cg-panel-head"><h2 id="cgListTitle">Groups</h2><button type="button" id="groupNew" class="primary"><i class="fa fa-plus" aria-hidden="true"></i> New group</button></div>
      <ul class="case-group-list" id="groupList" aria-label="Groups"></ul>
    </section>
    <form id="groupForm" class="panel cg-edit-panel" autocomplete="off">
      <h2 id="groupFormTitle">New group</h2>
      <label class="cg-label" for="groupName">Name</label>
      <div class="group-name-row">
        <input id="groupName" required maxlength="80" placeholder="Support, Sales…">
        <label class="color-dot" title="Group colour"><input id="groupColor" type="color" value="#4f7cff" aria-label="Group colour"></label>
      </div>
      <fieldset class="cg-fieldset">
        <legend>Who sees this group and its cases</legend>
        <label class="checkbox-label"><input type="radio" name="groupScope" value="company"> <span><i class="fa fa-building" aria-hidden="true"></i> <strong>Company</strong> <span class="muted small">— everyone in the company sees the group and its cases</span></span></label>
        <label class="checkbox-label"><input type="radio" name="groupScope" value="members"> <span><i class="fa fa-user-group" aria-hidden="true"></i> <strong>Members</strong> <span class="muted small">— only the people chosen below, and you</span></span></label>
        <div class="cg-members" id="groupMembersBox">
          <input type="search" id="groupSearch" placeholder="Find people">
          <ul class="people-list" id="groupPeople"></ul>
          <label class="checkbox-label" id="groupAutoAssignRow"><input type="checkbox" id="groupAutoAssign"> Hand new cases from routing rules to the member with the fewest open cases</label>
        </div>
        <label class="checkbox-label"><input type="radio" name="groupScope" value="personal"> <span><i class="fa fa-lock" aria-hidden="true"></i> <strong>Only me</strong> <span class="muted small">— nobody else sees the group, nor its cases</span></span></label>
        <p class="muted small" id="groupScopeNote">It applies to the cases already in the group too, and to every case put in it later. Someone you place on a case (for example as Responsible) always sees that case.</p>
      </fieldset>
      <fieldset class="cg-fieldset cg-rules">
        <legend>Rules for new incoming emails</legend>
        <p class="muted small">Should a new email that is in no case yet become a case in this group? Tell the system how to recognise those emails. An email with a case number or a reference number still goes to its own case first.</p>
        <label class="cg-label" for="groupRuleAi"><i class="fa fa-wand-magic-sparkles" aria-hidden="true"></i> Describe them in your own words (the AI reads each new email)</label>
        <textarea id="groupRuleAi" rows="3" maxlength="1500" placeholder="A customer asks us to calibrate one of their instruments (scales, thermometers, pressure gauges). Not: offers to sell us instruments, or invoices."></textarea>
        <p class="muted small cg-hint">Say what is in and what is not.</p>
        <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">How the AI learns</span></summary>
          <p class="muted small cg-hint">The AI also learns from the cases in this group and from your answers to its suggestions: dismiss a wrong one, or undo a case it started, and it remembers.</p>
        </details>
        <p class="muted small cg-learned" id="groupLearned" hidden></p>
        <label class="cg-label" for="groupRuleWords"><i class="fa fa-bolt" aria-hidden="true"></i> Or words that are enough on their own (no AI)</label>
        <textarea id="groupRuleWords" rows="2" placeholder="βαθμονόμηση&#10;calibration request"></textarea>
        <p class="muted small cg-hint">One per line, in the subject or the text. Capitals and Greek accents do not matter, and the start of a word is enough: <em>βαθμονόμ</em> also finds <em>βαθμονόμηση</em>.</p>
        <label class="cg-label" for="groupRuleMailbox">Only emails arriving in</label>
        <select id="groupRuleMailbox"><option value="">Any account</option></select>
        <span class="cg-label">When an email matches</span>
        <label class="checkbox-label"><input type="radio" name="groupRuleAction" value="off"> <span><strong>Do nothing</strong> <span class="muted small">— the rules are kept but not used</span></span></label>
        <label class="checkbox-label"><input type="radio" name="groupRuleAction" value="suggest"> <span><strong>Suggest</strong> <span class="muted small">— a card offers “Start a case in this group”; you decide</span></span></label>
        <label class="checkbox-label"><input type="radio" name="groupRuleAction" value="auto"> <span><strong>Start the case by itself</strong> <span class="muted small">— on one of the words, or when the AI is very sure (less sure, it only suggests). The account's owner is Responsible and a card tells them, with Undo.</span></span></label>
      </fieldset>
      <div class="cg-actions"><button type="submit" class="primary" id="groupSave">Create group</button> <button type="button" id="groupDelete" class="danger" hidden>Delete group</button></div>
      <p id="groupStatus" role="status" class="muted"></p>
    </form>
  </div>
</main>
</div>
<script src="<?= $h($base) ?>assets/avatars.js?v=<?= filemtime(dirname(__DIR__) . '/assets/avatars.js') ?>"></script>
<script src="<?= $h($base) ?>assets/case-groups.js?v=<?= filemtime(dirname(__DIR__) . '/assets/case-groups.js') ?>"></script></body></html>
