#!/bin/sh
# Go back to the version that ran before the last update (see scripts/rollback.ps1).
set -e
cd "$(dirname "$0")/.."
RB=$(ls -dt updates/rollback-* 2>/dev/null | head -n 1)
[ -n "$RB" ] || { echo "There is no earlier version kept (updates/rollback-*)." >&2; exit 1; }
echo "Going back to ${RB#updates/rollback-}..."
(cd "$RB" && tar -cf - .) | tar -xf -
docker compose up --build -d --wait --wait-timeout 600
docker compose exec -T web php bin/health.php --startup
