<?php declare(strict_types=1);
/**
 * Off-site backups: a copy of every backup somewhere other than this server.
 *
 *   folder  /opt/ecw/offsite, which compose.yaml maps to ECW_OFFSITE_DIR on the
 *           host: a NAS share, a USB disk, or a OneDrive / Google Drive / Dropbox
 *           folder that syncs itself to the cloud
 *   s3      any S3-compatible storage: Amazon S3, Backblaze B2, Wasabi,
 *           Cloudflare R2, Synology C2, MinIO on a NAS (Signature V4)
 *   webdav  Nextcloud, ownCloud, Synology / QNAP WebDAV, Box…
 *
 * The worker copies the newest backup once it exists (ecw_offsite_due) and
 * keeps the last N there. Copies leaving the building must be encrypted:
 * S3 and WebDAV refuse to run without a backup password (Settings → Security).
 * Secrets are stored encrypted with the app key.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/crypto.php';

const ECW_OFFSITE_DIR = '/opt/ecw/offsite';

function ecw_offsite_raw(): array
{
    $s = json_decode((string)(ecw_app_setting('offsite') ?? ''), true);
    return is_array($s) ? $s : [];
}

/** The settings without secrets (has_secret tells whether one is stored). */
function ecw_offsite_settings(): array
{
    $r = ecw_offsite_raw();
    return [
        'type' => in_array($r['type'] ?? 'none', ['none', 'folder', 's3', 'webdav'], true) ? ($r['type'] ?? 'none') : 'none',
        's3_endpoint' => (string)($r['s3_endpoint'] ?? ''), 's3_region' => (string)($r['s3_region'] ?? ''), 's3_bucket' => (string)($r['s3_bucket'] ?? ''),
        's3_prefix' => (string)($r['s3_prefix'] ?? ''), 's3_key' => (string)($r['s3_key'] ?? ''), 'has_s3_secret' => !empty($r['s3_secret']),
        'dav_url' => (string)($r['dav_url'] ?? ''), 'dav_user' => (string)($r['dav_user'] ?? ''), 'has_dav_pass' => !empty($r['dav_pass']),
        'keep' => max(1, min(365, (int)($r['keep'] ?? 30))),
    ];
}

function ecw_offsite_label(array $s): string
{
    return match ($s['type']) {
        'folder' => 'folder',
        's3' => 'S3 · ' . ($s['s3_bucket'] ?: '?'),
        'webdav' => 'WebDAV · ' . (parse_url($s['dav_url'], PHP_URL_HOST) ?: '?'),
        default => 'off',
    };
}

/** @return string|null error */
function ecw_offsite_settings_save(array $in): ?string
{
    $r = ecw_offsite_raw();
    $type = (string)($in['type'] ?? 'none');
    if (!in_array($type, ['none', 'folder', 's3', 'webdav'], true)) return 'Choose where to copy the backups.';
    $r['type'] = $type;
    $r['keep'] = max(1, min(365, (int)($in['keep'] ?? 30)));
    if ($type === 's3') {
        $endpoint = rtrim(trim((string)($in['s3_endpoint'] ?? '')), '/');
        if (!preg_match('#^https?://[^\s/]+(:\d+)?$#i', $endpoint)) return 'The S3 address looks like https://s3.eu-central-1.amazonaws.com (no bucket, no path).';
        if (str_starts_with(strtolower($endpoint), 'http://') && !preg_match('#^http://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|[a-z0-9-]+(:\d+)?$)#i', $endpoint)) return 'Use https:// for storage on the internet.';
        $bucket = trim((string)($in['s3_bucket'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket)) return 'The bucket name: 3–63 lowercase letters, digits, dots and dashes.';
        $r['s3_endpoint'] = $endpoint;
        $r['s3_region'] = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($in['s3_region'] ?? ''))) ?: 'us-east-1';
        $r['s3_bucket'] = $bucket;
        $r['s3_prefix'] = trim(preg_replace('#[^A-Za-z0-9/_\-.]#', '', (string)($in['s3_prefix'] ?? '')) ?? '', '/');
        $r['s3_key'] = trim((string)($in['s3_key'] ?? ''));
        if (trim((string)($in['s3_secret'] ?? '')) !== '') $r['s3_secret'] = ecw_encrypt(trim((string)$in['s3_secret']));
        if ($r['s3_key'] === '' || empty($r['s3_secret'])) return 'Enter the access key and the secret key.';
    } elseif ($type === 'webdav') {
        $url = rtrim(trim((string)($in['dav_url'] ?? '')), '/');
        if (!preg_match('#^https://\S+$#i', $url) && !preg_match('#^http://(localhost|127\.|10\.|192\.168\.|[a-z0-9-]+[:/])#i', $url)) return 'The WebDAV address must start with https:// (http:// only inside your own network).';
        $r['dav_url'] = $url;
        $r['dav_user'] = trim((string)($in['dav_user'] ?? ''));
        if ((string)($in['dav_pass'] ?? '') !== '') $r['dav_pass'] = ecw_encrypt((string)$in['dav_pass']);
    }
    ecw_app_setting_set('offsite', (string)json_encode($r));
    require_once __DIR__ . '/audit.php';
    ecw_audit('backup.offsite', null, null, ['type' => $type, 'where' => ecw_offsite_label(ecw_offsite_settings())]);
    return null;
}

/** @return array{ok_at:?string, file:?string, error:?string, error_at:?string} */
function ecw_offsite_status(): array
{
    $s = json_decode((string)(ecw_app_setting('offsite_status') ?? ''), true) ?: [];
    return ['ok_at' => $s['ok_at'] ?? null, 'file' => $s['file'] ?? null, 'error' => $s['error'] ?? null, 'error_at' => $s['error_at'] ?? null];
}

function ecw_offsite_status_set(?string $file, ?string $error): void
{
    $s = ecw_offsite_status();
    if ($error === null) { $s['ok_at'] = gmdate('Y-m-d H:i:s'); $s['file'] = $file; $s['error'] = null; }
    else { $s['error'] = mb_substr($error, 0, 300); $s['error_at'] = gmdate('Y-m-d H:i:s'); }
    ecw_app_setting_set('offsite_status', (string)json_encode($s));
}

// ------------------------------------------------------------------ transport

/** Copy $localPath there as $name. Throws with a readable reason. */
function ecw_offsite_put(string $localPath, string $name): void
{
    $s = ecw_offsite_raw();
    $type = $s['type'] ?? 'none';
    if (!preg_match('/^[A-Za-z0-9._\-]+$/', $name)) throw new InvalidArgumentException('Bad file name.');
    if ($type === 'folder') {
        if (!is_dir(ECW_OFFSITE_DIR) || !is_writable(ECW_OFFSITE_DIR)) throw new RuntimeException('The off-site folder is not there or not writable. Set ECW_OFFSITE_DIR in .env to your NAS / OneDrive folder and restart.');
        $tmp = ECW_OFFSITE_DIR . '/.' . $name . '.part';
        if (!@copy($localPath, $tmp) || !@rename($tmp, ECW_OFFSITE_DIR . '/' . $name)) { @unlink($tmp); throw new RuntimeException('Copying to the off-site folder failed (full?).'); }
        return;
    }
    if ($type === 's3') { ecw_s3_request($s, 'PUT', $name, $localPath); return; }
    if ($type === 'webdav') { ecw_dav_request($s, 'PUT', $name, $localPath); return; }
    throw new RuntimeException('No off-site copy is set up.');
}

function ecw_offsite_delete(string $name): void
{
    $s = ecw_offsite_raw();
    if (!preg_match('/^[A-Za-z0-9._\-]+$/', $name)) return;
    match ($s['type'] ?? 'none') {
        'folder' => @unlink(ECW_OFFSITE_DIR . '/' . $name),
        's3' => ecw_s3_request($s, 'DELETE', $name, null),
        'webdav' => ecw_dav_request($s, 'DELETE', $name, null),
        default => null,
    };
}

/** Put and remove a small file: does it work? @return string|null error */
function ecw_offsite_test(): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ecwoff');
    file_put_contents($tmp, 'Email Case Workspace off-site test ' . gmdate('c'));
    try {
        ecw_offsite_put($tmp, 'ecw-offsite-test.txt');
        ecw_offsite_delete('ecw-offsite-test.txt');
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    } finally { @unlink($tmp); }
}

/** S3 (Signature V4). Path style for custom endpoints, virtual-hosted for amazonaws.com. */
function ecw_s3_request(array $s, string $method, string $name, ?string $file): void
{
    $secret = ecw_decrypt($s['s3_secret'] ?? null);
    if ($secret === null || $secret === '') throw new RuntimeException('The S3 secret key is missing.');
    $u = parse_url((string)$s['s3_endpoint']);
    $scheme = $u['scheme'] ?? 'https'; $host = (string)($u['host'] ?? ''); $port = isset($u['port']) ? ':' . $u['port'] : '';
    $key = ($s['s3_prefix'] !== '' ? $s['s3_prefix'] . '/' : '') . $name;
    $enc = static fn(string $p): string => implode('/', array_map('rawurlencode', explode('/', $p)));
    if (str_ends_with($host, 'amazonaws.com')) { $host = $s['s3_bucket'] . '.' . $host; $path = '/' . $enc($key); }
    else { $path = '/' . rawurlencode((string)$s['s3_bucket']) . '/' . $enc($key); }
    $payloadHash = $file !== null ? (string)hash_file('sha256', $file) : hash('sha256', '');
    $amzDate = gmdate('Ymd\THis\Z'); $date = substr($amzDate, 0, 8);
    $region = (string)($s['s3_region'] ?: 'us-east-1');
    $hostHeader = $host . $port;
    $canonical = $method . "\n" . $path . "\n\nhost:" . $hostHeader . "\nx-amz-content-sha256:" . $payloadHash . "\nx-amz-date:" . $amzDate . "\n\nhost;x-amz-content-sha256;x-amz-date\n" . $payloadHash;
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $toSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);
    $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4' . $secret, true), true), true), true);
    $auth = 'AWS4-HMAC-SHA256 Credential=' . $s['s3_key'] . '/' . $scope . ', SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=' . hash_hmac('sha256', $toSign, $k);
    ecw_offsite_http($method, $scheme . '://' . $hostHeader . $path, ['Authorization: ' . $auth, 'x-amz-date: ' . $amzDate, 'x-amz-content-sha256: ' . $payloadHash], $file, null);
}

function ecw_dav_request(array $s, string $method, string $name, ?string $file): void
{
    $pass = ecw_decrypt($s['dav_pass'] ?? null) ?? '';
    $user = (string)($s['dav_user'] ?? '');
    $url = rtrim((string)$s['dav_url'], '/') . '/' . rawurlencode($name);
    ecw_offsite_http($method, $url, [], $file, $user !== '' ? $user . ':' . $pass : null);
}

/** One HTTP call with curl, streaming a file for PUT. Throws on a non-2xx answer. */
function ecw_offsite_http(string $method, string $url, array $headers, ?string $file, ?string $basicAuth): void
{
    if (isset($GLOBALS['ecw_offsite_transport']) && defined('ECW_TESTING')) { ($GLOBALS['ecw_offsite_transport'])($method, $url, $headers, $file); return; }
    $ch = curl_init($url);
    $fh = null;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 3600,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($basicAuth !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    if ($file !== null) {
        $fh = fopen($file, 'rb');
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => (int)filesize($file)]);
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($body === false) throw new RuntimeException('Could not reach the storage: ' . $err);
    if ($method === 'DELETE' && $status === 404) return;
    if ($status < 200 || $status >= 300) {
        $why = preg_match('#<Code>([^<]+)</Code>#', (string)$body, $m) ? $m[1] : 'HTTP ' . $status;
        $hint = match (true) { $status === 401, $status === 403 => ' (check the keys / user name and that it may write there)', $status === 404 => ' (check the bucket or folder address)', default => '' };
        throw new RuntimeException('The storage refused the file: ' . $why . $hint . '.');
    }
}

// ------------------------------------------------------------------- schedule

/**
 * Called by the worker: copy the newest backup off-site once, then keep the
 * last N there. @return bool something was copied
 */
function ecw_offsite_due(): bool
{
    $s = ecw_offsite_settings();
    if ($s['type'] === 'none') return false;
    require_once __DIR__ . '/backup.php';
    require_once __DIR__ . '/security.php';
    $list = ecw_backup_list();
    if (!$list) return false;
    $newest = $list[0];
    $sent = json_decode((string)(ecw_app_setting('offsite_files') ?? ''), true) ?: [];
    if (in_array($newest['name'], $sent, true)) return false;
    $st = ecw_offsite_status();
    // After a failure, try again every 30 minutes, not in a loop.
    if ($st['error'] !== null && ($st['error_at'] ?? '') >= ($st['ok_at'] ?? '') && strtotime(($st['error_at'] ?? '1970-01-01') . ' UTC') > time() - 1800) return false;
    if ($s['type'] !== 'folder' && ecw_backup_password() === null) {
        ecw_offsite_status_set(null, 'Set a backup password first (Settings → Security): backups leave the building only encrypted.');
        return false;
    }
    try {
        ecw_offsite_put(ecw_backup_dir() . '/' . $newest['name'], $newest['name']);
    } catch (Throwable $e) {
        ecw_offsite_status_set(null, $e->getMessage());
        ecw_log('off-site copy failed: ' . $e->getMessage());
        return false;
    }
    $sent[] = $newest['name'];
    // Keep the newest N copies there (only files this app put there are ever removed).
    while (count($sent) > $s['keep']) {
        $old = array_shift($sent);
        try { ecw_offsite_delete((string)$old); } catch (Throwable $e) { ecw_log('off-site prune failed: ' . $e->getMessage()); }
    }
    ecw_app_setting_set('offsite_files', (string)json_encode(array_values($sent)));
    ecw_offsite_status_set($newest['name'], null);
    ecw_log('off-site copy done: ' . $newest['name']);
    return true;
}
