#!/bin/bash
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

echo '{"async": true, "asyncTimeout": 300000}'

npm install --prefix "${CLAUDE_PROJECT_DIR}/backend" --prefer-offline 2>/dev/null || true
npm install --prefix "${CLAUDE_PROJECT_DIR}/frontend" --prefer-offline 2>/dev/null || true
