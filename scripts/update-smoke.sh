#!/usr/bin/env bash
# The module's self-update on a real shop (Spec §10, plan M6), through the back office as an administrator does it.
#
#   scripts/update-smoke.sh [91|178|81|82|90|91fpm] [--keep]
#
# A shop without the work tree mounted (docker/compose.zip.yml, project kx-upd<ps>, port 1<port>) gets the release
# build of the work tree, signed with the throw-away key of tests/Support/Releases.php (never a release key), from its
# zip; it pairs with tests/Integration/platform-stub.php, which answers the signed GET /v1/module/latest and serves
# the zips that tests/Integration/update-releases.php built. Then, logged in as the demo employee:
#
#   up to date · read-only module folder · tampered zip (hash; signature) · a zip of another channel · a half stage a
#   killed process left + update to 0.7.1 · an upgrade script that fails (rollback to 0.7.1) · a worker killed with
#   SIGKILL during the upgrade to 0.7.3, finished by the next page · a swap nobody followed, finished by the cron URL ·
#   an upgrade script that dies of the memory limit (0.7.5): run twice, the cron URL still flushes, then rolled back.
#
# Needs docker, curl and jq on the host. Prints one line per check and an UPDATE-SMOKE summary.
set -euo pipefail

ps="${1:-91}"
keep="${2:-}"
root="$(cd "$(dirname "$0")/.." && pwd)"
file="$root/docker/compose.$ps.yml"
[ -f "$file" ] || { echo "no such compose file: $file" >&2; exit 2; }
export PS_PORT="${PS_PORT:-1$(sed -n 's/.*PS_PORT:-\([0-9]*\)}.*/\1/p' "$file" | head -n 1)}"
base="http://localhost:$PS_PORT"
tmp="$(mktemp -d)"
t0=$(date +%s)
failures=0
stub=http://127.0.0.1:8099

dc() { "$root/scripts/compose.sh" "$ps" -p "kx-upd$ps" -f "$root/docker/compose.zip.yml" "$@"; }
step() { echo "[$(( $(date +%s) - t0 ))s] $*"; }
sql() { dc exec -T db mariadb -uroot -pprestashop prestashop -N -B -e "$1" </dev/null; }
console() { dc exec -T -u www-data ps php bin/console "$@" </dev/null; }
inps() { dc exec -T -u www-data ps "$@" </dev/null; }
expect_eq() { # name expected actual
    if [ "$2" = "$3" ]; then echo "  ok   $1 = $3"; else echo "  FAIL $1: expected '$2', got '$3'"; failures=$((failures + 1)); fi
}
cleanup() {
    local rc=$?
    if [ "$rc" -ne 0 ]; then dc logs --tail 60 ps 2>&1 || true; fi
    if [ "$keep" != "--keep" ]; then dc down -v --remove-orphans >/dev/null 2>&1 || true; fi
    rm -rf "$tmp"
    exit "$rc"
}
trap cleanup EXIT

# The releases, built from the work tree by the host's PHP when KX_PHP names it (CI), else by the PHP image (sodium).
mkdir -p "$tmp/releases"
if [ -n "${KX_PHP:-}" ]; then
    read -r -a php <<<"$KX_PHP"
    "${php[@]}" "$root/tests/Integration/update-releases.php" "$tmp/releases"
else
    docker run --rm -u "$(id -u):$(id -g)" -v "$root:/app:ro" -v "$tmp/releases:/out" -w /app php:8.5-cli php tests/Integration/update-releases.php /out
fi
from="$(sed -n "s/^    public const MODULE_VERSION = '\(.*\)';$/\1/p" "$root/src/Protocol.php")"

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
version="$(sql "SELECT value FROM ps_configuration WHERE name = 'PS_VERSION_DB'")"
# PHP as shared hosts run it: OPcache never checks a compiled script against its file again (only the module's
# opcache_invalidate() after the swap and the rollback lets the new or old code run), and open_basedir keeps PHP inside
# the shop and /tmp. php_ini <extra line> rewrites the file and reloads the web server.
php_ini() {
    dc exec -T ps sh -c "printf 'opcache.validate_timestamps=0\nopen_basedir=/var/www/html/:/tmp/\n%s\n' '$1' > /usr/local/etc/php/conf.d/zz-kx-host.ini && (apache2ctl -k graceful 2>/dev/null || kill -USR2 1)" </dev/null
    sleep 2
}
php_ini ''
# shellcheck disable=SC2016  # PHP code
dc exec -T ps php -r 'file_put_contents("/var/www/html/kx-ini.php", "<?php echo ini_get(\"opcache.enable\"), \" \", ini_get(\"opcache.validate_timestamps\"), \" \", ini_get(\"open_basedir\");");' </dev/null
expect_eq "PHP of the web server: OPcache on and never revalidating, open_basedir" "1 0 /var/www/html/:/tmp/" "$(curl -sS "$base/kx-ini.php")"
dc exec -T ps rm -f /var/www/html/kx-ini.php </dev/null
step "PrestaShop $version installed (port $PS_PORT)"

# The release build of the work tree, unpacked as the back office does, by the shop's own PHP.
dc cp "$tmp/releases/." ps:/tmp/kx-releases
dc exec -T ps sh -c 'mkdir -p /tmp/kx-stub/files /tmp/kxtest && cp /tmp/kx-releases/*.zip /tmp/kx-stub/files/ && chown -R www-data:www-data /tmp/kx-stub /tmp/kxtest && chmod -R a+rX /tmp/kx-releases' </dev/null
# shellcheck disable=SC2016  # PHP variables
inps php -r '$z = new ZipArchive; exit($z->open($argv[1]) === true && $z->extractTo("modules") ? 0 : 1);' "/tmp/kx-releases/kronixonconnect-$from.zip"
console prestashop:module install kronixonconnect
dc cp "$root/tests/Integration/platform-stub.php" ps:/tmp/kxtest/platform-stub.php
dc cp "$root/tests/Integration/update-driver.php" ps:/tmp/kxtest/update-driver.php
dc exec -d -u www-data -e KX_STUB_PLATFORM_KEY="$(cat "$tmp/releases/platform-key.txt")" ps php -S 127.0.0.1:8099 /tmp/kxtest/platform-stub.php
sleep 1
driver() { inps php /tmp/kxtest/update-driver.php /var/www/html "$@"; }
paired="$(driver pair "$stub" K7PM-3XQ9 || true)"
expect_eq "the release build pairs with the stub (its key pinned)" "paired" "$(printf '%s' "$paired" | jq -r '.state' 2>/dev/null || echo "$paired")"

# The back office as the demo employee: 9.x logs in through Symfony, 1.7.8 and 8.x through AdminLogin.
jar="$tmp/jar"
if [[ "$version" == 9.* ]]; then
    login="$(curl -sS -c "$jar" -b "$jar" "$base/admin-dev/index.php/login")"
    csrf="$(printf '%s' "$login" | grep -o 'id="_token" name="_token" value="[^"]*"' | head -n 1 | sed 's/.*value="//; s/"$//' || true)"
    curl -sS -o /dev/null -c "$jar" -b "$jar" "$base/admin-dev/index.php/login" \
        --data-urlencode 'email=demo@prestashop.com' --data-urlencode 'passwd=prestashop_demo' --data-urlencode "_token=$csrf"
else
    curl -sS -o /dev/null -c "$jar" -b "$jar" "$base/admin-dev/index.php?controller=AdminLogin" \
        -d ajax=1 -d submitLogin=1 --data-urlencode 'email=demo@prestashop.com' --data-urlencode 'passwd=prestashop_demo'
fi
cfg="$base/admin-dev/index.php?controller=AdminModules&configure=kronixonconnect&token=$(driver token)"
page="$tmp/page.html"
open_page() { curl -sS -L -o "$page" -c "$jar" -b "$jar" "$cfg"; }
text() { grep -o "id=\"$1\"[^>]*>[^<]*" "$page" | head -n 1 | sed 's/^[^>]*>//' || true; }
has() { if grep -q "id=\"$1\"" "$page"; then echo yes; else echo no; fi; }
# A form of the settings page, posted as the browser does (a 302 is followed with a GET).
submit() { # button [field=value…]
    local action token button="$1"
    shift
    action="$(grep -o '<form method="post" action="[^"]*"' "$page" | head -n 1 | sed 's/.*action="//; s/"$//; s/&amp;/\&/g')"
    token="$(grep -o 'name="kx_update_token" value="[^"]*"' "$page" | head -n 1 | sed 's/.*value="//; s/"$//')"
    local args=(--data-urlencode "kx_update_token=$token" --data-urlencode "$button=1")
    for field in "$@"; do args+=(--data-urlencode "$field"); done
    curl -sS -L -o "$page" -c "$jar" -b "$jar" "${args[@]}" "$action"
}
offer() { # case: the stub's answer, then the button
    dc exec -T ps cp "/tmp/kx-releases/latest-$1.json" /tmp/kx-stub/latest.json </dev/null
    open_page
    submit submitKxUpdateCheck
}
files_version() { inps sed -n "s/^    public const MODULE_VERSION = '\(.*\)';$/\1/p" modules/kronixonconnect/src/Protocol.php; }
row() { sql "SELECT CONCAT(m.version, ' ', m.active, ' ', (SELECT COUNT(*) FROM ps_module_shop ms WHERE ms.id_module = m.id_module)) FROM ps_module m WHERE m.name = 'kronixonconnect'"; }
events() { sql "SELECT CONCAT(type, ' ', payload) FROM ps_kx_outbox WHERE type LIKE 'module.update%' ORDER BY id_outbox" | tr '\n' '|'; }
hidden() { inps sh -c 'ls -a modules | grep "^\.kronixonconnect" | tr "\n" " "' || true; }
perms() { inps stat -c '%U:%G %a' modules/kronixonconnect modules/kronixonconnect/src modules/kronixonconnect/kronixonconnect.php | tr '\n' ' '; }
marker() { sql "SELECT COUNT(*) FROM ps_configuration WHERE name = '$1'"; }
refused() { # name expected-result-substring
    local result
    result="$(text kx-update-result)"
    if [[ "$result" == *"$2"* ]]; then echo "  ok   $1: $result"; else echo "  FAIL $1: result '$result' has no '$2'"; failures=$((failures + 1)); fi
    expect_eq "$1: the module files and row are unchanged" "$from $from 1 1" "$(files_version) $(row)"
}

open_page
expect_eq "the settings page has the update panel" "yes" "$(has kronixonconnect-update)"
offer current
expect_eq "the running version: up to date" "yes" "$(has kx-update-current)"
expect_eq "the check was signed with the query and named the channel" "staging $from $version" "$(dc exec -T ps cat /tmp/kx-stub/state.json </dev/null | jq -r '.latest_queries[-1] | "\(.channel) \(.module) \(.version)"')"

offer 0.7.1
expect_eq "0.7.1 offered after the check" "0.7.1" "$(grep -o 'id="kx-update-available" data-version="[^"]*"' "$page" | sed 's/.*data-version="//; s/"$//')"
before="$(perms)"
dc exec -T ps chmod -R a-w /var/www/html/modules/kronixonconnect </dev/null
submit submitKxUpdate kx_update_version=0.7.1
dc exec -T ps chmod -R u+w /var/www/html/modules/kronixonconnect </dev/null
refused "read-only module folder" "(read_only)"
expect_eq "read-only: the zip is offered for a manual upload" "yes" "$(has kx-update-zip)"
php_ini 'disable_functions=opcache_invalidate'
offer 0.7.1
submit submitKxUpdate kx_update_version=0.7.1
php_ini ''
refused "OPcache that never revalidates, opcache_invalidate() disabled" "(opcache_static)"

offer tampered-hash
submit submitKxUpdate kx_update_version=0.7.1
refused "a changed byte, announced hash" "(sha256_mismatch)"
offer tampered-signature
submit submitKxUpdate kx_update_version=0.7.1
refused "a changed byte whose hash the answer follows" "(bad_signature)"
offer other-channel
submit submitKxUpdate kx_update_version=0.7.1
refused "a stable zip offered to a staging build" "(channel_mismatch)"
expect_eq "nothing left next to the module after refusals" "" "$(hidden)"
step "refusals checked"

# What a worker killed while writing the new files leaves: a half folder next to the module. The module still
# loads, and the update starts over.
inps sh -c 'mkdir -p modules/.kronixonconnect-next/src && cp modules/kronixonconnect/kronixonconnect.php modules/.kronixonconnect-next/ && head -c 100 modules/kronixonconnect/src/Services.php > modules/.kronixonconnect-next/src/Services.php'
offer 0.7.1
expect_eq "a half stage left by a killed worker: the module still runs" "yes" "$(has kx-update-available)"
submit submitKxUpdate kx_update_version=0.7.1
expect_eq "0.7.1: the result shown after the redirects" "yes" "$(text kx-update-result | grep -q "updated from $from to 0.7.1" && echo yes || echo no)"
expect_eq "0.7.1: files, module row (version active shops)" "0.7.1 0.7.1 1 1" "$(files_version) $(row)"
expect_eq "0.7.1: its upgrade script ran through PrestaShop" "1" "$(marker KXTEST_UPGRADED_071)"
expect_eq "0.7.1: nothing left next to the module" "" "$(hidden)"
expect_eq "0.7.1: owner and permissions as before" "$before" "$(perms)"
expect_eq "0.7.1: the about panel runs the new code" "0.7.1" "$(grep -A0 -o 'Module version</th><td>[^<]*' "$page" | sed 's/.*<td>//' | head -n 1)"
step "update to 0.7.1 checked"

offer 0.7.2
submit submitKxUpdate kx_update_version=0.7.2
expect_eq "0.7.2: a failing upgrade script ran" "1" "$(marker KXTEST_UPGRADED_072)"
expect_eq "0.7.2: rolled back — files, module row (version active shops)" "0.7.1 0.7.1 1 1" "$(files_version) $(row)"
expect_eq "0.7.2: the result names the rollback" "yes" "$(text kx-update-result | grep -q '(upgrade_failed); the previous version was restored' && echo yes || echo no)"
expect_eq "0.7.2: nothing left next to the module" "" "$(hidden)"
step "rollback checked"

# A worker killed with SIGKILL while PrestaShop runs the upgrade scripts of 0.7.3.
dc exec -T ps sh -c 'rm -f /tmp/kx-update-pid; touch /tmp/kx-update-hold; chmod 644 /tmp/kx-update-hold' </dev/null
offer 0.7.3
action="$(grep -o '<form method="post" action="[^"]*"' "$page" | head -n 1 | sed 's/.*action="//; s/"$//; s/&amp;/\&/g')"
token="$(grep -o 'name="kx_update_token" value="[^"]*"' "$page" | head -n 1 | sed 's/.*value="//; s/"$//')"
swapped="$(curl -sS -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" --data-urlencode "kx_update_token=$token" -d submitKxUpdate=1 -d kx_update_version=0.7.3 "$action")"
expect_eq "0.7.3: the click swaps the files and redirects" "302 0.7.3" "$swapped $(files_version)"
# A connection per request: curl (like a browser) sends a GET again when a reused connection dies unanswered, and that
# retry would finish the upgrade before the state in between is looked at.
( curl -sS -L -H 'Connection: close' -o /dev/null -c "$jar" -b "$jar" "$cfg" >/dev/null 2>&1 || true ) &
pid=""
for _ in $(seq 1 60); do
    pid="$(dc exec -T ps cat /tmp/kx-update-pid </dev/null 2>/dev/null || true)"
    if [ -n "$pid" ]; then break; fi
    sleep 0.5
done
# The shell's own kill and /proc: the PHP-FPM image has neither ps nor a kill binary.
echo "  ..   worker of the upgrade: $pid $(dc exec -T ps sh -c "tr '\\0' ' ' < /proc/$pid/cmdline" </dev/null || echo "not running")"
dc exec -T ps sh -c "kill -9 $pid" </dev/null
wait || true
expect_eq "0.7.3: the upgrade ran once before the kill" "1" "$(dc exec -T ps sh -c 'wc -l < /tmp/kx-update-runs' </dev/null | tr -d ' ')"
expect_eq "0.7.3: killed mid-upgrade — new files, old module row, journal kept" "0.7.3 0.7.1 1 1 1" "$(files_version) $(row) $(sql "SELECT COUNT(*) FROM ps_configuration WHERE name = 'KX_UPDATE_STATE'")"
api="$(curl -sS -o /dev/null -w '%{http_code}' -H 'X-KX-Protocol: v1' "$base/index.php?fc=module&module=kronixonconnect&controller=api&path=/v1/status")"
expect_eq "0.7.3: the API answers meanwhile (unsigned call refused)" "401" "$api"
dc exec -T ps rm -f /tmp/kx-update-hold </dev/null
open_page
expect_eq "0.7.3: the next page finishes the upgrade" "0.7.3 0.7.3 1 1 1" "$(files_version) $(row) $(marker KXTEST_UPGRADED_073)"
expect_eq "0.7.3: nothing left next to the module" "" "$(hidden)"
step "kill during the upgrade checked"

# Files swapped, the browser gone: the cron URL finishes it.
offer 0.7.4
action="$(grep -o '<form method="post" action="[^"]*"' "$page" | head -n 1 | sed 's/.*action="//; s/"$//; s/&amp;/\&/g')"
token="$(grep -o 'name="kx_update_token" value="[^"]*"' "$page" | head -n 1 | sed 's/.*value="//; s/"$//')"
curl -sS -o /dev/null -c "$jar" -b "$jar" --data-urlencode "kx_update_token=$token" -d submitKxUpdate=1 -d kx_update_version=0.7.4 "$action"
cron="$(curl -sS "$base/index.php?fc=module&module=kronixonconnect&controller=cron&token=$(sql "SELECT value FROM ps_configuration WHERE name = 'KX_CRON_TOKEN'")")"
expect_eq "0.7.4: the cron URL finishes the upgrade" "updated 0.7.4" "$(printf '%s' "$cron" | jq -r '"\(.update.outcome) \(.update.to)"' 2>/dev/null || echo "$cron")"
expect_eq "0.7.4: files, module row, upgrade script" "0.7.4 0.7.4 1 1 1" "$(files_version) $(row) $(marker KXTEST_UPGRADED_074)"
step "cron URL finish checked"

# An upgrade script that dies where PHP cannot catch it (the memory limit): run twice, then rolled back. One connection
# per request (see 0.7.3), so that curl does not send a request again by itself.
cron_url="$base/index.php?fc=module&module=kronixonconnect&controller=cron&token=$(sql "SELECT value FROM ps_configuration WHERE name = 'KX_CRON_TOKEN'")"
pending() { sql "SELECT COUNT(*) FROM ps_kx_outbox WHERE status = 'pending'"; }
batches() { dc exec -T ps cat /tmp/kx-stub/state.json </dev/null | jq '.event_batches | length'; }
offer 0.7.5
action="$(grep -o '<form method="post" action="[^"]*"' "$page" | head -n 1 | sed 's/.*action="//; s/"$//; s/&amp;/\&/g')"
token="$(grep -o 'name="kx_update_token" value="[^"]*"' "$page" | head -n 1 | sed 's/.*value="//; s/"$//')"
swapped="$(curl -sS -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" --data-urlencode "kx_update_token=$token" -d submitKxUpdate=1 -d kx_update_version=0.7.5 "$action")"
expect_eq "0.7.5: the click swaps the files and redirects" "302 0.7.5" "$swapped $(files_version)"
first="$(curl -sS -L -H 'Connection: close' -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" "$cfg")"
expect_eq "0.7.5: the first page dies in the upgrade, the attempt counted" "500 1" "$first $(sql "SELECT JSON_VALUE(value, '$.attempts') FROM ps_configuration WHERE name = 'KX_UPDATE_STATE'")"
# The cron URL sends the store events first, then dies in the second attempt.
sql "UPDATE ps_configuration SET value = '0' WHERE name = 'KX_OUTBOX_LAST_FLUSH'"
waiting="$(pending)"
sent="$(batches)"
second="$(curl -sS -H 'Connection: close' -o /dev/null -w '%{http_code}' "$cron_url")"
expect_eq "0.7.5: the cron URL dies in the second attempt, after its flush" "500 2 0 yes" \
    "$second $(sql "SELECT JSON_VALUE(value, '$.attempts') FROM ps_configuration WHERE name = 'KX_UPDATE_STATE'") $(pending) $([ "$waiting" -gt 0 ] && [ "$(batches)" -gt "$sent" ] && echo yes || echo "no ($waiting pending before, $sent batches)")"
open_page
expect_eq "0.7.5: the third page rolls back instead of a third run — files, module row, runs" "0.7.4 0.7.4 1 1 2" "$(files_version) $(row) $(dc exec -T ps sh -c 'wc -l < /tmp/kx-075-runs' </dev/null | tr -d ' ')"
expect_eq "0.7.5: the settings page answers again and names the rollback" "200 yes" \
    "$(curl -sS -L -o "$page" -w '%{http_code}' -c "$jar" -b "$jar" "$cfg") $(text kx-update-result | grep -q '(upgrade_failed); the previous version was restored' && echo yes || echo no)"
expect_eq "0.7.5: journal gone, nothing left next to the module" "0 " "$(sql "SELECT COUNT(*) FROM ps_configuration WHERE name = 'KX_UPDATE_STATE'") $(hidden)"
expect_eq "0.7.5: the event says the upgrade died" "yes" "$(sql "SELECT payload FROM ps_kx_outbox WHERE type = 'module.update_failed' ORDER BY id_outbox DESC LIMIT 1" | grep -q '"error":"upgrade_failed: upgrade_died' && echo yes || echo no)"
sql "UPDATE ps_configuration SET value = '0' WHERE name = 'KX_OUTBOX_LAST_FLUSH'"
cron="$(curl -sS -H 'Connection: close' "$cron_url")"
expect_eq "0.7.5: the cron URL flushes again, nothing left to finish" "0 null" "$(printf '%s' "$cron" | jq -r '"\(.flush.failed) \(.update)"' 2>/dev/null || echo "$cron")"
step "upgrade that dies checked"

# The events for the platform (module.updated / module.update_failed through the outbox).
expect_eq "events: refusals, updates and the rollback, in order" \
    "module.update_failed read_only|module.update_failed opcache_static|module.update_failed sha256_mismatch|module.update_failed bad_signature|module.update_failed channel_mismatch|module.updated $from>0.7.1|module.update_failed upgrade_failed|module.updated 0.7.1>0.7.3|module.updated 0.7.3>0.7.4|module.update_failed upgrade_failed|" \
    "$(events | sed -E 's/\{"from":"([^"]*)","to":"([^"]*)"\}/\1>\2/g; s/\{"from":"[^"]*","to":"[^"]*","error":"([a-z0-9_]*)[^"]*"\}/\1/g')"
expect_eq "storefront home after the updates" "200" "$(curl -sL --max-redirs 3 -o /dev/null -w '%{http_code}' "$base/")"

summary="UPDATE-SMOKE ps=$ps prestashop=$version from=$from total_s=$(( $(date +%s) - t0 )) failures=$failures"
echo "$summary"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then echo "- \`$summary\`" >>"$GITHUB_STEP_SUMMARY"; fi
[ "$failures" -eq 0 ]
