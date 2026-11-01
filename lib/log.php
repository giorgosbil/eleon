<?php declare(strict_types=1);
/**
 * Email Case Workspace — logging.
 *
 * Two hard rules, both from the step 02 security list:
 *   1. Never log a token, a delta link, a clientState, or a password. The
 *      redactor below is a backstop, not permission to pass secrets in.
 *   2. An OAuth or token failure must still produce a log line — the failure
 *      is the thing worth knowing about, so only the secret is dropped.
 *
 * Writes to logs/ecw.log when that is writable and always mirrors to
 * error_log(). The mirror is not belt-and-braces: on this host the repo's
 * logs/ directory is not writable by www-data, so a file-only logger loses
 * every line written during a web request and does so silently.
 */

const ECW_LOG_FILE = __DIR__ . '/../logs/ecw.log';

/** Strip anything that looks like a secret before it reaches a log sink. */
function ecw_redact(string $message): string
{
    $patterns = [
        '/(access_token|refresh_token|id_token|client_secret|clientState|client_state)["\'\s:=]+[A-Za-z0-9._\-]+/i' => '$1=[redacted]',
        '/\bBearer\s+[A-Za-z0-9._\-]+/i'                                                                            => 'Bearer [redacted]',
        '/([?&](?:\$deltatoken|\$skiptoken|code|token)=)[^&\s]+/i'                                                   => '$1[redacted]',
        '/\beyJ[A-Za-z0-9._\-]{20,}/'                                                                               => '[redacted-jwt]',
    ];
    return (string)preg_replace(array_keys($patterns), array_values($patterns), $message);
}

function ecw_log(string $message, array $context = []): void
{
    $line = '[ecw] ' . ecw_redact($message);
    if ($context !== []) {
        $line .= ' ' . ecw_redact((string)json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    error_log($line);

    $dir = dirname(ECW_LOG_FILE);
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents(ECW_LOG_FILE, gmdate('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
