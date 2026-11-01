<?php declare(strict_types=1);
/**
 * Settings → Backups (admins): make a backup now, download or delete one, and
 * how to restore. Restoring replaces everything, so it is a command
 * (scripts/restore.ps1 / restore.sh), never a button on a web page.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/backup.php';
require_once dirname(__DIR__, 2) . '/lib/offsite.php';
require_once dirname(__DIR__, 2) . '/lib/security.php';
$context = ecw_require_admin(); $navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Download: only a name from the backups folder, admins only.
if (isset($_GET['download'])) {
    $path = ecw_backup_path((string)$_GET['download']);
    if ($path === null) { http_response_code(404); exit('Backup not found'); }
    require_once dirname(__DIR__, 2) . '/lib/audit.php';
    ecw_audit('backup.download', null, null, ['file' => basename($path)]);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

$message = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'The page expired. Reload and try again.';
    } elseif (($_POST['do'] ?? '') === 'create') {
        try { $message = 'Backup created: ' . ecw_backup_create('manual'); }
        catch (Throwable $e) { $error = 'The backup failed: ' . $e->getMessage(); }
    } elseif (($_POST['do'] ?? '') === 'offsite_save' || ($_POST['do'] ?? '') === 'offsite_test') {
        $error = ecw_offsite_settings_save($_POST);
        if ($error === null && ($_POST['do'] ?? '') === 'offsite_test') {
            $why = ecw_offsite_settings()['type'] === 'none' ? null : ecw_offsite_test();
            $why === null ? $message = 'Saved, and the test worked: a file was written there and removed again.' : $error = 'Saved, but the test failed: ' . $why;
        } elseif ($error === null) {
            $message = 'Saved. The newest backup is copied there within a minute, and every new one after it.';
            ecw_app_setting_set('offsite_status', null);
        }
    } elseif (($_POST['do'] ?? '') === 'delete') {
        $path = ecw_backup_path((string)($_POST['name'] ?? ''));
        if ($path !== null && @unlink($path)) $message = 'Deleted ' . basename($path) . '.';
        else $error = 'That backup could not be deleted.';
    }
}
$list = ecw_backup_list();
$off = ecw_offsite_settings();
$offStatus = ecw_offsite_status();
$encrypted = ecw_backup_password() !== null;
$auto = getenv('ECW_BACKUPS') !== '0';
$keep = max(1, (int)(getenv('ECW_BACKUP_KEEP') ?: 14));
$csrf = ecw_csrf_token();
$size = static fn(int $b): string => $b > 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Backups — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>">
<style>
  .backup-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:14px 0 18px; padding:14px 16px; border:1px solid var(--border); border-radius:12px; background:var(--bg); }
  .backup-head .grow { flex:1; min-width:240px; }
  .backup-head .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; background:<?= $auto ? '#2f9e44' : '#adb5bd' ?>; }
  .backup-list { list-style:none; margin:0; padding:0; display:grid; gap:8px; }
  .backup-row { display:flex; align-items:center; gap:12px; padding:10px 14px; border:1px solid var(--border); border-radius:10px; background:var(--bg); }
  .backup-row > i { color:var(--accent); font-size:18px; }
  .backup-row .name { flex:1; min-width:0; }
  .backup-row .name strong { display:block; font-size:13.5px; }
  .backup-row .name span { font-size:12px; color:var(--muted); }
  .backup-row form { margin:0; }
  .tag { display:inline-block; margin-left:6px; padding:0 7px; border-radius:999px; background:var(--bg-alt); color:var(--text-2); font-size:10.5px; font-weight:600; vertical-align:middle; }
  .restore-box { margin-top:12px; margin-bottom:14px; padding:14px 16px; border:1px solid color-mix(in srgb, var(--danger) 30%, var(--border)); border-radius:12px; background:color-mix(in srgb, var(--danger) 4%, var(--bg)); }
  .restore-box code { display:block; margin:6px 0; padding:8px 10px; border-radius:7px; background:var(--bg-alt); font-size:12.5px; overflow-x:auto; }
  .notice { padding:10px 14px; border-radius:10px; margin:10px 0; font-size:13px; }
  .notice.ok { background:color-mix(in srgb, #2f9e44 10%, var(--bg)); border:1px solid color-mix(in srgb, #2f9e44 35%, var(--border)); }
  .notice.err { background:color-mix(in srgb, var(--danger) 10%, var(--bg)); border:1px solid color-mix(in srgb, var(--danger) 35%, var(--border)); }
</style></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>Backups</h1>
  <p class="muted">A backup holds everything that exists only in this app: cases, notes, groups, users, rules, templates, settings and the copy of your mail. Files are saved in the <strong>backups</strong> folder next to the app on this computer — copy them to another disk or cloud folder now and then.</p>
  <?php if ($message): ?><div class="notice ok" role="status"><?= $h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <div class="backup-head">
    <div class="grow">
      <strong><span class="dot"></span><?= $auto ? 'Automatic backup every day' : 'Automatic backups are off' ?></strong>
      <div class="muted"><?= $auto ? 'The newest ' . $keep . ' automatic backups are kept; backups you make yourself are kept until you delete them.' : 'Set ECW_BACKUPS=1 in .env to turn them on.' ?></div>
    </div>
    <form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="create">
      <button type="submit" class="primary" onclick="this.disabled=true;this.textContent='Backing up…';this.form.submit();"><i class="fa fa-floppy-disk" aria-hidden="true"></i> Back up now</button></form>
  </div>

  <?php if (!$list): ?>
    <p class="muted">No backups yet.</p>
  <?php else: ?>
    <ul class="backup-list">
      <?php foreach ($list as $b): ?>
        <li class="backup-row">
          <i class="fa fa-file-zipper" aria-hidden="true"></i>
          <span class="name"><strong><?= $h(ecw_tz_local(gmdate('Y-m-d H:i:s', (int)$b['created']), 'l j F Y, H:i')) ?><?php if (str_contains($b['name'], '-auto')): ?><span class="tag">automatic</span><?php elseif (str_contains($b['name'], '-prerestore')): ?><span class="tag">before a restore</span><?php endif; ?></strong>
            <span><?= $h($b['name']) ?> · <?= $h($size($b['size'])) ?></span></span>
          <a class="button" href="?download=<?= $h(rawurlencode($b['name'])) ?>"><i class="fa fa-download" aria-hidden="true"></i> Download</a>
          <form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="name" value="<?= $h($b['name']) ?>">
            <button type="submit" class="danger" onclick="if (window.ecwArm && !window.ecwArm(this, 'Click again to delete')) return false;" title="Delete this backup"><i class="fa fa-trash-can" aria-hidden="true"></i></button></form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <section class="panel off-panel" id="offsite">
    <h2><i class="fa fa-cloud-arrow-up" aria-hidden="true"></i> Off-site copy <a href="#" class="help-link" data-help="backups"><i class="fa fa-circle-question" aria-hidden="true"></i> Why</a></h2>
    <p class="muted">A backup on the same computer is lost together with it (broken disk, theft, ransomware). Every new backup is copied here automatically, and the newest <?= (int)$off['keep'] ?> are kept there.</p>
    <?php if ($off['type'] !== 'none'): ?>
      <p class="sec-status"><i class="fa <?= $offStatus['error'] && ($offStatus['error_at'] ?? '') >= ($offStatus['ok_at'] ?? '') ? 'fa-circle-exclamation sec-warn' : ($offStatus['ok_at'] ? 'fa-circle-check sec-ok' : 'fa-clock') ?>" aria-hidden="true"></i>
      <?php if ($offStatus['error'] && ($offStatus['error_at'] ?? '') >= ($offStatus['ok_at'] ?? '')): ?>Last copy failed (<?= $h(ecw_tz_local($offStatus['error_at'])) ?>): <?= $h($offStatus['error']) ?>
      <?php elseif ($offStatus['ok_at']): ?>Last copy <?= $h(ecw_tz_local($offStatus['ok_at'])) ?> — <?= $h($offStatus['file']) ?>
      <?php else: ?>Waiting for the first copy.<?php endif; ?></p>
    <?php endif; ?>
    <?php if (!$encrypted): ?><p class="cop-warn">Backups are not encrypted. Set a backup password in <a href="security.php#backup-password">Security</a> first — copies to S3 or WebDAV are only made encrypted.</p><?php endif; ?>
    <form method="post" class="sec-form off-form">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <div class="sec-modes">
        <?php foreach (['none' => ['Off', 'Only on this computer.'], 'folder' => ['Folder', 'A NAS share or a OneDrive / Google Drive / Dropbox folder.'], 's3' => ['S3 storage', 'Amazon S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO…'], 'webdav' => ['WebDAV', 'Nextcloud, Synology, QNAP, ownCloud…']] as $v => [$label, $desc]): ?>
          <label class="sec-mode"><input type="radio" name="type" value="<?= $h($v) ?>" <?= $off['type'] === $v ? 'checked' : '' ?>> <span><strong><?= $h($label) ?></strong><span class="muted small"><?= $h($desc) ?></span></span></label>
        <?php endforeach; ?>
      </div>
      <div class="off-fields" data-for="folder">
        <p class="muted small">In the app's <code>.env</code> file set <code>ECW_OFFSITE_DIR=</code> to the folder (for example <code>D:/OneDrive/ECW-backups</code> or <code>\\nas\backups\ecw</code>), then run <code>docker compose up -d</code>. Without it the copies go to the <code>backups-offsite</code> folder next to the app — still on this computer.</p>
        <p class="muted small">Folder seen by the app: <?= is_dir(ECW_OFFSITE_DIR) && is_writable(ECW_OFFSITE_DIR) ? '<span class="sec-ok">ready</span>' : '<span class="sec-warn">not writable</span>' ?>.</p>
      </div>
      <div class="off-fields" data-for="s3">
        <label>Address (endpoint) <input type="text" name="s3_endpoint" value="<?= $h($off['s3_endpoint']) ?>" placeholder="https://s3.eu-central-1.amazonaws.com"></label>
        <div class="ap-two">
          <label>Region <input type="text" name="s3_region" value="<?= $h($off['s3_region']) ?>" placeholder="eu-central-1"></label>
          <label>Bucket <input type="text" name="s3_bucket" value="<?= $h($off['s3_bucket']) ?>" placeholder="company-backups"></label>
        </div>
        <label>Folder in the bucket (optional) <input type="text" name="s3_prefix" value="<?= $h($off['s3_prefix']) ?>" placeholder="email-case-workspace"></label>
        <div class="ap-two">
          <label>Access key <input type="text" name="s3_key" value="<?= $h($off['s3_key']) ?>" autocomplete="off" spellcheck="false"></label>
          <label>Secret key <input type="password" name="s3_secret" placeholder="<?= $off['has_s3_secret'] ? '•••••••• (saved — leave empty to keep)' : '' ?>" autocomplete="new-password"></label>
        </div>
        <p class="muted small">Give this key permission to write and delete in that bucket only.</p>
      </div>
      <div class="off-fields" data-for="webdav">
        <label>WebDAV folder address <input type="text" name="dav_url" value="<?= $h($off['dav_url']) ?>" placeholder="https://cloud.example.com/remote.php/dav/files/backup/ecw"></label>
        <div class="ap-two">
          <label>User name <input type="text" name="dav_user" value="<?= $h($off['dav_user']) ?>" autocomplete="off"></label>
          <label>Password / app password <input type="password" name="dav_pass" placeholder="<?= $off['has_dav_pass'] ? '•••••••• (saved — leave empty to keep)' : '' ?>" autocomplete="new-password"></label>
        </div>
      </div>
      <label>Keep the newest <input type="number" name="keep" min="1" max="365" value="<?= (int)$off['keep'] ?>" style="width:80px"> copies there</label>
      <p><button type="submit" name="do" value="offsite_save" class="primary">Save</button> <button type="submit" name="do" value="offsite_test">Save and test</button></p>
    </form>
  </section>
  <script>
  (() => { const f = document.querySelector('.off-form'); if (!f) return;
    const sync = () => { const v = (f.querySelector('input[name=type]:checked') || {}).value; f.querySelectorAll('.off-fields').forEach((d) => { d.hidden = d.dataset.for !== v; }); };
    f.addEventListener('change', sync); sync(); })();
  </script>

  <details class="st-fold" id="restore">
  <summary><span class="st-fold-title"><i class="fa fa-rotate-left" aria-hidden="true"></i> Restoring a backup</span><span class="st-fold-hint">Done on the computer running the app, not on this page</span></summary>
  <div class="restore-box">
    <p class="muted" style="margin:0 0 6px">Restoring replaces <em>all</em> current data with the backup, so it is done from the computer running the app, not from this page. A safety backup of the current data is made first. In the app folder run:</p>
    <code>.\scripts\restore.ps1 ecw-backup-…zip&nbsp;&nbsp;&nbsp;(Windows PowerShell)</code>
    <code>sh scripts/restore.sh ecw-backup-…zip&nbsp;&nbsp;&nbsp;(Mac / Linux)</code>
    <p class="muted" style="margin:6px 0 0">Backups include the key that protects the stored mailbox connections, so a restored copy keeps working without reconnecting. Treat backup files like your mailbox password<?= $encrypted ? '' : ' — or encrypt them with a backup password (Settings → Security)' ?>.</p>
    <p class="muted" style="margin:6px 0 0">An encrypted backup restores on another server once <code style="display:inline;padding:1px 5px">ECW_BACKUP_PASSWORD</code> is set there to its password.</p>
  </div>
  </details>

  <?php require_once dirname(__DIR__, 2) . '/lib/export.php'; $export = ecw_export_state((int)$context['organization_id']); ?>
  <section class="panel" id="export">
    <h2><i class="fa fa-file-zipper" aria-hidden="true"></i> Export everything</h2>
    <p class="muted">A copy of all your cases that opens without this app — to keep, to hand over, or to move to another system.</p>
    <details class="st-fold st-fold-inline"><summary><span class="st-fold-title">What is inside an export</span></summary>
    <p class="muted small">Every case gets its own folder: the case on one page (open it in a browser), every email as a file Outlook opens, with its attachments, and the notes, checklist and history as spreadsheets. Plus the people, groups and contacts. A backup is for restoring this app; an export is for people and other programs.</p>
    </details>
    <div id="exportBox" data-state="<?= $h($export['state'] ?? '') ?>">
      <?php if (($export['state'] ?? '') === 'ready'): ?>
        <p><i class="fa fa-circle-check sec-ok" aria-hidden="true"></i> Ready: <?= (int)$export['cases'] ?> cases, <?= $h(round($export['size'] / 1048576, 1)) ?> MB, made <?= $h(ecw_tz_local($export['at'])) ?> (kept <?= ECW_EXPORT_KEEP_DAYS ?> days).
          <?php if (!empty($export['missing'])): ?><span class="muted"><?= (int)$export['missing'] ?> attachments could not be fetched from the mailbox (listed inside).</span><?php endif; ?></p>
        <p><a class="primary btn-like" href="<?= $h($base) ?>api/index.php?action=export_download"><i class="fa fa-download" aria-hidden="true"></i> Download</a></p>
      <?php elseif (in_array($export['state'] ?? '', ['queued', 'running'], true)): ?>
        <p><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> Being made in the background. You get a notification when it is ready — you can leave this page.</p>
      <?php elseif (($export['state'] ?? '') === 'failed'): ?>
        <p class="flash err">The last export failed: <?= $h($export['error'] ?? '') ?></p>
      <?php endif; ?>
      <?php if (!in_array($export['state'] ?? '', ['queued', 'running'], true)): ?>
        <p><label class="checkbox-label"><input type="checkbox" id="exportFiles" checked> With the attachments (larger, takes longer)</label></p>
        <p><button type="button" id="exportStart" class="<?= ($export['state'] ?? '') === 'ready' ? '' : 'primary' ?>"><?= ($export['state'] ?? '') === 'ready' ? 'Make a new export' : 'Export everything' ?></button></p>
      <?php endif; ?>
    </div>
    <script>
    (() => {
      const b = document.getElementById('exportStart');
      b?.addEventListener('click', async () => {
        b.disabled = true;
        const r = await fetch(<?= json_encode($base . 'api/index.php?action=export_start', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf }, body: JSON.stringify({ files: document.getElementById('exportFiles').checked }) });
        if (r.ok) location.reload(); else { b.disabled = false; alert((await r.json().catch(() => ({}))).error || 'Could not start the export.'); }
      });
      // While it is being made: look again every 15 seconds.
      if (['queued', 'running'].includes(document.getElementById('exportBox').dataset.state)) setTimeout(() => location.reload(), 15000);
    })();
    </script>
  </section>
</main>
</div>
</body></html>
