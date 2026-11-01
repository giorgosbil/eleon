#!/bin/sh
# Encrypt the database on disk (encryption at rest). Run once, in the project folder:
#   sh scripts/encrypt-at-rest.sh           turn it on
#   sh scripts/encrypt-at-rest.sh --status  show what is encrypted
# See scripts/encrypt-at-rest.ps1 for what it does. KEEP the "db_keyring" volume.
set -e
cd "$(dirname "$0")/.."
F="-f compose.yaml -f compose.encryption.yaml"
if [ "$1" = "--status" ]; then exec docker compose $F exec -T web php bin/encrypt_tables.php --status; fi
echo "1/4 Backup first..."
docker compose exec -T -u www-data web php bin/backup.php create
echo "2/4 Restarting the database with the keyring..."
docker compose $F up -d --wait --wait-timeout 300
echo "3/4 Encrypting the tables (big mail tables take a while)..."
docker compose $F exec -T web php bin/encrypt_tables.php
echo "4/4 Remembering it in .env (COMPOSE_FILE)..."
grep -q '^COMPOSE_FILE=' .env 2>/dev/null || printf '\nCOMPOSE_FILE=compose.yaml:compose.encryption.yaml\n' >> .env
echo
echo 'Done. The database is encrypted on disk. Back up the Docker volume "db_keyring" somewhere safe (it holds the key).'