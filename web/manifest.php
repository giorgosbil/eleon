<?php declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/product_brand.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode(ecw_product_manifest(ecw_product_brand()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
