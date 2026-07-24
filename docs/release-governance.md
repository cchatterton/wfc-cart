# Release governance

WFC Cart 0.8.0 provides the controls for Phase 8. Installing the release does
not approve it for production. Approval belongs to authorised people operating
the target staging and production environments.

## Gate order

Run and save **WFC Cart → Production Readiness** for the exact installed
version before recording the first decision.

1. **External staging validation** — complete the full production validation
   matrix in a representative environment and link its controlled evidence.
2. **Independent security review** — record a review performed independently
   of implementation and attest to that independence.
3. **Independent accessibility review** — record keyboard, zoom, focus,
   announcement, contrast, reduced-motion, and assistive-technology evidence
   and attest to reviewer independence.
4. **Production pilot approval** — approve only after the two independent
   reviews and a bounded pilot with named support and rollback owners.
5. **Final production release approval** — the authorised release owner accepts
   the complete evidence set for general production use.

An approval requires the reviewer or owner name and a non-sensitive evidence
reference. A rejection or revocation requires a decision note. Downstream
approvals become blocked if an upstream gate is later rejected or revoked.

## Evidence handling

Use a ticket, controlled document, or report identifier as the evidence
reference. Keep detailed evidence in the organisation's approved system of
record.

Never enter:

- Stripe keys, webhook secrets, payment method identifiers, or card data;
- Salesforce client secrets, access tokens, or raw authenticated responses;
- donor names, email addresses, form submissions, or transaction payloads;
- private infrastructure credentials or unrestricted log extracts.

The downloadable JSON contains the saved technical audit, governance journal,
environment hostname, and a SHA-256 checksum. It is intended for release
records and review, not as a secret store.

## Journal integrity

Release decisions are append-only. Each entry includes the previous entry hash
and its own SHA-256 hash. If an entry is changed or removed, WFC Cart fails the
journal integrity check and disables further approvals.

The hash chain is tamper-evident, not a digital signature. Protect the WordPress
database, restrict the `wfcc_approve_release` capability, export evidence after
each final decision, and retain it in a controlled external record system.

Previous-version entries remain in the journal but never approve a new plugin
or schema version. Run a fresh technical audit and repeat all five gates after
every release.

## Pilot controls

Before approving the production pilot, record:

- the site and release version;
- start and end time;
- transaction and volume limit;
- enabled payment frequencies and integrations;
- monitoring owner and support contact;
- rollback owner and decision threshold;
- reconciliation outcome for Stripe, WordPress, Salesforce, and receipts; and
- incident, retry, refund, and accessibility outcomes.

Stop and roll back when payment verification, webhook integrity, transaction
correlation, Salesforce idempotency, donor privacy, or checkout accessibility
cannot be confirmed.

## Segregation of duties

Only users with `wfcc_approve_release` can record decisions or export the
evidence bundle. Administrators receive this capability during activation or
schema upgrade. Remove it from accounts that should configure the plugin but
must not approve releases.

The people named for security and accessibility must be independent of the
implementation work. The final release owner should verify that independence
and confirm that all evidence references remain accessible.

## Automated validation

Run:

```bash
./scripts/validate-release.sh
```

It lints PHP, checks JavaScript syntax, runs all contract and static security
tests, rebuilds both release ZIP copies, verifies their equality, and rejects
development artifacts in the package. GitHub Actions runs the same command for
pull requests, `main`, and version tags.

Automated validation does not replace external staging, security,
accessibility, or pilot evidence.
