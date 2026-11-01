#!/bin/sh
# Make a backup now. The file appears in the "backups" folder next to this project.
# Usage (in the project folder):  sh scripts/backup.sh
cd "$(dirname "$0")/.." || exit 1
docker compose exec -T -u www-data web php bin/backup.php create && docker compose exec -T -u www-data web php bin/backup.php list
