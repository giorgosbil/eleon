<?php declare(strict_types=1);
/**
 * Email Case Workspace — sign out.
 *
 * POST ONLY, with CSRF.
 *
 * A GET logout is a one-line denial of service: any page on the internet can
 * embed <img src="…/logout.php"> and sign the user out, and browsers and link
 * prefetchers will follow it without anyone clicking. The navigation therefore
 * submits a small form rather than linking here.
 *
 * A GET lands on a confirmation page with that form, so a bookmarked or typed
 * URL still does something sensible instead of erroring.
 */

require_once dirname(__DIR__) . '/lib/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Session expired. Go back and try again.\n";
        exit;
    }
    ecw_logout();
    ecw_redirect(ecw_web_base() . 'login.php');
}

if (ecw_context() === null) {
    ecw_redirect(ecw_web_base() . 'login.php');
}

$csrf = ecw_csrf_token();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign out</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-body">
<main class="auth-card">
  <h1>Sign out</h1>
  <p class="auth-sub">End this session?</p>
  <form method="post" action="logout.php">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <button type="submit" class="primary">Sign out</button>
  </form>
  <p class="auth-foot"><a href="app.php">Back to mail</a></p>
</main>
</body>
</html>
