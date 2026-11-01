<?php declare(strict_types=1);
/**
 * Workspace branding (Settings → Branding, admins): the name in the top bar
 * (or none) and the business logo. The Case Workspace mark always stays, as a
 * small badge on the logo's corner (partials/nav.php): the business leads, the
 * open-source software it runs on is still credited.
 *
 * Kept in app_settings ("branding:<org>"), the logo as a file in
 * storage/branding/<org>.<ext>, served by web/brand-logo.php. Only raster
 * pictures (PNG, JPEG, WebP, GIF), checked by their content: an SVG can carry
 * script, and the logo shows on every page.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/license.php';

const ECW_BRAND_DEFAULT_NAME = 'Eleon';
const ECW_BRAND_LOGO_MAX_BYTES = 1048576;
const ECW_BRAND_LOGO_TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

/** @return array{name:string, logo_ext:?string, logo_v:?string} name '' = no name shown */
function ecw_branding(int $orgId): array
{
    $b = json_decode((string)ecw_app_setting('branding:' . $orgId), true);
    $b = is_array($b) ? $b : [];
    $ext = in_array($b['logo_ext'] ?? null, ECW_BRAND_LOGO_TYPES, true) ? $b['logo_ext'] : null;
    if ($ext !== null && !is_file(ecw_brand_logo_path($orgId, $ext))) $ext = null;
    return [
        'name' => array_key_exists('name', $b) ? (string)$b['name'] : ECW_BRAND_DEFAULT_NAME,
        'logo_ext' => $ext,
        'logo_v' => $ext !== null ? (string)($b['logo_v'] ?? '') : null,
    ];
}

function ecw_brand_logo_path(int $orgId, string $ext): string
{
    return dirname(__DIR__) . '/storage/branding/' . $orgId . '.' . $ext;
}

function ecw_branding_store(int $orgId, array $b): void
{
    ecw_app_setting_set('branding:' . $orgId, json_encode(['name' => $b['name'], 'logo_ext' => $b['logo_ext'], 'logo_v' => $b['logo_v']]));
}

/** The name in the top bar; '' hides it. */
function ecw_branding_set_name(int $orgId, string $name): void
{
    $b = ecw_branding($orgId);
    $b['name'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 60);
    ecw_branding_store($orgId, $b);
}

/** Store an uploaded logo. @throws InvalidArgumentException with a message for the person */
function ecw_branding_set_logo(int $orgId, string $tmpPath): void
{
    $size = is_file($tmpPath) ? (int)filesize($tmpPath) : 0;
    if ($size === 0) throw new InvalidArgumentException('Choose a picture first.');
    if ($size > ECW_BRAND_LOGO_MAX_BYTES) throw new InvalidArgumentException('The picture is too large (up to 1 MB).');
    $info = @getimagesize($tmpPath);
    $ext = $info ? (ECW_BRAND_LOGO_TYPES[$info[2]] ?? null) : null;
    if ($ext === null) throw new InvalidArgumentException('Use a PNG, JPEG, WebP or GIF picture.');
    if ($info[0] < 16 || $info[1] < 16 || $info[0] > 4000 || $info[1] > 4000) throw new InvalidArgumentException('The picture should be between 16 and 4000 pixels on each side.');
    $dir = dirname(ecw_brand_logo_path($orgId, $ext));
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('ecw: cannot create ' . $dir);
    ecw_branding_remove_logo_file($orgId);
    if (!copy($tmpPath, ecw_brand_logo_path($orgId, $ext))) throw new RuntimeException('ecw: cannot store the logo');
    $b = ecw_branding($orgId);
    $b['logo_ext'] = $ext;
    $b['logo_v'] = substr(sha1_file(ecw_brand_logo_path($orgId, $ext)) ?: (string)time(), 0, 12);
    ecw_branding_store($orgId, $b);
}

function ecw_branding_remove_logo(int $orgId): void
{
    ecw_branding_remove_logo_file($orgId);
    $b = ecw_branding($orgId);
    $b['logo_ext'] = $b['logo_v'] = null;
    ecw_branding_store($orgId, $b);
}

function ecw_branding_remove_logo_file(int $orgId): void
{
    foreach (ECW_BRAND_LOGO_TYPES as $ext) {
        $p = ecw_brand_logo_path($orgId, $ext);
        if (is_file($p)) @unlink($p);
    }
}
