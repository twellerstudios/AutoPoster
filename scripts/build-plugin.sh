#!/usr/bin/env bash
#
# Build a fresh, installable zip of the Tweller Bookings WP plugin from its
# real source (tweller-bookings-wp/) — the folder that actually gets edited.
# Nothing else in this repo produces a plugin zip; a stale hand-made one
# (tweller-flow.zip) used to sit in the repo root and rot. This replaces it.
#
# Output goes to dist/, which is gitignored, so the zip never gets committed
# and never collides with `git pull` again — rebuild it any time you need a
# fresh copy to upload to WordPress.
#
# Usage: ./scripts/build-plugin.sh [output-name]
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT_DIR/tweller-bookings-wp"
DIST_DIR="$ROOT_DIR/dist"

if [ ! -d "$PLUGIN_DIR" ]; then
    echo "Error: $PLUGIN_DIR not found." >&2
    exit 1
fi

VERSION="$(grep -m1 '^ \* Version:' "$PLUGIN_DIR/tweller-bookings-wp.php" | sed 's/.*Version:[[:space:]]*//')"
if [ -z "$VERSION" ]; then
    echo "Error: could not read the plugin version from tweller-bookings-wp.php." >&2
    exit 1
fi

OUTPUT_NAME="${1:-tweller-bookings-wp-${VERSION}.zip}"
mkdir -p "$DIST_DIR"
OUTPUT_PATH="$DIST_DIR/$OUTPUT_NAME"

# Build in a temp dir so the zip contains a clean top-level
# "tweller-bookings-wp/" folder — exactly what WordPress expects when you
# upload a plugin zip — with none of the source tree's dev/test cruft.
# Plain cp + find, deliberately not rsync: it isn't guaranteed to be
# installed everywhere this script might run.
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

cp -R "$PLUGIN_DIR" "$WORK_DIR/tweller-bookings-wp"
find "$WORK_DIR/tweller-bookings-wp" \
    \( -name '.git' -o -name '.DS_Store' -o -name '*.log' -o -name 'node_modules' -o -name 'tests' -o -name '*.zip' \) \
    -exec rm -rf {} + 2>/dev/null || true

rm -f "$OUTPUT_PATH"
(cd "$WORK_DIR" && zip -rq "$OUTPUT_PATH" "tweller-bookings-wp")

echo "Built $OUTPUT_PATH (plugin v$VERSION)"
