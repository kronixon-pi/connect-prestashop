#!/usr/bin/env bash
# Bakes a fixture image: an installed PrestaShop with the module and the Spec §12 fixture (500
# products), committed from the official image, plus the MariaDB dump and an init script that
# restores it on an empty database (docker/fixture/kx-restore.sh). Then starts the baked image on a
# fresh database and checks it (500 fixture products, the API answers).
#
#   scripts/bake-fixture.sh <prestashop image tag> <output image>
#   e.g. scripts/bake-fixture.sh 9.1.5-8.4 ghcr.io/kronixon-pi/connect-ps-fixture:9.1.5-8.4-$(scripts/fixture-sha.sh)
#
# Prints a BAKE summary line (timings, image size). Pushing is the caller's job.
set -euo pipefail

tag="${1:?usage: scripts/bake-fixture.sh <prestashop tag> <output image>}"
out="${2:?usage: scripts/bake-fixture.sh <prestashop tag> <output image>}"
root="$(cd "$(dirname "$0")/.." && pwd)"
port="${BAKE_PORT:-8099}"
id="kxbake$$"
net="$id-net"
tmp="$(mktemp -d)"
t0=$(date +%s)

elapsed() { echo $(( $(date +%s) - t0 )); }
step() { echo "[$(elapsed)s] $*"; }
cleanup() {
    local rc=$?
    if [ "$rc" -ne 0 ]; then docker logs --tail 60 "$id-ps" 2>&1 || true; docker logs --tail 60 "$id-check" 2>&1 || true; fi
    docker rm -f "$id-ps" "$id-db" "$id-check" "$id-db2" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
    rm -rf "$tmp"
    exit "$rc"
}
trap cleanup EXIT

start_db() { # name
    docker run -d --name "$1" --network "$net" --network-alias db --tmpfs /var/lib/mysql \
        -e MARIADB_ROOT_PASSWORD=prestashop -e MARIADB_DATABASE=prestashop mariadb:10.11.19 >/dev/null
    local i=0
    until docker exec "$1" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
        i=$((i + 1)); [ "$i" -le 100 ] || { echo "database $1 did not start" >&2; return 1; }
        sleep 2
    done
}

wait_web() { # container [ready marker]
    # docker_run.sh prints "Starting web server now" before it runs /tmp/init-scripts; a baked image is
    # ready only after kx-restore.sh says so.
    local marker="${2:-Starting web server now}" deadline=$(( $(date +%s) + 900 )) logs
    while :; do
        logs="$(docker logs "$1" 2>&1 || true)"
        if [[ "$logs" == *"installation failed"* || "$logs" == *"kx-restore: database"* ]]; then echo "start failed in $1" >&2; return 1; fi
        if [[ "$logs" == *"$marker"* ]]; then return 0; fi
        if [ "$(date +%s)" -gt "$deadline" ]; then echo "$1 not ready in 900 s" >&2; return 1; fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$1")" != "true" ]; then echo "$1 stopped" >&2; return 1; fi
        sleep 3
    done
}

sql() { # container query
    docker exec "$1" mariadb -uroot -pprestashop prestashop -N -B -e "$2" </dev/null
}

docker network create "$net" >/dev/null
start_db "$id-db"
docker run -d --name "$id-ps" --network "$net" -p "127.0.0.1:$port:80" \
    -e DB_SERVER=db -e DB_USER=root -e DB_PASSWD=prestashop -e DB_NAME=prestashop \
    -e PS_INSTALL_AUTO=1 -e PS_INSTALL_DB=0 -e PS_FOLDER_ADMIN=admin-dev -e PS_FOLDER_INSTALL=install-dev \
    -e PS_DOMAIN="localhost:$port" -e PS_LANGUAGE=en -e PS_COUNTRY=BG -e PS_ENABLE_SSL=0 \
    "prestashop/prestashop:$tag" >/dev/null
wait_web "$id-ps"
install_s=$(elapsed)
step "PrestaShop $tag installed"

# The module as the zip will hold it, plus the two test tools (removed before the commit).
mkdir -p "$tmp/kronixonconnect/tests/fixtures" "$tmp/kronixonconnect/scripts/ps"
cp -R "$root/kronixonconnect.php" "$root/config.xml" "$root/index.php" "$root/LICENSE" \
    "$root/controllers" "$root/src" "$root/sql" "$root/views" "$tmp/kronixonconnect/"
cp "$root/tests/fixtures/generate.php" "$tmp/kronixonconnect/tests/fixtures/"
cp "$root/scripts/ps/prepare-shop.php" "$tmp/kronixonconnect/scripts/ps/"
docker cp "$tmp/kronixonconnect" "$id-ps:/var/www/html/modules/"
docker exec "$id-ps" chown -R www-data:www-data /var/www/html/modules/kronixonconnect
docker exec -u www-data -e PS_DOMAIN="localhost:$port" "$id-ps" php modules/kronixonconnect/scripts/ps/prepare-shop.php </dev/null
docker exec -u www-data "$id-ps" php bin/console prestashop:module install kronixonconnect </dev/null
step "module installed"

docker exec -u www-data -e PS_DOMAIN="localhost:$port" "$id-ps" php -d memory_limit=1G \
    modules/kronixonconnect/tests/fixtures/generate.php </dev/null >"$tmp/summary.json"
cat "$tmp/summary.json"
fixture_s=$(( $(elapsed) - install_s ))
step "fixture generated"

docker exec "$id-ps" rm -rf /var/www/html/modules/kronixonconnect/tests /var/www/html/modules/kronixonconnect/scripts \
    /var/www/html/var/cache/prod /var/www/html/var/cache/dev
docker exec "$id-db" sh -c 'mariadb-dump -uroot -pprestashop --single-transaction --routines prestashop | gzip -6 > /tmp/db.sql.gz'
docker cp "$id-db:/tmp/db.sql.gz" "$tmp/db.sql.gz"
docker exec "$id-ps" mkdir -p /kx-fixture /tmp/init-scripts
docker cp "$tmp/db.sql.gz" "$id-ps:/kx-fixture/db.sql.gz"
docker cp "$tmp/summary.json" "$id-ps:/kx-fixture/summary.json"
docker cp "$root/docker/fixture/kx-restore.sh" "$id-ps:/tmp/init-scripts/kx-restore.sh"
docker exec "$id-ps" chmod 0755 /tmp/init-scripts/kx-restore.sh
docker stop "$id-ps" >/dev/null
docker commit \
    --change 'ENV PS_INSTALL_AUTO=0' \
    --change 'LABEL org.opencontainers.image.source=https://github.com/kronixon-pi/connect-prestashop' \
    --change "LABEL org.opencontainers.image.description=\"PrestaShop $tag with Kronixon Connect and the 500-product test fixture\"" \
    --change 'LABEL org.opencontainers.image.licenses=AFL-3.0' \
    "$id-ps" "$out" >/dev/null
step "committed $out"

# Check the baked image on an empty database.
c0=$(date +%s)
docker rm -f "$id-ps" "$id-db" >/dev/null # both answer as "db" otherwise
start_db "$id-db2"
docker run -d --name "$id-check" --network "$net" -p "127.0.0.1:$((port + 1)):80" \
    -e DB_SERVER=db -e PS_DOMAIN="localhost:$((port + 1))" "$out" >/dev/null
wait_web "$id-check" "kx-restore: shop ready"
boot_s=$(( $(date +%s) - c0 ))
products="$(sql "$id-db2" "SELECT COUNT(*) FROM ps_product WHERE reference LIKE 'KX-%'")"
status="$(curl -s -o "$tmp/status.json" -w '%{http_code}' -H 'X-KX-Protocol: v1' "http://localhost:$((port + 1))/module/kronixonconnect/api?path=/v1/status")"
code="$(jq -r '.error.code' "$tmp/status.json")"
[ "$products" = "500" ] || { echo "baked image restored $products fixture products, want 500" >&2; exit 1; }
[ "$status" = "403" ] && [ "$code" = "store_unpaired" ] || { echo "baked image API answered $status $code" >&2; exit 1; }
step "baked image checked: $products fixture products, GET /v1/status → $status $code"

size_mb=$(( $(docker image inspect -f '{{.Size}}' "$out") / 1048576 ))
dump_kb=$(( $(stat -c %s "$tmp/db.sql.gz") / 1024 ))
summary="BAKE ps=$tag image=$out install_s=$install_s fixture_s=$fixture_s boot_from_bake_s=$boot_s dump_kb=$dump_kb image_mb=$size_mb total_s=$(elapsed)"
echo "$summary"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then echo "- \`$summary\`" >>"$GITHUB_STEP_SUMMARY"; fi
