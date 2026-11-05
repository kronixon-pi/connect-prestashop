#!/usr/bin/env bash
# Prints the 12-hex "fixture sha" of the baked fixture images (tag <ps-tag>-<fixture sha>): a hash
# over every input that changes what the image holds — the generator, the bake and restore scripts,
# the pinned base images and the module files bake-fixture.sh installs into the shop. It hashes the
# git index (mode, blob id and path of each file), so one tag always names one content; it refuses to
# run while an input differs from the index or is untracked, because the bake copies the work tree.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

inputs=(
    # generator, bake and restore scripts, base images
    tests/fixtures/generate.php scripts/bake-fixture.sh scripts/fixture-sha.sh scripts/image-ref.sh
    scripts/ps/prepare-shop.php docker/fixture docker/images.lock
    # the module as bake-fixture.sh copies and installs it
    kronixonconnect.php config.xml index.php LICENSE controllers src sql views
)

if ! git diff --quiet -- "${inputs[@]}" || [ -n "$(git ls-files --others --exclude-standard -- "${inputs[@]}")" ]; then
    echo "fixture-sha: an image input has uncommitted or untracked changes; commit them first" >&2
    exit 1
fi
listing="$(git ls-files -s -- "${inputs[@]}")"
[ -n "$listing" ] || { echo "fixture-sha: no input files found" >&2; exit 1; }
printf '%s\n' "$listing" | sha256sum | cut -c1-12
