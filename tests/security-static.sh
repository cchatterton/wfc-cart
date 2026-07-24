#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if rg -n "wp_ajax_nopriv_" "$REPOSITORY_DIR" -g "*.php" -g "!dist/**" -g "!docs/**"; then
	echo "Unexpected public AJAX action detected." >&2
	exit 1
fi

if rg -ni "bbconnect|brownbox|bb_cart|bb-cart" "$REPOSITORY_DIR" \
	-g "!dist/**" \
	-g "!wfc-cart.zip" \
	-g "!tests/security-static.sh" \
	-g "!CHANGELOG.md"; then
	echo "Removed product references detected." >&2
	exit 1
fi

if rg -ni \
	"(update_option|add_option|set_transient|set_site_transient|update_post_meta).*access_token|(error_log|trigger_error).*(access_token|client_secret)" \
	"$REPOSITORY_DIR" \
	-g "*.php" \
	-g "!dist/**" \
	-g "!tests/**"; then
	echo "Salesforce token persistence or credential logging detected." >&2
	exit 1
fi

if ! rg -q "'redirection' => 0" "$REPOSITORY_DIR/salesforce/authentication.php" "$REPOSITORY_DIR/salesforce/client.php"; then
	echo "Salesforce HTTP clients must disable redirects." >&2
	exit 1
fi

public_routes="$(rg -c "'permission_callback' => '__return_true'" "$REPOSITORY_DIR/rest/routes.php" || true)"
if [[ "$public_routes" != "2" ]]; then
	echo "Expected exactly two explicitly public REST routes." >&2
	exit 1
fi

if ! rg -q 'wfcc_verify_stripe_signature\(\$payload, \$signature, \$secret\)' "$REPOSITORY_DIR/rest/routes.php"; then
	echo "Stripe webhook route is missing raw-body signature verification." >&2
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
	if unzip -Z1 "$REPOSITORY_DIR/dist/wfc-cart.zip" | rg -q "/(compatibility|ia|assets)/"; then
		echo "Removed directories included in release package." >&2
		exit 1
	fi
fi

echo "WFC Cart static security checks passed."
