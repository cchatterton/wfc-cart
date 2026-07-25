# Gravity Forms checkout setup

## 1. Select the CRM data location

Open **WFC Cart → Settings → General** and choose:

- **WordPress — single Gravity Forms entry** for one-off payment sites that
  keep donor PII only in Gravity Forms; or
- **Salesforce CRM** for server-to-server donor and gift delivery, including
  recurring-payment ownership.

In WordPress mode, the accepted checkout entry is the sole WFC-managed donor
PII record. Do not add donor fields to transaction titles, operational
references, line-item labels, imports, exports, or release evidence.

## 2. Configure a checkout package

Open **WFC Cart → Settings → Checkout** and add an object keyed by an opaque
package ID. Amounts are integer minor units.

```json
{
  "winter-monthly-50": {
    "enabled": true,
    "label": "Winter appeal monthly gift",
    "mode": "payment",
    "amount": 5000,
    "allowed_amounts": [2500, 5000, 10000],
    "allow_custom_amount": true,
    "minimum_amount": 500,
    "maximum_amount": 500000,
    "amount_field_id": 7,
    "consent_field_id": 12,
    "currency": "AUD",
    "frequency": "monthly",
    "recurring": true,
    "campaign": "winter-appeal",
    "fund": "general",
    "gift_type": "donation",
    "thank_you_url": "https://example.org/thank-you/"
  }
}
```

`mode` is `payment` for a PaymentIntent or `setup` for a SetupIntent.
`amount_field_id` is optional. When present, its donor-entered value is checked
against the allowed amounts or custom bounds on the server. A recurring package
must name a required consent field for future off-session payments.

The thank-you URL must use the site host or a host listed above the package
JSON in the Checkout settings.

WordPress mode supports one-off `payment` packages only. Recurring packages and
`setup` mode are unavailable until Salesforce CRM mode is selected.

## 3. Designate the form

In the Gravity Forms form settings:

1. Enable **Use as a WFC Cart checkout form**.
2. Set **Default checkout package** to the exact package ID.
3. Do not add a Gravity Forms credit-card field.
4. For recurring gifts, make the mapped consent field required and use clear
   language describing future amount, frequency, and cancellation terms.

WFC Cart inserts Stripe's Payment Element before the form submit button.

Each completed checkout must create one Gravity Forms entry. WFC Cart links the
protected transaction to that entry ID and does not copy its donor field
values.

## 4. Package links

The default package loads without a query parameter. An explicitly allowed
package can be selected with:

```text
/donate/?package=winter-monthly-50
```

Only the form's configured default package is allowed by default. Additional
package IDs can be allowed deliberately with the
`wfcc_allowed_packages_for_form` filter.

## Security behaviour

The browser never determines currency, routing, or an unrestricted amount.
WFC Cart creates the intent from the server-owned package, sends only the
client secret to Stripe.js, and re-fetches the completed intent from Stripe
before Gravity Forms accepts the entry. Client secrets are not stored in the
entry or transaction record.
