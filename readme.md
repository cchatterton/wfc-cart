# WFC Cart

Author: AlphaSys
Version: 0.5.0
Status: Development

## Purpose

WFC Cart is a WordPress and Gravity Forms donation and transaction platform.
It separates website-owned campaign content from secure checkout, uses
Stripe-hosted payment collection, and delivers controlled transaction data to
Salesforce through server-to-server integration.

## Current phase

WFC Cart now includes the Phase 4 Stripe checkout and Phase 5 Salesforce
delivery pipeline. Completed payments are queued for server-to-server delivery
through a fixed, versioned Apex REST contract with controlled field mapping,
idempotency, retries, and reconciliation.

## Key features

- WFC-native settings, capabilities, dashboard, health, and delivery queue
  screens.
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
- WooCommerce and supported event integrations when their WFC modules are
  implemented and enabled.

## Folder structure

- `wfc-cart.php`: plugin metadata, constants, includes, and lifecycle hooks.
- `functions/`: setup, data model, capabilities, dependencies, assets, helpers,
  and GitHub updater.
- `gravity-forms/`: WFC Cart form roles and Gravity Forms integration.
- `admin/`: WFC-native administration screens.
- `checkout/`: checkout packages and protected transaction state.
- `stripe/`: fixed Stripe API client, intent orchestration, and webhooks.
- `rest/`: public intent and webhook routes with internal security controls.
- `salesforce/`: authentication, controlled mapping, fixed payload, delivery
  outbox, retries, and reconciliation.
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

See `docs/stripe-setup.md`, `docs/gravity-forms-checkout.md`, and
`docs/salesforce-setup.md`. Complete the staging matrix before enabling live
credentials.

## Next phase

Phase 6 will focus on WFC-native operational completeness and deployment
hardening without introducing dependencies on another cart or CRM plugin.
