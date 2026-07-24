# Salesforce setup

WFC Cart 0.5.0 sends completed transactions from WordPress to a
purpose-specific Apex REST endpoint. It does not expose Salesforce
authentication or object selection to the browser.

## 1. Create the Salesforce integration identity

Create a dedicated integration user with only the permissions required by the
transaction endpoint. Do not use a human administrator account.

Create an External Client App configured for the OAuth client-credentials flow
and assign its run-as user to the dedicated integration user. Record the
consumer key and consumer secret.

The endpoint must be implemented at:

```text
/services/apexrest/wfc-cart/v1/transactions
```

It must accept and return only the agreed versioned WFC transaction contract.
The Apex implementation is responsible for matching or creating donor and gift
records and for treating `transactionKey` as a unique idempotency key.

## 2. Configure credentials

The recommended production configuration is in `wp-config.php` or environment
variables:

```php
define('WFCC_SALESFORCE_LOGIN_URL', 'https://example.my.salesforce.com');
define('WFCC_SALESFORCE_CLIENT_ID', 'your-consumer-key');
define('WFCC_SALESFORCE_CLIENT_SECRET', 'your-consumer-secret');
define('WFCC_SALESFORCE_API_PATH', '/services/apexrest/wfc-cart/v1/transactions');
```

The same names may be provided as environment variables. Precedence is:

1. `wp-config.php` constant
2. environment variable
3. saved WFC Cart setting

WFC Cart validates login and instance URLs as HTTPS `salesforce.com` origins,
disables HTTP redirects, and retains OAuth access tokens only for the current
PHP request.

## 3. Configure controlled field mapping

In **WFC Cart → Settings → Salesforce**, enter a JSON object that maps Gravity
Forms entry fields to the fixed WFC payload targets. A source field uses the
Gravity Forms entry key, including sub-inputs such as `1.3`.

```json
{
  "first_name": {
    "source": "field",
    "field_id": "1.3",
    "transform": "text"
  },
  "last_name": {
    "source": "field",
    "field_id": "1.6",
    "transform": "text"
  },
  "email": {
    "source": "field",
    "field_id": "2",
    "transform": "email"
  },
  "source": {
    "source": "constant",
    "value": "website",
    "transform": "lower"
  },
  "consent_evidence": {
    "source": "constant",
    "value": "Gravity Forms recurring consent",
    "when": {
      "field_id": "8",
      "equals": "yes"
    }
  },
  "metadata": {
    "appeal_variant": {
      "source": "field",
      "field_id": "10",
      "transform": "text"
    }
  }
}
```

Supported fixed targets are:

```text
first_name
last_name
email
phone
address_line1
address_line2
city
state
postcode
country
source
medium
attribution_campaign
recurrence_start
consent_evidence
```

Supported transformations are `text`, `email`, `phone`, `upper`, `lower`,
`date`, and `boolean`. Mapping keys that resemble Salesforce object or field
API names are discarded. Required targets are configured separately; the
default is `email, last_name`.

## 4. Validate the connection

Save the settings, then use **Test saved connection**. The test performs OAuth
authentication only. It does not create a Salesforce transaction and stores
only the result category and time, never a credential or response body.

Confirm the Salesforce check on **WFC Cart → Health** is healthy.

## 5. Validate delivery

In a Salesforce sandbox and Stripe test mode:

1. Complete a one-off donation.
2. Confirm its queue state becomes `salesforce_delivered`.
3. Confirm the returned Salesforce transaction reference is attached to the
   protected WFC transaction.
4. Replay the same transaction key and confirm Salesforce returns the existing
   result without creating duplicate records.
5. Cause a temporary endpoint failure and confirm the transaction is retried.
6. Cause a validation failure and confirm it moves to `manual_review`.
7. Use **WFC Cart → Delivery Queue → Retry** after correcting the configuration.
8. Issue a partial refund, full refund, cancellation, and dispute in test mode
   and confirm the current payment state is reconciled.

WP-Cron must be able to run. WFC Cart processes a bounded delivery batch every
five minutes. A successful checkout remains successful even if Salesforce is
temporarily unavailable.

## Fixed response contract

The endpoint returns:

```json
{
  "transactionKey": "wfcc_unique_transaction_key",
  "success": true,
  "duplicate": false,
  "records": {
    "transactionId": "a001234567890AB",
    "contactId": "0031234567890AB",
    "recurringGiftId": "a0R1234567890AB"
  },
  "reconciliationStatus": "synced"
}
```

Record references must be valid 15- or 18-character Salesforce IDs. An HTTP
409 response is accepted only when the response explicitly marks the request
as a duplicate and returns the matching `transactionKey`.

## Operational behaviour

- HTTP 408, 429, 500, 502, 503, and 504 responses are retried.
- A 401 triggers one fresh OAuth exchange and one request retry.
- Authorization, validation, schema, and payload failures require manual
  review.
- The default attempt limit is eight and may be changed from 1 to 20.
- Stored errors contain only an internal code, category, and HTTP status.
- Donor details are read from the protected Gravity Forms entry at delivery
  time and are not duplicated into the queue metadata.
