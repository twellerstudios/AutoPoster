#!/bin/bash
# Quick update script for AutoPoster
# Run this from your phone/terminal — no need to stop the server!
# Usage: bash update.sh

set -e

echo "Pulling latest changes..."
git pull origin main 2>/dev/null || git pull

# Check if any package.json files changed (need npm install)
CHANGED=$(git diff --name-only HEAD@{1} HEAD 2>/dev/null || echo "")

if echo "$CHANGED" | grep -q "package.json"; then
  echo "Dependencies changed — installing..."
  npm --prefix backend install --silent
  npm --prefix frontend install --silent
  echo "Dependencies updated."
fi

echo ""
echo "Done! Both servers will auto-reload with the new code."
echo "  - Backend: nodemon detects file changes and restarts"
echo "  - Frontend: React hot-reloads in your browser"
echo ""
echo "No need to restart anything."
