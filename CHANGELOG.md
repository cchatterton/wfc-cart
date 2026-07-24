# Changelog

All notable changes to WFC Cart are recorded here.

## 0.5.0 - 2026-07-24

- Added Salesforce External Client App OAuth client-credentials
  authentication with strict Salesforce HTTPS origin validation.
- Added a fixed, versioned Apex REST request and response contract with stable
  transaction-key idempotency.
- Added controlled Gravity Forms field mapping, allow-listed transformations,
  constants, conditions, metadata, and required-value validation.
- Added a persistent transaction outbox with delivery states, attempt history,
  deterministic exponential backoff, retry classification, and a configurable
  maximum attempt threshold.
- Added Stripe state reconciliation, Salesforce record references, connection
  diagnostics, health status, queue visibility, and nonce-protected manual
  retries.
- Added five-minute WordPress-native queue scheduling and schema upgrade logic.
- Added Phase 5 contract tests and Salesforce setup/operator documentation.

## 0.4.0 - 2026-07-24

- Added Stripe Payment Element integration to designated Gravity Forms
  checkout forms.
- Added fixed-schema server-owned packages with approved amounts, currency,
  recurrence, consent mapping, attribution, and thank-you routing.
- Added PaymentIntent, recurring initial payment, Stripe Customer, and
  SetupIntent orchestration.
- Added protected transaction correlation, request idempotency, replay
  protection, and intent-creation rate limiting.
- Added raw-body Stripe webhook signature verification, event allow-listing,
  deduplication, and guarded state reconciliation.
- Added server-to-server intent verification before Gravity Forms entry
  acceptance.
- Added Phase 4 contract tests and Stripe/Gravity Forms setup guides.

## 0.3.0 - 2026-07-24

- Removed all third-party cart and CRM integration code from the WFC Cart
  runtime and release package.
- Removed inherited hooks, prefixes, options, form builders, administration
  modules, assets, migration tooling, and documentation.
- Replaced form designation with WFC-prefixed Gravity Forms settings.
- Retained a WFC-native protected transaction data model and administration.
- Simplified activation, settings, dependencies, tests, and packaging to WFC
  responsibilities only.
