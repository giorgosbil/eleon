<?php declare(strict_types=1);
/**
 * Settings pages of optional modules (lib/modules.php):
 *   settings/module.php?m=<module>&p=<page>
 * The page checks sign-in, admin and its feature itself.
 */
require_once dirname(__DIR__, 2) . '/lib/modules.php';

$ecwModulePage = ecw_module_page_file((string)($_GET['m'] ?? ''), (string)($_GET['p'] ?? ''), 'settings');
if ($ecwModulePage === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
require $ecwModulePage;
