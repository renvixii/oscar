#!/bin/bash
# PDF Finder on http://localhost:8082 (same Docker network as db).
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d db
docker compose up -d --build --force-recreate pdffinder
echo "PDF Finder: http://localhost:8082"
echo "Test: http://localhost:8082/test-oscar-connection.php"
