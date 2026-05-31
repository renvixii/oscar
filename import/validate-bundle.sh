#!/usr/bin/env bash
# Wrapper for import bundle validation (no database access).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_DIR="${1:-${SCRIPT_DIR}/example-bundle}"
shift || true
EXTRA_ARGS=("$@")

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 is required to run import validation." >&2
  exit 1
fi

if [ ${#EXTRA_ARGS[@]} -gt 0 ]; then
  exec python3 "${SCRIPT_DIR}/validate_import_bundle.py" "${BUNDLE_DIR}" --dry-run "${EXTRA_ARGS[@]}"
else
  exec python3 "${SCRIPT_DIR}/validate_import_bundle.py" "${BUNDLE_DIR}" --dry-run
fi
