<?php declare(strict_types=1);
/**
 * Versions and updates.
 *
 * The running version is the VERSION file at the project root. A release is a
 * .zip of the project plus a signed release token (made by
 * tools/release/make-release.php with the vendor key, the same Ed25519 key as
 * licenses): {type: release, version, sha256, size, date, notes}. The update
 * scripts (scripts/update.ps1 / .sh) refuse a zip whose SHA-256 or signature
 * does not match, back up first, keep a way back, and roll back by themselves
 * when the new version does not start.
 *
 * Update channel: ECW_UPDATE_URL (or Settings → System health), a JSON file
 * {"version", "notes", "zip", "token"} the vendor publishes. Checked once a day
 * by the worker; nothing is installed without someone running the script.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/license.php';

function ecw_app_version(): string
{
    $v = @file_get_contents(dirname(__DIR__) . '/VERSION');
    return $v !== false && preg_match('/^\d+\.\d+\.\d+/', trim($v)) ? trim($v) : '0.0.0';
}

/** Sign a release (vendor side). @return string the release token */
function ecw_release_sign(string $version, string $sha256, int $size, string $notes, string $secretKeyB64): string
{
    return ecw_license_sign(['type' => 'release', 'version' => $version, 'sha256' => strtolower($sha256), 'size' => $size,
        'date' => gmdate('Y-m-d'), 'notes' => mb_substr($notes, 0, 2000)], $secretKeyB64);
}

/**
 * Check a release token (and, with $zipPath, the zip it describes).
 * @return array{ok:bool, release:?array, error:?string}
 */
function ecw_release_verify(string $token, ?string $zipPath = null, ?string $publicKeyB64 = null): array
{
    $token = preg_replace('/\s+/', '', $token) ?? '';
    $parts = explode('.', $token);
    if (count($parts) !== 3 || $parts[0] !== 'ECW1') return ['ok' => false, 'release' => null, 'error' => 'Not a release signature.'];
    if ($publicKeyB64 === null && defined('ECW_TESTING') && isset($GLOBALS['ecw_release_test_public_key'])) $publicKeyB64 = $GLOBALS['ecw_release_test_public_key'];
    $public = base64_decode($publicKeyB64 ?? ECW_LICENSE_PUBLIC_KEY, true);
    $sig = ecw_b64url_decode($parts[2]);
    if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached($sig, 'ECW1.' . $parts[1], $public)) {
        return ['ok' => false, 'release' => null, 'error' => 'The release signature is not valid: this zip was not published by the vendor, or was changed.'];
    }
    $r = json_decode((string)ecw_b64url_decode($parts[1]), true);
    if (!is_array($r) || ($r['type'] ?? '') !== 'release' || !preg_match('/^\d+\.\d+\.\d+$/', (string)($r['version'] ?? '')) || !preg_match('/^[0-9a-f]{64}$/', (string)($r['sha256'] ?? ''))) {
        return ['ok' => false, 'release' => null, 'error' => 'The release signature does not describe a release.'];
    }
    if ($zipPath !== null) {
        if (!is_file($zipPath)) return ['ok' => false, 'release' => $r, 'error' => 'The release zip is missing.'];
        if (!hash_equals($r['sha256'], (string)hash_file('sha256', $zipPath))) return ['ok' => false, 'release' => $r, 'error' => 'The zip does not match its signature (damaged or changed download).'];
    }
    return ['ok' => true, 'release' => $r, 'error' => null];
}

/** Is $a newer than $b? */
function ecw_version_newer(string $a, string $b): bool
{
    return version_compare($a, $b, '>');
}

function ecw_update_url(): string
{
    $env = (string)getenv('ECW_UPDATE_URL');
    if ($env !== '') return $env;
    return (string)(ecw_app_setting('update_url') ?? '');
}

/**
 * Ask the update channel what the newest release is (at most once a day unless $force).
 * @return array{checked_at:?string, current:string, latest:?string, notes:?string, available:bool, error:?string}
 */
function ecw_update_check(bool $force = false): array
{
    $current = ecw_app_version();
    $saved = json_decode((string)(ecw_app_setting('update_state') ?? ''), true) ?: [];
    $base = ['checked_at' => $saved['checked_at'] ?? null, 'current' => $current, 'latest' => $saved['latest'] ?? null, 'notes' => $saved['notes'] ?? null, 'error' => $saved['error'] ?? null];
    $url = ecw_update_url();
    if ($url === '') return $base + ['available' => false, 'error' => null, 'no_channel' => true];
    if (!$force && isset($saved['checked_at']) && strtotime($saved['checked_at'] . ' UTC') > time() - 86400) {
        return $base + ['available' => $base['latest'] !== null && ecw_version_newer($base['latest'], $current)];
    }
    $state = ['checked_at' => gmdate('Y-m-d H:i:s'), 'latest' => null, 'notes' => null, 'error' => null];
    try {
        if (!preg_match('#^https://#i', $url) && !(defined('ECW_TESTING') && str_starts_with($url, 'file://'))) throw new RuntimeException('The update channel must be an https:// address.');
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: EmailCaseWorkspace/{$current}\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx, 0, 200000);
        if ($raw === false) throw new RuntimeException('The update channel did not answer.');
        $feed = json_decode($raw, true);
        if (!is_array($feed) || empty($feed['token'])) throw new RuntimeException('The update channel answered with something unexpected.');
        $v = ecw_release_verify((string)$feed['token']);
        if (!$v['ok']) throw new RuntimeException($v['error']);
        $state['latest'] = (string)$v['release']['version'];
        $state['notes'] = (string)($v['release']['notes'] ?? '');
    } catch (Throwable $e) {
        $state['error'] = $e->getMessage();
        $state['latest'] = $saved['latest'] ?? null;
        $state['notes'] = $saved['notes'] ?? null;
    }
    ecw_app_setting_set('update_state', (string)json_encode($state));
    return ['current' => $current] + $state + ['available' => $state['latest'] !== null && ecw_version_newer($state['latest'], $current)];
}
