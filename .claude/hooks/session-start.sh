#!/bin/bash
set -euo pipefail

# Only run in Claude Code on the web
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# Async mode — session starts immediately, deps install in background
echo '{"async": true, "asyncTimeout": 300000}'

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-/home/user/AutoPoster}"

# Re-arm the release hook. core.hooksPath lives in .git/config, which is NOT
# versioned — so every fresh clone (each new web container is one) silently
# loses it, and version bumps stop producing a zip in releases/ with no error
# to notice. Setting it here makes the archive self-healing instead of
# something a human has to remember after every rebuild.
if [ -d "$PROJECT_DIR/.githooks" ]; then
  git -C "$PROJECT_DIR" config core.hooksPath .githooks || true
  chmod +x "$PROJECT_DIR/.githooks/"* "$PROJECT_DIR/scripts/"*.sh 2>/dev/null || true
  echo "[AutoPoster] Release hook armed (core.hooksPath=.githooks)."
fi

echo "[AutoPoster] Installing backend dependencies..."
npm install --prefix "$PROJECT_DIR/backend"

echo "[AutoPoster] Installing frontend dependencies..."
npm install --prefix "$PROJECT_DIR/frontend"

echo "[AutoPoster] Dependencies ready."
