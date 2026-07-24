# Production validation

WFC Cart 0.8.0 converts configuration and runtime prerequisites into an
executable readiness audit, then defines the external scenarios that still
require staging evidence.

## Readiness audit

Run **WFC Cart → Production Readiness → Run and save readiness audit**.

Blocking checks:

- PHP 8.1 or later;
- WordPress 6.4 or later;
- WordPress recognises HTTPS;
- WFC schema 8 completed;
- Gravity Forms available;
- Stripe publishable and secret keys configured;
- Stripe webhook signing secret configured;
- Salesforce OAuth and fixed endpoint configured;
- at least one checkout package configured; and
- WFC REST cache and request-size boundaries loaded.

Warnings:

- WP-Cron disabled or the delivery schedule missing;
- automatic receipt email enabled without a Gravity Forms field; and
- an `X-Forwarded-For` header present when the immediate proxy address is not
  in the trusted CIDR list.

The saved audit contains only time, WFC version/schema, aggregate counts, and
check states. It stores no credentials, endpoint responses, donor details, or
client addresses.

## Reverse proxies and CDNs

Checkout rate limiting uses `REMOTE_ADDR` by default. If WordPress is behind a
known reverse proxy or load balancer, configure its exact addresses or CIDRs
under **WFC Cart → Settings → Advanced → Trusted proxy CIDRs**.

Forwarded addresses are ignored unless `REMOTE_ADDR` belongs to that list.
When multiple approved proxies are present, WFC Cart walks the forwarding chain
from the nearest hop and selects the first untrusted address. A spoofed
leftmost address cannot override that nearer client hop.

Examples:

```text
203.0.113.5/32
10.20.0.0/16
2001:db8:1234::/48
```

Universal and excessively broad networks are rejected. The minimum accepted
prefix is `/8` for IPv4 and `/16` for IPv6.

All `/wfc-cart/v1/` responses include:

```text
Cache-Control: no-store, no-cache, must-revalidate, private
Pragma: no-cache
Expires: 0
X-Content-Type-Options: nosniff
```

The intent route accepts at most 16 KB and the signed Stripe webhook route at
most 1 MB. Configure the CDN to honour origin no-store headers and never cache
POST requests.

## Multisite lifecycle

Network activation initialises settings, capabilities, and schedules for every
existing site using 100-site query batches. Network deactivation removes WFC
scheduled events from every site without deleting settings or records. A site
created while the plugin is network active is initialised automatically.

For large networks, perform activation in a maintenance window and verify the
readiness audit on representative sites with distinct domains and credentials.

## Staging scenario matrix

Record date, environment, tester, result, transaction key, and non-sensitive
evidence for each scenario.

### Checkout and payment

1. One-off successful contribution.
2. Bounded custom amount.
3. Rejected amount below and above configured limits.
4. Recurring initial payment and explicit consent.
5. SetupIntent flow where configured.
6. 3DS authentication.
7. Declined card.
8. Browser refresh during checkout.
9. Double-click and duplicate form submission.
10. Amount changed repeatedly before submission.
11. Amount changed immediately before submit; confirm the server rejects an
    intent prepared for a different visible amount.
12. Mobile viewport and keyboard-only completion.

### Webhooks and Salesforce

1. Valid Stripe webhook.
2. Invalid signature and expired timestamp.
3. Duplicate and delayed webhook.
4. Partial and full refund.
5. Cancellation and dispute.
6. Temporary Salesforce 429/5xx with retry.
7. Salesforce validation failure with manual recovery.
8. Duplicate Salesforce transaction key.
9. Payload schema `1.1` with zero, one, and multiple line items.

### Operations

1. Receipt generation with automatic email disabled.
2. Successful receipt email and manual resend.
3. Invalid receipt email field.
4. Report and export at their maximum date/record bounds.
5. Spreadsheet formula text in an imported external reference.
6. Import containing one unknown transaction key; confirm no row changes.
7. Concurrent batch creation; confirm transactions appear once.
8. Repeated line-item source key; confirm one item.

### Infrastructure and lifecycle

1. CDN/reverse proxy honours no-store headers.
2. Untrusted forwarding header is ignored.
3. Approved single and chained proxies resolve the client rate-limit identity.
4. WP-Cron and external cron configurations.
5. Object cache enabled.
6. Page cache enabled.
7. Network activation on multisite.
8. New multisite blog after network activation.
9. Schema 6 to 7 upgrade.
10. Deactivation/reactivation.
11. Rollback to the previous immutable release and forward upgrade again.

## Accessibility checks

- All checkout actions are operable by keyboard.
- Visible focus remains clear.
- Payment preparation and confirmation expose status changes.
- Errors receive focus and are announced once.
- Disabled submit controls communicate their state.
- Zoom to 200% does not obscure checkout actions.
- Reduced-motion preference does not introduce essential motion.
- Admin data tables expose meaningful captions and header relationships.
- Colour is not the only indicator of readiness or delivery state.

## Performance checks

- Verify checkout assets load only on designated forms.
- Confirm no WFC REST POST response is cached.
- Confirm amount-change debouncing prevents rapid client remounts.
- Observe intent, webhook, Salesforce, reports, exports, and batch queries under
  production-like transaction volume.
- Confirm reports/exports stop at 5,000 records, batches at 500, imports at 500,
  Salesforce payloads at 100 line items, and queue processing at its configured
  bounded batch size.

The executable audit is a prerequisite, not a substitute, for this staging
matrix. Record the controlled evidence reference for the completed matrix under
**WFC Cart → Production Readiness → Phase 8 release governance**. Do not paste
credentials, payment data, donor data, access tokens, or raw integration
payloads into a release decision.

## Exit criteria

The staging gate may be approved only when:

- the saved technical audit is for the exact installed version and schema;
- it contains no blocking findings;
- every applicable scenario above has a dated result;
- failed scenarios have a linked disposition and retest;
- the evidence set contains no secrets or donor data; and
- the rollback rehearsal has completed successfully.

Warnings in the technical audit must be explicitly addressed in the staging
evidence. They do not automatically block the gate, but the approving reviewer
owns the documented risk decision.
