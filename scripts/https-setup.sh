#!/bin/sh
# Turn on HTTPS for the office network (optional).
# Usage (in the project folder):
#   sh scripts/https-setup.sh                                  -> localhost + this machine's addresses
#   sh scripts/https-setup.sh "localhost, cases.office.lan, 192.168.2.10"
# Writes ECW_HTTPS_HOSTS / ECW_PROXY_SECRET to .env, starts the HTTPS proxy on
# port 8443 and saves its root certificate as caddy-root.crt — install that file
# on every computer and phone that should trust the address.
set -e
cd "$(dirname "$0")/.."
HOSTS="$1"
if [ -z "$HOSTS" ]; then
  IPS=$( (hostname -I 2>/dev/null || ipconfig getifaddr en0 2>/dev/null) | tr ' ' '\n' | grep -E '^[0-9]+\.' | grep -v '^172\.1[7-9]\.' | paste -sd, - | sed 's/,/, /g')
  HOSTS="localhost${IPS:+, $IPS}"
fi
set_env() {
  if grep -q "^$1=" .env; then
    tmp=$(mktemp); awk -v k="$1" -v v="$2" 'BEGIN{FS=OFS="="} $1==k{print k"="v; next} {print}' .env > "$tmp" && cat "$tmp" > .env && rm -f "$tmp"
  else
    printf '\n%s=%s\n' "$1" "$2" >> .env
  fi
}
set_env ECW_HTTPS_HOSTS "\"$HOSTS\""
grep -q '^ECW_PROXY_SECRET=.' .env || set_env ECW_PROXY_SECRET "$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
echo "HTTPS names: $HOSTS"
docker compose --profile https up -d
docker compose up -d web sync
i=0
until docker compose cp https:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt 2>/dev/null || [ $i -ge 15 ]; do i=$((i+1)); sleep 2; done
[ -f caddy-root.crt ] && echo "Saved caddy-root.crt — install it as a trusted root certificate on each device." || echo "Open https://localhost:8443 once, then run this script again."
echo "$HOSTS" | tr ',' '\n' | while read -r h; do [ -n "$h" ] && echo "  https://$h:8443/other_things/web/"; done
