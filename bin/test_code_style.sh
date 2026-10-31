#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

if [ ! -x "$PROJECT_ROOT/vendor/bin/pint" ]; then
    echo "vendor/bin/pint not found. Run: composer install" >&2
    exit 1
fi

echo "==> vendor/bin/pint --parallel --test"
./vendor/bin/pint --parallel --test "$@"
