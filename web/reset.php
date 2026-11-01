<?php declare(strict_types=1);
/** Choose a new password from a reset link (lib/account_security.php). */
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/account_security.php';

header('Referrer-Policy: no-referrer');   // the token must not leak to other sites
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$user  = ecw_password_reset_user($token);
$error = null;
if ($user && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) {
        $error = 'The two passwords are not the same.';
    } else {
        $r = ecw_password_reset_use($token, (string)($_POST['password'] ?? ''));
        if ($r['ok']) {
            if (ecw_context() !== null) ecw_logout();
            ecw_redirect(ecw_web_base() . 'login.php?' . (!empty($user['invited']) ? 'welcome=1' : 'reset=1') . '&email=' . rawurlencode((string)$user['email']));
        }
        $error = $r['error'];
    }
}
$csrf = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= !empty($user['invited']) ? 'Welcome' : 'New password' ?> — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-body">
<?php require_once dirname(__DIR__) . '/lib/i18n.php'; echo ecw_i18n_scripts(ecw_user_language(null), ecw_web_base()); ?>
<main class="auth-card">
  <?php $welcome = $user && !empty($user['invited']); ?>
  <h1><?= $welcome ? 'Welcome' . ($user['name'] ? ', ' . $h(strtok((string)$user['name'], ' ')) : '') : 'Choose a new password' ?></h1>
  <?php if (!$user): ?>
    <div class="auth-error" role="alert">This link has expired or was already used. Ask your administrator for a new invitation, or get a new link below.</div>
    <p class="auth-foot"><a href="forgot.php">Get a new link</a> · <a href="login.php">Sign in</a></p>
  <?php else: ?>
    <?php if ($welcome): ?>
    <p class="auth-sub">Choose your password to start. Only you will know it.</p>
    <p class="auth-sub">You sign in with <strong><?= $h($user['email']) ?></strong></p>
    <?php else: ?>
    <p class="auth-sub">For <strong><?= $h($user['email']) ?></strong></p>
    <?php endif; ?>
    <?php if ($error): ?><div class="auth-error" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <form method="post" action="reset.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="token" value="<?= $h($token) ?>">
      <input type="text" name="username" value="<?= $h($user['email']) ?>" autocomplete="username" hidden>
      <label for="password"><?= $welcome ? 'Your password' : 'New password' ?></label>
      <input type="password" id="password" name="password" minlength="<?= ECW_PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required autofocus>
      <label for="password2">Type it again</label>
      <input type="password" id="password2" name="password2" minlength="<?= ECW_PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
      <p class="muted">At least <?= ECW_PASSWORD_MIN_LENGTH ?> characters<?= $welcome ? '. A sentence you can remember works well.' : '. Two-factor sign-in, if you use it, stays on.' ?></p>
      <label class="show-pw"><input type="checkbox" onchange="document.getElementById('password').type = document.getElementById('password2').type = this.checked ? 'text' : 'password'"> Show what I type</label>
      <button type="submit" class="primary"><?= $welcome ? 'Save my password' : 'Save new password' ?></button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
