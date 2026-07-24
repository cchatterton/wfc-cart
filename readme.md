# WFC Cart

Author: AlphaSys  
Version: 0.3.0
Status: Development

## Purpose

WFC Cart is a WordPress and Gravity Forms donation and transaction platform.
It separates website-owned campaign content from secure checkout, uses
Stripe-hosted payment collection, and delivers controlled transaction data to
Salesforce through server-to-server integration.

## Current phase

WFC Cart now has a fully independent runtime, data model, administration area,
settings, Gravity Forms roles, assets, and release process. Stripe payment and
Salesforce delivery remain under development and must be integration-tested
before live checkout is enabled.

## Key features

- WFC-native settings, capabilities, dashboard, health, and delivery queue
  screens.
- WFC-prefixed Gravity Forms cart and checkout roles.
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
- `checkout/`, `stripe/`, `salesforce/`, and `rest/`: reserved for approved
  phase-specific modules.
- `scripts/` and `styles/`: scoped WFC assets.
- `docs/`: baseline, decisions, phase reports, and future setup guides.

## Important notes

- Back up the database and files before deploying checkout changes.
- Activation creates WFC settings and capabilities without importing data from
  another plugin.
- `WFCC_STRIPE_SECRET_KEY`, `WFCC_STRIPE_WEBHOOK_SECRET`, and
  `WFCC_SALESFORCE_CLIENT_SECRET` may be defined in `wp-config.php` or as
  environment variables.
- The public release repository is `cchatterton/wfc-cart`. Define
  `WFCC_GITHUB_OWNER` only when maintaining an authorised downstream fork.

## Build

Run:

```bash
./scripts/build-plugin-zip.sh
```

The build creates `dist/wfc-cart.zip` and the matching root
`wfc-cart.zip`. Both contain `wfc-cart/` as the top-level plugin folder.

## Future considerations

The next phase adds Stripe PaymentIntent/SetupIntent checkout, verified
webhooks, idempotency, and payment-state reconciliation. Later phases add the
Salesforce OAuth client, fixed payload, persistent delivery/retry processing,
and representative integration testing.
