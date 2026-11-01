<?php declare(strict_types=1);
/**
 * Validate a Google ID token's claims.
 *
 * The token is received directly from Google's token endpoint over TLS in the
 * same authenticated (client secret + PKCE) request that redeemed the code, so
 * per OpenID Connect Core 3.1.3.7 the TLS server validation stands in for the
 * signature check. The claims are still verified: issuer, audience, expiry,
 * nonce (binds the token to this browser's handshake), subject, and a
 * verified email address.
 */
function ecw_google_identity(string $jwt, string $clientId, ?string $nonce): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Invalid Google identity.');
    }
    $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/'), true), true);
    if (!is_array($claims)
        || !in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
        || ($claims['aud'] ?? '') !== $clientId
        || (int)($claims['exp'] ?? 0) <= time()
        || empty($claims['sub'])
        || ($nonce !== null && !hash_equals($nonce, (string)($claims['nonce'] ?? '')))
        || empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
        throw new RuntimeException('Invalid Google identity.');
    }
    return $claims;
}
