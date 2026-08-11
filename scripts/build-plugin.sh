#!/usr/bin/env bash
#
# Build an installable zip of the Tweller Bookings WP plugin from its real
# source (tweller-bookings-wp/) — the folder that actually gets edited.
#
# The zip is named for the version in the plugin header, so every release
# lands as its own file (tweller-bookings-wp-3.34.0.zip) in releases/. Those
# are kept in git on purpose: each one is written once and never touched
# again, so unlike the old single mutable tweller-flow.zip they can't cause
# a merge conflict — and you get a rollback-ready copy of every version.
#
# Normally you never run this by hand: the pre-commit hook in .githooks/
# calls it automatically whenever the plugin version changes.
#
# Usage:
#   ./scripts/build-plugin.sh           # build current version if missing
#   ./scripts/build-plugin.sh --force   # rebuild even if that version exists
#   OUT_DIR=/tmp ./scripts/build-plugin.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT_DIR/tweller-bookings-wp"
OUT_DIR="${OUT_DIR:-$ROOT_DIR/releases}"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

if [ ! -d "$PLUGIN_DIR" ]; then
    echo "Error: $PLUGIN_DIR not found." >&2
    exit 1
fi

VERSION="$(grep -m1 '^ \* Version:' "$PLUGIN_DIR/tweller-bookings-wp.php" | sed 's/.*Version:[[:space:]]*//')"
if [ -z "$VERSION" ]; then
    echo "Error: could not read the plugin version from tweller-bookings-wp.php." >&2
    exit 1
fi

mkdir -p "$OUT_DIR"
OUTPUT_PATH="$OUT_DIR/tweller-bookings-wp-${VERSION}.zip"

# A released version is immutable: once 3.34.0 is built, that file is what
# 3.34.0 means. Rebuilding it silently would let the archive drift from the
# tag it claims, so we refuse unless --force is explicit.
if [ -f "$OUTPUT_PATH" ] && [ "$FORCE" -eq 0 ]; then
    echo "v$VERSION already archived at $OUTPUT_PATH (use --force to rebuild)"
    exit 0
fi

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
