<?php declare(strict_types=1);
/** Standalone configuration takes precedence; the hosted app keeps its existing fallback. */
function ecw_config(): array
{
    $path = getenv('ECW_CONFIG_FILE') ?: dirname(__DIR__, 3) . '/config.local.php';
    $config = is_readable($path) ? require $path : [];
    if (getenv('ECW_DB_HOST') !== false) {
        $config['db'] = ['host'=>getenv('ECW_DB_HOST'),'port'=>getenv('ECW_DB_PORT') ?: '3306',
            'name'=>getenv('ECW_DB_NAME') ?: 'email_case_workspace','user'=>getenv('ECW_DB_USER') ?: 'ecw','pass'=>getenv('ECW_DB_PASSWORD') ?: ''];
    }
    $keyFile = getenv('ECW_ENCRYPTION_KEY_FILE');
    if ($keyFile && is_readable($keyFile)) $config['encryption_key'] = trim((string)file_get_contents($keyFile));
    if (getenv('ECW_ENCRYPTION_KEY')) $config['encryption_key'] = getenv('ECW_ENCRYPTION_KEY');
    return $config;
}
function ecw_private_mailboxes(): bool { return getenv('ECW_PRIVATE_MAILBOXES') === '1'; }
function ecw_local_features(): bool { return getenv('ECW_LOCAL_FEATURES') === '1'; }
