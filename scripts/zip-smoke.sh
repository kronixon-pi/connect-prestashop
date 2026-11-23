#!/usr/bin/env bash
# Builds the release zip of HEAD and installs it on a PrestaShop that has nothing else of this repository, so a file
# missing from the zip fails here (module-ci; scripts/ps-smoke.sh mounts the whole work tree).
#
#   scripts/zip-smoke.sh [91]
#
# The zip is built by scripts/release/build.php with a public key made of fixed bytes: nothing is signed here. Needs PHP
# and git on the host, or KX_PHP="docker run …" (a command that runs php on this checkout).
set -euo pipefail

ps="${1:-91}"
root="$(cd "$(dirname "$0")/.." && pwd)"
file="$root/docker/compose.$ps.yml"
[ -f "$file" ] || { echo "no such compose file: $file" >&2; exit 2; }
port="${PS_PORT:-$(sed -n 's/.*PS_PORT:-\([0-9]*\)}.*/\1/p' "$file" | head -n 1)}"
base="http://localhost:$port"
out="${KX_OUT:-$(mktemp -d)}"
t0=$(date +%s)
failures=0

dc() { "$root/scripts/compose.sh" "$ps" -p "kx-zip$ps" -f "$root/docker/compose.zip.yml" "$@"; }
step() { echo "[$(( $(date +%s) - t0 ))s] $*"; }
sql() { dc exec -T db mariadb -uroot -pprestashop prestashop -N -B -e "$1" </dev/null; }
console() { dc exec -T -u www-data ps php bin/console "$@" </dev/null; }
expect_eq() { # name expected actual
    if [ "$2" = "$3" ]; then echo "  ok   $1 = $3"; else echo "  FAIL $1: expected '$2', got '$3'"; failures=$((failures + 1)); fi
}
cleanup() {
    local rc=$?
    if [ "$rc" -ne 0 ]; then dc logs --tail 60 ps 2>&1 || true; fi
    dc down -v --remove-orphans >/dev/null 2>&1 || true
    if [ -z "${KX_OUT:-}" ]; then rm -rf "$out"; fi
    exit "$rc"
}
trap cleanup EXIT

version="$(sed -n "s/^    public const MODULE_VERSION = '\(.*\)';$/\1/p" "$root/src/Protocol.php")"
key="kx-smoke-1:$(printf '%032d' 0 | base64)"
read -r -a php <<<"${KX_PHP:-php}"
"${php[@]}" "$root/scripts/release/build.php" "$version" staging "$key" "$out/k.zip"

dc up -d --quiet-pull
deadline=$(( $(date +%s) + 600 ))
while :; do
    logs="$(dc logs ps 2>&1 || true)"
    if [[ "$logs" == *"installation failed"* ]]; then echo "PrestaShop auto-install failed" >&2; exit 1; fi
    if [[ "$logs" == *"Starting web server now"* ]]; then break; fi
    if [ "$(date +%s)" -gt "$deadline" ]; then echo "auto-install did not finish in 600 s" >&2; exit 1; fi
    if [ -z "$(dc ps -q --status running ps)" ]; then echo "the shop container stopped" >&2; exit 1; fi
    sleep 3
done
step "PrestaShop installed"

# Unpacked as the back office does (a zip upload is extracted into modules/), by the shop's own PHP.
expect_eq "the module folder before the zip" "absent" "$(dc exec -T ps sh -c '[ -e modules/kronixonconnect ] && echo present || echo absent' </dev/null)"
dc cp "$out/k.zip" ps:/tmp/k.zip
dc exec -T ps chmod 0644 /tmp/k.zip </dev/null
# shellcheck disable=SC2016  # PHP variables
dc exec -T -u www-data ps php -r '$z = new ZipArchive; exit($z->open("/tmp/k.zip") === true && $z->extractTo("modules") ? 0 : 1);' </dev/null
console prestashop:module install kronixonconnect
expect_eq "module row (name active version)" "kronixonconnect	1	$version" "$(sql "SELECT name, active, version FROM ps_module WHERE name = 'kronixonconnect'")"
expect_eq "kx_ tables" "8" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop' AND table_name LIKE 'ps\_kx\_%'")"
expect_eq "registered hooks" "20" "$(sql "SELECT COUNT(*) FROM ps_hook_module hm JOIN ps_module m ON m.id_module = hm.id_module WHERE m.name = 'kronixonconnect'")"

# The API controller answers from the zip's own classes.
answer="$(curl -sS -H 'X-KX-Protocol: v1' "$base/index.php?fc=module&module=kronixonconnect&controller=api&path=/v1/status" | jq -r '.error.code' 2>/dev/null || echo "no answer")"
expect_eq "GET /v1/status of an unpaired shop" "store_unpaired" "$answer"
expect_eq "the built-in host" "https://connect-staging.kronixon.com" "$(dc exec -T -u www-data ps php -r 'require "modules/kronixonconnect/src/Protocol.php"; echo Kronixon\Connect\Protocol::DEFAULT_PLATFORM_URL;' </dev/null)"
console prestashop:module uninstall kronixonconnect
expect_eq "kx_ tables after uninstall" "0" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop' AND table_name LIKE 'ps\_kx\_%'")"

summary="ZIP-SMOKE ps=$ps version=$version zip_sha256=$(sha256sum "$out/k.zip" | cut -c1-12) total_s=$(( $(date +%s) - t0 )) failures=$failures"
echo "$summary"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then echo "- \`$summary\`" >>"$GITHUB_STEP_SUMMARY"; fi
[ "$failures" -eq 0 ]
