#!/usr/bin/env bash
# Install (or update) the BEAZER demo at https://<your-site>/beazertheband/
# on a Lightsail WordPress server, next to WordPress without touching it.
#
# Run in the Lightsail browser SSH console:
#   curl -fsSL https://raw.githubusercontent.com/twellerstudios/AutoPoster/ccr-fddeaeb4-o3mwxx/clients/beazer/deploy/lightsail-deploy.sh | sudo bash
#
# Overrides (optional): DOCROOT=/path/to/wordpress  ARCHIVE_URL=https://...tar.gz
set -euo pipefail

ARCHIVE_URL="${ARCHIVE_URL:-https://raw.githubusercontent.com/twellerstudios/AutoPoster/ccr-fddeaeb4-o3mwxx/clients/beazer/dist/beazertheband-demo.tar.gz}"
FOLDER=beazertheband

# Find the WordPress root (Bitnami images first, then common LAMP paths).
if [ -z "${DOCROOT:-}" ]; then
	for d in /opt/bitnami/wordpress /home/bitnami/apps/wordpress/htdocs /home/bitnami/htdocs /var/www/html /var/www/wordpress; do
		if [ -f "$d/wp-load.php" ]; then DOCROOT="$d"; break; fi
	done
fi
if [ -z "${DOCROOT:-}" ] || [ ! -f "$DOCROOT/wp-load.php" ]; then
	echo "Could not find the WordPress folder. Re-run with DOCROOT=/path/to/wordpress" >&2
	exit 1
fi
# Bitnami keeps WordPress behind a symlink; resolve it so files land in the real folder.
DOCROOT="$(cd "$DOCROOT" && pwd -P)"
echo "WordPress found at $DOCROOT"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "Downloading the demo..."
curl -fsSL "$ARCHIVE_URL" -o "$TMP/demo.tar.gz"
tar -xzf "$TMP/demo.tar.gz" -C "$TMP"
[ -f "$TMP/$FOLDER/index.html" ] || { echo "Download did not contain $FOLDER/index.html" >&2; exit 1; }

TARGET="$DOCROOT/$FOLDER"
if [ -d "$TARGET" ]; then
	# Keep fan club sign-ups across updates; back up the rest.
	BACKUP="$DOCROOT/.${FOLDER}-backup-$(date +%Y%m%d-%H%M%S)"
	mv "$TARGET" "$BACKUP"
	[ -d "$BACKUP/data" ] && cp -a "$BACKUP/data" "$TMP/$FOLDER/data"
	echo "Previous version moved to $BACKUP"
fi
mv "$TMP/$FOLDER" "$TARGET"

# Same owner as WordPress; the web server group may write only data/ (sign-ups).
OWNER="$(stat -c '%U' "$DOCROOT/wp-load.php")"
GROUP="$(stat -c '%G' "$DOCROOT/wp-load.php")"
chown -R "$OWNER:$GROUP" "$TARGET"
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +
mkdir -p "$TARGET/data"
chown "$OWNER:$GROUP" "$TARGET/data"
chmod 775 "$TARGET/data"

echo
echo "Done. The BEAZER demo is at /$FOLDER/ on this site, e.g. https://www.twellerstudios.com/$FOLDER/"
echo "If an old version still shows, purge the Cloudflare cache for that path."
