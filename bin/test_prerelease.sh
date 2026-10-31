#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

step() {
    local name="$1"
    shift
    echo ""
    echo "============================================================"
    echo "  $name"
    echo "============================================================"
    "$@"
}

step "composer"   "$SCRIPT_DIR/test_composer.sh"
step "code_style" "$SCRIPT_DIR/test_code_style.sh"
step "phpstan"    "$SCRIPT_DIR/test_phpstan.sh"
step "unit_test"  "$SCRIPT_DIR/test_unit.sh"
step "dep_vuln"   "$SCRIPT_DIR/dep_vuln.sh"

echo ""
echo "============================================================"
echo "  all prerelease checks passed"
echo "============================================================"
