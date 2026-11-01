<?php declare(strict_types=1);
require_once __DIR__ . '/provider/GraphHttp.php';

/**
 * Validate the ID token, never interpret the opaque Graph access token.
 * Personal Microsoft accounts (tenant 9188040d-…) are accepted only when
 * $allowPersonal (ECW_GRAPH_PERSONAL_ACCOUNTS=1).
 */
function ecw_graph_identity(string $jwt, string $clientId, ?string $nonce, bool $allowPersonal = false): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Invalid Microsoft identity.');
    }
    $decode = static fn(string $value) => base64_decode(strtr($value, '-_', '+/'), true);
    $header = json_decode((string)$decode($parts[0]), true);
    $claims = json_decode((string)$decode($parts[1]), true);
    $tenant = (string)($claims['tid'] ?? '');
    $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
    $personal = strcasecmp($tenant, '9188040d-6c67-4c5b-b112-36a304b66dad') === 0;
    if (($header['alg'] ?? '') !== 'RS256'
        || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $tenant)
        || ($personal && !$allowPersonal)
        || ($claims['iss'] ?? '') !== $issuer || ($claims['aud'] ?? '') !== $clientId
        || (int)($claims['exp'] ?? 0) <= time() || (int)($claims['nbf'] ?? 0) > time() + 60
        || empty($claims['oid']) || ($nonce !== null && !hash_equals($nonce, (string)($claims['nonce'] ?? '')))) {
        throw new RuntimeException('Invalid Microsoft identity.');
    }
    // "common" lists the signing keys of work, school and personal accounts alike.
    $keys = (new GraphHttp())->request('GET', GraphHttp::LOGIN_BASE . '/' . ($allowPersonal ? 'common' : 'organizations') . '/discovery/v2.0/keys')['body']['keys'] ?? [];
    foreach ($keys as $key) {
        if (($key['kid'] ?? '') !== ($header['kid'] ?? null) || ($key['kty'] ?? '') !== 'RSA'
            || ($key['use'] ?? '') !== 'sig' || empty($key['x5c'][0])) {
            continue;
        }
        $keyIssuer = str_replace('{tenantid}', $tenant, (string)($key['issuer'] ?? ''));
        if ($keyIssuer !== $issuer) { continue; }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
        if (openssl_verify($parts[0] . '.' . $parts[1], (string)$decode($parts[2]), $pem, OPENSSL_ALGO_SHA256) === 1) {
            return $claims;
        }
    }
    throw new RuntimeException('Invalid Microsoft identity signature.');
}

/**
 * Is Graph's /me id the same person as the token's oid? For work accounts they
 * are equal. For personal accounts Graph gives 16 hex digits ("4ad3c0e1b2a39f07")
 * and the token "00000000-0000-0000-4ad3-c0e1b2a39f07".
 */
function ecw_graph_same_account(string $meId, string $oid): bool
{
    if (strcasecmp($meId, $oid) === 0) return true;
    $norm = static fn(string $s): string => ltrim(strtolower(str_replace('-', '', $s)), '0');
    return preg_match('/^[0-9a-f]{16}$/i', $meId) === 1 && $norm($meId) !== '' && $norm($meId) === $norm($oid);
}
