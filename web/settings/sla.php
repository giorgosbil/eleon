<?php declare(strict_types=1);
/**
 * Settings → Response times (admins): how fast customers should get a reply,
 * counted in business hours, plus a different target per case group.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/sla.php';
$context = ecw_require_admin(); ecw_require_feature('sla'); $navActive = 'settings'; $base = ecw_web_base();
$orgId = (int)$context['organization_id'];
$notice = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            ecw_sla_save($orgId, $_POST);
            // The workspace's time zone (working hours, case timelines and reminders use it).
            $tzIn = (string)($_POST['timezone'] ?? '');
            if (in_array($tzIn, DateTimeZone::listIdentifiers(), true)) {
                ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET timezone = ? WHERE id = ?')->execute([$tzIn, $orgId]);
            }
            $set = ecw_db()->prepare('UPDATE `' . ecw_table('case_groups') . '` SET sla_hours = ? WHERE id = ? AND organization_id = ?');
            foreach ((array)($_POST['group'] ?? []) as $gid => $hours) {
                $hours = trim((string)$hours);
                if ($hours !== '' && ((float)$hours < 0.5 || (float)$hours > 240)) throw new InvalidArgumentException('Group targets must be between 0.5 and 240 business hours.');
                $set->execute([$hours === '' ? null : round((float)$hours, 1), (int)$gid, $orgId]);
            }
            $notice = 'Saved.';
        } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    }
}
$s = (static function () use ($orgId) { $st = ecw_db()->prepare('SELECT sla_json, timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?'); $st->execute([$orgId]); $r = $st->fetch(); return ((json_decode((string)($r['sla_json'] ?? ''), true) ?: []) + ECW_SLA_DEFAULTS) + ['timezone' => $r['timezone'] ?: 'UTC']; })();
$st = ecw_db()->prepare('SELECT id, name, color, sla_hours FROM `' . ecw_table('case_groups') . '` WHERE organization_id = ? ORDER BY name');
$st->execute([$orgId]);
$groups = $st->fetchAll();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Response times — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>">
</head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>Response times</h1>
  <p class="muted">An open case is <strong>waiting for us</strong> from the oldest customer email nobody has answered. Its clock runs only in working hours.</p>
  <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">More about this</span><span class="st-fold-hint">Cases without a clock, and where clocks show</span></summary>
    <p class="muted small">Cases marked <em>Waiting</em> (on the customer) or <em>Closed</em> have no clock. Clocks show on the case list, on each case, and in Reports.</p>
  </details>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <form method="post" class="panel sla-form">
    <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
    <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" <?= !empty($s['enabled']) ? 'checked' : '' ?>> Use response-time targets</label>
    <label>Reply within <span class="sla-inline"><input type="number" name="reply_hours" min="0.5" max="240" step="0.5" value="<?= $h($s['reply_hours']) ?>" required> business hours</span></label>
    <fieldset class="sla-days"><legend>Working days</legend>
      <?php foreach ($dayNames as $n => $label): ?>
        <label class="day-chip"><input type="checkbox" name="days[]" value="<?= $n ?>" <?= in_array($n, $s['days'], true) ? 'checked' : '' ?>><span><?= $h($label) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="sla-hours-row">
      <label>From <input type="time" name="start" value="<?= $h($s['start']) ?>" required></label>
      <label>To <input type="time" name="end" value="<?= $h($s['end']) ?>" required></label>
      <label>Time zone <select name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tzName): ?><option value="<?= $h($tzName) ?>"<?= $tzName === $s['timezone'] ? ' selected' : '' ?>><?= $h(str_replace('_', ' ', $tzName)) ?></option><?php endforeach; ?></select></label>
      <span class="muted small">The company's time zone: working hours, the morning briefing, due dates and "today". Everyone still sees times on screen in the zone of their own computer.</span>
    </div>
    <?php if ($groups): $groupsSet = array_filter($groups, static fn($g) => $g['sla_hours'] !== null); ?>
      <details class="st-fold"<?= $groupsSet || $error !== null ? ' open' : '' ?>>
      <summary><span class="st-fold-title"><i class="fa fa-layer-group" aria-hidden="true"></i> Per case group</span><span class="st-fold-hint"><?= $groupsSet ? count($groupsSet) . ' of ' . count($groups) . ' with their own target' : 'A different target for some groups' ?></span></summary>
      <table class="aging-table sla-groups">
        <?php foreach ($groups as $g): ?>
          <tr><td><span class="group-badge" style="--group-color:<?= $h($g['color'] ?: '#94a3b8') ?>"><?= $h($g['name']) ?></span></td>
            <td><input type="number" name="group[<?= (int)$g['id'] ?>]" min="0.5" max="240" step="0.5" value="<?= $g['sla_hours'] !== null ? $h((float)$g['sla_hours']) : '' ?>" placeholder="<?= $h($s['reply_hours']) ?>" aria-label="Reply target for <?= $h($g['name']) ?>"> business hours</td></tr>
        <?php endforeach; ?>
      </table>
      <p class="muted small">Empty = the target above.</p>
      </details>
    <?php endif; ?>
    <button type="submit" class="primary">Save</button>
  </form>
</main>
</div>
</body></html>
