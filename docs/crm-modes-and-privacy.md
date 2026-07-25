# CRM modes and donor-data privacy

WFC Cart 0.9.0 supports two explicit CRM data locations.

## WordPress mode

Select **WordPress — single Gravity Forms entry** under **WFC Cart → Settings
→ General**.

For each completed checkout:

1. Gravity Forms creates one protected cart entry containing the configured
   donor fields.
2. WFC Cart stores the entry ID on its protected transaction.
3. WFC Cart retains non-PII operational information required for payment
   recovery, receipts, reconciliation, batching, and aggregate reporting.
4. WFC Cart does not create a Salesforce outbox record or schedule Salesforce
   delivery.

The following belong only in the Gravity Forms entry:

- donor name;
- email and phone;
- address;
- donor-entered notes or free text;
- consent evidence containing donor-entered content; and
- client-specific personal fields.

WFC operational records may retain:

- opaque transaction and Gravity Forms entry IDs;
- Stripe operational object IDs needed for payment reconciliation;
- amount, currency, frequency, package, campaign, and fund codes;
- payment, receipt, batch, and generic CRM state;
- timestamps and bounded error categories; and
- opaque external references that contain no donor information.

WordPress mode supports one-off payment packages. Recurring and SetupIntent
packages are not available because this release has no WordPress-owned
subsequent-payment orchestrator.

## Salesforce mode

Select **Salesforce CRM** when Salesforce owns donor matching, gifts, and
recurring-payment orchestration. WFC Cart reads donor values from the Gravity
Forms entry only when building the in-memory server-to-server payload.

The delivery queue stores no payload body or donor field values. Its stored
fingerprint excludes donor fields and mapped metadata. Salesforce Contact IDs
are not copied back into WordPress.

## Upgrade behaviour

Schema 9 assigns the mode once:

- an existing site with both Salesforce Client ID and Client Secret resolves
  to Salesforce mode; and
- an unconfigured existing site resolves to WordPress mode.

The schema upgrade removes known historical WFC-prefixed donor metadata copies,
including the previously stored Salesforce Contact ID. It does not remove the
authoritative Gravity Forms entry or any financial, receipt, payment, batch, or
gift record.

Fresh installations default to WordPress mode. A managed environment may
define:

```php
define('WFCC_CRM_MODE', 'wordpress');
```

or:

```php
define('WFCC_CRM_MODE', 'salesforce');
```

The constant overrides the saved setting. Reactivate the plugin or resave the
General settings after changing a deployment constant so the schedule is
synchronised.

## Gravity Forms access and retention

Authorised CRM users need the appropriate Gravity Forms entry-view capability
in addition to WFC transaction capabilities. WFC Cart does not weaken Gravity
Forms access controls.

Configure Gravity Forms retention and erasure policies to match the
organisation's legal requirements. Deleting the authoritative Gravity Forms
entry will leave the PII-free financial transaction record intact but removes
WFC Cart's ability to resend a receipt or later deliver that donor record to
Salesforce.

Do not place donor data in transaction titles, line-item labels, operational
imports, logs, release evidence, or campaign/package configuration.
