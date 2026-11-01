<?php declare(strict_types=1);
/** A colleague's Outlook photo. Same organization only; 404 means "use initials". */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/avatars.php';

$context = ecw_context();
$userId  = (int)($_GET['u'] ?? 0);
if ($context === null || $userId <= 0) {
    http_response_code(404);
    exit;
}

$st = ecw_db()->prepare(
    'SELECT 1 FROM `' . ecw_table('organization_users') . '` WHERE organization_id = ? AND user_id = ?'
);
$st->execute([(int)$context['organization_id'], $userId]);
$path = ecw_avatar_path($userId);
if (!$st->fetchColumn() || !is_file($path)) {
    http_response_code(404);
    exit;
}

// URLs carry ?v=<hash>, so a long private cache is safe: a new photo is a new URL.
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
