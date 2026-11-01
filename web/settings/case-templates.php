<?php declare(strict_types=1);
/**
 * Settings → Case templates (admins): ready-made cases for work that repeats.
 * A template has checklist steps (each "+N days" from the day it is used, and
 * who does it), tags, a due date and a group. People pick it when they create
 * a case (Mail → Add to case → name a new case) or in a case's Checklist tab.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/checklists.php';
$context = ecw_require_admin(); ecw_require_feature('case_templates');
$orgId = (int)$context['organization_id'];
$actorId = (int)$context['user']['id'];
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$notice = null; $error = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'save') {
                $steps = [];
                foreach ((array)($_POST['step_text'] ?? []) as $i => $text) {
                    $steps[] = ['text' => (string)$text, 'days' => $_POST['step_days'][$i] ?? null, 'who' => $_POST['step_who'][$i] ?? null];
                }
                $id = ecw_case_template_save($orgId, $actorId, $_POST + ['steps' => $steps], (int)($_POST['id'] ?? 0) ?: null);
                $notice = 'Saved. People see it when they start a case.';
                $editId = $id;
            } elseif ($do === 'starter' && isset(ECW_TEMPLATE_STARTERS[$_POST['starter'] ?? ''])) {
                $editId = ecw_case_template_save($orgId, $actorId, ['name' => $_POST['starter']] + ECW_TEMPLATE_STARTERS[$_POST['starter']]);
                $notice = 'Added "' . $_POST['starter'] . '". Change the steps to how your team works.';
            } elseif ($do === 'archive' || $do === 'restore') {
                ecw_case_template_archive($orgId, (int)($_POST['id'] ?? 0), $do === 'archive');
                $notice = $do === 'archive' ? 'Archived: nobody can pick it any more (cases made from it keep their steps).' : 'Restored.';
                $editId = null;
            }
        } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    }
}
$templates = ecw_case_templates($orgId, true);
$editing = $editId ? ecw_case_template($orgId, $editId) : null;
if (isset($_GET['new'])) $editing = ['id' => 0, 'name' => '', 'description' => '', 'name_prefix' => '', 'steps' => [['text' => '', 'days' => null, 'who' => null]], 'tags' => [], 'due_days' => null, 'group_id' => null, 'archived' => false];
$groups = ecw_db()->prepare('SELECT id, name FROM `' . ecw_table('case_groups') . '` WHERE organization_id = ? ORDER BY name');
$groups->execute([$orgId]);
$groups = $groups->fetchAll();
$usage = [];
foreach (ecw_db()->query('SELECT template_id, COUNT(*) n FROM `' . ecw_table('cases') . '` WHERE organization_id = ' . $orgId . ' AND template_id IS NOT NULL AND deleted_at IS NULL GROUP BY template_id') as $r) $usage[(int)$r['template_id']] = (int)$r['n'];
$who = ['' => 'Nobody yet', 'owner' => 'Responsible', 'handler' => 'Handled by'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Case templates — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope sec-page">
  <h1>Case templates <a href="#" class="help-link" data-help="templates"><i class="fa fa-circle-question" aria-hidden="true"></i> Help</a></h1>
  <p class="muted">For work that repeats — a new customer, a complaint, a warranty claim.</p>
  <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">More about this</span><span class="st-fold-hint">What a case gets from a template, and where people pick one</span></summary>
    <p class="muted small">A case started from a template gets its steps (each with a date and a person), its tags, its due date and its group. People pick a template when they name a new case in Mail → Add to case, or in a case's Checklist tab.</p>
  </details>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <?php if ($editing !== null): ?>
  <section class="panel ct-editor">
    <h2><?= $editing['id'] ? 'Change “' . $h($editing['name']) . '”' : 'New template' ?></h2>
    <form method="post" class="sec-form" id="ctForm">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <div class="ap-two">
        <label>Name <input type="text" name="name" value="<?= $h($editing['name']) ?>" required maxlength="120" placeholder="Complaint"></label>
        <label>In front of the case name <span class="muted small">(optional)</span> <input type="text" name="name_prefix" value="<?= $h($editing['name_prefix']) ?>" maxlength="60" placeholder="Complaint · "></label>
      </div>
      <label>What it is for <span class="muted small">(shown when people choose)</span> <input type="text" name="description" value="<?= $h($editing['description']) ?>" maxlength="500" placeholder="A customer is unhappy with a delivery or a product"></label>
      <div class="ct-three">
        <label>Case due in <input type="number" name="due_days" min="0" max="365" value="<?= $h($editing['due_days'] ?? '') ?>" placeholder="—"> days</label>
        <label>Group <select name="group_id" class="ot-select"><option value="">None</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$editing['group_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= $h($g['name']) ?></option><?php endforeach; ?></select></label>
        <label>Tags <input type="text" name="tags" value="<?= $h(implode(', ', $editing['tags'])) ?>" placeholder="complaint, quality"></label>
      </div>
      <h3 class="ct-steps-title">Steps</h3>
      <p class="muted small">Each step's date counts from the day the template is used. "Responsible" and "Handled by" are the people on that case.</p>
      <ol class="ct-steps" id="ctSteps">
        <?php foreach ($editing['steps'] ?: [['text' => '', 'days' => null, 'who' => null]] as $s): ?>
          <li class="ct-step">
            <span class="ct-grip" aria-hidden="true"><i class="fa fa-grip-vertical"></i></span>
            <input type="text" name="step_text[]" value="<?= $h($s['text']) ?>" maxlength="255" placeholder="What has to be done" aria-label="Step">
            <label class="ct-days">+<input type="number" name="step_days[]" min="0" max="365" value="<?= $h($s['days'] ?? '') ?>" aria-label="Days after the start"> days</label>
            <select name="step_who[]" class="ot-select" aria-label="Who does it"><?php foreach ($who as $k => $l): ?><option value="<?= $h($k) ?>" <?= (string)($s['who'] ?? '') === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
            <button type="button" class="ct-remove" aria-label="Remove step"><i class="fa fa-xmark" aria-hidden="true"></i></button>
          </li>
        <?php endforeach; ?>
      </ol>
      <p><button type="button" id="ctAddStep"><i class="fa fa-plus" aria-hidden="true"></i> Add a step</button></p>
      <p><button type="submit" class="primary">Save</button> <a class="button" href="case-templates.php">Cancel</a></p>
    </form>
    <?php if ($editing['id']): ?>
    <form method="post" class="ct-archive"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <button type="submit" name="do" value="<?= $editing['archived'] ? 'restore' : 'archive' ?>"><?= $editing['archived'] ? 'Restore' : 'Archive' ?></button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel">
    <h2>Templates</h2>
    <?php if (!$templates): ?><p class="muted">No templates yet. Start from an example below, or make your own.</p><?php else: ?>
    <ul class="ct-list">
      <?php foreach ($templates as $t): ?>
        <li class="<?= $t['archived'] ? 'archived' : '' ?>">
          <a href="?edit=<?= (int)$t['id'] ?>"><strong><?= $h($t['name']) ?></strong></a><?= $t['archived'] ? ' <span class="muted small">(archived)</span>' : '' ?>
          <span class="muted small"><?= count($t['steps']) ?> step<?= count($t['steps']) === 1 ? '' : 's' ?><?= $t['due_days'] !== null ? ' · due in ' . (int)$t['due_days'] . ' days' : '' ?><?= $t['tags'] ? ' · ' . $h(implode(', ', $t['tags'])) : '' ?> · used for <?= (int)($usage[$t['id']] ?? 0) ?> case<?= ($usage[$t['id']] ?? 0) === 1 ? '' : 's' ?></span>
          <?php if ($t['description']): ?><br><span class="muted small"><?= $h($t['description']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p><a class="button primary" href="?new=1"><i class="fa fa-plus" aria-hidden="true"></i> New template</a></p>
    <details class="st-fold st-fold-inline"<?= $templates ? '' : ' open' ?>><summary><span class="st-fold-title">Start from an example</span></summary>
    <form method="post" class="ct-starters">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="do" value="starter">
      <span class="muted small">Start from an example:</span>
      <?php foreach (array_keys(ECW_TEMPLATE_STARTERS) as $name): ?><button type="submit" name="starter" value="<?= $h($name) ?>"><?= $h($name) ?></button><?php endforeach; ?>
    </form>
    </details>
  </section>
</main>
</div>
<script>
(() => {
  const steps = document.getElementById('ctSteps');
  if (!steps) return;
  const blank = () => { const li = steps.querySelector('.ct-step').cloneNode(true); li.querySelectorAll('input').forEach((i) => { i.value = ''; }); li.querySelector('select').value = ''; return li; };
  document.getElementById('ctAddStep').addEventListener('click', () => { const li = blank(); steps.appendChild(li); li.querySelector('input').focus(); });
  steps.addEventListener('click', (e) => {
    const b = e.target.closest('.ct-remove');
    if (!b) return;
    const li = b.closest('.ct-step');
    if (steps.children.length > 1) li.remove(); else li.querySelectorAll('input').forEach((i) => { i.value = ''; });
  });
  // Enter in a step adds the next one instead of saving.
  steps.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.matches('input[name="step_text[]"]')) return;
    e.preventDefault();
    const li = blank(); e.target.closest('.ct-step').after(li); li.querySelector('input').focus();
  });
  // Drag to reorder.
  let dragged = null;
  steps.querySelectorAll('.ct-step').forEach((li) => { li.draggable = true; });
  steps.addEventListener('dragstart', (e) => { dragged = e.target.closest('.ct-step'); });
  steps.addEventListener('dragover', (e) => { if (!dragged) return; e.preventDefault(); const over = e.target.closest('.ct-step'); if (over && over !== dragged) { const r = over.getBoundingClientRect(); steps.insertBefore(dragged, e.clientY < r.top + r.height / 2 ? over : over.nextSibling); } });
  steps.addEventListener('dragend', () => { dragged = null; steps.querySelectorAll('.ct-step').forEach((li) => { li.draggable = true; }); });
})();
</script>
</body></html>
