<?php declare(strict_types=1);
/**
 * Settings → Users. Organization admins only.
 *
 * Replaces bin/dev_user.php as the normal way to add someone.
 *
 * Every write goes through lib/auth.php, which takes the organization from the
 * ACTOR's session and never from this form — that is what makes "an admin of
 * organization A cannot touch organization B" true by construction rather than
 * by remembering to check here.
 */

require_once dirname(__DIR__, 2) . '/lib/invitations.php';
require_once dirname(__DIR__, 2) . '/lib/account_security.php';
require_once dirname(__DIR__, 2) . '/lib/audit.php';
require_once dirname(__DIR__, 2) . '/lib/handover.php';

$context = ecw_require_admin();
$actorId = (int)$context['user']['id'];
$orgId   = $context['organization_id'];
$base    = ecw_web_base();

$invitationLink = null;
$resetLink = null; $resetFor = null; $resetIsInvite = false;
$notice = null;
$error  = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        switch ((string)($_POST['action'] ?? '')) {
            case 'invite':
                try {
                    $token = ecw_create_invitation($actorId, $orgId, (string)($_POST['email'] ?? ''));
                    $invitationLink = rtrim((string)getenv('ECW_BASE_URL'), '/') . $base . 'register.php?token=' . $token;
                    ecw_audit('user.invite', null, null, ['email' => (string)($_POST['email'] ?? '')]);
                    $notice = 'Invitation created. Share this link with the invited person. It expires in three days.';
                } catch (InvalidArgumentException | RuntimeException $e) { $error = $e->getMessage(); }
                break;
            case 'create':
                // Not before the invitation link can open on the colleague's computer.
                if (ecw_email_link_base() === null) { $error = 'First set the address colleagues use to open the app: the invitation link would not open on their computer.'; break; }
                // No password here: the person chooses their own with the emailed link.
                $result = ecw_admin_create_user($actorId, $orgId, [
                    'name'   => $_POST['name'] ?? '',
                    'email'  => $_POST['email'] ?? '',
                    'role'   => $_POST['role'] ?? 'user',
                    'invite' => true,
                ]);
                if (!$result['ok']) { $error = $result['error']; break; }
                ecw_audit('user.create', 'user', (int)$result['user_id'], ['email' => (string)($_POST['email'] ?? ''), 'role' => (string)($_POST['role'] ?? 'user')]);
                // fall through: the invitation email
            case 'resend_invite':
                $target = ($_POST['action'] ?? '') === 'create' ? (int)$result['user_id'] : (int)($_POST['user_id'] ?? 0);
                if (ecw_user_role($target, $orgId) === null) { $error = 'That user is not a member of this organization.'; break; }
                if (!ecw_user_is_invited($target)) { $error = 'They already chose a password. Use "Reset link" if they forgot it.'; break; }
                $sent = ecw_invite_email_send($orgId, $target, $actorId);
                $email = '';
                foreach (ecw_organization_users($orgId) as $m) if ((int)$m['id'] === $target) { $email = (string)$m['email']; $resetFor = $m['name'] ?: $m['email']; }
                if ($sent['sent']) {
                    $notice = (($_POST['action'] ?? '') === 'create' ? 'User added. ' : '') . 'Invitation sent to ' . $email . '. They choose their own password with the link (it works for 3 days).';
                } else {
                    $resetLink = $sent['link']; $resetIsInvite = true;
                    $notice = (($_POST['action'] ?? '') === 'create' ? 'User added. ' : '') . $sent['error'] . ' Send them the link below yourself.';
                }
                break;

            case 'app_url':
                try { ecw_app_url_save((string)($_POST['app_url'] ?? '')); $notice = 'Saved: links in emails now open ' . (ecw_app_url() ?? 'the address you use'); }
                catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
                break;

            case 'delete':
                $target = (int)($_POST['user_id'] ?? 0);
                $label = '';
                foreach (ecw_organization_users($orgId) as $m) if ((int)$m['id'] === $target) $label = (string)($m['name'] ?: $m['email']);
                $result = ecw_admin_delete_user($actorId, $orgId, $target);
                if ($result['ok']) {
                    ecw_audit('user.delete', 'user', $target, ['name' => $label, 'mailboxes' => $result['mailboxes'], 'emails' => $result['emails']]);
                    $notice = $label . ' was deleted for good' . ($result['mailboxes'] ? ', with their ' . $result['mailboxes'] . ' mailbox' . ($result['mailboxes'] === 1 ? '' : 'es') . ' (' . number_format($result['emails']) . ' emails)' : '') . '. You can add them again at any time.';
                } else {
                    $error = $result['error'];
                }
                break;

            case 'disable':
            case 'enable':
                $result = ecw_admin_set_user_disabled(
                    $actorId, $orgId, (int)($_POST['user_id'] ?? 0),
                    ($_POST['action'] ?? '') === 'disable'
                );
                if ($result['ok']) ecw_audit(($_POST['action'] ?? '') === 'disable' ? 'user.disable' : 'user.enable', 'user', (int)($_POST['user_id'] ?? 0));
                // Someone leaving: straight on to handing their work over, so nothing is left behind.
                if ($result['ok'] && ($_POST['action'] ?? '') === 'disable') {
                    require_once dirname(__DIR__, 2) . '/lib/handover.php';
                    $left = ecw_handover_preview((int)$orgId, (int)($_POST['user_id'] ?? 0));
                    if (array_sum($left) > 0) ecw_redirect($base . 'settings/handover.php?user=' . (int)($_POST['user_id'] ?? 0) . '&disabled=1');
                }
                $result['ok'] ? $notice = 'User updated.' : $error = $result['error'];
                break;

            case 'role':
                $result = ecw_admin_set_user_role(
                    $actorId, $orgId, (int)($_POST['user_id'] ?? 0), (string)($_POST['role'] ?? 'user')
                );
                if ($result['ok']) ecw_audit('user.role', 'user', (int)($_POST['user_id'] ?? 0), ['role' => (string)($_POST['role'] ?? '')]);
                $result['ok'] ? $notice = 'Role updated.' : $error = $result['error'];
                break;

            case 'reset_link':
            case 'reset_2fa':
                $target = (int)($_POST['user_id'] ?? 0);
                if (ecw_user_role($target, $orgId) === null) { $error = 'That user is not a member of this organization.'; break; }
                if ($_POST['action'] === 'reset_2fa') {
                    if ($target === $actorId) { $error = 'Turn off your own two-factor sign-in on your Account page.'; break; }
                    ecw_totp_disable($target, $actorId);
                    $notice = 'Two-factor sign-in was turned off. They can set it up again from their Account page.';
                } else {
                    $resetLink = ecw_link_base_url() . $base . 'reset.php?token=' . ecw_password_reset_create($target, $actorId);
                    foreach (ecw_organization_users($orgId) as $m) if ((int)$m['id'] === $target) $resetFor = $m['name'] ?: $m['email'];
                    $notice = 'Reset link created. Send it to them privately — it works once, for 24 hours.';
                }
                break;
        }
    }
}

$users = ecw_organization_users($orgId);
$tfa = [];
if ($users) {
    $ids = implode(',', array_map(static fn($u) => (int)$u['id'], $users));
    foreach (ecw_db()->query('SELECT id FROM `' . ecw_table('users') . "` WHERE id IN ({$ids}) AND totp_secret_encrypted IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $id) $tfa[(int)$id] = true;
}
$invited = [];
if ($users) {
    foreach (ecw_db()->query('SELECT id FROM `' . ecw_table('users') . "` WHERE id IN ({$ids}) AND password_hash = '" . ECW_INVITED_HASH . "'")->fetchAll(PDO::FETCH_COLUMN) as $id) $invited[(int)$id] = true;
}
// Where invitation links lead (Settings → Users → Address colleagues use); null = nowhere that works for them.
$appUrl = ecw_app_url(); $linkBase = ecw_email_link_base();
$csrf  = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$navActive = 'settings';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Users — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css">
<link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css">
</head>
<body class="page-body">
<?php  require dirname(__DIR__) . '/partials/nav.php'; ?>

<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page wide">
  <h1>Users</h1>
  

  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <?php if (ecw_local_features()): ?>
  <section class="panel">
    <h2>Invite a colleague or contact</h2>
    <p>They choose their own password and connect their own mailbox. Mailboxes are private; threads linked to a case are shared with everyone who can see that case — the whole workspace, or only the people chosen under the case's “Shared with”.</p>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="invite">
      <label>Email <input type="email" name="email" required></label>
      <button type="submit" class="primary">Create invitation link</button>
    </form>
    <?php if ($invitationLink): ?><label>Invitation link <input type="text" readonly style="width:100%" value="<?= $h($invitationLink) ?>" onclick="this.select()"></label><?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($resetLink): ?>
  <section class="panel reset-link-box">
    <h2><i class="fa fa-key" aria-hidden="true"></i> <?= $resetIsInvite ? 'Invitation link' : 'Password reset link' ?><?= $resetFor ? ' for ' . $h($resetFor) : '' ?></h2>
    <div class="copy-row"><input type="text" readonly value="<?= $h($resetLink) ?>" onclick="this.select()" aria-label="Reset link">
      <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(() => { this.textContent = 'Copied'; })">Copy</button></div>
    <p class="muted">With this link they choose their own password, so send it to them privately (Teams chat, SMS). It stops working after one use or <?= $resetIsInvite ? '3 days' : '24 hours' ?>.</p>
  </section>
  <?php endif; ?>
  <table class="grid users-table">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Two-factor</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $member): ?>
        <?php $isSelf = (int)$member['id'] === $actorId; ?>
        <tr<?= $member['disabled_at'] !== null ? ' class="row-disabled"' : '' ?>>
          <td class="user-name"><?= $h($member['name']) ?><?= $isSelf ? ' <span class="muted">(you)</span>' : '' ?></td>
          <td class="user-email"><?= $h($member['email']) ?></td>
          <td data-label="Role">
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="role">
              <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
              <select name="role" class="ot-select" onchange="this.form.submit()" <?= $isSelf ? 'disabled' : '' ?>>
                <?php foreach (ECW_ROLES as $role): ?>
                  <option value="<?= $h($role) ?>"<?= $member['role'] === $role ? ' selected' : '' ?>><?= $h($role) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <?php $away = ecw_away_get((int)$member['id']); ?>
          <td data-label="Status"><?= $member['disabled_at'] !== null ? 'disabled' : (isset($invited[(int)$member['id']]) ? '<span class="invited-badge" title="Has not chosen a password yet"><i class="fa fa-envelope" aria-hidden="true"></i> Invited</span>' : 'active') ?><?php if ($away['until'] && $away['until'] >= gmdate('Y-m-d')): ?><br><span class="muted small"><i class="fa fa-umbrella-beach" aria-hidden="true"></i> away <?= $h($away['from']) ?> – <?= $h($away['until']) ?>, cover: <?= $h($away['cover_name'] ?? '—') ?></span><?php endif; ?></td>
          <td data-label="Two-factor"><?= isset($tfa[(int)$member['id']]) ? '<span class="tfa-badge on"><i class="fa fa-shield-halved" aria-hidden="true"></i> On</span>' : '<span class="muted">Off</span>' ?></td>
          <td class="user-actions">
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <?php if (isset($invited[(int)$member['id']]) && $member['disabled_at'] === null): ?>
              <input type="hidden" name="action" value="resend_invite">
              <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
              <button type="submit" title="Email them a new link to choose their password (3 days)" onclick="if (this.disabled) return false; setTimeout(() => { this.disabled = true; this.innerHTML = '<span class=&quot;ai-spin&quot; aria-hidden=&quot;true&quot;></span> Sending…'; }, 0);"<?= $linkBase === null ? ' disabled title="Set the address colleagues use first"' : '' ?>><i class="fa fa-paper-plane" aria-hidden="true"></i> Resend invitation</button>
              <?php else: ?>
              <input type="hidden" name="action" value="reset_link">
              <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
              <button type="submit" title="Make a one-time link to set a new password"><i class="fa fa-key" aria-hidden="true"></i> Reset link</button>
              <?php endif; ?>
            </form>
            <?php if (!$isSelf && isset($tfa[(int)$member['id']])): ?>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="reset_2fa">
                <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                <button type="submit" title="For a lost phone" onclick="if (window.ecwArm && !window.ecwArm(this, 'Click again to turn off')) return false;">Turn off 2FA</button>
              </form>
            <?php endif; ?>
            <a class="button" href="<?= $h($base) ?>settings/handover.php?user=<?= (int)$member['id'] ?>" title="Holiday cover, or hand all their work to a colleague"><i class="fa fa-people-arrows" aria-hidden="true"></i> Cover / hand over</a>
            <?php if (!$isSelf): ?>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="<?= $member['disabled_at'] !== null ? 'enable' : 'disable' ?>">
                <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                <?php if ($member['disabled_at'] !== null): ?>
                  <button type="submit" class="icon-action" title="Enable: <?= $h($member['name']) ?> can sign in again" aria-label="Enable <?= $h($member['name']) ?>"><i class="fa fa-rotate-left" aria-hidden="true"></i></button>
              </form>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                <button type="submit" class="icon-action danger" title="Delete for good: their sign-in, personal settings and the mailboxes they connected (with those emails). Cases, notes and history stay. They can be added again."
                        aria-label="Delete <?= $h($member['name']) ?> for good"
                        onclick="if (window.ecwArm && !window.ecwArm(this, 'Delete for good: their mailboxes too')) return false;"><i class="fa fa-user-xmark" aria-hidden="true"></i></button>
                <?php else: ?>
                  <button type="submit" class="icon-action danger" title="Disable: <?= $h($member['name']) ?> can no longer sign in (their mail and cases stay)" aria-label="Disable <?= $h($member['name']) ?>"
                          onclick="if (window.ecwArm && !window.ecwArm(this, 'Click again to disable')) return false;"><i class="fa fa-trash-can" aria-hidden="true"></i></button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php $postAction = (string)($_POST['action'] ?? ''); ?>
  <details class="st-fold" id="app-url"<?= $linkBase === null || ($error !== null && $postAction === 'app_url') ? ' open' : '' ?>>
  <summary><span class="st-fold-title"><i class="fa fa-link" aria-hidden="true"></i> Address colleagues use to open the app</span><span class="st-fold-hint"><?= $linkBase !== null ? $h($linkBase) : 'Not set' ?></span></summary>
  <section class="panel app-url-panel">
    <p class="muted">Invitations and password emails link here. It must work on <strong>their</strong> computer:
      in the office network this PC's network address, or your company's web address. Leave it empty, or save an
      office-network address (192.168.…, 10.…), and links follow this PC's address on whatever network it is on now<?= ($lanIp = ecw_lan_ip()) ? ' (now <strong>' . $h($lanIp) . '</strong>)' : '' ?>.</p>
    <?php if ($linkBase === null): ?><div class="flash err">Not set, and you are using <strong>localhost</strong>, which only works on this computer: no invitation is sent until it is set.</div><?php endif; ?>
    <form method="post" class="copy-row">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="app_url">
      <input type="url" name="app_url" value="<?= $h($appUrl ?? '') ?>" placeholder="<?= $h($linkBase ?? 'http://192.168.1.10:8080') ?>" aria-label="Address colleagues use">
      <button type="submit">Save</button>
    </form>
    <p class="muted small">Links will open: <strong><?= $h(($linkBase ?? '—') . ($linkBase ? $base . 'reset.php' : '')) ?></strong></p>
  </section>
  </details>

  <details class="st-fold"<?= count($users) <= 1 || ($error !== null && $postAction === 'create') ? ' open' : '' ?>>
  <summary><span class="st-fold-title"><i class="fa fa-user-plus" aria-hidden="true"></i> Add a user</span><span class="st-fold-hint">They get an email to choose their own password</span></summary>
  <section class="panel">
    <p class="muted">They get an email with a link to choose their own password: nobody else, not even an
       admin, knows it. Until then they show as <strong>Invited</strong>, with "Resend invitation".</p>
    <?php if ($linkBase === null): ?><div class="flash err">Set the address colleagues use to open the app (above) first: until then the invitation link would not open on their computer.</div><?php endif; ?>
    <form method="post" class="stack add-user-form" autocomplete="off"<?= $linkBase === null ? ' aria-disabled="true"' : '' ?>
          onsubmit="const b = this.querySelector('button[type=submit]'); if (b.disabled) return false; b.disabled = true; b.setAttribute('aria-busy', 'true'); b.innerHTML = '<span class=&quot;ai-spin&quot; aria-hidden=&quot;true&quot;></span> Sending invitation…'; this.querySelectorAll('input:not([type=hidden]), select').forEach((i) => { i.readOnly = true; });">
      <fieldset class="add-user-fields"<?= $linkBase === null ? ' disabled' : '' ?>>
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <label for="name">Name</label>
      <input type="text" id="name" name="name" required>

      <label for="email">Email</label>
      <input type="email" id="email" name="email" required>

      <label for="role">Role</label>
      <select id="role" name="role" class="ot-select">
        <?php foreach (ECW_ROLES as $role): ?>
          <option value="<?= $h($role) ?>"<?= $role === 'user' ? ' selected' : '' ?>><?= $h($role) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="primary"><i class="fa fa-paper-plane" aria-hidden="true"></i> Add and send invitation</button>
      </fieldset>
    </form>
  </section>
  </details>
</main>
</div>
</body>
</html>
