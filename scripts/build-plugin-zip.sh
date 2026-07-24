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
	"operations"
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
rm -f "$PACKAGE_DIR/scripts/build-plugin-zip.sh" "$PACKAGE_DIR/scripts/validate-release.sh"
rm -rf "$PACKAGE_DIR/node_modules" "$PACKAGE_DIR/vendor"
TZ=UTC find "$PACKAGE_DIR" -exec touch -t 200001010000 {} +

(
	cd "$DIST_DIR"
	LC_ALL=C find "$PLUGIN_SLUG" -print | LC_ALL=C TZ=UTC zip -q -X "$PLUGIN_SLUG.zip" -@
)

cp "$DIST_DIR/$PLUGIN_SLUG.zip" "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"

printf 'Built %s and %s\n' "$DIST_DIR/$PLUGIN_SLUG.zip" "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"
