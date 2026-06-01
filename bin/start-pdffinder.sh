#!/bin/bash
# PDF Finder on http://localhost:9082 (same Docker network as db).
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d db
docker compose up -d --build --force-recreate pdffinder
echo "PDF Finder: http://localhost:9082"
echo "Test: http://localhost:9082/test-oscar-connection.php"
