#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

# composer.lock is not committed, so this checks the versions installed by your last `composer update`. Run
# `composer update` first if an advisory is reported for a version that a newer release already fixes.
echo "==> composer audit"
composer audit "$@"
