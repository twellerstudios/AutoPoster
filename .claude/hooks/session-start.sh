#!/bin/bash
set -euo pipefail

# Only run in Claude Code on the web
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# Async mode — session starts immediately, deps install in background
echo '{"async": true, "asyncTimeout": 300000}'

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-/home/user/AutoPoster}"

echo "[AutoPoster] Installing backend dependencies..."
npm install --prefix "$PROJECT_DIR/backend"

echo "[AutoPoster] Installing frontend dependencies..."
npm install --prefix "$PROJECT_DIR/frontend"

echo "[AutoPoster] Dependencies ready."
