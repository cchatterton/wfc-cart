# Phase 5 implementation report

Date: 24 July 2026
Release: 0.5.0

## Outcome

Phase 5 adds a complete WFC-native Salesforce delivery layer. Successful Stripe
transactions are queued independently from checkout and sent server-to-server
through a fixed Apex REST contract.

## Delivered

- External Client App OAuth client-credentials authentication.
- Dedicated configuration precedence for constants, environment variables, and
  saved settings.
- Strict Salesforce HTTPS origin and endpoint-path validation.
- Memory-only OAuth access tokens and redirect-disabled HTTP calls.
- Fixed payload version `1.0` and fixed response validation.
- Stable transaction-key headers and response correlation.
- Allow-listed Gravity Forms mapping targets, transformations, constants,
  equality conditions, metadata, and required fields.
- Persistent outbox state on protected WFC transactions.
- Attempt count, last/next attempt, error category, HTTP status, payload hash,
  payload version, record references, and reconciliation status.
- Deterministic exponential backoff with bounded batches and stale-safe locks.
- Retryable versus intervention-required error handling.
- Five-minute WP-Cron processing and nonce-protected manual retry.
- Stripe refund, dispute, and cancellation reconciliation.
- Connection test, health check, and delivery queue operational controls.

## Privacy and security decisions

- No Salesforce call is made from browser code.
- The browser cannot choose a Salesforce object, field API name, or endpoint.
- OAuth access tokens are not written to options, transients, post metadata,
  logs, analytics, cookies, or Gravity Forms entries.
- Donor data remains in Gravity Forms and is assembled only while a delivery is
  being attempted.
- Stored operational errors exclude response bodies and credentials.
- Salesforce record references remain on protected transaction records.

## Verification

- All PHP files pass syntax checks on PHP 8.1-compatible syntax.
- Bootstrap and Phase 4 regression tests pass.
- Phase 5 contract tests cover URL boundaries, controlled mapping,
  transformations, required idempotent correlation, record references,
  retry classification, backoff, and currency conversion.
- Static security checks reject public AJAX actions, removed-product
  references, credential logging, access-token persistence, and redirectable
  Salesforce HTTP calls.
- The release ZIP is built from an allow-listed directory set and inspected
  before publication.

## Remaining external work

The target Salesforce organisation must provide the versioned Apex REST
implementation and its fixed response contract. End-to-end sandbox validation
requires organisation-specific credentials, field permissions, and data-model
behaviour.

## Next phase

Phase 6 should focus on WFC-native operational completeness and deployment
hardening: receipts, reporting, imports/exports, batch workflows, supported
commerce/event scenarios, accessibility, performance, upgrade, and rollback
validation.
