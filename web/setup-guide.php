<?php declare(strict_types=1);
/**
 * The setup guide (welcome.html, in the app folder) opened from the app itself, so an admin who
 * still has to set up Outlook or Gmail finds it where they are: Settings → Mailbox and Get
 * started link here (#mail, #mail-google). Admins only. The guide works in the browser: what is
 * typed into it never reaches the server; it gives a new .env to put in the app folder.
 *
 *   ?img=name.png   one of its pictures (welcome-images/, when the installation has them)
 */
require_once dirname(__DIR__) . '/lib/session.php';
$context = ecw_require_login();
if (($context['role'] ?? '') !== 'admin') { http_response_code(403); exit('Only an administrator can set up Outlook and Gmail.'); }
$root = dirname(__DIR__);

if (isset($_GET['img'])) {
    $name = basename((string)$_GET['img']);
    $file = $root . '/welcome-images/' . $name;
    if (!preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|webp)$/i', $name) || !is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: ' . (preg_match('/\.png$/i', $name) ? 'image/png' : (preg_match('/\.webp$/i', $name) ? 'image/webp' : 'image/jpeg')));
    header('Cache-Control: private, max-age=86400');
    readfile($file);
    exit;
}

$html = @file_get_contents($root . '/welcome.html');
if ($html === false) { http_response_code(404); exit('The setup guide (welcome.html) is not in this installation.'); }
$base = ecw_web_base();
// Written to be opened from the app folder: its icons are under web/, its pictures in welcome-images/.
$html = str_replace(['src="web/', "const IMG_DIR = 'welcome-images/';"], ['src="' . $base, "const IMG_DIR = 'setup-guide.php?img=';"], $html);
$back = '<a class="hero-btn" href="' . htmlspecialchars($base . 'settings/mailbox.php', ENT_QUOTES, 'UTF-8') . '" style="text-decoration:none">← Back to the app</a>';
$html = str_replace('<button type="button" class="hero-btn" id="themeBtn"', $back . '<button type="button" class="hero-btn" id="themeBtn"', $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
echo $html;
