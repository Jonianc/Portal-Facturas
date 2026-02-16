#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$PLUGIN_DIR/.." && pwd)"

VERSION_LINE="$(sed -n 's/^ \* Version: //p' "$PLUGIN_DIR/wp-facturas-portal.php" | head -n 1)"
VERSION="${VERSION_LINE:-0.0.0}"

mkdir -p "$REPO_DIR/dist"
ZIP_PATH="$REPO_DIR/dist/wp-facturas-portal-${VERSION}.zip"

cd "$REPO_DIR"
rm -f "$ZIP_PATH"
zip -r "$ZIP_PATH" "wp-facturas-portal" \
  -x "wp-facturas-portal/.git/*" \
  -x "wp-facturas-portal/.DS_Store" \
  -x "wp-facturas-portal/dist/*"

echo "ZIP generado: $ZIP_PATH"
