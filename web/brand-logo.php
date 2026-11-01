<?php declare(strict_types=1);
/** The workspace's business logo (lib/branding.php), for the top bar. Cached for good: the URL carries ?v=<hash>. */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/branding.php';
$context = ecw_require_login();
$b = ecw_branding((int)$context['organization_id']);
if ($b['logo_ext'] === null) { http_response_code(404); exit; }
$path = ecw_brand_logo_path((int)$context['organization_id'], $b['logo_ext']);
header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$b['logo_ext']]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
