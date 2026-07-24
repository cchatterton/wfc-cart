#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="wfc-cart"
REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$REPOSITORY_DIR/dist"
PACKAGE_DIR="$DIST_DIR/$PLUGIN_SLUG"

rm -rf "$PACKAGE_DIR"
rm -f "$DIST_DIR/$PLUGIN_SLUG.zip"
rm -f "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"
mkdir -p "$PACKAGE_DIR"

files=(
	"wfc-cart.php"
	"readme.md"
	"CHANGELOG.md"
	"uninstall.php"
)

directories=(
	"admin"
	"checkout"
	"functions"
	"gravity-forms"
	"rest"
	"salesforce"
	"scripts"
	"stripe"
	"styles"
	"templates"
)

for file in "${files[@]}"; do
	cp "$REPOSITORY_DIR/$file" "$PACKAGE_DIR/$file"
done

for directory in "${directories[@]}"; do
	cp -R "$REPOSITORY_DIR/$directory" "$PACKAGE_DIR/$directory"
done

find "$PACKAGE_DIR" -name ".DS_Store" -delete
find "$PACKAGE_DIR" -name "*.zip" -delete
rm -f "$PACKAGE_DIR/scripts/build-plugin-zip.sh"
rm -rf "$PACKAGE_DIR/node_modules" "$PACKAGE_DIR/vendor"

(
	cd "$DIST_DIR"
	zip -qr "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
)

cp "$DIST_DIR/$PLUGIN_SLUG.zip" "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"

printf 'Built %s and %s\n' "$DIST_DIR/$PLUGIN_SLUG.zip" "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"
