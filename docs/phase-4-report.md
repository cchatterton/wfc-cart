# Phase 4 — Stripe modernisation

## Delivered

- Stripe-hosted Payment Element fields on designated Gravity Forms checkouts.
- Server-owned checkout packages with fixed schema, amount controls, currency,
  recurrence, attribution, consent mapping, and approved completion routing.
- PaymentIntent processing for one-off and initial recurring payments.
- Stripe Customer creation and `setup_future_usage=off_session` for recurring
  first payments.
- SetupIntent processing for payment-method-only flows.
- Locally correlated protected transactions without stored client secrets.
- Per-request Stripe idempotency keys, local replay protection, and
  per-address intent-creation rate limiting.
- Raw-body Stripe webhook HMAC verification, event allow-listing, event-ID
  deduplication, and guarded state transitions.
- Server-to-server intent verification before Gravity Forms accepts an entry.
- Safe frontend funnel events containing package reference only.

## Verification

- PHP syntax validation across source and tests.
- WordPress-free bootstrap smoke test.
- Webhook signature, timestamp, amount, package, and event allow-list contract
  tests.
- Static direct-access, public-route, signature-verification, removed-product,
  and package-content checks.
- Release ZIP integrity, top-level-folder, exclusion, and checksum checks.

## Integration boundary

The repository does not contain live Stripe credentials or a full
WordPress/Gravity Forms browser fixture. A staging site must still exercise
test-mode success, decline, 3DS, recurring consent, SetupIntent, retry,
webhook replay, refund, dispute, AJAX form, and thank-you scenarios before
live mode is enabled.

## Next phase

Phase 5 adds the fixed Salesforce server-to-server payload, OAuth client,
delivery outbox, retries, reconciliation, and operational controls.
