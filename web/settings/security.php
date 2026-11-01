<?php declare(strict_types=1);
/**
 * Settings → Security (admins): Microsoft sign-in, legal holds, data
 * retention, backup password and encryption at rest. The rules live in
 * lib/security.php and lib/sso.php.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/sso.php';
$context = ecw_require_admin();
$orgId = (int)$context['organization_id'];
$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$licensed = ecw_security_licensed();
$notice = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $form = (string)($_POST['form'] ?? '');
        if ($form === 'backup_password') {
            $pw = (string)($_POST['backup_password'] ?? '');
            if (!empty($_POST['remove'])) $pw = '';
            elseif ($pw !== (string)($_POST['backup_password2'] ?? '')) $error = 'The two passwords are not the same.';
            if ($error === null) {
                $error = ecw_backup_password_set($pw, $orgId);
                if ($error === null) $notice = $pw === '' ? 'Backups are no longer encrypted.' : 'Saved. New backups are encrypted with this password — keep it somewhere safe: without it they cannot be restored.';
            }
        } elseif (!$licensed) {
            $error = 'Microsoft sign-in and retention are part of the Business package.';
        } elseif ($form === 'sso') {
            $error = ecw_security_settings_save($orgId, $_POST);
            if ($error === null) $notice = 'Saved.';
        } elseif ($form === 'retention') {
            $error = ecw_security_settings_save($orgId, array_intersect_key($_POST, ['audit_days' => 1, 'trash_days' => 1, 'removed_days' => 1]));
            if ($error === null) $notice = 'Saved. Retention runs once a day in the background.';
        }
    }
}

$s = ecw_security_settings($orgId, true);
$msReady = ecw_provider_configured('microsoft');
$meLinked = ecw_sso_linked((int)$context['user']['id']);
$st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE ou.organization_id = ? AND u.sso_subject IS NOT NULL');
$st->execute([$orgId]);
$linkedCount = (int)$st->fetchColumn();
$st = ecw_db()->prepare("SELECT DISTINCT provider_tenant_id FROM `" . ecw_table('mailboxes') . "` WHERE organization_id = ? AND provider = 'microsoft' AND provider_tenant_id IS NOT NULL AND provider_tenant_id <> '' AND provider_tenant_id <> '9188040d-6c67-4c5b-b112-36a304b66dad'");
$st->execute([$orgId]);
$knownTenants = $st->fetchAll(PDO::FETCH_COLUMN);
$held = ecw_held_cases($orgId);
$preview = $licensed ? ecw_retention_run($orgId, true) : ['audit' => 0, 'cases' => 0, 'emails' => 0];
$backupEncrypted = ecw_backup_password() !== null;
$backupFromEnv = (string)getenv('ECW_BACKUP_PASSWORD') !== '';
$dbEnc = ecw_db_encryption_status();
$daysOpts = static fn(array $opts, int $cur): string => implode('', array_map(static fn($d) => '<option value="' . $d . '"' . ($d === $cur ? ' selected' : '') . '>' . ($d === 0 ? 'Keep forever' : ($d % 365 === 0 ? ($d / 365) . ' year' . ($d === 365 ? '' : 's') : $d . ' days')) . '</option>', array_unique(array_merge($opts, [$cur]))));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Security — Case Workspace</title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope sec-page">
  <h1>Security</h1>
  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if (!$licensed): ?><div class="panel au-locked"><i class="fa fa-lock" aria-hidden="true"></i> <div><strong>Microsoft sign-in, legal hold and retention are part of the Business package.</strong><br>Backup encryption and encryption at rest work in every package. See <a href="license.php">License</a>.</div></div><?php endif; ?>

  <section class="panel" id="sso">
    <h2><svg width="16" height="16" viewBox="0 0 21 21" aria-hidden="true" style="vertical-align:-2px"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg> Sign in with Microsoft</h2>
    <p class="muted">People sign in with their work Microsoft account, so Microsoft's multi-factor and company sign-in rules protect the app too, and switching someone off in Microsoft 365 locks them out here. Only who they are is asked for — no mail access.</p>
    <?php if (!$msReady): ?>
      <div class="cop-warn">The Microsoft connection is not set up on this server (the same one used for connecting Outlook mailboxes).</div>
    <?php else: ?>
    <form method="post" class="sec-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="sso">
      <fieldset class="sec-modes"<?= $licensed ? '' : ' disabled' ?>>
        <legend class="sr-only">Microsoft sign-in</legend>
        <?php foreach (['off' => ['Off', 'Passwords only.'], 'on' => ['On', 'People choose: password or Microsoft.'], 'required' => ['Required', 'Everyone except admins must use Microsoft. Admins keep their password as the way back in.']] as $v => [$label, $desc]): ?>
          <label class="sec-mode"><input type="radio" name="sso" value="<?= $h($v) ?>" <?= $s['sso'] === $v ? 'checked' : '' ?>> <span><strong><?= $h($label) ?></strong><span class="muted small"><?= $h($desc) ?></span></span></label>
        <?php endforeach; ?>
      </fieldset>
      <label for="ssoTenant">Your Microsoft 365 tenant ID <span class="muted small">(Entra admin center → Overview → Tenant ID)</span></label>
      <input type="text" id="ssoTenant" name="sso_tenant" value="<?= $h($s['sso_tenant']) ?>" placeholder="00000000-0000-0000-0000-000000000000" spellcheck="false" autocomplete="off" <?= $licensed ? '' : 'disabled' ?> list="ssoTenantKnown">
      <?php if ($knownTenants): ?><datalist id="ssoTenantKnown"><?php foreach ($knownTenants as $t): ?><option value="<?= $h($t) ?>"><?php endforeach; ?></datalist>
        <p class="muted small">From your connected mailboxes: <?php foreach ($knownTenants as $i => $t): ?><button type="button" class="sec-chip" data-fill="<?= $h($t) ?>"><?= $h($t) ?></button><?php endforeach; ?></p>
      <?php endif; ?>
      <p class="muted small">People from this tenant are matched to their account here by email address the first time. Anyone else (another company, a personal Microsoft account) links Microsoft themselves under Account.</p>
      <label class="checkbox-label"><input type="checkbox" name="sso_create" value="1" <?= $s['sso_create'] ? 'checked' : '' ?> <?= $licensed ? '' : 'disabled' ?>> Give people of this tenant without an account one automatically, as
        <select name="sso_role" class="ot-select" <?= $licensed ? '' : 'disabled' ?>><?php foreach (array_diff(ECW_ROLES, ['admin']) as $r): ?><option value="<?= $h($r) ?>" <?= $s['sso_role'] === $r ? 'selected' : '' ?>><?= $h($r === 'read_only' ? 'Read only' : 'User') ?></option><?php endforeach; ?></select>
      </label>
      <p class="muted small sec-status"><i class="fa <?= $meLinked ? 'fa-circle-check sec-ok' : 'fa-circle-info' ?>" aria-hidden="true"></i>
        <?= $meLinked ? 'Your own Microsoft account is linked.' : 'Your own account is not linked yet — <a href="../account.php#microsoft">link it under Account</a> to test before you make it required.' ?>
        <?= $linkedCount ?> <?= $linkedCount === 1 ? 'person has' : 'people have' ?> Microsoft sign-in linked.</p>
      <p><button type="submit" class="primary" <?= $licensed ? '' : 'disabled' ?>>Save</button></p>
    </form>
    <?php endif; ?>
  </section>

  <section class="panel" id="hold">
    <h2><i class="fa fa-scale-balanced" aria-hidden="true"></i> Legal hold</h2>
    <p class="muted">For a dispute, claim or audit: a case on hold cannot be deleted, emails cannot be taken out of it or deleted for good from it, and retention skips it. Emails someone deletes in Outlook stay here. Put a case on hold with the <i class="fa fa-scale-balanced" aria-hidden="true"></i> button at the top of the case.</p>
    <?php if ($held): ?>
      <table class="sec-table"><thead><tr><th>Case</th><th>Reason</th><th>Since</th><th>By</th></tr></thead><tbody>
      <?php foreach ($held as $c): ?>
        <tr><td><a href="<?= $h($base) ?>case.php?id=<?= (int)$c['id'] ?>"><?= $h($c['case_number']) ?></a> <?= $h($c['name']) ?></td><td><?= $h($c['legal_hold_note'] ?? '—') ?></td><td><?= $h(substr((string)$c['legal_hold_at'], 0, 10)) ?></td><td><?= $h($c['by_name'] ?? '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php else: ?><p class="muted small">No case is on hold.</p><?php endif; ?>
  </section>

  <section class="panel" id="retention">
    <h2><i class="fa fa-hourglass-half" aria-hidden="true"></i> Data retention</h2>
    <p class="muted">How long this app keeps things. Mail in the mailbox itself follows your Microsoft 365 / Google retention, not this. Cases on legal hold are never touched.</p>
    <form method="post" class="sec-form">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="retention">
      <div class="sec-ret">
        <label>Audit log <select name="audit_days" class="ot-select" <?= $licensed ? '' : 'disabled' ?>><?= $daysOpts([0, 365, 730, 1095, 1825, 2555], $s['audit_days']) ?></select></label>
        <label>Deleted cases, removed for good after <select name="trash_days" class="ot-select" <?= $licensed ? '' : 'disabled' ?>><?= $daysOpts([0, 30, 90, 180, 365], $s['trash_days']) ?></select></label>
        <label>Copies of emails deleted from the mailbox <select name="removed_days" class="ot-select" <?= $licensed ? '' : 'disabled' ?>><?= $daysOpts([0, 90, 365, 730, 1825], $s['removed_days']) ?></select></label>
      </div>
      <?php if ($licensed && array_sum($preview) > 0): ?>
        <p class="sec-preview"><i class="fa fa-circle-info" aria-hidden="true"></i> With these settings the next run removes <?= implode(', ', array_filter([$preview['audit'] ? number_format($preview['audit']) . ' audit entries' : '', $preview['cases'] ? $preview['cases'] . ' deleted case' . ($preview['cases'] === 1 ? '' : 's') : '', $preview['emails'] ? number_format($preview['emails']) . ' email cop' . ($preview['emails'] === 1 ? 'y' : 'ies') : ''])) ?>.</p>
      <?php endif; ?>
      <p><button type="submit" class="primary" <?= $licensed ? '' : 'disabled' ?>>Save</button></p>
    </form>
  </section>

  <section class="panel" id="backup-password">
    <h2><i class="fa fa-file-zipper" aria-hidden="true"></i> Backup encryption</h2>
    <p class="muted">Backups hold all mail and the key to the connected mailboxes. With a password they are AES-256 encrypted, so a copied backup file is useless without it. <strong>Keep the password safe</strong>: a backup cannot be restored without it.</p>
    <p class="sec-status"><i class="fa <?= $backupEncrypted ? 'fa-lock sec-ok' : 'fa-lock-open sec-warn' ?>" aria-hidden="true"></i> <?= $backupEncrypted ? 'New backups are encrypted' . ($backupFromEnv ? ' (password set on the server, ECW_BACKUP_PASSWORD).' : '.') : 'Backups are not encrypted.' ?></p>
    <?php if (!$backupFromEnv): ?>
    <form method="post" class="sec-form sec-pw" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="form" value="backup_password">
      <label>New backup password <input type="password" name="backup_password" minlength="12" autocomplete="new-password"></label>
      <label>Again <input type="password" name="backup_password2" minlength="12" autocomplete="new-password"></label>
      <p><button type="submit" class="primary"><?= $backupEncrypted ? 'Change password' : 'Encrypt backups' ?></button>
        <?php if ($backupEncrypted): ?> <button type="submit" name="remove" value="1" formnovalidate>Stop encrypting</button><?php endif; ?></p>
      <p class="muted small">To restore an encrypted backup on another server, set ECW_BACKUP_PASSWORD there before running the restore script.</p>
    </form>
    <?php endif; ?>
  </section>

  <section class="panel" id="at-rest">
    <h2><i class="fa fa-database" aria-hidden="true"></i> Encryption at rest</h2>
    <ul class="sec-list">
      <li><i class="fa fa-circle-check sec-ok" aria-hidden="true"></i> Mailbox sign-in tokens, AI keys, two-factor secrets, webhook addresses and the backup password are encrypted by the app (AES-256-GCM).</li>
      <li><i class="fa fa-circle-check sec-ok" aria-hidden="true"></i> Passwords are stored only as hashes; AI access tokens only as SHA-256.</li>
      <?php if ($dbEnc['keyring'] && $dbEnc['encrypted'] >= $dbEnc['tables'] && $dbEnc['tables'] > 0): ?>
        <li><i class="fa fa-circle-check sec-ok" aria-hidden="true"></i> The database is encrypted on disk: all <?= $dbEnc['tables'] ?> tables<?= $dbEnc['redo'] ? ', and its logs' : '' ?>. Keep the Docker volume <code>db_keyring</code> safe — it holds the key.</li>
      <?php elseif ($dbEnc['keyring']): ?>
        <li><i class="fa fa-circle-exclamation sec-warn" aria-hidden="true"></i> The keyring is on, but <?= $dbEnc['encrypted'] ?> of <?= $dbEnc['tables'] ?> tables are encrypted. Run <code>scripts/encrypt-at-rest.ps1</code> again to finish.</li>
      <?php else: ?>
        <li><i class="fa fa-circle-info" aria-hidden="true"></i> The database files are not encrypted on disk. To encrypt them (mail text, attachments' text, everything), run <code>.\scripts\encrypt-at-rest.ps1</code> on the server once (Linux: <code>sh scripts/encrypt-at-rest.sh</code>). It makes a backup first; search keeps working. It protects a copied disk, volume or server image — not a running, broken-into server.</li>
      <?php endif; ?>
    </ul>
  </section>
</main>
</div>
<script>
document.querySelectorAll('.sec-chip').forEach((b) => b.addEventListener('click', () => { const i = document.getElementById('ssoTenant'); i.value = b.dataset.fill; i.focus(); }));
</script>
</body></html>
