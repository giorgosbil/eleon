<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/license.php';
require_once dirname(__DIR__) . '/lib/features.php';
$checks = 0;
$check = static function (bool $value, string $name) use (&$checks): void {
    if (!$value) throw new RuntimeException($name);
    $checks++;
};
$s = ecw_license_state(true);
$check($s['source'] === 'community', 'Fresh installation uses Community without database licensing state.');
$check($s['trial_ends'] === null && $s['expires'] === null && !$s['expired'], 'Community has no trial or expiry.');
$check($s['users'] === null && $s['mailboxes'] === null, 'No license-based capacity limits.');
foreach (array_keys(ecw_feature_catalog()) as $feature) $check(ecw_package_has($feature), 'Installed feature available: ' . $feature);
$check(ecw_package_at_least('business'), 'Existing Business capabilities remain available.');
$check(!ecw_paid_features_present(), 'No paid-trial initialization.');
$check(ecw_license_user_limit_problem() === null && ecw_license_mailbox_limit_problem() === null, 'Capacity checks need no paid key.');
$check(!ecw_profile_shows('ai', ['profile' => 'custom', 'features' => ['ai' => false]]), 'User visibility settings still hide optional features.');
echo $checks . " Community checks passed.\n";
