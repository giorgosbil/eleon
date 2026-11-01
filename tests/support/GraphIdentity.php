<?php declare(strict_types=1);
/** Ephemeral signing material only; never a production key or credential. */
function ecw_test_identity(array $claims = []): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'ECW test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($cert, $pem);
    $tenant = '11111111-2222-3333-4444-555555555555';
    $claims += ['tid' => $tenant, 'oid' => 'oid-fresh', 'aud' => 'test-client',
        'iss' => 'https://login.microsoftonline.com/' . $tenant . '/v2.0',
        'exp' => time() + 3600, 'nbf' => time() - 10];
    $enc = static fn($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $input = $enc(json_encode(['alg' => 'RS256', 'kid' => 'test-key'])) . '.' . $enc(json_encode($claims));
    openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
    return ['jwt' => $input . '.' . $enc($sig), 'keys' => [[
        'kid' => 'test-key', 'kty' => 'RSA', 'use' => 'sig',
        'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
        'x5c' => [preg_replace('/-----[^-]+-----|\s/', '', $pem)],
    ]]];
}
