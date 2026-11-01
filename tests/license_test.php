<?php declare(strict_types=1);
/**
 * Packages, license keys, the trial, user/mailbox limits and view profiles.
 *
 *   php tests/license_test.php
 *
 * Signs keys with a throwaway key pair made here (the vendor's private key is
 * never needed). Database parts run inside one transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
// Exercise retained legacy key/trial compatibility separately from Community defaults.
define('ECW_COMMUNITY_EDITION', false);

require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/license.php';
require_once dirname(__DIR__) . '/lib/features.php';

$pass = 0; $fail = 0;
function group(string $t): void { echo "\n=== {$t} ===\n"; }
function check(bool $c, string $label): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$label}\n"; } else { $fail++; echo "  FAIL  {$label}\n"; } }

$pair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = base64_encode(sodium_crypto_sign_publickey($pair));
$issue = static fn(array $p): string => ecw_license_sign($p + ['id' => 'T-1', 'customer' => 'Acme Ltd', 'issued' => '2026-10-01'], $secret);

group('license keys');
$team = $issue(['package' => 'pro', 'users' => 12, 'mailboxes' => 20, 'expires' => '2027-10-31']);
$v = ecw_license_verify($team, $public);
check($v['ok'] && $v['payload']['package'] === 'pro' && $v['payload']['users'] === 12, 'a signed key verifies and carries its package and limits');
check(ecw_license_verify($issue(['package' => 'team']), $public)['payload']['package'] === 'pro'
    && ecw_license_verify($issue(['package' => 'core']), $public)['payload']['package'] === 'free', 'keys from before the rename keep working: Team is Pro, Core is Free');
check(ecw_license_verify(" \n" . chunk_split($team, 40, "\n"), $public)['ok'], 'spaces and line breaks from copying are ignored');
[$h, $body, $sig] = explode('.', $team);
$forged = ecw_b64url((string)json_encode(['package' => 'business'] + json_decode((string)ecw_b64url_decode($body), true)));
check(!ecw_license_verify("$h.$forged.$sig", $public)['ok'], 'changing the package breaks the signature');
check(!ecw_license_verify($team)['ok'], 'a key signed by someone else does not verify against the shipped public key');
check(!ecw_license_verify('hello', $public)['ok'] && !ecw_license_verify('ECW1.a.b', $public)['ok'], 'garbage is refused');
check(!ecw_license_verify($issue(['package' => 'platinum']), $public)['ok'], 'an unknown package is refused');
check(strlen(ECW_LICENSE_PUBLIC_KEY) === 44 && strlen((string)base64_decode(ECW_LICENSE_PUBLIC_KEY, true)) === 32, 'the shipped public key is a real Ed25519 key');

group('trial and packages');
$s = ecw_license_state_for(null, '2026-10-01', '2026-10-04');
check($s['source'] === 'trial' && $s['package'] === 'business' && $s['trial_days_left'] === 27, 'a fresh install has a 30-day Business trial');
check(in_array('ai', $s['features'], true) && $s['users'] === null, 'with every feature and no user limit');
$s = ecw_license_state_for(null, '2026-10-01', '2026-10-31');
check($s['source'] === 'free' && $s['package'] === 'free' && $s['users'] === null && $s['mailboxes'] === null, 'after 30 days: Free, with no user or mailbox limit');
check(in_array('calendar', $s['features'], true) && in_array('contacts', $s['features'], true) && in_array('case_templates', $s['features'], true)
    && !array_intersect(['ai', 'automation', 'reports', 'sla', 'teams', 'deals', 'shared_drafts'], $s['features']), 'Free has the approved free features and none of the paid ones');
$s = ecw_license_state_for(null, null, '2026-10-04');
check($s['source'] === 'free' && $s['trial_ends'] === null && $s['package'] === 'free', 'with nothing paid installed there is no trial');
$s = ecw_license_state_for($team, '2026-10-01', '2026-11-05', $public);
check($s['source'] === 'license' && $s['package'] === 'pro' && $s['users'] === 12 && $s['customer'] === 'Acme Ltd', 'a license gives its package and limits (trial or not)');
check(in_array('ai', $s['features'], true) && in_array('teams', $s['features'], true) && in_array('reports', $s['features'], true), 'Pro has AI, Microsoft Teams and reports');
$s = ecw_license_state_for($team, '2026-01-01', '2027-11-01', $public);
check($s['source'] === 'expired' && $s['expired'] && $s['package'] === 'free' && $s['users'] === null, 'an expired license falls back to Free, not to nothing');
$forever = $issue(['package' => 'business', 'expires' => null]);
check(ecw_license_state_for($forever, '2020-01-01', '2040-01-01', $public)['package'] === 'business', 'a key without an end date never expires');
check(ecw_license_state_for($team, '2026-10-01', '2026-10-02')['source'] === 'trial', 'a key that does not verify is ignored (trial goes on)');
foreach (['free', 'pro'] as $i => $id) {
    $next = array_keys(ecw_packages())[$i + 1];
    check(array_diff(ecw_packages()[$id]['features'], ecw_packages()[$next]['features']) === [], ecw_packages()[$next]['name'] . ' includes everything in ' . ecw_packages()[$id]['name']);
}
check(array_diff(array_keys(ecw_feature_catalog()), ecw_packages()['business']['features']) === [], 'Business includes every switchable feature');
check(ecw_package_needed('ai') === 'pro' && ecw_package_needed('reports') === 'pro' && ecw_package_needed('calendar') === 'free', 'the badge names the cheapest package with a feature');
check(ecw_paid_features_present(), 'this install has paid features (still in the core until they move to modules)');

group('view profiles');
$view = static fn(string $p, array $f = []): array => ['profile' => $p, 'chosen' => true, 'features' => $f + array_fill_keys(array_keys(ecw_feature_catalog()), true)];
check(!ecw_profile_shows('calendar', $view('simple')) && !ecw_profile_shows('templates', $view('simple')), 'Simple hides calendar and templates');
check(ecw_profile_shows('calendar', $view('standard')) && !ecw_profile_shows('ai', $view('standard')), 'Standard shows calendar, hides AI');
check(ecw_profile_shows('ai', $view('everything')), 'Everything shows everything');
check(!ecw_profile_shows('reports', $view('custom', ['reports' => false])) && ecw_profile_shows('ai', $view('custom', ['reports' => false])), 'Custom follows the person\'s own picks');

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = function (string $t, array $row) use ($pdo): int {
        $c = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };
    $org = $ins('organizations', ['name' => 'License test']);
    $uid = $ins('users', ['name' => 'Member', 'email' => 'lic' . uniqid() . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $uid, 'role' => 'user']);
    $ctx = ['user' => ['id' => $uid], 'organization_id' => $org, 'role' => 'user'];

    group('three layers together');
    check(ecw_user_view($uid, $org, true)['profile'] === 'everything' && !ecw_user_view($uid, $org)['chosen'], 'nobody chose: the organization default (Everything)');
    ecw_org_default_profile_save($org, 'simple');
    check(ecw_user_view($uid, $org, true)['profile'] === 'simple', 'the admin can make Simple the default');
    ecw_user_view_save($uid, 'custom', ['ai']);
    $v = ecw_user_view($uid, $org, true);
    check($v['profile'] === 'custom' && $v['features']['ai'] && !$v['features']['calendar'], 'a person saves their own Custom view');
    check(ecw_profile_shows('ai', $v) && !ecw_profile_shows('calendar', $v), 'and it decides what they see');
    ecw_features_save($org, array_values(array_diff(array_keys(ecw_feature_catalog()), ['ai'])));
    // ecw_features() caches per request, so check layer 2 on a fresh organization row read.
    check(!(json_decode((string)$pdo->query('SELECT features_json FROM ecw_organizations WHERE id = ' . $org)->fetchColumn(), true)['ai'] ?? true), 'the admin can switch AI off for members');
    ecw_user_view_save($uid, null);
    check(!ecw_user_view($uid, $org, true)['chosen'], 'a person can go back to the workspace default');
    try { ecw_user_view_save($uid, 'platinum'); check(false, 'an unknown profile is refused'); } catch (InvalidArgumentException $e) { check(true, 'an unknown profile is refused'); }

    group('who can switch what on: package > admin > view');
    $adminId = $ins('users', ['name' => 'Boss', 'email' => 'boss' . uniqid() . '@acme.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $adminId, 'role' => 'admin']);
    ecw_user_view_save($adminId, 'everything');   // (the workspace default was set to Simple above)
    ecw_user_view($adminId, $org, true);
    $member = ['user' => ['id' => $uid], 'organization_id' => $org, 'role' => 'user'];
    $admin = ['user' => ['id' => $adminId], 'organization_id' => $org, 'role' => 'admin'];
    $set = static function (?string $trialStart) use ($pdo): void {
        $pdo->exec("DELETE FROM ecw_app_settings WHERE name IN ('license_key', 'trial_started_at')");
        if ($trialStart !== null) $pdo->prepare("INSERT INTO ecw_app_settings (name, value) VALUES ('trial_started_at', ?)")->execute([$trialStart]);
        ecw_license_state(true);
    };
    // Free (trial long over), member with a Custom view that ticks everything.
    $set('2000-01-01');
    ecw_features_save($org, array_keys(ecw_feature_catalog()));            // the admin allows everything for members
    ecw_user_view_save($uid, 'custom', array_keys(ecw_feature_catalog()));  // the member ticks everything
    ecw_user_view($uid, $org, true);
    $paid = array_values(array_diff(array_keys(ecw_feature_catalog()), ecw_packages()['free']['features']));
    check(array_filter($paid, static fn($f) => ecw_feature_allowed_for($f, $member)) === [], 'Free: a member who ticks everything still gets no paid feature');
    check(array_filter($paid, static fn($f) => ecw_feature_allowed_for($f, $admin)) === [], 'Free: an admin gets no paid feature either — the package beats everyone');
    check(ecw_feature_allowed_for('templates', $member), 'Free: what Free has works');
    // Business (trial running): now the admin and the views decide.
    $set(gmdate('Y-m-d'));
    check(ecw_feature_allowed_for('ai', $member) && ecw_feature_allowed_for('contacts', $member), 'Business: allowed by the admin and ticked in the view → on');
    ecw_features_save($org, array_values(array_diff(array_keys(ecw_feature_catalog()), ['ai', 'contacts'])));
    check(!ecw_feature_allowed_for('ai', $member) && !ecw_feature_allowed_for('contacts', $member), 'the admin switches AI and Contacts off: the member\'s own view cannot turn them back on');
    check(ecw_feature_allowed_for('ai', $admin), 'admins keep what the package has (Feature access is for members)');
    ecw_user_view_save($uid, 'simple');
    ecw_user_view($uid, $org, true);
    check(!ecw_feature_allowed_for('calendar', $member) && ecw_feature_allowed_for('calendar', $admin), 'a member\'s Simple view hides calendar for them only');
    ecw_user_view_save($adminId, 'simple');
    ecw_user_view($adminId, $org, true);
    check(!ecw_feature_allowed_for('calendar', $admin), 'an admin\'s own view hides things for the admin too');
    try { ecw_user_view_save($uid, 'everything-and-more'); check(false, 'a made-up view is refused'); } catch (InvalidArgumentException $e) { check(true, 'a made-up view is refused'); }
    ecw_user_view_save($uid, 'custom', ['made_up_feature', 'ai']);
    $v = ecw_user_view($uid, $org, true);
    check(!array_key_exists('made_up_feature', $v['features']) && !ecw_feature_allowed_for('ai', $member), 'unknown names in a Custom view are ignored, and the admin\'s "off" still wins');
    // Package features that run without a person also stop with the package.
    $set('2000-01-01');
    require_once dirname(__DIR__) . '/lib/teams.php';
    require_once dirname(__DIR__) . '/lib/case_extras.php';
    check(ecw_teams_notify($org, 'test', 'x', []) === false, 'Free: no Teams channel posts');
    check(ecw_package_has('case_extras') && !ecw_package_has('automation'), 'Free: due-date reminders run (tags and due dates are free), automation does not');
    $set(gmdate('Y-m-d'));

    group('limits never touch existing data');
    check(ecw_license_users_used() >= 1 && ecw_license_mailboxes_used() >= 0, 'users and mailboxes in use are counted');
    check(ecw_license_mailbox_limit_problem(true) === null, 'reconnecting an existing mailbox is always allowed');
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
