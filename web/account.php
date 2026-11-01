<?php declare(strict_types=1);
/**
 * Email Case Workspace — your account.
 *
 * Display name, language and password. No avatar, no cosmetic profile — the step is
 * explicit that those are not wanted.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/account_security.php';

$context = ecw_require_login();
$user    = $context['user'];
$orgId   = $context['organization_id'];

$notice = null;
$error  = null;
$recoveryCodes = null;   // shown once, right after two-factor is turned on

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'name') {
            $result = ecw_update_display_name((int)$user['id'], (string)($_POST['name'] ?? ''));
            $result['ok'] ? $notice = 'Name updated.' : $error = $result['error'];
            $user = ecw_current_user() ?? $user;
        } elseif ($action === 'language') {
            // The app's own text (lib/i18n.php); "auto" follows the browser.
            require_once dirname(__DIR__) . '/lib/i18n.php';
            try {
                $lang = (string)($_POST['language'] ?? 'auto');
                ecw_user_language_save((int)$user['id'], $lang === 'auto' ? null : $lang);
                ecw_redirect(ecw_web_base() . 'account.php?lang_saved=1');
            } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
        } elseif ($action === 'password') {
            $result = ecw_change_password(
                (int)$user['id'],
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? '')
            );
            if ($result['ok']) ecw_trusted_forget_all((int)$user['id']);   // a new password: trusted computers ask for the code again
            $result['ok'] ? $notice = 'Password changed.' : $error = $result['error'];
        } elseif ($action === 'away') {
            require_once dirname(__DIR__) . '/lib/handover.php';
            $err = ecw_away_set((int)$orgId, (int)$user['id'], (int)$user['id'], (string)($_POST['from'] ?? ''), (string)($_POST['until'] ?? ''), (int)($_POST['cover'] ?? 0));
            $err === null ? $notice = 'Holiday cover saved. Your colleague was told.' : $error = $err;
        } elseif ($action === 'away_clear') {
            require_once dirname(__DIR__) . '/lib/handover.php';
            ecw_away_clear((int)$orgId, (int)$user['id'], (int)$user['id']);
            $notice = 'Holiday cover removed.';
        } elseif ($action === 'trusted_forget') {
            ecw_trusted_forget((int)$user['id'], (int)($_POST['device_id'] ?? 0));
            $notice = 'That computer will ask for a code next time.';
        } elseif ($action === 'trusted_forget_all') {
            ecw_trusted_forget_all((int)$user['id']);
            $notice = 'Every computer will ask for a code next time.';
        } elseif ($action === 'totp_start') {
            // The secret lives in the session until a correct code proves the phone has it.
            $_SESSION['ecw_totp_setup'] = ecw_totp_new_secret();
        } elseif ($action === 'totp_cancel') {
            unset($_SESSION['ecw_totp_setup']);
        } elseif ($action === 'totp_confirm' && !empty($_SESSION['ecw_totp_setup'])) {
            try {
                $recoveryCodes = ecw_totp_enable((int)$user['id'], (string)$_SESSION['ecw_totp_setup'], (string)($_POST['code'] ?? ''));
                unset($_SESSION['ecw_totp_setup']);
                $notice = 'Two-factor sign-in is on.';
            } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
        } elseif ($action === 'totp_disable') {
            $st = ecw_db()->prepare('SELECT password_hash FROM `' . ecw_table('users') . '` WHERE id = ?');
            $st->execute([(int)$user['id']]);
            if (password_verify((string)($_POST['current_password'] ?? ''), (string)$st->fetchColumn())) {
                ecw_totp_disable((int)$user['id']);
                $notice = 'Two-factor sign-in is off.';
            } else {
                $error = 'Your password is not correct.';
            }
        }
    }
}
require_once dirname(__DIR__) . '/lib/sso.php';
if (isset($_SESSION['ecw_sso_error'])) { $error = (string)$_SESSION['ecw_sso_error']; unset($_SESSION['ecw_sso_error']); }
$ssoMsg = ['linked' => 'Your Microsoft account is linked. You can now use "Sign in with Microsoft".',
    'unlinked' => 'Your Microsoft account is unlinked.', 'taken' => 'That Microsoft account is already linked to another person here.'][$_GET['sso'] ?? ''] ?? null;
if ($ssoMsg !== null && $notice === null && $error === null) { ($_GET['sso'] === 'taken') ? $error = $ssoMsg : $notice = $ssoMsg; }
$ssoMode = ecw_security_settings((int)$orgId)['sso'];
$ssoLinked = ecw_sso_linked((int)$user['id']);
$totpOn = ecw_totp_enabled((int)$user['id']);
$totpSetup = !$totpOn ? ($_SESSION['ecw_totp_setup'] ?? null) : null;

$st = ecw_db()->prepare('SELECT name, timezone FROM `' . ecw_table('organizations') . '` WHERE id = ?');
$st->execute([$orgId]);
$organization = $st->fetch() ?: ['name' => '—', 'timezone' => 'UTC'];

$csrf = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$navActive = 'settings';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account — Case Workspace</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body class="page-body">
<?php  require __DIR__ . '/partials/nav.php'; ?>

<div class="settings-shell btn-scope"><?php require __DIR__ . '/partials/settings-nav.php'; ?>
<main class="page">
  <h1>Account</h1>

  <?php if ($notice !== null): ?><div class="flash ok"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="flash err" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <section class="panel">
    <h2>Details</h2>
    <dl class="facts">
      <div><dt>Email</dt><dd><?= $h($user['email']) ?></dd></div>
      <div><dt>Organization</dt><dd><?= $h($organization['name']) ?></dd></div>
      <div><dt>Role</dt><dd><?= $h($context['role']) ?></dd></div>
      <?php $myTz = ecw_tz_for_user((int)$user['id'], (int)$context['organization_id']); ?>
      <div><dt>Your time zone</dt><dd><?= $h(str_replace('_', ' ', $myTz)) ?> <span class="muted small">· now <?= $h(ecw_tz_format(gmdate('Y-m-d H:i:s'), $myTz, 'H:i')) ?> · follows the computer you use, like Outlook when you travel</span></dd></div>
      <div><dt>Company time zone</dt><dd><?= $h(str_replace('_', ' ', (string)$organization['timezone'])) ?> <span class="muted small">· for working hours, the morning briefing and due dates</span></dd></div>
    </dl>
    <p class="muted">Your email address is your sign-in name. An admin can change it.</p>
  </section>

  <section class="panel">
    <h2>Display name</h2>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="name">
      <label for="name">Name</label>
      <input type="text" id="name" name="name" value="<?= $h($user['name']) ?>" required>
      <button type="submit" class="primary">Save name</button>
    </form>
  </section>

  <?php require_once dirname(__DIR__) . '/lib/i18n.php';
    $langSt = ecw_db()->prepare('SELECT language FROM `' . ecw_table('users') . '` WHERE id = ?'); $langSt->execute([(int)$user['id']]); $myLang = (string)$langSt->fetchColumn(); ?>
  <?php if (isset($_GET['lang_saved']) && $notice === null && $error === null): ?><div class="flash ok">Language saved.</div><?php endif; ?>
  <section class="panel">
    <h2><i class="fa fa-language" aria-hidden="true"></i> Language</h2>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="language">
      <label for="language">The app's language</label>
      <select id="language" name="language" class="ot-select" onchange="this.form.submit()">
        <option value="auto"<?= $myLang === '' ? ' selected' : '' ?>>Same as my browser</option>
        <?php foreach (ECW_LANGUAGES as $code => $label): ?><option value="<?= $h($code) ?>" class="notranslate"<?= $myLang === $code ? ' selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
      </select>
      <span class="muted small">Only the app's own text changes. Emails, names and notes stay as they were written.</span>
    </form>
  </section>

  <section class="panel">
    <h2>Change password</h2>
    <form method="post" class="stack" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="password">

      <label for="current_password">Current password</label>
      <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

      <label for="new_password">New password</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password"
             minlength="<?= ECW_PASSWORD_MIN_LENGTH ?>" required>
      <p class="muted">At least <?= ECW_PASSWORD_MIN_LENGTH ?> characters, and different from the current one.</p>

      <button type="submit" class="primary">Change password</button>
    </form>
  </section>

  <?php if ($ssoMode !== 'off' && ecw_provider_configured('microsoft')): ?>
  <section class="panel" id="microsoft">
    <h2><svg width="16" height="16" viewBox="0 0 21 21" aria-hidden="true" style="vertical-align:-2px"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg> Microsoft sign-in</h2>
    <?php if ($ssoLinked): ?>
      <p><span class="tfa-state on">Linked.</span> You can sign in with your Microsoft account<?= $ssoMode === 'required' && $context['role'] !== 'admin' ? ' (your organization requires it)' : ' or with your password' ?>.</p>
      <form method="post" action="sso.php"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="mode" value="unlink"><button type="submit">Unlink Microsoft account</button></form>
    <?php else: ?>
      <p class="muted">Sign in with your work Microsoft account instead of a password. Microsoft's own security (multi-factor, company rules) then protects your sign-in.</p>
      <form method="post" action="sso.php"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="mode" value="link"><button type="submit" class="primary">Link Microsoft account</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php require_once dirname(__DIR__) . '/lib/handover.php'; $away = ecw_away_get((int)$user['id']);
        $colleagues = array_filter(ecw_organization_users((int)$orgId), static fn($u) => (int)$u['id'] !== (int)$user['id'] && $u['disabled_at'] === null && $u['role'] !== 'read_only'); ?>
  <?php if ($colleagues && $context['role'] !== 'read_only'): ?>
  <section class="panel" id="away">
    <h2><i class="fa fa-umbrella-beach" aria-hidden="true"></i> Holiday cover <a href="#" class="help-link" data-help="holiday"><i class="fa fa-circle-question" aria-hidden="true"></i> Help</a></h2>
    <p class="muted">Going away? A colleague sees your cases and gets a copy of your alerts and reminders until you are back. Nothing is moved. Your Outlook out-of-office reply is under Settings → Mailbox.</p>
    <?php if ($away['until'] && $away['until'] >= gmdate('Y-m-d')): ?><p><span class="tfa-state on"><?= $away['active'] ? 'Away now' : 'Planned' ?>:</span> <?= $h($away['from']) ?> to <?= $h($away['until']) ?>, covered by <?= $h($away['cover_name'] ?? '?') ?>.</p><?php endif; ?>
    <form method="post" class="stack ho-cover">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="away">
      <label>First day away <input type="date" name="from" required value="<?= $h($away['from'] ?? gmdate('Y-m-d')) ?>"></label>
      <label>Last day away <input type="date" name="until" required value="<?= $h($away['until'] ?? '') ?>"></label>
      <label>Covered by <select name="cover" class="ot-select" required><option value="">Choose a colleague…</option><?php foreach ($colleagues as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $away['cover_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= $h($c['name'] ?: $c['email']) ?></option><?php endforeach; ?></select></label>
      <button type="submit" class="primary">Save</button>
    </form>
    <?php if ($away['until']): ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="away_clear"><button type="submit">I'm back — remove cover</button></form><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel" id="two-factor">
    <h2><i class="fa fa-shield-halved" aria-hidden="true"></i> Two-factor sign-in</h2>
    <?php if ($recoveryCodes): ?>
      <p><strong>Save these recovery codes now.</strong> Each one signs you in once if you lose your phone. They are not shown again.</p>
      <ol class="recovery-codes"><?php foreach ($recoveryCodes as $c): ?><li><code><?= $h($c) ?></code></li><?php endforeach; ?></ol>
      <p><button type="button" onclick="navigator.clipboard.writeText(<?= $h(json_encode(implode("\n", $recoveryCodes))) ?>).then(() => { this.textContent = 'Copied'; })">Copy codes</button>
         <button type="button" onclick="window.print()">Print</button></p>
    <?php elseif ($totpOn): ?>
      <p class="tfa-state on"><i class="fa fa-circle-check" aria-hidden="true"></i> On — signing in asks for a code from your authenticator app. <?= ecw_totp_recovery_left((int)$user['id']) ?> recovery codes left.</p>
      <p class="muted">If the app is not at hand, the sign-in page can email you a code instead.</p>
      <?php $trusted = ecw_trusted_list((int)$user['id']); ?>
      <h3 class="tfa-sub">Trusted computers</h3>
      <?php if ($trusted === []): ?>
        <p class="muted">None. Tick “Trust this computer for <?= ECW_TRUST_DAYS ?> days” when you enter a code, and that browser stops asking.</p>
      <?php else: ?>
        <ul class="trusted-list">
          <?php foreach ($trusted as $d): ?>
            <li><i class="fa fa-desktop" aria-hidden="true"></i>
              <span class="grow"><?= $h($d['label']) ?><?php if ($d['current']): ?><span class="this-one">this computer</span><?php endif; ?><br>
                <span class="muted">Last used <?= $h(ecw_tz_local($d['last_used_at'] ?? $d['created_at'], 'j M Y')) ?> · until <?= $h(ecw_tz_local($d['expires_at'], 'j M Y')) ?></span></span>
              <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="trusted_forget"><input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><button type="submit">Forget</button></form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($trusted) > 1): ?>
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="trusted_forget_all"><button type="submit">Forget all</button></form>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="totp_disable">
        <label for="tfa_password">To turn it off, enter your password</label>
        <input type="password" id="tfa_password" name="current_password" autocomplete="current-password" required>
        <button type="submit" class="danger">Turn off two-factor sign-in</button>
      </form>
    <?php elseif ($totpSetup): ?>
      <ol class="tfa-steps">
        <li>Open an authenticator app on your phone (Microsoft Authenticator, Google Authenticator, 1Password…).</li>
        <li>Scan this code — or type the key by hand.
          <div class="tfa-qr" id="tfaQr" data-uri="<?= $h(ecw_totp_uri($totpSetup, (string)$user['email'])) ?>"></div>
          <code class="tfa-key"><?= $h(trim(chunk_split($totpSetup, 4, ' '))) ?></code></li>
        <li>Enter the 6-digit code the app shows.</li>
      </ol>
      <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="totp_confirm">
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" required autofocus>
        <button type="submit" class="primary">Turn on</button>
      </form>
      <form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="totp_cancel"><button type="submit" class="link-btn">Cancel</button></form>
      <script src="assets/vendor/qrcode/qrcode.js"></script>
      <script>(function () { var box = document.getElementById('tfaQr'); try { var q = qrcode(0, 'M'); q.addData(box.dataset.uri); q.make(); box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 4, scalable: true }); } catch (e) { box.textContent = 'Type the key below into your app.'; } })();</script>
    <?php else: ?>
      <p class="muted">Off. With two-factor sign-in, someone who learns your password still cannot sign in without your phone. Recommended — this app can read your whole mailbox.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="totp_start">
        <button type="submit" class="primary"><i class="fa fa-shield-halved" aria-hidden="true"></i> Set up two-factor sign-in</button></form>
    <?php endif; ?>
  </section>
</main>
</div>
</body>
</html>
