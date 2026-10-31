#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

echo "==> php vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=2G"
php vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=2G "$@"
