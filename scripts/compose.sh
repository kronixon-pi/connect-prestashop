#!/usr/bin/env bash
# docker compose for docker/compose.<ps>.yml with the images pinned in docker/images.lock: the
# version's default PrestaShop tag is x-kx-ps-tag in the compose file, PS_TAG overrides it (and must
# be pinned too).
#
#   scripts/compose.sh <178|81|82|90|91|92> <docker compose arguments…>
set -euo pipefail

ps="${1:?usage: scripts/compose.sh <178|81|82|90|91|92> <docker compose arguments…>}"
shift
root="$(cd "$(dirname "$0")/.." && pwd)"
file="$root/docker/compose.$ps.yml"
[ -f "$file" ] || { echo "no such compose file: $file" >&2; exit 2; }
tag="${PS_TAG:-$(sed -n 's/^x-kx-ps-tag: *"\([^"]*\)"$/\1/p' "$file")}"
[ -n "$tag" ] || { echo "no x-kx-ps-tag in $file" >&2; exit 2; }

PS_IMAGE="$("$root/scripts/image-ref.sh" "prestashop/prestashop:$tag")"
MARIADB_IMAGE="$("$root/scripts/image-ref.sh" mariadb)"
export PS_IMAGE MARIADB_IMAGE
exec docker compose -f "$file" "$@"
