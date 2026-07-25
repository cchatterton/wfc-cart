# Phase 9 implementation report

Date: 25 July 2026
Release: 0.9.0

## Outcome

WFC Cart can now operate with Salesforce as the CRM or with donor CRM data
retained in WordPress. In WordPress mode, the single protected Gravity Forms
cart entry is the sole WFC-managed donor PII record.

## Delivered

- Explicit Salesforce and WordPress CRM data-location modes.
- Safe schema 9 inference that preserves configured Salesforce integrations
  and moves unconfigured sites to WordPress mode.
- Conditional Salesforce queueing, reconciliation, scheduling, administration,
  health, and readiness.
- One-off WordPress-mode checkout with recurring packages blocked until a
  supported recurring-payment owner exists.
- Generic CRM mode/state reporting and privacy-safe transaction-to-entry
  navigation.
- Operational metadata PII guards and opaque-reference validation.
- Removal of Salesforce Contact ID retention and known historical
  WFC-prefixed donor metadata copies.
- Non-donor Salesforce delivery fingerprints.
- Phase 9 contracts covering both modes, migration, privacy boundaries,
  readiness, recurring restrictions, and export fields.

## Preserved capability

Stripe checkout, Gravity Forms entries, protected transactions, receipts,
reports, exports, imports, batches, line items, Salesforce delivery, webhook
reconciliation, release governance, and GitHub updates remain available in
their applicable mode.

## Known boundary

WordPress mode does not orchestrate subsequent recurring payments. Adding a
WordPress-owned recurring engine would require a separate specification and
security review.
