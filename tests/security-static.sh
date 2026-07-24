#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if rg -n "wp_ajax_nopriv_" "$REPOSITORY_DIR" -g "*.php" -g "!dist/**" -g "!docs/**"; then
	echo "Unexpected public AJAX action detected." >&2
	exit 1
fi

while IFS= read -r file; do
	if ! rg -q "defined\\('ABSPATH'\\)|defined\\('WP_UNINSTALL_PLUGIN'\\)" "$file"; then
		echo "Missing direct-access guard: $file" >&2
		exit 1
	fi
done < <(
	rg --files "$REPOSITORY_DIR" \
		-g "*.php" \
		-g "!dist/**" \
		-g "!demo/**" \
		-g "!tests/**" \
		| sort
)

if [[ -f "$REPOSITORY_DIR/dist/wfc-cart.zip" ]]; then
	if unzip -Z1 "$REPOSITORY_DIR/dist/wfc-cart.zip" | rg -q "/(compatibility|forms|ia|assets)/"; then
		echo "Removed directories included in release package." >&2
		exit 1
	fi
fi

echo "WFC Cart static security checks passed."
