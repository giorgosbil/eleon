<?php declare(strict_types=1);
/**
 * Vendor tool: make the signing key pair and issue license keys.
 * The private key file must stay with the vendor — never in the repository,
 * an image, or a customer's server. Without it this tool cannot issue anything.
 *
 *   php bin/license_tool.php keygen  <private-key-file>
 *       writes the private key file and prints the public key for lib/license.php
 *   php bin/license_tool.php issue   <private-key-file> --customer="Acme Ltd" --package=team
 *                                    [--users=10] [--mailboxes=10] [--expires=2027-10-31] [--id=ACME-001]
 *   php bin/license_tool.php show    <license-key>
 */

require_once dirname(__DIR__) . '/lib/license.php';

$cmd = $argv[1] ?? '';
$opt = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) if (str_starts_with($a, '--' . $name . '=')) return substr($a, strlen($name) + 3);
    return null;
};
$die = static function (string $msg): never { fwrite(STDERR, $msg . PHP_EOL); exit(1); };

if ($cmd === 'keygen') {
    $file = $argv[2] ?? $die('Usage: keygen <private-key-file>');
    if (file_exists($file)) $die('Refusing to overwrite ' . $file . ' — a new key pair would invalidate every license already issued.');
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($file, base64_encode(sodium_crypto_sign_secretkey($pair)) . PHP_EOL);
    @chmod($file, 0600);
    echo 'Private key written to ' . $file . ' — back it up somewhere safe.' . PHP_EOL;
    echo 'Public key (put it in ECW_LICENSE_PUBLIC_KEY): ' . base64_encode(sodium_crypto_sign_publickey($pair)) . PHP_EOL;
    exit(0);
}

if ($cmd === 'issue') {
    $file = $argv[2] ?? $die('Usage: issue <private-key-file> --customer=... --package=pro|business');
    $secret = trim((string)@file_get_contents($file));
    if ($secret === '') $die('Cannot read the private key file.');
    $package = $opt('package') ?? $die('--package is required (pro or business).');
    if (!isset(ecw_packages()[$package]) || $package === 'free') $die('Unknown package ' . $package . ' (pro or business; Free needs no key).');
    $customer = $opt('customer') ?? $die('--customer is required.');
    $expires = $opt('expires');
    if ($expires !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) $die('--expires must be YYYY-MM-DD.');
    $payload = [
        'id' => $opt('id') ?? strtoupper(bin2hex(random_bytes(4))),
        'customer' => $customer,
        'package' => $package,
        'users' => $opt('users') !== null ? (int)$opt('users') : null,
        'mailboxes' => $opt('mailboxes') !== null ? (int)$opt('mailboxes') : null,
        'issued' => gmdate('Y-m-d'),
        'expires' => $expires,
    ];
    $key = ecw_license_sign($payload, $secret);
    // Check it against the public key the app ships with.
    $check = ecw_license_verify($key);
    if (!$check['ok']) fwrite(STDERR, 'Warning: this key does not match the public key in lib/license.php (' . $check['error'] . ')' . PHP_EOL);
    echo $key . PHP_EOL;
    exit(0);
}

if ($cmd === 'show') {
    $r = ecw_license_verify($argv[2] ?? '');
    echo $r['ok'] ? json_encode($r['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL : $r['error'] . PHP_EOL;
    exit($r['ok'] ? 0 : 1);
}

$die("Commands: keygen, issue, show (see the top of this file).");
