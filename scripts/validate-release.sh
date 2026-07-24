#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_COMMAND="${PHP_COMMAND:-php}"
NODE_COMMAND="${NODE_COMMAND:-node}"

while IFS= read -r file; do
	"$PHP_COMMAND" -l "$file" >/dev/null
done < <(
	rg --files "$REPOSITORY_DIR" \
		-g "*.php" \
		-g "!dist/**" \
		| sort
)

"$NODE_COMMAND" --check "$REPOSITORY_DIR/scripts/wfc-cart-checkout.js"

for test_file in \
	"bootstrap-smoke.php" \
	"phase-4-core.php" \
	"phase-5-core.php" \
	"phase-6-core.php" \
	"phase-7-core.php" \
	"phase-8-core.php"; do
	"$PHP_COMMAND" "$REPOSITORY_DIR/tests/$test_file"
done

"$REPOSITORY_DIR/tests/security-static.sh"
"$REPOSITORY_DIR/scripts/build-plugin-zip.sh"

cmp "$REPOSITORY_DIR/dist/wfc-cart.zip" "$REPOSITORY_DIR/wfc-cart.zip"

if [[ "$(unzip -Z1 "$REPOSITORY_DIR/dist/wfc-cart.zip" | head -1)" != "wfc-cart/" ]]; then
	echo "Release ZIP must use wfc-cart/ as its top-level directory." >&2
	exit 1
fi

if unzip -Z1 "$REPOSITORY_DIR/dist/wfc-cart.zip" | rg -q '(^|/)(\\.git|node_modules|vendor|tests|docs)(/|$)|\\.zip$|scripts/(build-plugin-zip|validate-release)\\.sh$'; then
	echo "Release ZIP contains a development or nested-package artifact." >&2
	exit 1
fi

printf 'WFC Cart release validation passed: %s\n' "$(shasum -a 256 "$REPOSITORY_DIR/dist/wfc-cart.zip" | awk '{print $1}')"
