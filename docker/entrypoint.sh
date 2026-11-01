#!/bin/sh
set -eu
mkdir -p /run/ecw /opt/ecw/storage /opt/ecw/storage/sessions /opt/ecw/cache /opt/ecw/logs
chmod 700 /opt/ecw/storage/sessions
# Exclusive creation prevents concurrent container starts from changing the key.
php -r '$p=getenv("ECW_ENCRYPTION_KEY_FILE");if($p&&!file_exists($p)){$f=@fopen($p,"x");if($f){fwrite($f,bin2hex(random_bytes(32)));fclose($f);chmod($p,0640);}}'
chown -R www-data:www-data /run/ecw /opt/ecw/storage /opt/ecw/cache /opt/ecw/logs
# ./backups is a folder on the host; some hosts do not allow changing its owner.
mkdir -p /opt/ecw/backups && { chown www-data:www-data /opt/ecw/backups 2>/dev/null || chmod 0777 /opt/ecw/backups 2>/dev/null || true; }
if [ "${1:-}" = "php" ]; then
    exec gosu www-data docker-php-entrypoint "$@"
fi
exec docker-php-entrypoint "$@"
