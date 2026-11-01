#!/bin/sh
# Restore a backup. This REPLACES all current data (a safety backup of it is made first).
# Usage (in the project folder):
#   sh scripts/restore.sh                     -> lists the backups
#   sh scripts/restore.sh ecw-backup-20261004-020000-auto.zip
cd "$(dirname "$0")/.." || exit 1
if [ -z "$1" ]; then
  docker compose exec -T -u www-data web php bin/backup.php list
  echo; echo "Run again with the file name, for example: sh scripts/restore.sh <name>.zip"
  exit 0
fi
printf 'Replace ALL current data with %s ? Type YES to continue: ' "$1"
read -r answer
[ "$answer" = "YES" ] || { echo "Cancelled."; exit 1; }
docker compose stop sync
docker compose exec -T -u www-data web php bin/backup.php restore "$1" --yes
code=$?
docker compose restart web sync
exit $code
