<?php declare(strict_types=1);
/**
 * Packages (what a customer bought) and the license key that says so.
 *
 *   Free      the open-source core, always, with no license and no user or mailbox limit
 *   Pro       Free + AI, automation, reports and response times, shared drafts,
 *             Microsoft Teams, deals, and the Pro modules (modules/<id>/, package "pro")
 *   Business  Pro + sign in with Microsoft, audit log, legal hold and retention
 *
 * Paid features exist only where they are installed (the Pro modules). When
 * they are, the first 30 days have Business unlocked (the trial), then it falls
 * back to Free; an install with nothing paid has no trial at all. An expired
 * license falls back to Free too. Nothing ever locks anyone out of their mail
 * or cases: a package only decides which optional features show, and a
 * license's user/mailbox limits only stop adding new ones.
 *
 * Keys issued before the packages were renamed keep working: "core" is read
 * as Free and "team" as Pro (ECW_PACKAGE_ALIASES).
 *
 * LICENSE KEY
 *   ECW1.<base64url(JSON payload)>.<base64url(Ed25519 signature of the payload part)>
 *   payload: { id, customer, package, users (null = no limit), mailboxes, issued, expires (Y-m-d or null) }
 *   Signed offline with the vendor's private key (bin/license_tool.php issue),
 *   checked here with the public key below — no call home, works offline.
 *
 * What is switched on is the intersection of three layers:
 *   package (this file)  ∩  admin's Feature access  ∩  the person's view profile
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

// The public Eleon application includes every installed feature without a paid key.
// Legacy licensing helpers remain for signature compatibility and historical tests.
if (!defined('ECW_COMMUNITY_EDITION')) define('ECW_COMMUNITY_EDITION', true);

/** The vendor's Ed25519 public key (base64). The private key never ships. */
const ECW_LICENSE_PUBLIC_KEY = 'XS7lBaFfuizKORxzUrV/2CwnGczv/kjGK0jbxeAegPs=';
const ECW_TRIAL_DAYS = 30;

/**
 * name, short pitch, features (keys of ECW_FEATURES), user / mailbox limits (null = none).
 * Each package includes everything of the one before it.
 */
const ECW_PACKAGES = [
    'free' => [
        'name' => 'Free', 'pitch' => 'Open source. For one person or a whole office, with no limits.',
        'features' => ['templates', 'checklists', 'next_actions', 'spelling', 'calendar', 'rules', 'case_groups', 'export', 'case_extras', 'auto_reply', 'contacts', 'case_templates'],
        'users' => null, 'mailboxes' => null,
    ],
    'pro' => [
        'name' => 'Pro', 'pitch' => 'For teams: AI, automation, reports and the Pro modules.',
        'features' => ['templates', 'checklists', 'next_actions', 'spelling', 'calendar', 'rules', 'case_groups', 'export', 'case_extras', 'auto_reply', 'contacts', 'case_templates',
                       'automation', 'reports', 'sla', 'shared_drafts', 'teams', 'ai', 'ai_actions', 'deals'],
        'users' => null, 'mailboxes' => null,
    ],
    'business' => [
        'name' => 'Business', 'pitch' => 'For firms with compliance needs.',
        'features' => ['templates', 'checklists', 'next_actions', 'spelling', 'calendar', 'rules', 'case_groups', 'export', 'case_extras', 'auto_reply', 'contacts', 'case_templates',
                       'automation', 'reports', 'sla', 'shared_drafts', 'teams', 'ai', 'ai_actions', 'deals'],
        'users' => null, 'mailboxes' => null,
    ],
];
/** Package names in keys issued before the rename. */
const ECW_PACKAGE_ALIASES = ['core' => 'free', 'team' => 'pro'];

/** What every package has, shown on the License page (not switchable). */
const ECW_PACKAGE_ALWAYS = [
    'Outlook and Gmail mail with two-way sync', 'Cases, case timeline, notes and follow-ups', 'Emails join their case by case or reference number',
    'Shared mailboxes, holiday cover and handover', 'Drafts, undo send and send later, signatures',
    'Selecting several emails, right-click, drag and drop', 'File viewers (PDF, Word, Excel, PowerPoint, DXF)', 'Phone app and push notifications',
    'Two-factor sign-in, backups and off-site copies', 'System health, error reports and signed updates', 'Export of one case or everything',
];
/** Built, but not a switchable feature (lib/security.php checks the package): listed under the package that has them. */
const ECW_PACKAGE_EXTRAS = [
    'business' => ['Sign in with Microsoft', 'Legal hold and retention rules', 'Audit log', 'Encrypted backups'],
];
/** Planned, shown on the License page as "coming" under the package that will get them. */
const ECW_PACKAGE_COMING = [
    'business' => ['Court-ready case export', 'Document versions and transmittals', 'Client portal', 'AI that runs on your own server, built in'],
];

/**
 * The packages with the features of the installed modules added: a module's
 * features are in the package it names and every package above it.
 */
function ecw_packages(): array
{
    static $packages = null;
    if ($packages !== null) return $packages;
    require_once __DIR__ . '/modules.php';
    $packages = ECW_PACKAGES;
    $order = array_keys(ECW_PACKAGES);
    foreach (ecw_module_features() as $key => [, , , $package]) {
        $from = array_search($package, $order, true);
        if ($from === false) $from = count($order) - 1;   // unknown package: the top one
        foreach (array_slice($order, (int)$from) as $id) {
            if (!in_array($key, $packages[$id]['features'], true)) $packages[$id]['features'][] = $key;
        }
    }
    return $packages;
}

// ---------------------------------------------------------------- settings

function ecw_app_setting(string $name): ?string
{
    try {
        $st = ecw_db()->prepare('SELECT value FROM `' . ecw_table('app_settings') . '` WHERE name = ?');
        $st->execute([$name]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) { return null; }   // before migration 021
}

function ecw_app_setting_set(string $name, ?string $value): void
{
    $t = ecw_table('app_settings');
    if ($value === null) { ecw_db()->prepare("DELETE FROM `{$t}` WHERE name = ?")->execute([$name]); return; }
    ecw_db()->prepare("INSERT INTO `{$t}` (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$name, $value]);
}

// ---------------------------------------------------------------- keys

function ecw_b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function ecw_b64url_decode(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/** Sign a payload with a base64 Ed25519 secret key (the vendor's tool uses this). */
function ecw_license_sign(array $payload, string $secretKeyB64): string
{
    $body = ecw_b64url((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig = sodium_crypto_sign_detached('ECW1.' . $body, (string)base64_decode($secretKeyB64, true));
    return 'ECW1.' . $body . '.' . ecw_b64url($sig);
}

/**
 * Check a key's signature and shape. Does not look at the expiry date.
 * @return array{ok:bool, payload:?array, error:?string}
 */
function ecw_license_verify(string $key, ?string $publicKeyB64 = null): array
{
    $key = preg_replace('/\s+/', '', $key) ?? '';
    $parts = explode('.', $key);
    if (count($parts) !== 3 || $parts[0] !== 'ECW1') return ['ok' => false, 'payload' => null, 'error' => 'This is not a Case Workspace license key.'];
    $public = base64_decode($publicKeyB64 ?? ECW_LICENSE_PUBLIC_KEY, true);
    $sig = ecw_b64url_decode($parts[2]);
    if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached($sig, 'ECW1.' . $parts[1], $public)) {
        return ['ok' => false, 'payload' => null, 'error' => 'The license key is not valid. Check that it was copied completely.'];
    }
    $payload = json_decode((string)ecw_b64url_decode($parts[1]), true);
    if (is_array($payload) && isset(ECW_PACKAGE_ALIASES[$payload['package'] ?? ''])) $payload['package'] = ECW_PACKAGE_ALIASES[$payload['package']];
    if (!is_array($payload) || !isset(ecw_packages()[$payload['package'] ?? ''])) return ['ok' => false, 'payload' => null, 'error' => 'The license key is for an unknown package.'];
    return ['ok' => true, 'payload' => $payload, 'error' => null];
}

// ---------------------------------------------------------------- state

/**
 * What this installation may use right now.
 * @return array{package:string, name:string, source:string, customer:?string, license_id:?string,
 *               expires:?string, expired:bool, trial_ends:?string, trial_days_left:?int,
 *               users:?int, mailboxes:?int, features:string[]}
 *   source: license | trial | free | expired
 */
function ecw_license_state(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    if (ECW_COMMUNITY_EDITION) {
        require_once __DIR__ . '/features.php';
        return $cache = ['package' => 'business', 'name' => 'Eleon Community', 'source' => 'community',
            'features' => array_keys(ecw_feature_catalog()), 'customer' => null, 'license_id' => null,
            'expires' => null, 'expired' => false, 'trial_ends' => null, 'trial_days_left' => null,
            'users' => null, 'mailboxes' => null];
    }
    $key = ecw_app_setting('license_key');
    // The trial starts the first time anyone asks once something paid is installed.
    $started = ecw_app_setting('trial_started_at');
    if ($started === null && ecw_paid_features_present()) {
        try { ecw_db()->prepare('INSERT IGNORE INTO `' . ecw_table('app_settings') . '` (name, value) VALUES (\'trial_started_at\', ?)')->execute([gmdate('Y-m-d')]); } catch (Throwable $e) {}
        $started = ecw_app_setting('trial_started_at') ?? gmdate('Y-m-d');
    }
    if (!ecw_paid_features_present()) $started = null;   // nothing paid to try
    return $cache = ecw_license_state_for($key, $started, gmdate('Y-m-d'));
}

/** Are features of a paid package installed (in the core today, the Pro modules later)? */
function ecw_paid_features_present(): bool
{
    if (ECW_COMMUNITY_EDITION) return false;
    require_once __DIR__ . '/features.php';
    return array_diff(array_keys(ecw_feature_catalog()), ECW_PACKAGES['free']['features']) !== [];
}

/** The state for a given key / trial start (null = no trial) / day (no database: tests use it directly). */
function ecw_license_state_for(?string $key, ?string $trialStarted, string $today, ?string $publicKeyB64 = null): array
{
    $state = ['package' => 'free', 'source' => 'free', 'customer' => null, 'license_id' => null, 'expires' => null, 'expired' => false,
              'trial_ends' => null, 'trial_days_left' => null, 'users' => null, 'mailboxes' => null];
    $lic = $key !== null ? ecw_license_verify($key, $publicKeyB64) : null;
    if ($lic !== null && $lic['ok']) {
        $p = $lic['payload'];
        $state['customer'] = isset($p['customer']) ? (string)$p['customer'] : null;
        $state['license_id'] = isset($p['id']) ? (string)$p['id'] : null;
        $state['expires'] = !empty($p['expires']) ? (string)$p['expires'] : null;
        if ($state['expires'] !== null && $state['expires'] < $today) {
            $state['source'] = 'expired';   // back to Free, nothing locked
            $state['expired'] = true;
        } else {
            $state['source'] = 'license';
            $state['package'] = (string)$p['package'];
            $state['users'] = isset($p['users']) ? (int)$p['users'] : null;
            $state['mailboxes'] = isset($p['mailboxes']) ? (int)$p['mailboxes'] : null;
        }
    }
    if ($state['source'] === 'free' && $trialStarted !== null) {
        $ends = gmdate('Y-m-d', strtotime($trialStarted . ' +' . ECW_TRIAL_DAYS . ' days'));
        $state['trial_ends'] = $ends;
        if ($ends > $today) {
            $state['source'] = 'trial';
            $state['package'] = 'business';
            $state['trial_days_left'] = (int)ceil((strtotime($ends) - strtotime($today)) / 86400);
        }
    }
    $pkg = ecw_packages()[$state['package']];
    $state['name'] = $pkg['name'];
    if ($state['source'] !== 'license') { $state['users'] = $pkg['users']; $state['mailboxes'] = $pkg['mailboxes']; }
    $state['features'] = $pkg['features'];
    return $state;
}

/** Does the current package include $feature? Features outside the catalogue are core. */
function ecw_package_has(string $feature): bool
{
    require_once __DIR__ . '/features.php';
    if (!isset(ecw_feature_catalog()[$feature])) return true;
    return in_array($feature, ecw_license_state()['features'], true);
}

/** Is the current package $package or higher? For admin-only extras (audit log, retention). */
function ecw_package_at_least(string $package): bool
{
    $order = array_keys(ecw_packages());
    $have = array_search(ecw_license_state()['package'] ?? 'free', $order, true);
    $need = array_search($package, $order, true);
    return $have !== false && $need !== false && $have >= $need;
}

/** The cheapest package that has $feature (for "Team" / "Business" badges). */
function ecw_package_needed(string $feature): string
{
    if (ECW_COMMUNITY_EDITION) return 'free';
    foreach (ecw_packages() as $id => $p) if (in_array($feature, $p['features'], true)) return $id;
    return 'free';
}

/**
 * Install or replace the license key. @return array{ok:bool, error:?string, payload:?array}
 */
function ecw_license_install(string $key): array
{
    $r = ecw_license_verify($key);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'payload' => null];
    if (!empty($r['payload']['expires']) && $r['payload']['expires'] < gmdate('Y-m-d')) {
        return ['ok' => false, 'error' => 'This license key expired on ' . $r['payload']['expires'] . '.', 'payload' => $r['payload']];
    }
    ecw_app_setting_set('license_key', preg_replace('/\s+/', '', $key));
    ecw_license_state(true);
    ecw_log('license installed: ' . ($r['payload']['id'] ?? '?') . ' (' . $r['payload']['package'] . ') for ' . ($r['payload']['customer'] ?? '?'));
    require_once __DIR__ . '/audit.php';
    ecw_audit('license.install', null, null, ['package' => $r['payload']['package'] ?? null, 'license' => $r['payload']['id'] ?? null]);
    return ['ok' => true, 'error' => null, 'payload' => $r['payload']];
}

function ecw_license_remove(): void
{
    ecw_app_setting_set('license_key', null);
    ecw_license_state(true);
    ecw_log('license removed');
}

// ---------------------------------------------------------------- limits

/** Active users in the installation (disabled accounts do not count). */
function ecw_license_users_used(): int
{
    return (int)ecw_db()->query('SELECT COUNT(*) FROM `' . ecw_table('users') . '` WHERE disabled_at IS NULL')->fetchColumn();
}

/** Connected mailboxes (disconnected ones do not count). */
function ecw_license_mailboxes_used(): int
{
    return (int)ecw_db()->query('SELECT COUNT(*) FROM `' . ecw_table('mailboxes') . "` WHERE sync_status <> 'disconnected'")->fetchColumn();
}

/** null when one more user may be added, else the reason. Existing users are never touched. */
function ecw_license_user_limit_problem(): ?string
{
    $s = ecw_license_state();
    if ($s['users'] === null || ecw_license_users_used() < $s['users']) return null;
    return $s['name'] . ' allows ' . $s['users'] . ' active users. Disable someone, or upgrade in Settings → License.';
}

/** null when one more mailbox may be connected, else the reason. $reconnecting: an existing one coming back is always fine. */
function ecw_license_mailbox_limit_problem(bool $reconnecting = false): ?string
{
    $s = ecw_license_state();
    if ($reconnecting || $s['mailboxes'] === null || ecw_license_mailboxes_used() < $s['mailboxes']) return null;
    return $s['name'] . ' allows ' . $s['mailboxes'] . ' connected mailboxes. Disconnect one, or upgrade in Settings → License.';
}
