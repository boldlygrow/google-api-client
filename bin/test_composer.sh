#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

# --no-check-lock because composer.lock is not committed for a package.
echo "==> composer validate --strict --no-check-lock"
composer validate --strict --no-check-lock "$@"
