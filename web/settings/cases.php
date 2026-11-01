<?php declare(strict_types=1);
/**
 * Settings → Case settings (admins): the company's own statuses, the overdue timers, the
 * conversation roles, the case number format and the tag list (lib/case_settings.php).
 * One form per part, each saved on its own.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/case_settings.php';
require_once dirname(__DIR__, 2) . '/lib/sla.php';
$context = ecw_require_admin(); $navActive = 'settings'; $base = ecw_web_base();
$orgId = (int)$context['organization_id'];
$userId = (int)$context['user']['id'];
$notice = null; $error = null;
$part = (string)($_POST['part'] ?? '');
$hasSla = ecw_feature_allowed('sla');

/** Rows of a list part of the form (statuses, roles): label, group, colour, key. */
$rows = static function (string $name): array {
    $out = [];
    foreach ((array)($_POST[$name] ?? []) as $r) {
        if (!is_array($r)) continue;
        $out[] = ['label' => (string)($r['label'] ?? ''), 'base' => (string)($r['base'] ?? 'open'), 'color' => (string)($r['color'] ?? ''), 'key' => (string)($r['key'] ?? '')];
    }
    return $out;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            switch ($part) {
                case 'statuses': ecw_case_settings_save($orgId, ['statuses' => $rows('statuses')], $userId); $notice = 'Statuses saved.'; break;
                case 'roles':    ecw_case_settings_save($orgId, ['roles' => $rows('roles')], $userId); $notice = 'Roles saved.'; break;
                case 'overdue':
                    ecw_case_settings_save($orgId, array_intersect_key($_POST, array_flip(['waiting_overdue_days', 'default_due_days', 'due_grace_days'])), $userId);
                    // The response-time target lives with Response times; the rest of it stays as it is.
                    if ($hasSla && isset($_POST['reply_hours'])) ecw_sla_save($orgId, ['reply_hours' => $_POST['reply_hours']] + ecw_sla_settings($orgId));
                    $notice = 'Overdue timers saved.';
                    break;
                case 'numbers':  ecw_case_settings_save($orgId, ['number_format' => (string)($_POST['number_format'] ?? ''), 'number_digits' => (int)($_POST['number_digits'] ?? 5)], $userId); $notice = 'Case number format saved. New cases get numbers like this; existing cases keep theirs.'; break;
                case 'tags':
                    $colors = [];
                    $changed = 0;
                    foreach ((array)($_POST['tag'] ?? []) as $t) {
                        if (!is_array($t)) continue;
                        $tag = mb_strtolower(trim((string)($t['name'] ?? '')));
                        if ($tag === '') continue;
                        $new = !empty($t['delete']) ? '' : trim((string)($t['rename'] ?? ''));
                        if (!empty($t['delete']) || ($new !== '' && mb_strtolower($new) !== $tag)) {
                            $changed += ecw_case_tag_rename($orgId, $tag, $new, $userId);
                            if ($new === '') continue;
                            $tag = mb_strtolower($new);
                        }
                        if (!empty($t['use_color'])) $colors[$tag] = (string)($t['color'] ?? '');
                    }
                    ecw_case_settings_save($orgId, ['tag_colors' => $colors], $userId);
                    $notice = 'Tags saved.' . ($changed ? ' ' . $changed . ' case' . ($changed === 1 ? '' : 's') . ' changed.' : '');
                    break;
            }
        } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    }
}
$s = ecw_case_settings($orgId, true);
$sla = $hasSla ? ecw_sla_settings($orgId) : null;
$tags = ecw_case_tag_list($orgId);
require_once dirname(__DIR__, 2) . '/lib/cases.php';
$nextNumber = ecw_case_next_number($orgId);
$inUse = [];
$st = ecw_db()->prepare('SELECT sub_status, COUNT(*) FROM `' . ecw_table('cases') . '` WHERE organization_id = ? AND deleted_at IS NULL AND sub_status IS NOT NULL GROUP BY sub_status');
$st->execute([$orgId]);
foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) $inUse[$k] = (int)$n;
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$blank = 3;   // empty rows to add new ones
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Case settings — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>">
</head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope case-settings">
  <h1>Case settings</h1>
  <p class="muted">For the whole company. Each part is saved on its own.</p>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <section class="panel" id="statuses">
    <h2>Statuses</h2>
    <p class="muted">Add your own statuses, such as <em>Waiting for parts</em> or <em>Quoted</em>.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">How statuses work</span><span class="st-fold-hint">Open, Waiting, Closed, and removing one</span></summary>
      <p class="muted small">Each one belongs to <strong>Open</strong>, <strong>Waiting</strong> or <strong>Closed</strong>, and the case behaves like that group: a reply from the customer reopens a Waiting case, and Closed cases leave the lists. To remove a status, empty its name. Its cases keep the group.</p>
    </details>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="part" value="statuses">
      <table class="cs-table">
        <thead><tr><th>Name</th><th>Belongs to</th><th>Colour</th><th class="muted">Cases</th></tr></thead>
        <tbody>
        <?php foreach (ECW_CASE_STATUS_GROUPS as $g => [$label, $color]): ?>
          <tr class="cs-builtin"><td><?= $h($label) ?></td><td class="muted">built in</td><td><span class="cs-swatch" style="background:<?= $h($color) ?>"></span></td><td></td></tr>
        <?php endforeach; ?>
        <?php foreach (array_merge($s['statuses'], array_fill(0, $blank, ['key' => '', 'label' => '', 'base' => 'open', 'color' => ''])) as $i => $st): ?>
          <tr>
            <td><input type="text" name="statuses[<?= $i ?>][label]" value="<?= $h($st['label']) ?>" maxlength="40" placeholder="<?= $st['key'] === '' ? 'New status' : '' ?>" aria-label="Status name"><input type="hidden" name="statuses[<?= $i ?>][key]" value="<?= $h($st['key']) ?>"></td>
            <td><select name="statuses[<?= $i ?>][base]" aria-label="Belongs to"><?php foreach (ECW_CASE_STATUS_GROUPS as $g => [$label]): ?><option value="<?= $g ?>"<?= $st['base'] === $g ? ' selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select></td>
            <td><input type="color" name="statuses[<?= $i ?>][color]" value="<?= $h($st['color'] ?: ECW_CASE_STATUS_GROUPS[$st['base']][1]) ?>" aria-label="Colour"></td>
            <td class="muted"><?= $st['key'] !== '' ? (int)($inUse[$st['key']] ?? 0) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="primary">Save statuses</button>
    </form>
  </section>

  <section class="panel" id="overdue">
    <h2>Overdue</h2>
    <p class="muted">When a case shows as <strong>Overdue</strong> (the Overdue view on Cases, the red dates, the morning summary). 0 turns a timer off.</p>
    <form method="post" class="cs-grid">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="part" value="overdue">
      <label>Waiting too long after <span class="sla-inline"><input type="number" name="waiting_overdue_days" min="0" max="365" value="<?= (int)$s['waiting_overdue_days'] ?>"> days</span>
        <span class="muted small">A case in Waiting longer than this counts as overdue.</span></label>
      <label>Due date for a new case <span class="sla-inline"><input type="number" name="default_due_days" min="0" max="365" value="<?= (int)$s['default_due_days'] ?>"> working days ahead</span>
        <span class="muted small">0: new cases have no due date until someone sets one.</span></label>
      <label>Overdue <span class="sla-inline"><input type="number" name="due_grace_days" min="0" max="60" value="<?= (int)$s['due_grace_days'] ?>"> days after the due date</span>
        <span class="muted small">0: the day after the due date.</span></label>
      <?php if ($sla !== null): ?>
      <label>Reply to customers within <span class="sla-inline"><input type="number" name="reply_hours" min="0.5" max="240" step="0.5" value="<?= $h($sla['reply_hours']) ?>"> business hours</span>
        <span class="muted small">The red response-time badge. Working days, hours and a target per group: <a href="sla.php">Response times</a>.</span></label>
      <?php endif; ?>
      <div><button type="submit" class="primary">Save overdue timers</button></div>
    </form>
  </section>

  <section class="panel" id="roles">
    <h2>Conversation roles</h2>
    <p class="muted">The <em>Whose conversation?</em> choice above each conversation on a case. Add your own, such as <em>Carrier</em> or <em>Lab</em>.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">More about roles</span><span class="st-fold-hint">What your own roles change, and removing one</span></summary>
      <p class="muted small">Who the case waits on, deals and reports go by Customer and Supplier only. To remove a role, empty its name.</p>
    </details>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="part" value="roles">
      <table class="cs-table">
        <thead><tr><th>Name</th><th>Colour</th></tr></thead>
        <tbody>
        <?php foreach (ECW_PARTY_ROLE_BUILTIN as $k => [$label, $color]): ?>
          <tr class="cs-builtin"><td><?= $h($label) ?> <span class="muted small">built in</span></td><td><span class="cs-swatch" style="background:<?= $h($color) ?>"></span></td></tr>
        <?php endforeach; ?>
        <?php foreach (array_merge($s['roles'], array_fill(0, $blank, ['key' => '', 'label' => '', 'color' => '#475569'])) as $i => $r): ?>
          <tr>
            <td><input type="text" name="roles[<?= $i ?>][label]" value="<?= $h($r['label']) ?>" maxlength="30" placeholder="<?= $r['key'] === '' ? 'New role' : '' ?>" aria-label="Role name"><input type="hidden" name="roles[<?= $i ?>][key]" value="<?= $h($r['key']) ?>"></td>
            <td><input type="color" name="roles[<?= $i ?>][color]" value="<?= $h($r['color']) ?>" aria-label="Colour"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="primary">Save roles</button>
    </form>
  </section>

  <section class="panel" id="numbers">
    <h2>Case numbers</h2>
    <p class="muted">How new cases are numbered. End with <code>{N}</code>, the running number.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">The year in the number, and older cases</span></summary>
      <p class="muted small">Add <code>{YYYY}</code> or <code>{YY}</code> for the year, and the numbers start again each year. Existing cases keep their numbers, and replies quoting old numbers still find their case.</p>
    </details>
    <form method="post" class="cs-grid">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="part" value="numbers">
      <label>Format <input type="text" name="number_format" value="<?= $h($s['number_format']) ?>" maxlength="30" placeholder="CASE-{N}" spellcheck="false"></label>
      <label>Digits <input type="number" name="number_digits" min="1" max="9" value="<?= (int)$s['number_digits'] ?>"></label>
      <p class="muted small">The next case will be <strong><?= $h($nextNumber) ?></strong>.</p>
      <div><button type="submit" class="primary">Save case numbers</button></div>
    </form>
  </section>

  <section class="panel" id="tags">
    <h2>Tags</h2>
    <p class="muted">Every tag on your cases. Give a tag a colour, rename it on every case (renaming to a tag that exists merges the two), or delete it from every case.</p>
    <?php if ($tags === []): ?>
      <p class="muted">No tags yet. Tags are added on a case, next to its due date.</p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="part" value="tags">
      <table class="cs-table">
        <thead><tr><th>Tag</th><th class="muted">Cases</th><th>Colour</th><th>Rename to</th><th>Delete</th></tr></thead>
        <tbody>
        <?php foreach ($tags as $i => $t): $c = $s['tag_colors'][$t['tag']] ?? null; ?>
          <tr>
            <td><span class="tag-chip"<?= $c ? ' style="background:color-mix(in srgb, ' . $h($c) . ' 14%, var(--bg));color:' . $h($c) . '"' : '' ?>><?= $h($t['tag']) ?></span><input type="hidden" name="tag[<?= $i ?>][name]" value="<?= $h($t['tag']) ?>"></td>
            <td class="muted"><a href="../cases.php?tag=<?= rawurlencode($t['tag']) ?>&amp;status=all"><?= (int)$t['cases'] ?></a></td>
            <td class="cs-color"><label><input type="checkbox" name="tag[<?= $i ?>][use_color]" value="1"<?= $c ? ' checked' : '' ?>> <input type="color" name="tag[<?= $i ?>][color]" value="<?= $h($c ?: '#7048e8') ?>" aria-label="Colour of <?= $h($t['tag']) ?>"></label></td>
            <td><input type="text" name="tag[<?= $i ?>][rename]" maxlength="40" placeholder="<?= $h($t['tag']) ?>" aria-label="New name for <?= $h($t['tag']) ?>"></td>
            <td><input type="checkbox" name="tag[<?= $i ?>][delete]" value="1" aria-label="Delete <?= $h($t['tag']) ?> from every case"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="primary" onclick="return !this.form.querySelector('input[name$=&quot;[delete]&quot;]:checked') || confirm('Delete the ticked tags from every case?');">Save tags</button>
    </form>
    <?php endif; ?>
  </section>
</main>
</div>
</body></html>
