# Deployment and rollback

## Before deployment

1. Back up the WordPress database and plugin directory.
2. Confirm WordPress 6.4 or later and PHP 8.1 or later.
3. Test Gravity Forms, Stripe, and Salesforce in non-production environments.
4. Confirm the Salesforce endpoint accepts payload schema `1.1`.
5. Confirm WP-Cron runs, or configure an external request to `wp-cron.php` when
   `DISABLE_WP_CRON` is enabled.
6. Record the currently installed WFC Cart version and release ZIP checksum.

## Upgrade

Install `wfc-cart.zip` from the immutable GitHub release. Schema 7:

- preserves transactions, settings, entries, delivery state, and external
  records;
- adds operational capabilities to administrators;
- enables receipt-number generation for future successful checkouts;
- leaves automatic receipt email disabled; and
- adds an empty trusted-proxy allow-list;
- records the previous and current schema versions in a privacy-safe upgrade
  journal; and
- does not backfill receipts, batches, line items, imports, or exports.

After upgrade, visit **WFC Cart → Health** and run the checkout, Salesforce,
receipt, export, import-failure, and batch staging checks.

## Rollback

Rollback is a plugin-code operation, not a data deletion operation:

1. Disable automatic receipt email.
2. Allow in-flight requests to finish.
3. Deactivate WFC Cart.
4. Restore the previous immutable release ZIP.
5. Reactivate WFC Cart.
6. Confirm its scheduled events and health checks.
7. Re-run a test-mode checkout.

WFC Cart preserves schema 7 metadata when older code is restored. Versions
0.5.0 and 0.6.0 ignore newer receipt, line-item, import-reference, batch,
trusted-proxy, readiness, and upgrade-journal metadata they do not understand.
Do not delete that metadata during rollback.

If Salesforce has already accepted payload version `1.1`, downstream records
remain authoritative and must not be deleted as part of a WordPress rollback.
