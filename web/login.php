<?php declare(strict_types=1);
/**
 * Email Case Workspace — sign in.
 *
 * Handles its own POST rather than posting elsewhere, so a failed attempt can
 * redisplay the form with the entered address and an error without a redirect
 * round-trip that would lose both.
 *
 * The error message is identical for an unknown address, a wrong password and a
 * disabled account. Anything more specific is an account oracle: an attacker
 * with a list of addresses learns which ones are real.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/account_security.php';
require_once dirname(__DIR__) . '/lib/i18n.php';
require_once dirname(__DIR__) . '/lib/sso.php';

// ?lang=el / ?lang=en on the sign-in page: remembered in a cookie until they choose in Settings.
if (isset($_GET['lang']) && is_string($_GET['lang']) && isset(ECW_LANGUAGES[$_GET['lang']])) {
    setcookie('ecw_lang', $_GET['lang'], ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['ecw_lang'] = $_GET['lang'];
}
$authLang = ecw_user_language(null);

// Already signed in? Nothing to do here.
if (ecw_context() !== null) {
    ecw_redirect(ecw_web_base() . 'app.php');
}

$next  = ecw_safe_next($_GET['next'] ?? $_POST['next'] ?? null);
$email = trim((string)($_POST['email'] ?? ''));
// Back from choosing a password (reset.php): the address is filled in.
if ($email === '' && isset($_GET['email']) && is_string($_GET['email']) && filter_var($_GET['email'], FILTER_VALIDATE_EMAIL)) $email = (string)$_GET['email'];
$error = null;
$notice = isset($_GET['welcome']) ? 'Your password is saved. Sign in to start.' : (isset($_GET['reset']) ? 'Your password was changed. Sign in with the new one.' : null);
$goNext = static function (?string $next): never {
    ecw_redirect($next !== null ? (str_starts_with($next, '/') ? $next : ecw_web_base() . $next) : ecw_web_base() . 'app.php');
};

// Two-factor: after a correct password the session remembers WHO, for five
// minutes, but is not signed in until the code is right.
ecw_session_start();
$pending = $_SESSION['ecw_pending_2fa'] ?? null;
if (is_array($pending) && time() - (int)$pending['at'] > (isset($pending['email_code']) ? 60 * ECW_EMAIL_CODE_MINUTES : 300)) { unset($_SESSION['ecw_pending_2fa']); $pending = null; }
if (isset($_GET['cancel2fa'])) { unset($_SESSION['ecw_pending_2fa']); $pending = null; }
$show2fa = is_array($pending);
if (isset($_SESSION['ecw_sso_error'])) { $error = (string)$_SESSION['ecw_sso_error']; unset($_SESSION['ecw_sso_error']); }
$ssoAvailable = ecw_sso_available();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        // Usually a stale tab rather than an attack, so say something useful.
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['step'] ?? '') === 'email_code') {
        // "Email me a code instead" — for when the phone is not at hand.
        if (!$show2fa) {
            $error = 'Sign-in timed out. Enter your password again.';
        } else {
            try {
                $_SESSION['ecw_pending_2fa']['email_code'] = ecw_email_code_send((int)$pending['user_id']);
                $_SESSION['ecw_pending_2fa']['at'] = time();   // give them time to open the email
                $pending = $_SESSION['ecw_pending_2fa'];
                $notice = 'We emailed a code to ' . $pending['email_code']['sent_to'] . '. It works for ' . ECW_EMAIL_CODE_MINUTES . ' minutes.';
            } catch (EcwRateLimitExceeded $e) {
                $error = 'Too many codes asked for. Wait a few minutes, or use a recovery code.';
                http_response_code(429);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif (($_POST['step'] ?? '') === '2fa') {
        if (!$show2fa) {
            $error = 'Sign-in timed out. Enter your password again.';
        } else {
            try {
                $userId = (int)$pending['user_id'];
                $typed = (string)($_POST['code'] ?? '');
                $byEmail = ecw_email_code_matches($pending['email_code'] ?? null, $typed);
                if (!$byEmail && isset($pending['email_code'])) $_SESSION['ecw_pending_2fa']['email_code']['tries'] = (int)($pending['email_code']['tries'] ?? 0) + 1;
                if ($byEmail || ecw_totp_verify_login($userId, $typed)) {
                    $st = ecw_db()->prepare('SELECT id, name, email FROM `' . ecw_table('users') . '` WHERE id = ? AND disabled_at IS NULL');
                    $st->execute([$userId]);
                    $u = $st->fetch();
                    unset($_SESSION['ecw_pending_2fa']);
                    if ($u) {
                        if (!empty($_POST['trust'])) ecw_trusted_remember($userId);
                        if ($byEmail) ecw_log('user ' . $userId . ' signed in with an emailed code');
                        ecw_login_user($u);
                        $goNext($pending['next'] ?? null);
                    }
                    $error = 'Sign-in failed.';
                    $show2fa = false;
                } else {
                    require_once dirname(__DIR__) . '/lib/audit.php';
                    ecw_audit('login.2fa_fail', 'user', $userId, ['by' => isset($pending['email_code']) ? 'email or app' : 'app'], ecw_user_organization_id($userId), $userId);
                    $error = isset($pending['email_code'])
                        ? 'That code is not right. Use the code from the email, the newest code from your authenticator app, or a recovery code.'
                        : 'That code is not right. Use the newest code from your authenticator app, or a recovery code.';
                    http_response_code(401);
                }
            } catch (EcwRateLimitExceeded $e) {
                $error = 'Too many attempts. Try again in a few minutes.';
                http_response_code(429);
            }
        }
    } else {
        $result = ecw_attempt_login($email, (string)($_POST['password'] ?? ''), ecw_client_ip());

        if ($result['ok'] && ecw_security_password_blocked((int)$result['user']['id'])) {
            // The password was right, so saying why is no account oracle.
            $error = 'Your organization signs in with Microsoft. Use "Sign in with Microsoft" below.';
            http_response_code(403);
        } elseif ($result['ok']) {
            if (ecw_totp_enabled((int)$result['user']['id']) && !ecw_trusted_check((int)$result['user']['id'])) {
                session_regenerate_id(true);
                $_SESSION['ecw_pending_2fa'] = ['user_id' => (int)$result['user']['id'], 'at' => time(), 'next' => $next];
                $show2fa = true;
            } else {
                ecw_login_user($result['user']);
                $goNext($next);
            }
        } else {
            $error = $result['error'];
            if ($result['retry_after'] !== null) {
                header('Retry-After: ' . $result['retry_after']);
                http_response_code(429);
            } else {
                http_response_code(401);
            }
        }
    }
}

$csrf = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/auth.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="auth-body">
<?= ecw_i18n_scripts($authLang, ecw_web_base()) ?>
<nav class="auth-lang" aria-label="Language"><?php foreach (ECW_LANGUAGES as $code => $label): ?><a href="?lang=<?= $h($code) ?>" class="notranslate<?= $code === $authLang ? ' active' : '' ?>" lang="<?= $h($code) ?>"><?= $h($label) ?></a><?php endforeach; ?></nav>

<main class="auth-card">
  <h1><img src="<?= $h(ecw_product_brand()['icons']) ?>favicon-32.png" width="32" height="32" alt=""> <?= $h(ecw_product_brand()['name']) ?></h1>
  <p class="auth-sub">Sign in to continue.</p>

  <?php if ($error !== null): ?>
    <div class="auth-error" role="alert"><?= $h($error) ?></div>
  <?php endif; ?>
  <?php if ($notice !== null && $error === null): ?>
    <div class="auth-ok" role="status"><?= $h($notice) ?></div>
  <?php endif; ?>

  <?php if ($show2fa): ?>
  <form method="post" action="login.php" autocomplete="off" class="auth-2fa">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <input type="hidden" name="step" value="2fa">
    <p class="auth-2fa-icon"><i class="fa <?= isset($pending['email_code']) ? 'fa-envelope-circle-check' : 'fa-shield-halved' ?>" aria-hidden="true"></i></p>
    <label for="code"><?= isset($pending['email_code']) ? 'Code from the email (or your authenticator app)' : 'Code from your authenticator app' ?></label>
    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9A-Fa-f\- ]{6,12}" maxlength="12" required autofocus placeholder="123 456">
    <label class="auth-check"><input type="checkbox" name="trust" value="1" checked> Trust this computer for <?= ECW_TRUST_DAYS ?> days</label>
    <button type="submit" class="primary">Verify</button>
  </form>
  <?php if (ecw_email_code_available((int)$pending['user_id'])): ?>
  <form method="post" action="login.php" class="auth-alt">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <input type="hidden" name="step" value="email_code">
    <button type="submit" class="auth-link-btn"><i class="fa fa-envelope" aria-hidden="true"></i> <?= isset($pending['email_code']) ? 'Send the email again' : 'Authenticator not working? Email me a code' ?></button>
  </form>
  <?php endif; ?>
  <p class="auth-foot">Lost your phone? Type one of your recovery codes instead, or ask your admin to turn two-factor sign-in off. <a href="login.php?cancel2fa=1">Use another account</a></p>
  <?php else: ?>
  <form method="post" action="login.php" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <?php if ($next !== null): ?>
      <input type="hidden" name="next" value="<?= $h($next) ?>">
    <?php endif; ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= $h($email) ?>"
           autocomplete="username" required autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit" class="primary">Log in</button>
  </form>

  <?php if ($ssoAvailable): ?>
  <div class="auth-or"><span>or</span></div>
  <a class="auth-sso" href="sso.php<?= $next !== null ? '?next=' . $h(rawurlencode($next)) : '' ?>">
    <svg width="18" height="18" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
    Sign in with Microsoft
  </a>
  <?php endif; ?>

  <p class="auth-foot"><a href="forgot.php">Forgot password?</a> · Accounts are created by your organization admin.</p>
  <?php endif; ?>
</main>

</body>
</html>
