#!/bin/bash
# Every PHP test against a brand-new, empty database, then everything is thrown away.
# Run on the PC (Git Bash), from the project folder, with the app's image built:
#   bash tests/run_fresh.sh           with dummy Microsoft settings, as an installation has
#   bash tests/run_fresh.sh --bare    with none at all: each test must bring its own
# Nothing touches the real database, storage or .env: a throwaway MySQL container on the
# app's network, the code of this folder mounted read-only, storage in memory, dummy
# Microsoft settings. A test that needs data must make its own (an empty database is
# what a new installation has).
set -u
cd "$(dirname "$0")/.." || exit 1
root=$(pwd -W 2>/dev/null || pwd)
id=$(date +%s)$RANDOM
db="ecw-testdb-$id"
net="email-case-workspace_default"
image="email-case-workspace:local"
pass="throwaway-test-only"
export MSYS_NO_PATHCONV=1

cleanup() { docker rm -f "$db" >/dev/null 2>&1; }
trap cleanup EXIT

echo "== Starting an empty test database"
docker run -d --name "$db" --network "$net" -e MYSQL_ROOT_PASSWORD="$pass" -e MYSQL_DATABASE=ecw_test \
  --tmpfs /var/lib/mysql mysql:8.4 --disable-log-bin --innodb-buffer-pool-size=256M >/dev/null || exit 1
for i in $(seq 1 90); do
  docker exec "$db" mysql -uroot -p"$pass" -e 'SELECT 1' ecw_test >/dev/null 2>&1 && break
  sleep 1
done

graph=(-e ECW_GRAPH_CLIENT_ID=00000000-0000-0000-0000-000000000001 -e ECW_GRAPH_CLIENT_SECRET=fake-test-secret
  -e ECW_GRAPH_REDIRECT_URI=http://localhost/other_things/web/oauth/callback.php)
[ "${1:-}" = "--bare" ] && graph=()

mounts=()
for d in lib web modules tests database bin; do mounts+=(-v "$root/$d:/opt/ecw/$d:ro"); done
tmpfs=()
for d in storage cache logs backups; do tmpfs+=(--tmpfs "/opt/ecw/$d:uid=33,gid=33"); done

echo "== Installing (all migrations) and running every test"
docker run --rm --network "$net" "${mounts[@]}" "${tmpfs[@]}" --tmpfs /run/ecw \
  -e ECW_DB_HOST="$db" -e ECW_DB_NAME=ecw_test -e ECW_DB_USER=root -e ECW_DB_PASSWORD="$pass" \
  -e ECW_ENCRYPTION_KEY_FILE=/run/ecw/encryption-key -e ECW_LOCAL_FEATURES=1 \
  -e ECW_GRAPH_CLIENT_ID=00000000-0000-0000-0000-000000000001 -e ECW_GRAPH_CLIENT_SECRET=fake-test-secret \
  -e ECW_GRAPH_REDIRECT_URI=http://localhost/other_things/web/oauth/callback.php -e ECW_BASE_URL=http://localhost:8080 \
  -w /opt/ecw "$image" sh -c 'php bin/migrate.php >/tmp/migrate.log 2>&1 || { cat /tmp/migrate.log; exit 1; }; sh tests/run_all.sh'
