# WFC Cart

Author: AlphaSys
Version: 0.9.0
Status: Release candidate

## Purpose

WFC Cart is a WordPress and Gravity Forms donation and transaction platform.
It separates website-owned campaign content from secure checkout, uses
Stripe-hosted payment collection, and supports either Salesforce CRM delivery
or privacy-minimised WordPress retention in the single Gravity Forms cart
entry.

## Current phase

Phase 9 adds an explicit CRM data-location choice. Salesforce mode retains the
server-to-server delivery workflow. WordPress mode keeps donor PII only in the
single protected Gravity Forms cart entry while WFC records retain operational
identifiers, financial state, receipts, and aggregate reporting data.

## Key features

- WFC-native settings, capabilities, dashboard, health, and delivery queue
  screens.
- Selectable Salesforce or WordPress CRM data location, with safe upgrade
  inference for existing installations.
- Gravity Forms-entry-only donor PII retention in WordPress mode, enforced by
  operational metadata guards and PII-free reporting/export contracts.
- WFC-prefixed Gravity Forms cart and checkout roles.
- Stripe Payment Element fields; card data is sent directly to Stripe.
- Server-approved checkout packages and bounded donor-entered amounts.
- PaymentIntent and SetupIntent processing with recurring Customer setup.
- Verified, deduplicated webhooks and guarded transaction-state transitions.
- Salesforce External Client App authentication using OAuth client credentials.
- Fixed Apex REST payload and response contracts with stable transaction keys.
- Controlled Gravity Forms mapping into approved WFC payload targets.
- Persistent delivery state, bounded retries, manual recovery, and Stripe-state
  reconciliation.
- Fixed, idempotent transaction line items for donations, products, events,
  shipping, fees, and adjustments.
- Deterministic receipt records with optional plain-text email delivery.
- Bounded operational reports and privacy-minimised, formula-safe CSV exports.
- Validation-first metadata imports that cannot create transactions or change
  financial state.
- Immutable transaction batches with per-currency original totals.
- Explicit no-store headers and fixed body-size limits on WFC REST routes.
- Trusted-proxy CIDR handling for per-client checkout rate limiting.
- Multisite-aware network activation, deactivation, and new-site setup.
- Executable production-readiness checks with a safe audit snapshot.
- Ordered, version-bound release gates with independent-review attestations.
- Append-only, hash-chained approval history and checksummed JSON evidence
  export.
- Automated pull-request, main-branch, and release-tag validation through
  GitHub Actions.
- Localised checkout states, focused errors, submit-state controls, input
  debouncing, and reduced-motion support.
- Protected WordPress-native transaction, line-item, batch, and fund-code
  records.
- Direct-access protection across packaged PHP modules.
- Secret precedence through wp-config.php constants, environment variables,
  then saved WordPress settings.
- WordPress-native GitHub release update checks using an immutable
  `wfc-cart.zip` release asset.
- No deletion of user data on deactivation or uninstall.

## Requirements

- WordPress 6.4 or later.
- PHP 8.1 or later.
- Gravity Forms for donation and checkout presentation.
- Salesforce is optional for one-off payments when WordPress CRM mode is
  selected.
- Recurring and SetupIntent packages require Salesforce CRM mode in this
  release.
- Optional server-side adapters may add product, event, or shipping line items
  through the fixed WFC line-item contract.

## Folder structure

- `wfc-cart.php`: plugin metadata, constants, includes, and lifecycle hooks.
- `functions/`: setup, data model, capabilities, dependencies, assets, helpers,
  CRM/privacy boundaries, runtime/readiness hardening, and GitHub updater.
- `gravity-forms/`: WFC Cart form roles and Gravity Forms integration.
- `admin/`: WFC-native administration screens.
- `checkout/`: checkout packages and protected transaction state.
- `stripe/`: fixed Stripe API client, intent orchestration, and webhooks.
- `rest/`: public intent and webhook routes with internal security controls.
- `salesforce/`: authentication, controlled mapping, fixed payload, delivery
  outbox, retries, and reconciliation.
- `operations/`: line items, receipts, reporting, CSV import/export, and
  immutable batches.
- `scripts/` and `styles/`: scoped WFC assets.
- `docs/`: baseline, decisions, phase reports, and future setup guides.

## Important notes

- Back up the database and files before deploying checkout changes.
- Activation creates WFC settings and capabilities without importing data from
  another plugin.
- `WFCC_STRIPE_SECRET_KEY`, `WFCC_STRIPE_WEBHOOK_SECRET`,
  `WFCC_SALESFORCE_LOGIN_URL`, `WFCC_SALESFORCE_CLIENT_ID`,
  `WFCC_SALESFORCE_CLIENT_SECRET`, and `WFCC_SALESFORCE_API_PATH` may be
  defined in `wp-config.php` or as environment variables.
- `WFCC_CRM_MODE` may be defined as `wordpress` or `salesforce` to enforce the
  mode in a managed environment.
- Salesforce access tokens are retained only in memory for the current PHP
  request and are never saved to WordPress.
- The public release repository is `cchatterton/wfc-cart`. Define
  `WFCC_GITHUB_OWNER` only when maintaining an authorised downstream fork.

## Build

Run:

```bash
./scripts/build-plugin-zip.sh
```

The build creates `dist/wfc-cart.zip` and the matching root
`wfc-cart.zip`. Both contain `wfc-cart/` as the top-level plugin folder.

## Checkout setup

See `docs/stripe-setup.md`, `docs/gravity-forms-checkout.md`,
`docs/crm-modes-and-privacy.md`, `docs/salesforce-setup.md`,
`docs/operations-guide.md`,
`docs/production-validation.md`, and `docs/release-governance.md`. Complete the
staging matrix and every release gate before enabling general production use.

## Production approval

Version 0.9.0 retains the Phase 8 governance controls. It is not automatically
approved for production: authorised reviewers must run the current technical
audit, attach non-sensitive evidence references, complete the bounded pilot,
and approve all five gates.
