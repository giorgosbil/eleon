#!/bin/sh
# Schedule once per minute. The lock prevents overlapping scheduled runs.
set -eu
ECW_SYNC_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
exec 9>"$ECW_SYNC_ROOT/logs/sync-scheduler.lock"
/usr/bin/flock -n 9 || exit 0
cd "$ECW_SYNC_ROOT"
/usr/bin/php bin/sync_cron.php
/usr/bin/php bin/sync_worker.php --max-jobs=25
