# Changelog

All notable changes to WFC Cart are recorded here.

## 0.9.0 - 2026-07-25

- Added an explicit CRM data-location setting with Salesforce and WordPress
  operating modes.
- Made Salesforce configuration, delivery scheduling, health, queueing, and
  production-readiness checks conditional on Salesforce CRM mode.
- Defaulted fresh installations and existing unconfigured sites to WordPress
  mode while preserving Salesforce mode for sites with complete existing
  credentials.
- Retained donor PII only in the single protected Gravity Forms cart entry in
  WordPress mode; transaction, line-item, reporting, export, receipt, and audit
  records remain operational and PII-free.
- Added operational metadata write guards, removed Salesforce Contact ID
  persistence and known legacy donor-field metadata copies, restricted
  imported references to opaque non-PII identifiers, and excluded donor fields
  from stored delivery fingerprints.
- Added CRM mode/state reporting and privacy-safe links from transactions to
  the authoritative Gravity Forms cart entry.
- Prevented recurring and SetupIntent packages in WordPress mode until a
  supported downstream recurring-payment owner is configured.
- Added schema 9 migration, Phase 9 contracts, documentation, and release
  validation coverage.

## 0.8.0 - 2026-07-24

- Added five ordered production release gates for external staging, independent
  security review, independent accessibility review, production pilot, and
  final approval.
- Bound approvals to the exact plugin and schema version so earlier evidence
  cannot silently approve a later release.
- Required a current no-blocker technical audit before staging or final release
  approval.
- Added dedicated release-approval capability, protected approval, rejection,
  and revocation actions, and independent-review attestations.
- Added an append-only SHA-256 hash chain for release decisions with
  fail-closed integrity validation.
- Added a privacy-minimised, checksummed JSON evidence export.
- Added Phase 8 contract tests and a repeatable local and GitHub Actions release
  validation workflow.
- Made release ZIP creation byte-for-byte reproducible with normalised metadata
  and a two-build checksum assertion.
- Documented evidence handling, gate order, pilot limits, rollback criteria,
  segregation of duties, and the rule that installing this release does not
  constitute production approval.

## 0.7.0 - 2026-07-24

- Added an executable production-readiness audit covering platform versions,
  HTTPS, schema, dependencies, integrations, packages, cron, receipt email,
  proxy handling, REST boundaries, and site lifecycle.
- Added explicit no-store/nosniff headers to every WFC REST response, including
  errors, plus fixed checkout and webhook request-size limits.
- Added trusted proxy CIDR validation and right-to-left forwarded-address
  resolution that ignores spoofed client-supplied hops.
- Added multisite-aware network activation, deactivation, bounded site
  iteration, context restoration, and new-site initialisation.
- Added an idempotent schema 6-to-7 upgrade with a privacy-safe upgrade journal.
- Improved checkout accessibility with localised state messages, error focus,
  submit disabled states, reduced-motion support, and accessible admin table
  captions.
- Added amount-change debouncing, stale intent-response suppression, explicit
  browser no-store requests, and safer non-JSON error handling.
- Added server-side Gravity Forms amount revalidation so a changed visible
  amount cannot be submitted against an earlier prepared intent.
- Added Phase 7 proxy, caching, readiness, multisite, activation, upgrade, and
  rollback contract tests.

## 0.6.0 - 2026-07-24

- Added fixed, idempotent transaction line items for donation, product, event,
  shipping, fee, and adjustment sources.
- Added optional line items to Salesforce payload contract version `1.1`.
- Added deterministic receipt records, optional plain-text receipt email,
  manual resend controls, and privacy-minimised delivery metadata.
- Added bounded operational reporting with payment, Salesforce, receipt, batch,
  and per-currency original-total metrics.
- Added formula-safe CSV exports that exclude donor details and reusable
  payment identifiers.
- Added validation-first operational metadata imports that cannot create
  transactions or alter payment amounts or states.
- Added immutable, stale-lock-protected transaction batch creation with
  per-currency totals.
- Added dedicated operational capabilities, scheduled-processing health,
  Phase 6 contract tests, and deployment/rollback documentation.

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
