#!/usr/bin/env bash
# Prints the digest-pinned reference of an image listed in docker/images.lock, or fails.
#
#   scripts/image-ref.sh prestashop/prestashop:9.1.5-8.4   → prestashop/prestashop:9.1.5-8.4@sha256:…
#   scripts/image-ref.sh mariadb                           → the one mariadb:<tag>@sha256:… line
set -euo pipefail

want="${1:?usage: scripts/image-ref.sh <image[:tag]>}"
lock="$(cd "$(dirname "$0")/.." && pwd)/docker/images.lock"

if ! ref="$(awk -v want="$want" '
    /^[[:space:]]*(#|$)/ { next }
    $1 == want || (index(want, ":") == 0 && index($1, want ":") == 1) { found = found $1 "@" $2 "\n"; n++ }
    END { if (n != 1) exit 1; printf "%s", found }' "$lock")"; then
    echo "image-ref: $want is not pinned exactly once in docker/images.lock" >&2
    exit 1
fi
if [[ ! "$ref" =~ ^[a-z0-9./_-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$ ]]; then
    echo "image-ref: malformed entry for $want in docker/images.lock" >&2
    exit 1
fi
printf '%s\n' "$ref"
