#!/usr/bin/env bash
# Import or purge validated CSV bundles into OpenOSP MariaDB.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 is required." >&2
  exit 1
fi

exec python3 "${SCRIPT_DIR}/import_to_oscar.py" "$@"
