#!/bin/sh
# Update Email Case Workspace, safely. In the app folder:
#   sh scripts/update.sh                     the newest version from the update channel
#   sh scripts/update.sh ecw-1.2.0.zip       a release you downloaded (its .json next to it)
# Same steps as scripts/update.ps1: verify signature, back up, keep a way back,
# install, check it works, roll back by itself if not.
set -e
cd "$(dirname "$0")/.."
ROOT=$(pwd)
DIR="$ROOT/updates"
mkdir -p "$DIR"
ZIP="$1"
IMG=$(docker compose config --images | grep -v -e mysql -e caddy | head -n 1)
[ -n "$IMG" ] || IMG=email-case-workspace:local
RBIMG="${IMG%:*}:rollback"
ALLOW=""
[ "$2" = "--allow-same" ] && ALLOW="--allow-same"

if [ -z "$ZIP" ]; then
  echo "== Asking the update channel for the newest version"
  URL=$(docker compose exec -T web php -r "require 'lib/updates.php'; echo ecw_update_url();")
  [ -n "$URL" ] || { echo "No update channel is set (Settings -> System health, or ECW_UPDATE_URL)." >&2; exit 1; }
  curl -fsSL "$URL" -o "$DIR/latest.json"
  ZIPREF=$(docker compose run --rm --no-deps -T -v "$DIR:/opt/ecw/updates:ro" web php -r 'echo json_decode(file_get_contents("/opt/ecw/updates/latest.json"), true)["zip"] ?? "";')
  case "$ZIPREF" in https://*) ZIPURL="$ZIPREF" ;; *) ZIPURL="${URL%/*}/$ZIPREF" ;; esac
  ZIP="$DIR/$(basename "$ZIPURL")"
  curl -fSL "$ZIPURL" -o "$ZIP"
  mv "$DIR/latest.json" "${ZIP%.zip}.json"
fi
JSON="${ZIP%.zip}.json"
[ -f "$ZIP" ] && [ -f "$JSON" ] || { echo "The zip and its .json must both be there." >&2; exit 1; }
case "$ZIP" in "$DIR"/*) ;; *) cp "$ZIP" "$JSON" "$DIR/" ;; esac
ZN=$(basename "$ZIP"); JN=$(basename "$JSON")

echo "== Checking the signature"
NEW=$(docker compose run --rm --no-deps -T -v "$DIR:/opt/ecw/updates:ro" web php bin/update_verify.php "/opt/ecw/updates/$ZN" "/opt/ecw/updates/$JN" $ALLOW | tail -n 1)
case "$NEW" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "The release did not pass the check (see above). Nothing was changed." >&2; exit 1 ;; esac
CURRENT=$(cat VERSION | tr -d '[:space:]')
echo "Signed release $NEW (running $CURRENT)."

echo "== Backing up"
docker compose exec -T -u www-data web php bin/backup.php create

echo "== Keeping $CURRENT as the way back"
RB="$DIR/rollback-$CURRENT"
rm -rf "$RB"; mkdir -p "$RB"
tar --exclude=./updates --exclude=./backups --exclude=./backups-offsite --exclude=./storage --exclude=./cache --exclude=./logs \
    --exclude=./node_modules --exclude=./.git --exclude=./.env -cf - . | (cd "$RB" && tar -xf -)
docker tag "$IMG" "$RBIMG"

echo "== Installing $NEW"
docker compose run --rm --no-deps -T -v "$DIR:/opt/ecw/updates:ro" web php -r '$z = new ZipArchive(); $z->open($argv[1]); for ($i = 0; $i < $z->numFiles; $i++) echo $z->getNameIndex($i), "\n";' "/opt/ecw/updates/$ZN" > "$DIR/.new-files"
unzip -oq "$ZIP" -d "$ROOT"
OK=1
docker compose up --build -d --wait --wait-timeout 600 || OK=0
if [ $OK = 1 ]; then docker compose exec -T web php bin/health.php --startup || OK=0; fi

if [ $OK = 1 ]; then
  ls -dt "$DIR"/rollback-* 2>/dev/null | tail -n +3 | xargs rm -rf
  echo; echo "Updated to $NEW. If something is wrong: sh scripts/rollback.sh"
  exit 0
fi

echo "Version $NEW did not start properly. Putting $CURRENT back..." >&2
while IFS= read -r f; do
  case "$f" in */|.env*) continue ;; esac
  [ -e "$RB/$f" ] || rm -f "$ROOT/$f"
done < "$DIR/.new-files"
(cd "$RB" && tar -cf - .) | (cd "$ROOT" && tar -xf -)
docker tag "$RBIMG" "$IMG"
docker compose up -d --wait --wait-timeout 600 || docker compose up --build -d --wait --wait-timeout 600
echo "Back on $CURRENT. If anything misbehaves, restore the backup made just before: sh scripts/restore.sh <name>"
exit 1
