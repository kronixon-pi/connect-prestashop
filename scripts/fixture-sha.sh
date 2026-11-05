#!/usr/bin/env bash
# Prints the 12-hex "fixture sha" of the baked fixture images: a hash over every input that changes
# what the image holds (generator, bake and restore scripts). The image tag is <ps-tag>-<fixture sha>.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
sha256sum tests/fixtures/generate.php scripts/bake-fixture.sh scripts/ps/prepare-shop.php docker/fixture/kx-restore.sh \
    | sha256sum | cut -c1-12
