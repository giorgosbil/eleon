<?php declare(strict_types=1);
/** Installation branding lives in .env, never in shared workspace data. */
function ecw_product_brand(?string $profile = null): array
{
    $profile ??= (string)getenv('ECW_PRODUCT_BRAND');
    $eleon = $profile === 'eleon';
    return [
        'profile' => $eleon ? 'eleon' : 'original',
        'name' => $eleon ? 'Eleon' : 'Case Workspace',
        'short_name' => $eleon ? 'Eleon' : 'Cases',
        'icons' => $eleon ? 'assets/brands/eleon/' : 'assets/brands/original/',
    ];
}

/** An administrator's one-page preview never changes settings or the installed app. */
function ecw_product_brand_for_page(array $context, array $query): array
{
    return ecw_product_brand(($context['role'] ?? '') === 'admin' && ($query['brand_preview'] ?? '') === 'eleon' ? 'eleon' : null);
}

function ecw_product_manifest(array $brand): array
{
    $manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/web/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['name'] = $brand['name'];
    $manifest['short_name'] = $brand['short_name'];
    foreach ($manifest['icons'] as &$icon) $icon['src'] = $brand['icons'] . basename($icon['src']);
    unset($icon);
    foreach ($manifest['shortcuts'] as &$shortcut) foreach ($shortcut['icons'] as &$icon) $icon['src'] = $brand['icons'] . basename($icon['src']);
    unset($shortcut, $icon);
    // Existing installations keep their identity when the selected brand changes.
    $manifest['id'] = 'app.php?source=pwa';
    return $manifest;
}
