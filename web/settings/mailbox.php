<?php declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/oauth.php';
require_once dirname(__DIR__, 2) . '/lib/shared_mailboxes.php';

$context = ecw_require_login();
$orgId = $context['organization_id'];
$base = ecw_web_base();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('This form has expired. Reload and try again.');
    }
    $mailbox = ecw_require_mailbox_access((int)($_POST['mailbox_id'] ?? 0));
    $id = (int)$mailbox['id'];
    $canManage = ecw_mailbox_can_manage((int)$orgId, (int)$context['user']['id'], $mailbox);
    if (($_POST['action'] ?? '') === 'folders_sync' && $canManage) {
        // Inbox and Sent always; Outlook's bookkeeping folders never.
        $want = array_map('intval', (array)($_POST['folders'] ?? []));
        $ft = ecw_table('mail_folders');
        $rows = ecw_db()->prepare("SELECT id, folder_type FROM `{$ft}` WHERE mailbox_id = ? AND folder_type <> 'system'");
        $rows->execute([$id]);
        $set = ecw_db()->prepare("UPDATE `{$ft}` SET is_selected_for_sync = ? WHERE id = ?");
        foreach ($rows->fetchAll() as $r) {
            $on = in_array($r['folder_type'], ['inbox', 'sent'], true) || in_array((int)$r['id'], $want, true);
            $set->execute([$on ? 1 : 0, (int)$r['id']]);
        }
        ecw_queue_enqueue($id, null, 'folders', 'manual');   // imports the newly chosen folders
        $_SESSION['ecw_outlook_notice'] = 'folders_saved';
    } elseif (($_POST['action'] ?? '') === 'share') {
        $err = ecw_mailbox_share_save((int)$orgId, (int)$context['user']['id'], $id, !empty($_POST['shared']), array_map('intval', (array)($_POST['members'] ?? [])));
        $_SESSION['ecw_outlook_notice'] = $err === null ? 'shared_saved' : 'shared_denied';
    } elseif (($_POST['action'] ?? '') === 'disconnect' && !$canManage) {
        $_SESSION['ecw_outlook_notice'] = 'shared_denied';
    } elseif (($_POST['action'] ?? '') === 'disconnect' && ($_POST['confirm'] ?? '') === 'yes') {
        ecw_mailbox_disconnect($id);
        $_SESSION['ecw_outlook_notice'] = 'disconnected';
    } elseif (($_POST['action'] ?? '') === 'sync' && in_array($mailbox['sync_status'], ['connected', 'syncing', 'temp_error'], true)) {
        require_once dirname(__DIR__, 2) . '/lib/sync/activity.php';
        ecw_mailbox_sync_now($id);
        $_SESSION['ecw_outlook_notice'] = 'sync';
    } elseif (($_POST['action'] ?? '') === 'snooze_pref' && (int)$mailbox['connected_by_user_id'] === (int)$context['user']['id']) {
        ecw_db()->prepare('UPDATE `' . ecw_table('mailboxes') . '` SET snooze_in_mailbox = ? WHERE id = ?')->execute([!empty($_POST['on']) ? 1 : 0, $id]);
    }
    ecw_redirect($base . 'settings/mailbox.php');
}
$mbParams = [$orgId];
$st = ecw_db()->prepare('SELECT mb.*, u.name AS connected_by_name FROM `' . ecw_table('mailboxes') . '` mb LEFT JOIN `' . ecw_table('users') . '` u ON u.id = mb.connected_by_user_id
                          WHERE mb.organization_id = ?' . (ecw_private_mailboxes() ? ' AND ' . ecw_mailbox_visible_sql('mb', (int)$context['user']['id'], $mbParams) : '') . ' ORDER BY mb.is_shared, mb.id');
$st->execute($mbParams);
$mailboxes = $st->fetchAll();
// One account at a time (lib/account.php): this page shows the account chosen at
// the top; the others are listed below it, one click away.
require_once dirname(__DIR__, 2) . '/lib/account.php';
$activeAccountId = ecw_active_account_id((int)$orgId, (int)$context['user']['id']);
$otherAccounts = array_values(array_filter($mailboxes, static fn($m) => (int)$m['id'] !== $activeAccountId));
// And the accounts of the other workspaces (companies) this person is in.
foreach (ecw_account_choices((int)$orgId, (int)$context['user']['id']) as $oa) {
    if ($oa['organization_id'] !== (int)$orgId) $otherAccounts[] = $oa + ['other_workspace' => true];
}
$mailboxes = array_values(array_filter($mailboxes, static fn($m) => (int)$m['id'] === $activeAccountId));
$isAdmin = ($context['role'] ?? '') === 'admin';
$people = ecw_private_mailboxes() ? ecw_organization_users((int)$orgId) : [];   // only for choosing who sees a shared mailbox
$sharedError = $_SESSION['ecw_outlook_shared_error'] ?? null;
unset($_SESSION['ecw_outlook_shared_error']);
$configured = GraphProductConfig::isConfigured();
$googleConfigured = ecw_provider_configured('google');
$notice = $_SESSION['ecw_outlook_notice'] ?? '';
unset($_SESSION['ecw_outlook_notice']);
$messages = [
    'connected' => '✓ Mailbox connected successfully. Initial mailbox synchronization has started.',
    'consent_incomplete' => 'Microsoft authorization is not complete. If you submitted an administrator approval request, wait for approval and then try again. Otherwise, sign in again to grant consent.',
    'denied' => 'The mailbox was not connected. Authorization was declined. You can try again.',
    'error' => 'The mailbox could not be connected. Please try again or contact your system administrator.',
    'approval_return' => 'If your administrator approved the application, choose Connect Microsoft Outlook to finish connecting your mailbox.',
    'disconnected' => 'Mailbox disconnected. Your synchronized email and Case history have been kept.',
    'sync' => 'Synchronization queued. You can leave this page while it runs.',
    'folders_saved' => 'Saved. Newly chosen folders are being imported now (newest email first); you can keep working.',
    'shared_saved' => 'Saved. Everyone it is shared with sees it under Mail in the mailbox list.',
    'shared_denied' => 'Only the person who connected this mailbox, or an admin, can change that.',
    'shared_no_access' => 'The shared mailbox could not be opened with your account. In Exchange admin center, give yourself "Full Access" (and "Send As") on it, wait about 30 minutes, and try again.',
];
$labels = ['connected' => 'Connected · Healthy', 'syncing' => 'Connected · Syncing',
    'needs_reauth' => 'Connection expired', 'disconnected' => 'Disconnected',
    'temp_error' => 'Error · Retrying automatically', 'pending' => 'Not connected'];
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$navActive = 'settings';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mailbox — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css">
</head><body class="page-body">
<?php  require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page wide">
<h1>Mailbox</h1>
<p>Connect your Microsoft 365 (Outlook) or Google (Gmail) mailbox to synchronize email, send/reply from the workspace, and use Cases.</p>
<?php if (isset($messages[$notice])): ?><div class="flash"><?= $h($messages[$notice]) ?></div><?php endif; ?>
<?php if (!$configured && !$googleConfigured && !$isAdmin): ?>
<div class="flash err">Outlook and Gmail are not set up on this server yet. Ask your administrator to set them up; then you can connect your mailbox here.</div>
<?php elseif (!$configured && !$googleConfigured): ?>
<section class="panel"><h3>One-time setup: Outlook or Gmail</h3>
<p>Before anyone can connect a mailbox, register <?= $h(ecw_product_brand()['name']) ?> once with Microsoft or Google. It takes about 10 minutes, and the setup guide walks you through it:</p>
<ol>
  <li>In the guide, <strong>load your current <code>.env</code></strong> (in the app folder), so its passwords are kept.</li>
  <li>Register the app with Microsoft or Google. The guide shows this installation's exact <strong>redirect address</strong> to paste there.</li>
  <li>Paste the two values you get into the guide, <strong>download the new <code>.env</code></strong> and put it in the app folder in place of the old one.</li>
  <li>Restart the app (the guide shows the command), then come back here and connect.</li>
</ol>
<p><a class="button primary" href="<?= $h($base) ?>setup-guide.php#mail" target="_blank" rel="noopener"><i class="fa-brands fa-microsoft" aria-hidden="true"></i> Set up Outlook</a>
<a class="button" href="<?= $h($base) ?>setup-guide.php#mail-google" target="_blank" rel="noopener"><i class="fa-brands fa-google" aria-hidden="true"></i> Set up Gmail</a></p>
</section>
<?php elseif ($configured && ($notice === 'approval_required' || !empty($_SESSION['ecw_outlook_approval'][$orgId]))): ?>
<section class="panel"><h3>Administrator approval required</h3>
<p>Your Microsoft 365 administrator must approve Email Case Workspace before this mailbox can be connected.</p>
<p>Ask your administrator to approve the application, then try again. The approval button opens Microsoft’s administrator consent screen. Only a Microsoft 365 administrator can approve it. If your organization offers an approval request during Microsoft sign-in, you can use that workflow.</p>
<a class="button" href="<?= $h($base) ?>oauth/start.php?action=approval">Request administrator approval</a>
<a class="button" href="<?= $h($base) ?>oauth/start.php">Try again</a></section>
<?php endif; ?>
<?php foreach ($mailboxes as $mailbox): ?>
<section class="panel">
<?php $isGoogle = $mailbox['provider'] === 'google'; $providerOk = $isGoogle ? $googleConfigured : $configured; $plabel = ecw_provider_label((string)$mailbox['provider']); ?>
<?php $mine = (int)($mailbox['connected_by_user_id'] ?? 0) === (int)$context['user']['id']; $canManage = $mine || $isAdmin; $members = ecw_mailbox_members((int)$mailbox['id']); $isTeamBox = (int)$mailbox['is_shared'] === 1; ?>
<h3><i class="fa <?= $isGoogle ? 'fa-brands fa-google' : 'fa-brands fa-microsoft' ?>" aria-hidden="true"></i> <?= $h($mailbox['email_address']) ?> <span class="muted small">· <?= $h($plabel) ?></span>
  <?php if ($isTeamBox): ?><span class="mbx-shared-badge"><i class="fa fa-users" aria-hidden="true"></i> Shared · <?= $members ? count($members) . ' ' . (count($members) === 1 ? 'person' : 'people') : 'everyone' ?></span><?php endif; ?></h3>
<?php if (!$mine && $mailbox['connected_by_name']): ?><p class="muted small">Connected by <?= $h($mailbox['connected_by_name']) ?><?= $mailbox['shared_address'] ? ' (Microsoft 365 shared mailbox, through their sign-in)' : '' ?>.</p><?php endif; ?>
<p><?= $h($labels[$mailbox['sync_status']] ?? 'Error') ?></p>
<p>Last sync: <?= $h(ecw_tz_local($mailbox['last_sync_at'] ?? null) ?: 'Not yet synchronized') ?></p>
<?php if ($mailbox['sync_status'] === 'syncing' && !$mailbox['initial_sync_completed_at']): ?><p>Initial synchronization in progress… You can leave this page.</p><?php endif; ?>
<?php if ($providerOk && in_array($mailbox['sync_status'], ['needs_reauth', 'disconnected', 'pending'], true)): ?>
<p><?= $h($plabel) ?> needs to be reconnected. Sign in with <?= $h($mailbox['email_address']) ?>.</p>
<a class="button primary" href="<?= $h($base) ?>oauth/start.php<?= $isGoogle ? '?provider=google' : ($mailbox['shared_address'] ? '?shared=' . rawurlencode((string)$mailbox['shared_address']) : '') ?>">Reconnect <?= $h($plabel) ?></a>
<?php endif; ?>
<?php if (!in_array($mailbox['sync_status'], ['disconnected', 'pending'], true)): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="mailbox_id" value="<?= (int)$mailbox['id'] ?>">
<?php if ($providerOk && $mailbox['sync_status'] !== 'needs_reauth'): ?><button class="button sync-now-settings" name="action" value="sync" data-mailbox-id="<?= (int)$mailbox['id'] ?>"><i class="fa fa-arrows-rotate" aria-hidden="true"></i> <span>Sync now</span></button><?php endif; ?>
</form>
<?php if ($canManage && !in_array($mailbox['sync_status'], ['disconnected', 'pending'], true)):
    $fq = ecw_db()->prepare("SELECT f.id, f.name, f.folder_type, f.is_selected_for_sync, f.parent_provider_folder_id, f.provider_folder_id,
                                    (SELECT COUNT(*) FROM `" . ecw_table('email_messages') . "` m WHERE m.mail_folder_id = f.id AND m.provider_removed_at IS NULL) AS n
                               FROM `" . ecw_table('mail_folders') . "` f WHERE f.mailbox_id = ? AND f.folder_type <> 'system'
                              ORDER BY FIELD(f.folder_type, 'inbox', 'sent', 'custom', 'archive', 'snoozed', 'junk', 'deleted', 'drafts'), f.name");
    $fq->execute([(int)$mailbox['id']]);
    $mbFolders = $fq->fetchAll(); $syncedCount = count(array_filter($mbFolders, static fn($x) => (int)$x['is_selected_for_sync'] === 1)); ?>
<details class="mbx-section mbx-folders" data-mailbox-id="<?= (int)$mailbox['id'] ?>"<?= $mbFolders === [] ? ' open' : '' ?>>
  <summary><i class="fa fa-folder-tree" aria-hidden="true"></i> Folders to sync <span class="muted small mbx-folder-count">(<?= $syncedCount ?> of <?= count($mbFolders) ?>)</span></summary>
  <form method="post" class="mbx-body">
    <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="mailbox_id" value="<?= (int)$mailbox['id'] ?>"><input type="hidden" name="action" value="folders_sync">
    <p class="muted small"><a href="#" class="help-link" data-help="folders"><i class="fa fa-circle-question" aria-hidden="true"></i> Folders</a> Mail in these folders is brought into the app (search, cases, the assistant). Outlook's own bookkeeping folders (Sync Issues, Conflicts, Outbox…) are left out. The AI agents never read Junk or Deleted Items.</p>
    <p class="mbx-folder-status muted small" role="status"<?= $mbFolders === [] ? '' : ' hidden' ?>><span class="ai-spin" aria-hidden="true"></span> Finding your folders in Outlook… they appear here by themselves.</p>
    <div class="mbx-people mbx-folder-list">
      <?php foreach ($mbFolders as $fo): $fixed = in_array($fo['folder_type'], ['inbox', 'sent'], true); ?>
        <label class="checkbox-label mbx-folder" data-folder-id="<?= (int)$fo['id'] ?>"><input type="checkbox" name="folders[]" value="<?= (int)$fo['id'] ?>" <?= (int)$fo['is_selected_for_sync'] === 1 ? 'checked' : '' ?> <?= $fixed ? 'disabled' : '' ?>>
          <span class="mbx-folder-name"><?= $h($fo['name']) ?><?= $fixed ? ' <span class="muted small">(always)</span>' : '' ?></span> <span class="mbx-folder-progress muted small"><?= (int)$fo['n'] ?></span></label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="button primary">Save</button>
  </form>
</details>
<?php endif; ?>
<?php if ($canManage && ecw_private_mailboxes() && !in_array($mailbox['sync_status'], ['disconnected', 'pending'], true)): ?>
<details class="mbx-section mbx-share" <?= $isTeamBox ? '' : '' ?>>
  <summary><i class="fa fa-users" aria-hidden="true"></i> <?= $isTeamBox ? 'Who works from this mailbox' : 'Share with the team' ?></summary>
  <form method="post" class="mbx-body">
    <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="mailbox_id" value="<?= (int)$mailbox['id'] ?>"><input type="hidden" name="action" value="share">
    <?php if ($mailbox['shared_address']): ?><input type="hidden" name="shared" value="1"><?php else: ?>
    <label class="checkbox-label"><input type="checkbox" name="shared" value="1" <?= $isTeamBox ? 'checked' : '' ?>> <span><strong>Shared mailbox</strong> — like info@ or sales@: colleagues read it, reply from it and claim conversations, so two people never answer the same customer.</span></label>
    <?php endif; ?>
    <p class="muted small">Who sees it (nobody ticked = everyone in the workspace):</p>
    <div class="mbx-people">
      <?php foreach ($people as $p): if ($p['disabled_at'] !== null || (int)$p['id'] === (int)$mailbox['connected_by_user_id']) continue; ?>
        <label class="checkbox-label"><input type="checkbox" name="members[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $members, true) ? 'checked' : '' ?>> <?= $h($p['name'] ?: $p['email']) ?></label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="button primary">Save</button>
  </form>
</details>
<?php endif; ?>
<?php if ($mine && !$mailbox['shared_address'] && !in_array($mailbox['sync_status'], ['needs_reauth'], true)): ?>
<div class="mailbox-extra btn-scope" data-mailbox="<?= (int)$mailbox['id'] ?>" data-provider="<?= $h($mailbox['provider']) ?>">
  <form method="post" class="mbx-pref">
    <input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="mailbox_id" value="<?= (int)$mailbox['id'] ?>"><input type="hidden" name="action" value="snooze_pref">
    <label class="checkbox-label"><input type="checkbox" name="on" value="1" <?= (int)($mailbox['snooze_in_mailbox'] ?? 1) === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
      <span><strong>Snooze in <?= $mailbox['provider'] === 'google' ? 'Gmail' : 'Outlook' ?> too</strong> — a snoozed conversation moves to a “Snoozed” <?= $mailbox['provider'] === 'google' ? 'label' : 'folder' ?> there, and back when it wakes.</span></label>
  </form>
  <details class="mbx-section" id="auto-reply" <?= isset($_GET['auto_reply']) ? 'open' : '' ?>>
    <summary><i class="fa fa-plane-departure" aria-hidden="true"></i> Automatic replies <span class="mbx-badge" data-ar-badge hidden>On</span></summary>
    <div class="mbx-body" data-ar><p class="muted">Loading…</p></div>
  </details>
  <details class="mbx-section">
    <summary><i class="fa fa-user-slash" aria-hidden="true"></i> Blocked senders <span class="muted small" data-bl-count></span></summary>
    <div class="mbx-body" data-bl><p class="muted">Loading…</p></div>
  </details>
  <?php if ($mailbox['provider'] === 'microsoft' && strcasecmp((string)($mailbox['provider_tenant_id'] ?? ''), MicrosoftGraphProvider::PERSONAL_TENANT) !== 0): ?>
  <details class="mbx-section mbx-approve" id="approve-<?= (int)$mailbox['id'] ?>">
    <summary><i class="fa fa-shield-halved" aria-hidden="true"></i> Approve for the whole company</summary>
    <div class="mbx-body">
      <p>Microsoft asks every colleague of <strong><?= $h(substr((string)strrchr((string)$mailbox['email_address'], '@'), 1)) ?></strong> for permission when they connect their mailbox here: one screen for email, calendar and automatic replies, and one more the first time they use Teams chats.</p>
      <p>A <strong>Microsoft 365 administrator</strong> of that company can approve the app once for everyone. After that nobody there sees a permission screen.</p>
      <ul class="muted small">
        <li>It covers mail, calendar, automatic replies, Teams chats and shared mailboxes.</li>
        <li>The app still acts only as each signed-in person, and sees only what they can see in Outlook.</li>
        <li>Needs a Global, Application or Cloud Application administrator of that Microsoft 365 (not an admin of this app).</li>
        <li>It can be taken back at any time in Microsoft's admin center (Entra → Enterprise applications).</li>
      </ul>
      <p><a class="button" href="<?= $h($base) ?>oauth/start.php?action=approval&amp;mailbox_id=<?= (int)$mailbox['id'] ?>"><i class="fa-brands fa-microsoft" aria-hidden="true"></i> Approve for the whole company</a>
        <span class="muted small">You sign in with the administrator account, Microsoft lists the permissions, you press Accept. Steps: docs/MICROSOFT_ADMIN_APPROVAL.md</span></p>
    </div>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($canManage): ?><details><summary>Disconnect <?= $h($plabel) ?></summary><p>Disconnect this mailbox? Synchronized email and Case history will be kept.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="mailbox_id" value="<?= (int)$mailbox['id'] ?>"><input type="hidden" name="confirm" value="yes">
<button class="button" name="action" value="disconnect">Confirm disconnect</button></form></details><?php endif; ?>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php if ($otherAccounts): ?>
<section class="panel mbx-other-accounts">
  <h3>Your other accounts</h3>
  <p class="muted small">Each account has its own mail, cases, contacts, calendar and settings. Switch to change its settings.</p>
  <ul>
  <?php foreach ($otherAccounts as $oa): ?>
    <li><i class="fa <?= $oa['provider'] === 'google' ? 'fa-brands fa-google' : 'fa-brands fa-microsoft' ?>" aria-hidden="true"></i>
      <span><strong><?= $h($oa['email_address']) ?></strong> <span class="muted small">· <?= $h($labels[$oa['sync_status']] ?? $oa['sync_status']) ?><?= !empty($oa['other_workspace']) ? ' · ' . $h($oa['workspace']) . ' workspace' : '' ?></span></span>
      <button type="button" class="button" data-switch-account="<?= (int)$oa['id'] ?>">Switch to this account</button></li>
  <?php endforeach; ?>
  </ul>
</section>
<script>
document.querySelectorAll('[data-switch-account]').forEach((b) => b.addEventListener('click', async () => {
  b.disabled = true;
  try {
    const r = await fetch('../api/index.php?action=account_switch', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify({ account_id: Number(b.dataset.switchAccount) }) });
    if (!r.ok) throw new Error();
    location.href = location.pathname;
  } catch (_) { b.disabled = false; }
}));
</script>
<?php endif; ?>
<?php if ($configured && $isAdmin && ecw_private_mailboxes()): ?>
<details class="st-fold" id="shared"<?= $sharedError ? ' open' : '' ?>>
<summary><span class="st-fold-title"><i class="fa fa-users" aria-hidden="true"></i> Add a Microsoft 365 shared mailbox</span><span class="st-fold-hint">For info@, sales@, support@…</span></summary>
<section class="panel mbx-add-shared">
  <p><a href="#" class="help-link" data-help="shared-mailbox"><i class="fa fa-circle-question" aria-hidden="true"></i> How shared mailboxes work</a></p>
  <p class="muted">For info@, sales@, support@… You sign in with <strong>your own</strong> account, which needs "Full Access" and "Send As" on the shared mailbox (Exchange admin center → Recipients → Mailboxes → the shared mailbox → Delegation). The team then reads it here, replies from it and claims conversations.</p>
  <?php if ($sharedError): ?><div class="flash err"><?= $h($sharedError) ?></div><?php endif; ?>
  <form method="get" action="<?= $h($base) ?>oauth/start.php" class="mbx-shared-form">
    <input type="email" name="shared" required placeholder="info@yourcompany.com" aria-label="Shared mailbox address">
    <button type="submit" class="button primary">Connect shared mailbox</button>
  </form>
  <p class="muted small">A Gmail or other info@ account with its own password: connect it below as usual, then choose "Share with the team" on it.</p>
</section>
</details>
<?php endif; ?>
<p class="connect-buttons">
<?php if ($configured): ?><a class="button primary" href="<?= $h($base) ?>oauth/start.php"><i class="fa-brands fa-microsoft" aria-hidden="true"></i> Connect Microsoft Outlook</a><?php endif; ?>
<?php if ($googleConfigured): ?><a class="button primary" href="<?= $h($base) ?>oauth/start.php?provider=google"><i class="fa-brands fa-google" aria-hidden="true"></i> Connect Gmail</a><?php endif; ?>
</p>
<?php if ($configured && !empty((static function () { try { return ecw_graph_config(); } catch (Throwable $e) { return []; } })()['personal_accounts'])): ?><p class="muted small">Microsoft: a work or school account, or a personal Outlook.com / Hotmail / Live account. Teams chats are available for work and school accounts only.</p><?php endif; ?>
<?php foreach ([['Gmail', $googleConfigured, '#mail-google'], ['Outlook', $configured, '#mail']] as [$pname, $pok, $anchor]): if ($pok || (!$configured && !$googleConfigured)) continue; ?>
<p class="muted small"><?php if ($isAdmin): ?><?= $pname ?> needs a one-time setup too: <a href="<?= $h($base) ?>setup-guide.php<?= $anchor ?>" target="_blank" rel="noopener">open the setup guide</a>.<?php else: ?><?= $pname ?> is available once an administrator sets it up.<?php endif; ?></p>
<?php endforeach; ?>
</main>
</div>
<script src="<?= $h($base) ?>assets/mailbox-settings.js?v=<?= filemtime(dirname(__DIR__) . '/assets/mailbox-settings.js') ?>"></script>
<script>
// Folders to sync, live: they appear while the first scan runs, and each one shows its
// progress against Outlook's own count, with a turning icon while it imports and a tick
// when it is complete (api mailbox_folders). Boxes the person changed are left alone.
(() => {
  const boxes = document.querySelectorAll('.mbx-folders[data-mailbox-id]');
  if (!boxes.length) return;
  const num = (n) => Number(n || 0).toLocaleString();
  const touched = new WeakMap();
  boxes.forEach((box) => {
    touched.set(box, new Set());
    box.addEventListener('change', (e) => { if (e.target.matches('input[name="folders[]"]')) touched.get(box).add(e.target.value); });
  });
  const progress = (f) => {
    const out = document.createElement('span');
    out.className = 'mbx-folder-progress small' + (f.selected ? '' : ' muted');
    if (!f.selected) { out.textContent = f.total !== null ? 'not synced · ' + num(f.total) + ' in Outlook' : 'not synced'; return out; }
    const done = !f.busy && f.total !== null && f.synced >= f.total;
    if (f.busy) { const s = document.createElement('span'); s.className = 'ai-spin'; s.setAttribute('aria-hidden', 'true'); out.append(s, ' '); }
    out.append(f.total !== null ? num(f.synced) + ' of ' + num(f.total) : num(f.synced));
    if (done) { out.classList.add('is-done'); out.prepend('✓ '); }
    // The last two years are in; older emails follow when every folder has its two years.
    else if (f.recent && !f.busy) out.append(' · last 2 years ✓' + (f.older_busy ? ', older importing' : ', older next'));
    out.title = f.busy ? 'Importing now (newest email first)' : (done ? 'Complete: every email Outlook has in this folder is here'
      : f.recent ? 'Every email of the last 2 years is here. Older emails come in the background.' : (f.total !== null ? 'Outlook counts ' + num(f.total) + ' items here' : ''));
    return out;
  };
  const render = (box, data) => {
    const list = box.querySelector('.mbx-folder-list');
    const status = box.querySelector('.mbx-folder-status');
    status.hidden = !data.discovering;
    const mine = touched.get(box);
    const current = new Map([...list.querySelectorAll('input[name="folders[]"]')].map((i) => [i.value, i.checked]));
    const fresh = document.createDocumentFragment();
    data.folders.forEach((f) => {
      const label = document.createElement('label');
      label.className = 'checkbox-label mbx-folder';
      label.dataset.folderId = f.id;
      const input = document.createElement('input');
      input.type = 'checkbox'; input.name = 'folders[]'; input.value = String(f.id);
      input.checked = mine.has(String(f.id)) && current.has(String(f.id)) ? current.get(String(f.id)) : f.selected;
      input.disabled = f.fixed;
      const name = document.createElement('span');
      name.className = 'mbx-folder-name';
      name.textContent = f.name;
      if (f.fixed) { const a = document.createElement('span'); a.className = 'muted small'; a.textContent = ' (always)'; name.append(a); }
      label.append(input, ' ', name, ' ', progress(f));
      fresh.appendChild(label);
    });
    list.replaceChildren(fresh);
    const count = box.querySelector('.mbx-folder-count');
    if (count) count.textContent = '(' + data.folders.filter((f) => f.selected).length + ' of ' + data.folders.length + ')';
    if (data.discovering) box.open = true;
    return data.discovering || data.folders.some((f) => f.busy) || !!(data.sync && data.sync.busy);
  };
  const check = async () => {
    let active = false;
    for (const box of boxes) {
      try {
        const r = await fetch('../api/index.php?action=mailbox_folders&mailbox_id=' + box.dataset.mailboxId);
        if (r.ok && render(box, await r.json())) active = true;
      } catch (_) { active = true; }
    }
    // Every 3 seconds while something is happening, then every 20.
    setTimeout(() => (document.hidden ? setTimeout(check, 3000) : check()), active ? 3000 : 20000);
  };
  check();
})();
</script>
<script>
// Sync now: the icon turns while the mailbox syncs, until it is complete (api mailbox_activity, lib/sync/activity.php).
(() => {
  const buttons = document.querySelectorAll('.sync-now-settings[data-mailbox-id]');
  if (!buttons.length) return;
  const check = async () => {
    for (const b of buttons) {
      try {
        const r = await fetch('../api/index.php?action=mailbox_activity&mailbox_id=' + b.dataset.mailboxId);
        if (!r.ok) continue;
        const s = (await r.json()).sync || {};
        b.classList.toggle('is-syncing', !!s.busy);
        b.querySelector('span').textContent = s.busy ? (s.history_done ? 'Synchronizing…' : 'Importing… ' + Number(s.conversations || 0).toLocaleString() + ' conversations') : 'Sync now';
      } catch (_) { /* try again on the next round */ }
    }
  };
  buttons.forEach((b) => b.closest('form')?.addEventListener('submit', () => { b.classList.add('is-syncing'); b.querySelector('span').textContent = 'Synchronizing…'; }));
  check();
  setInterval(() => { if (!document.hidden) check(); }, 5000);
})();
</script>
</body></html>
