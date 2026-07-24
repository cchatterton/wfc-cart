# Phase 6 implementation report

Date: 24 July 2026
Release: 0.6.0

## Outcome

Phase 6 adds a WFC-native operational layer around the secure checkout and
Salesforce pipeline. It introduces no runtime dependency on another cart,
commerce, event, or CRM plugin.

## Delivered

- Fixed, idempotent transaction line items with bounded values and approved
  types.
- Primary donation line-item creation before Salesforce queueing.
- Salesforce payload version `1.1` with at most 100 fixed-schema line items.
- Deterministic receipt records with optional plain-text email and manual
  resend.
- No duplication of receipt recipient email into transaction metadata.
- Bounded operational reports and per-currency original totals.
- Privacy-minimised, formula-safe CSV exports.
- Validation-first metadata imports restricted to fund code and external
  reference.
- Immutable transaction batches with eligibility checks, bounded size, audit
  metadata, and a stale-safe creation lock.
- Dedicated capabilities for reports, exports, imports, batches, and receipts.
- Dashboard payment-success and receipt metrics.
- Scheduled-processing and receipt configuration health checks.

## Safety boundaries

- Imports cannot create transactions or alter financial state.
- Exports exclude donor details and reusable payment identifiers.
- Receipt email remains disabled after upgrade until explicitly configured.
- Receipt recipients are read from Gravity Forms only at send time.
- Reports and exports are limited to 366 days and 5,000 transactions.
- Imports are limited to 500 rows and 256 KB.
- Batches are limited to 500 eligible transactions and cannot reuse a
  previously batched transaction.
- Line items require an idempotent source key and only adjustments may be
  negative.
- Every state-changing administration action checks a dedicated capability and
  nonce.

## Verification

- PHP syntax checks pass across the packaged source.
- Bootstrap and Phase 4/5 regression contracts pass.
- Phase 6 contracts cover date bounds, line-item schemas, negative-amount
  restrictions, report and batch totals, receipt numbers, export privacy, CSV
  formula neutralisation, import header restrictions, and duplicate rows.
- Static checks cover operational capabilities/nonces, recipient persistence,
  public AJAX actions, access-token persistence, and removed-product
  references.
- The release ZIP is inspected and checksum-verified after publication.

## Remaining production validation

- WordPress mail delivery depends on the site's configured mail transport.
- Salesforce must accept payload schema `1.1`.
- Adapter-specific product, event, shipping, tax, and authoritative source
  behaviour must be tested by each server-side adapter.
- Production-like accessibility, performance, caching, multisite, upgrade, and
  rollback exercises remain the focus of Phase 7.
