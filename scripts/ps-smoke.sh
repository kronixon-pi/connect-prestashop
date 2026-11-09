#!/usr/bin/env bash
# Smoke test of the module on one PrestaShop version in Docker (used by `make smoke`, `make matrix`,
# module-ci and matrix workflows).
#
#   scripts/ps-smoke.sh <178|81|82|90|91|92> [--keep]
#
# Starts docker/compose.<ps>.yml through scripts/compose.sh (images pinned by digest in
# docker/images.lock; PS_TAG / PS_PORT override the image tag and port), waits for the
# auto-install, installs the module from the read-only mount through bin/console, uninstalls and
# reinstalls it, turns friendly URLs on and checks GET /v1/status through both URL forms (Spec §5.1).
# Prints one timing line per step and a SMOKE summary line; --keep leaves the shop running.
set -euo pipefail

ps="${1:?usage: scripts/ps-smoke.sh <178|81|82|90|91|92> [--keep]}"
keep="${2:-}"
root="$(cd "$(dirname "$0")/.." && pwd)"
file="$root/docker/compose.$ps.yml"
[ -f "$file" ] || { echo "no such compose file: $file" >&2; exit 2; }
port="${PS_PORT:-$(sed -n 's/.*PS_PORT:-\([0-9]*\)}.*/\1/p' "$file" | head -n 1)}"
base="http://localhost:$port"
tmp="$(mktemp -d)"
t0=$(date +%s)
failures=0

dc() { "$root/scripts/compose.sh" "$ps" "$@"; }
elapsed() { echo $(( $(date +%s) - t0 )); }
step() { echo "[$(elapsed)s] $*"; }
sql() { dc exec -T db mariadb -uroot -pprestashop prestashop -N -B -e "$1" </dev/null; }
console() { dc exec -T -u www-data ps php bin/console "$@" </dev/null; }

cleanup() {
    local rc=$?
    if [ "$rc" -ne 0 ]; then dc logs --tail 80 ps 2>&1 || true; fi
    if [ "$keep" != "--keep" ]; then dc down -v --remove-orphans >/dev/null 2>&1 || true; fi
    rm -rf "$tmp"
    exit "$rc"
}
trap cleanup EXIT

dc up -d --quiet-pull
step "containers started (port $port)"

deadline=$(( $(date +%s) + 900 ))
while :; do
    logs="$(dc logs ps 2>&1 || true)"
    if [[ "$logs" == *"installation failed"* ]]; then echo "PrestaShop auto-install failed" >&2; exit 1; fi
    if [[ "$logs" == *"Starting web server now"* ]]; then break; fi
    if [ "$(date +%s)" -gt "$deadline" ]; then echo "auto-install did not finish in 900 s" >&2; exit 1; fi
    if [ -z "$(dc ps -q --status running ps)" ]; then echo "the shop container stopped" >&2; exit 1; fi
    sleep 3
done
install_s=$(elapsed)
read -r version php_version < <(dc exec -T -u www-data -e PS_DOMAIN="localhost:$port" ps php modules/kronixonconnect/scripts/ps/prepare-shop.php </dev/null)
step "PrestaShop $version on PHP $php_version installed, friendly URLs on"

# `access` / `module_access` rows whose authorization role no longer exists. A stock shop may already
# hold some; the module must not add any (PrestaShop deletes a tab's roles but not their access rows).
orphans() {
    sql "SELECT (SELECT COUNT(*) FROM ps_access a LEFT JOIN ps_authorization_role r ON r.id_authorization_role = a.id_authorization_role WHERE r.id_authorization_role IS NULL), (SELECT COUNT(*) FROM ps_module_access a LEFT JOIN ps_authorization_role r ON r.id_authorization_role = a.id_authorization_role WHERE r.id_authorization_role IS NULL)"
}
orphans_before="$(orphans)"

m0=$(date +%s)
console prestashop:module install kronixonconnect
module_s=$(( $(date +%s) - m0 ))
step "module installed in ${module_s}s"

expect_eq() { # name expected actual
    if [ "$2" = "$3" ]; then echo "  ok   $1 = $3"; else echo "  FAIL $1: expected '$2', got '$3'"; failures=$((failures + 1)); fi
}

module_version="$(sed -n 's/.*<version><!\[CDATA\[\(.*\)\]\]><\/version>.*/\1/p' "$root/config.xml")"
cfg() { sql "SELECT COUNT(*) FROM ps_configuration WHERE name = '$1' AND $2"; }
account() { # employees + profiles + access rows of the service account, tab rows
    sql "SELECT (SELECT COUNT(*) FROM ps_employee WHERE email LIKE 'connect-%@kronixon.invalid'), (SELECT COUNT(*) FROM ps_profile_lang WHERE name = 'Kronixon Connect' AND id_lang = (SELECT MIN(id_lang) FROM ps_lang)), (SELECT COUNT(*) FROM ps_access a JOIN ps_profile_lang pl ON pl.id_profile = a.id_profile WHERE pl.name = 'Kronixon Connect'), (SELECT COUNT(*) FROM ps_tab WHERE class_name = 'AdminKronixonConnect')"
}
expect_eq "module row (name active version)" "kronixonconnect	1	$module_version" "$(sql "SELECT name, active, version FROM ps_module WHERE name = 'kronixonconnect'")"
expect_eq "kx_ tables" "7" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop' AND table_name LIKE 'ps\_kx\_%'")"
expect_eq "registered hooks" "19" "$(sql "SELECT COUNT(*) FROM ps_hook_module hm JOIN ps_module m ON m.id_module = hm.id_module WHERE m.name = 'kronixonconnect'")"
expect_eq "KX_INSTANCE_ID is a UUID" "1" "$(cfg KX_INSTANCE_ID "value REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'")"
expect_eq "X25519 public key (base64 of 32 bytes)" "1" "$(cfg KX_PUBLIC_KEY "value REGEXP '^[A-Za-z0-9+/]{43}=$'")"
expect_eq "key pair stored encrypted (PhpEncryption, not base64 of 64 bytes)" "1" "$(cfg KX_PRIVATE_KEY "LENGTH(value) > 200 AND value NOT REGEXP '^[A-Za-z0-9+/]{86}==$'")"
expect_eq "service account: employee, profile, access rows, BO tab" "1	1	0	1" "$(account)"
expect_eq "the employee cannot log in (inactive)" "0" "$(sql "SELECT active FROM ps_employee WHERE email LIKE 'connect-%@kronixon.invalid'")"

console prestashop:module uninstall kronixonconnect
expect_eq "kx_ tables after uninstall" "0" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop' AND table_name LIKE 'ps\_kx\_%'")"
expect_eq "KX_ keys after uninstall" "0" "$(sql "SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'KX\_%'")"
expect_eq "service account and tab after uninstall" "0	0	0	0" "$(account)"
expect_eq "access rows of deleted roles after uninstall (stock shop: $orphans_before)" "$orphans_before" "$(orphans)"
console prestashop:module install kronixonconnect
expect_eq "kx_ tables after reinstall" "7" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop' AND table_name LIKE 'ps\_kx\_%'")"
expect_eq "service account after reinstall" "1	1	0	1" "$(account)"
step "uninstall + reinstall checked"

check() { # name expected-status expected-error-code curl-args...
    local name="$1" want_status="$2" want_code="$3"
    shift 3
    local status code proto
    status="$(curl -sS -o "$tmp/body" -D "$tmp/headers" -w '%{http_code}' "$@")"
    code="$(jq -r '.error.code // "-"' "$tmp/body" 2>/dev/null || echo "not-json")"
    proto="$(tr -d '\r' <"$tmp/headers" | awk -F': ' 'tolower($1) == "x-kx-protocol" { print $2 }')"
    if [ "$status" = "$want_status" ] && [ "$code" = "$want_code" ] && [ "$proto" = "v1" ]; then
        echo "  ok   $name → $status $code"
    else
        echo "  FAIL $name → status $status (want $want_status), code $code (want $want_code), X-KX-Protocol '$proto'"
        head -c 400 "$tmp/body"; echo
        failures=$((failures + 1))
    fi
    if tr -d '\r' <"$tmp/headers" | awk 'tolower($1) == "set-cookie:" { found = 1 } END { exit found ? 0 : 1 }'; then
        echo "  FAIL $name sets a cookie"
        failures=$((failures + 1))
    fi
}

h=(-H 'X-KX-Protocol: v1')
check "GET /v1/status (index.php?fc=module)" 403 store_unpaired "${h[@]}" "$base/index.php?fc=module&module=kronixonconnect&controller=api&path=/v1/status"
check "GET /v1/status (friendly URL)" 403 store_unpaired "${h[@]}" "$base/module/kronixonconnect/api?path=/v1/status"
check "GET capabilities (Spec form path=capabilities)" 403 store_unpaired "${h[@]}" "$base/module/kronixonconnect/api?path=capabilities"
check "POST with X-HTTP-Method-Override: DELETE" 403 store_unpaired "${h[@]}" -X POST -H 'X-HTTP-Method-Override: DELETE' --data-binary '{}' "$base/module/kronixonconnect/api?path=images"
check "without X-KX-Protocol" 400 protocol_version_unsupported "$base/module/kronixonconnect/api?path=/v1/status"
check "path=/v2/status" 400 protocol_version_unsupported "${h[@]}" "$base/module/kronixonconnect/api?path=/v2/status"
check "PUT" 405 method_not_allowed "${h[@]}" -X PUT "$base/module/kronixonconnect/api?path=/v1/status"
check "missing path" 400 bad_request "${h[@]}" "$base/module/kronixonconnect/api"

# Pairing end to end against tests/Integration/platform-stub.php (the merged platform's pair and store
# routes, run by the shop's own PHP on 127.0.0.1:8099 inside the container), then the platform's side
# played by tests/Integration/platform-driver.php: reverse check, refusals, rotation, unpair.
stub=http://127.0.0.1:8099
psphp() { dc exec -T -u www-data -e PS_DOMAIN="localhost:$port" ps php "$@" </dev/null; }
dc exec -d -u www-data ps php -S 127.0.0.1:8099 modules/kronixonconnect/tests/Integration/platform-stub.php
for _ in $(seq 1 20); do
    if psphp -r 'exit(strpos((string) @file_get_contents("http://127.0.0.1:8099/v1/status", false, stream_context_create(["http" => ["header" => "X-KX-Protocol: v1"]])), "\"v1\"") === false ? 1 : 0);'; then break; fi
    sleep 0.5
done
paired="$(psphp modules/kronixonconnect/scripts/ps/pair.php "$stub" K7PM-3XQ9 || true)"
expect_eq "pair with the stub (state, no warning)" "paired -" "$(printf '%s' "$paired" | jq -r '"\(.state) \(.warning // "-")"' 2>/dev/null || echo "$paired")"
expect_eq "the shared secret is stored encrypted" "1" "$(cfg KX_SECRET "LENGTH(value) > 100 AND value NOT LIKE '%kxs\\_%'")"
if psphp modules/kronixonconnect/tests/Integration/platform-driver.php "$base" 127.0.0.1:80; then
    echo "  ok   platform driver"
else
    echo "  FAIL platform driver"; failures=$((failures + 1))
fi
expect_eq "kx_log holds rows but no secret" "1 0" "$(sql "SELECT COUNT(*) > 0, SUM(message LIKE '%kxs\\_%' OR context LIKE '%kxs\\_%') FROM ps_kx_log" | tr '\t' ' ')"
psphp modules/kronixonconnect/scripts/ps/pair.php "$stub" K7PM-3XQ9 >/dev/null
console prestashop:module uninstall kronixonconnect
expect_eq "uninstall told the platform (reason)" "uninstall" "$(dc exec -T ps cat /tmp/kx-stub/state.json </dev/null | jq -r '.unpaired')"
expect_eq "service account after uninstall of a paired shop" "0	0	0	0" "$(account)"
expect_eq "access rows of deleted roles after the second uninstall (stock shop: $orphans_before)" "$orphans_before" "$(orphans)"
console prestashop:module install kronixonconnect
step "pairing, signed calls, rotation and unpair checked"

# Catalog reads: tests/Integration/catalog-walk.php walks every GET catalog/* reader in K = 1, 3 and 8 id
# ranges (and a products walk while products are created and deleted) on KX_WALK_PRODUCTS products
# (generated by SQL; 0 = the shop's own), one JSON line per walk; then every answer of the walk and of
# the driver's signed calls against its schema (validate-answers.php, needs tools/vendor: make install).
w0=$(date +%s)
if psphp -d memory_limit=512M modules/kronixonconnect/tests/Integration/catalog-walk.php --size="${KX_WALK_PRODUCTS:-0}" --mutate --out=/tmp/kx-answers >"$tmp/walk"; then
    echo "  ok   catalog walks (every row once, K = 1, 3, 8 and while products change)"
else
    echo "  FAIL catalog walks"; failures=$((failures + 1))
fi
cat "$tmp/walk"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    { echo "Catalog walks on PrestaShop $version (\`KX_WALK_PRODUCTS=${KX_WALK_PRODUCTS:-0}\`):"; echo '```'; cat "$tmp/walk"; echo '```'; } >>"$GITHUB_STEP_SUMMARY"
fi
if [ ! -f "$root/tools/vendor/autoload.php" ]; then
    echo "  FAIL schema validation of the catalog answers needs tools/vendor (make install)"; failures=$((failures + 1))
elif psphp modules/kronixonconnect/tests/Integration/validate-answers.php /tmp/kx-answers; then
    echo "  ok   catalog answers match their schemas"
else
    echo "  FAIL catalog answers against their schemas"; failures=$((failures + 1))
fi
step "catalog reads checked in $(( $(date +%s) - w0 ))s"

total_s=$(elapsed)
front="$(curl -s -o /dev/null -w '%{http_code}' "$base/")"
expect_eq "storefront home with the module installed" "200" "$front"
summary="SMOKE ps=$ps image=$(dc config --images 2>/dev/null | grep prestashop | head -n 1) prestashop=$version php=$php_version install_s=$install_s module_install_s=$module_s total_s=$total_s failures=$failures"
echo "$summary"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then echo "- \`$summary\`" >>"$GITHUB_STEP_SUMMARY"; fi
[ "$failures" -eq 0 ]
