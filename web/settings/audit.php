<?php declare(strict_types=1);
/**
 * Settings → Audit log (admins). Who did what and when: sign-ins, rights,
 * cases, downloads and exports, sending, settings, AI access. Recorded in
 * every package (lib/audit.php); viewing and exporting is part of Business.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/audit.php';
require_once dirname(__DIR__, 2) . '/lib/license.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';
$context = ecw_require_admin();
$orgId = (int)$context['organization_id'];
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$allowed = ecw_package_at_least('business');

$groups = ['' => 'Everything', 'login' => 'Sign-in', 'user.' => 'Users and rights', 'case.' => 'Cases', 'email.' => 'Sending and deleting email',
    'attachment' => 'File downloads', 'ai.' => 'AI assistant', 'mcp.' => 'Copilot and AI apps', 'token.' => 'AI access tokens',
    'settings.' => 'Settings', 'mailbox.' => 'Mailboxes', 'rule.' => 'Rules', 'features.' => 'Feature access'];
$f = ['from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'user' => (int)($_GET['user'] ?? 0),
    'action' => (string)($_GET['action'] ?? ''), 'q' => (string)($_GET['q'] ?? ''),
    'target_type' => (string)($_GET['target_type'] ?? ''), 'target_id' => (int)($_GET['target_id'] ?? 0)];
if (!isset($groups[$f['action']]) && !preg_match('/^[a-z0-9_.]{1,60}$/', $f['action'])) $f['action'] = '';

// CSV export: everything matching the filters, up to 50,000 rows.
if ($allowed && ($_GET['export'] ?? '') === 'csv') {
    ecw_audit('audit.export', null, null, array_filter($f));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // UTC stays (a record that reads the same anywhere), the viewer's local time beside it.
    $csvTz = ecw_tz_for_user(null, (int)$orgId);
    fputcsv($out, ['Time (UTC)', 'Time (' . $csvTz . ')', 'Who', 'Email', 'Action', 'Code', 'Target', 'Target id', 'Details', 'Via', 'IP']);
    $cell = static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;   // no spreadsheet formulas
    foreach (ecw_audit_list($orgId, $f, 50000) as $r) {
        fputcsv($out, array_map($cell, [$r['created_at'], ecw_tz_format((string)$r['created_at'], $csvTz, 'Y-m-d H:i:s'), $r['user_name'], $r['user_email'], ecw_audit_label((string)$r['action']), $r['action'],
            $r['target_type'], $r['target_id'], $r['detail'], $r['via'], $r['ip']]));
    }
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 100;
$rows = $allowed ? ecw_audit_list($orgId, $f, $per, ($page - 1) * $per) : [];
$total = $allowed ? ecw_audit_count($orgId, $f) : 0;
$people = ecw_organization_users($orgId);
// In the viewer's zone (lib/timezone.php), as every time on screen.
$local = static fn(string $utc): string => ecw_tz_local($utc, 'j M Y, H:i:s', (int)$orgId) ?: $utc;
$targetLink = static function (array $r) use ($base, $h): string {
    $id = (int)$r['target_id'];
    $detail = json_decode((string)$r['detail'], true) ?: [];
    return match ((string)$r['target_type']) {
        'case' => '<a href="' . $h($base) . 'case.php?id=' . $id . '">' . $h($detail['case'] ?? ('Case #' . $id)) . '</a>',
        'user' => $id ? 'User #' . $id : '',
        'attachment' => $h($detail['file'] ?? ('File #' . $id)),
        'thread', 'email', 'draft', 'mailbox', 'rule', 'ai_token' => $h(ucfirst(str_replace('_', ' ', (string)$r['target_type'])) . ' #' . $id),
        default => '',
    };
};
$detailText = static function (array $r) use ($h): string {
    $d = json_decode((string)$r['detail'], true);
    if (!is_array($d) || !$d) return '';
    $bits = [];
    foreach ($d as $k => $v) {
        if (in_array($k, ['case', 'file'], true)) continue;
        $v = is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string)$v);
        if ($v === '') continue;
        $bits[] = '<span class="au-kv"><span>' . $h(str_replace('_', ' ', (string)$k)) . '</span> ' . $h($v) . '</span>';
    }
    return implode(' ', $bits);
};
$qs = static fn(array $over) => '?' . http_build_query(array_filter(array_merge($f, $over), static fn($v) => $v !== '' && $v !== 0 && $v !== null));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Audit log — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope au-page">
  <h1>Audit log</h1>
  <p class="muted">A permanent record of who did what: sign-ins, users and rights, cases opened, deleted or shared, files downloaded, emails sent or deleted forever, exports, settings and AI access. It never contains email content or passwords.</p>
  <?php if (!$allowed): ?>
    <div class="panel au-locked"><i class="fa fa-lock" aria-hidden="true"></i> <div><strong>Part of the Business package.</strong><br>Everything is already being recorded; with Business you can view, filter and export it. See <a href="license.php">License</a>.</div></div>
  <?php else: ?>
  <form method="get" class="au-filters">
    <label>From <input type="date" name="from" value="<?= $h($f['from']) ?>"></label>
    <label>To <input type="date" name="to" value="<?= $h($f['to']) ?>"></label>
    <label>Who <select name="user" class="ot-select"><option value="">Everyone</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $f['user'] === (int)$p['id'] ? 'selected' : '' ?>><?= $h($p['name'] ?: $p['email']) ?></option><?php endforeach; ?></select></label>
    <label>What <select name="action" class="ot-select"><?php foreach ($groups as $k => $l): ?><option value="<?= $h($k) ?>" <?= $f['action'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select></label>
    <label class="au-q">Search <input type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="file name, address, IP, case id…"></label>
    <?php if ($f['target_type'] !== ''): ?><input type="hidden" name="target_type" value="<?= $h($f['target_type']) ?>"><input type="hidden" name="target_id" value="<?= (int)$f['target_id'] ?>"><?php endif; ?>
    <div class="au-actions"><button type="submit" class="primary">Show</button> <a class="button" href="audit.php">Clear</a> <a class="button" href="<?= $h($qs(['export' => 'csv'])) ?>"><i class="fa fa-file-csv" aria-hidden="true"></i> Export CSV</a></div>
  </form>
  <?php if ($f['target_type'] !== ''): ?><p class="muted">Only entries about <?= $h($f['target_type']) ?> #<?= (int)$f['target_id'] ?>. <a href="<?= $h($qs(['target_type' => '', 'target_id' => 0])) ?>">Show all</a></p><?php endif; ?>
  <p class="muted small"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> · times in <?= $h($tz) ?></p>
  <div class="au-table-wrap">
  <table class="grid au-table">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>About</th><th>Details</th><th>From</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $bad = in_array($r['action'], ['login.fail', 'login.2fa_fail'], true); ?>
      <tr<?= $bad ? ' class="au-warn"' : '' ?>>
        <td data-label="When" class="au-when"><?= $h($local((string)$r['created_at'])) ?></td>
        <td data-label="Who"><?= $r['user_name'] !== null ? $h($r['user_name']) : '<span class="muted">' . ($r['via'] === 'worker' ? 'Automation' : 'Unknown') . '</span>' ?></td>
        <td data-label="What"><span class="au-action au-<?= $h(explode('.', (string)$r['action'])[0]) ?>"><?= $h(ecw_audit_label((string)$r['action'])) ?></span></td>
        <td data-label="About"><?= $targetLink($r) ?></td>
        <td data-label="Details" class="au-detail"><?= $detailText($r) ?></td>
        <td data-label="From" class="au-from"><?= $h($r['via']) ?><?= $r['ip'] ? '<br><span class="muted small">' . $h($r['ip']) . '</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing matches.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($total > $per): ?>
    <p class="au-pages">
      <?php if ($page > 1): ?><a class="button" href="<?= $h($qs(['page' => $page - 1])) ?>">‹ Newer</a><?php endif; ?>
      <span class="muted">Page <?= $page ?> of <?= (int)ceil($total / $per) ?></span>
      <?php if ($page * $per < $total): ?><a class="button" href="<?= $h($qs(['page' => $page + 1])) ?>">Older ›</a><?php endif; ?>
    </p>
  <?php endif; ?>
  <?php endif; ?>
</main>
</div>
</body></html>
