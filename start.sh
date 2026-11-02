#!/bin/sh
set -eu
cd "$(dirname "$0")"
docker info >/dev/null
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" -w /work php:8.4-cli php docker/configure.php
docker compose up --build -d --wait --wait-timeout 240
# The code was printed before the long build too; repeat it here, where people look.
code=$(sed -n 's/^ECW_SETUP_TOKEN=//p' .env | tr -d "\"' \r")
port=$(sed -n 's/^ECW_HTTP_PORT=//p' .env | tr -d "\"' \r")
printf '\nReady.\nFirst-admin setup code: %s\n' "$code"
# On the clipboard too, where there is one (macOS, or a Linux desktop): paste it on the setup page.
copied=''
for c in pbcopy wl-copy 'xclip -selection clipboard' 'xsel --clipboard --input'; do
  if command -v "${c%% *}" >/dev/null 2>&1 && printf %s "$code" | $c >/dev/null 2>&1; then copied=1; break; fi
done
[ -n "$copied" ] && printf 'The code is copied: paste it on the setup page.\n'
printf 'Open http://localhost:%s/other_things/web/setup.php and paste the code.\n' "${port:-8080}"
