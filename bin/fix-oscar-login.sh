#!/bin/bash
# Reset Open-O / OpenOSP default login for local development.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f "./local.env" ]; then
  # shellcheck disable=SC1091
  source ./local.env
fi

if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
  echo "MYSQL_ROOT_PASSWORD not set. Run ./openosp setup first."
  exit 1
fi

echo "Applying login fix (openodoc + legacy oscardoc expiry)..."
docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" oscar < bin/fix-oscar-login.sql

if grep -q '^mandatory_password_reset=true' ./volumes/oscar.properties 2>/dev/null; then
  sed -i 's/^mandatory_password_reset=true/mandatory_password_reset=false/' ./volumes/oscar.properties
  echo "Set mandatory_password_reset=false in volumes/oscar.properties"
fi

echo "Restarting Oscar..."
docker compose restart oscar

echo ""
echo "Try logging in at http://localhost:8080/oscar with:"
echo "  Username: openodoc"
echo "  Password: openo2025"
echo "  2nd-level PIN: 2025"
echo ""
echo "(README oscardoc/mac2002/1117 is outdated for current Open-O databases.)"
