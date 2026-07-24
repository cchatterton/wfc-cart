# Phase 3 — Security and Structural Modernisation

## Product positioning

WFC Cart is an independent donation and transaction platform. Public
documentation, administration, runtime code, and release notes describe WFC
Cart solely through its own responsibilities.

## What changed

- Established the WFC-native runtime for new installations and upgrades.
- Added direct-access guards across packaged PHP modules.
- Added a protected WFC transaction, line-item, batch, and fund-code model.
- Added WFC-specific capabilities for settings, records, delivery operations,
  and sensitive data.
- Added fixed allow-list sanitisation for WFC settings.
- Added safe redirect validation and secret-source precedence.
- Added dependency health reporting without exposing secret values.
- Added WFC-prefixed Gravity Forms cart and checkout roles.

## Capability impact

WFC Cart settings, transaction administration, Gravity Forms designation,
delivery queue visibility, health reporting, and package building are
available through WFC-native modules.

## Standards applied

- WFC Cart implementation specification.
- General Codex Development Standards.
- WordPress Plugin Build Standard.
- WordPress Plugin GitHub Update Standard.
- WordPress nonce, capability, sanitisation, escaping, safe-redirect, session,
  and file-upload controls.

## Tests performed

- PHP syntax checks across source and tests.
- Native bootstrap smoke test.
- Static checks for direct-access guards and unauthenticated AJAX actions.
- Shell syntax validation for build and security scripts.
- Release ZIP build, integrity test, top-level-folder check, exclusion check,
  and local/root ZIP checksum comparison.

## Migration impact

The WFC option schema advances without importing, rewriting, or deleting
transaction, form, user, or external-service data.

## Known limitations

- Full WordPress, Gravity Forms, WooCommerce, and event integration fixtures
  are not present in the repository.
- Stripe payment collection, signed webhooks, and reconciliation are Phase 4.
- Salesforce authentication, delivery, retry, and reconciliation are Phase 5.

## Security implications

Packaged PHP files reject direct execution. WFC administration requires
plugin-specific capabilities, settings use fixed sanitisation, and secret
values are not rendered after save.

## Next phase

Phase 4 replaces payment placeholders with Stripe-hosted fields,
PaymentIntents, SetupIntents, verified webhooks, idempotency, and
payment-state reconciliation.
