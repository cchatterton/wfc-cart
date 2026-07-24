# Phase 8 implementation report

Date: 24 July 2026
Release: 0.8.0

## Outcome

Phase 8 adds enforceable release governance without representing repository
tests as external production evidence. WFC Cart can now record, validate, and
export the approvals required to move from a release candidate to an approved
production release.

## Delivered

- Five ordered release gates covering external staging, independent security
  review, independent accessibility review, production pilot, and final
  approval.
- Exact plugin-version and schema binding for every decision.
- Current technical-audit prerequisite for staging and final approval.
- Dedicated `wfcc_approve_release` capability.
- Nonce-protected approval, rejection, revocation, and JSON export actions.
- Required reviewer and evidence references for approval.
- Required independence attestations for security and accessibility.
- Append-only SHA-256 hash-chained decision history with fail-closed integrity
  handling.
- Checksummed, privacy-minimised evidence export.
- Phase 8 regression contracts and repeatable local/GitHub Actions validation.
- Deterministic release ZIP generation with normalised archive metadata and a
  two-build reproducibility assertion.
- Release governance, evidence handling, pilot, rollback, and segregation-of-
  duties guidance.

## Validation boundary

The software controls and repository checks can be completed as part of this
release. Real staging transactions, organisation-specific infrastructure,
independent human reviews, and a production pilot cannot be truthfully approved
from the source repository. Those gates intentionally remain pending until
authorised reviewers record controlled evidence in the target environment.

## Completion condition

Phase 8 is operationally complete only when all five gates show `approved` for
the exact installed version and the exported evidence bundle is retained in the
organisation's release record system.
