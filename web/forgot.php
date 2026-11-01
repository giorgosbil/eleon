<?php declare(strict_types=1);
/**
 * "Forgot password?" — emails a reset link when it can (lib/account_security.php).
 * The answer is the same whether or not the address has an account.
 */
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/account_security.php';

if (ecw_context() !== null) ecw_redirect(ecw_web_base() . 'account.php');
$sent = false; $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        ecw_password_reset_request((string)($_POST['email'] ?? ''), ecw_client_ip());
        $sent = true;
    }
}
$canEmail = ecw_public_base_url() !== null;
$csrf = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Forgot password — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-body">
<?php require_once dirname(__DIR__) . '/lib/i18n.php'; echo ecw_i18n_scripts(ecw_user_language(null), ecw_web_base()); ?>
<main class="auth-card">
  <h1>Forgot your password?</h1>
  <?php if ($error): ?><div class="auth-error" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($sent): ?>
    <div class="auth-ok" role="status">If that address has an account, a reset link is on its way. It works for 24 hours. Nothing arrived after a few minutes? Check Junk, or ask your administrator for a reset link.</div>
    <p class="auth-foot"><a href="login.php">Back to sign in</a></p>
  <?php else: ?>
    <p class="auth-sub"><?= $canEmail ? 'Enter your email and we will send you a link to choose a new password.' : 'Ask your administrator for a reset link: Settings → Users → “Reset link”. (Emailed links need ECW_BASE_URL to be set on the server.)' ?></p>
    <?php if ($canEmail): ?>
    <form method="post" action="forgot.php">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" required autofocus>
      <button type="submit" class="primary">Send reset link</button>
    </form>
    <?php endif; ?>
    <p class="auth-foot"><a href="login.php">Back to sign in</a></p>
  <?php endif; ?>
</main>
</body>
</html>
