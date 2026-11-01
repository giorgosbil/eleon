<?php declare(strict_types=1);
/**
 * Email Case Workspace — encryption for data at rest.
 *
 * Only ever used for provider secrets: OAuth access/refresh tokens and the
 * delta/sync cursors, which embed mailbox-scoped tokens of their own.
 *
 * AES-256-GCM, because these values must be tamper-evident as well as secret:
 * a truncated or flipped ciphertext fails the auth tag instead of decrypting
 * to garbage that then gets sent to Graph as a bearer token. The key is the
 * 64 hex characters already in config.local.php under `encryption_key`.
 *
 * Format: v1:base64(iv[12] || tag[16] || ciphertext). The version prefix means
 * a future key rotation or cipher change can be detected rather than guessed.
 */

require_once __DIR__ . '/config.php';

/** @return string 32 raw bytes */
function ecw_encryption_key(): string
{
    if (PHP_SAPI === 'cli' && defined('ECW_TESTING') && isset($GLOBALS['ecw_test_encryption_key'])) {
        return $GLOBALS['ecw_test_encryption_key'];
    }
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $config = ecw_config();
    $hex = (string)($config['encryption_key'] ?? '');
    if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
        throw new RuntimeException('ecw: config.local.php encryption_key must be 64 hex characters');
    }
    $key = (string)hex2bin($hex);
    return $key;
}

function ecw_encrypt(string $plaintext): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', ecw_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('ecw: encryption failed');
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

/** Returns null for an empty input; throws if the value has been tampered with. */
function ecw_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    if (!str_starts_with($stored, 'v1:')) {
        throw new RuntimeException('ecw: unknown ciphertext version');
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('ecw: malformed ciphertext');
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', ecw_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) {
        throw new RuntimeException('ecw: decryption failed (wrong key or tampered ciphertext)');
    }
    return $pt;
}

/**
 * Constant-time comparison of a received webhook clientState against the
 * stored sha256. Used instead of storing the secret itself.
 */
function ecw_client_state_hash(string $clientState): string
{
    return hash('sha256', $clientState);
}

function ecw_client_state_matches(string $received, string $storedHash): bool
{
    return hash_equals($storedHash, ecw_client_state_hash($received));
}

/** A fresh clientState secret for a new subscription. */
function ecw_new_client_state(): string
{
    return bin2hex(random_bytes(32));
}
