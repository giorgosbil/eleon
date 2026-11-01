<?php declare(strict_types=1);
/**
 * Pages and assets of optional modules (lib/modules.php), which live outside
 * the web folder:
 *   module.php?m=<module>&p=<page>       a page of the module (it checks sign-in itself)
 *   module.php?m=<module>&asset=<file>   a file from modules/<module>/assets/
 */
require_once dirname(__DIR__) . '/lib/modules.php';

$ecwModuleId = (string)($_GET['m'] ?? '');
if (isset($_GET['asset'])) {
    ecw_module_serve_asset($ecwModuleId, (string)$_GET['asset']);
}
$ecwModulePage = ecw_module_page_file($ecwModuleId, (string)($_GET['p'] ?? ''));
if ($ecwModulePage === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
require $ecwModulePage;
