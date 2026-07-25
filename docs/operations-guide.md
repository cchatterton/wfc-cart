# WFC Cart operations guide

WFC Cart operational workflows use protected transaction records without
duplicating donor PII. In WordPress CRM mode, the single Gravity Forms cart
entry is the authoritative donor record. Transaction-list links open that
entry for users who also have the required Gravity Forms permissions.

## Capabilities

Administrators receive these capabilities during activation or the schema 6
upgrade:

```text
wfcc_view_reports
wfcc_export_transactions
wfcc_import_operations
wfcc_manage_batches
wfcc_manage_receipts
```

Assign only the capabilities required by each operational role.

## Receipts

Configure receipts under **WFC Cart → Settings → Receipts**.

- Receipt generation is enabled by default for new successful payments.
- Automatic email is disabled by default.
- Receipt numbers are deterministic:
  `PREFIX-YEAR-TRANSACTIONID`.
- Configure the Gravity Forms entry field containing the recipient email.
- `{receipt_number}` may be used in the email subject.
- Emails are plain text and contain the receipt number, date, original amount,
  and WFC transaction reference.

The email address is read from the protected Gravity Forms entry only when
sending. It is never copied into transaction metadata. The Receipts screen
shows delivery state and permits a nonce-protected manual resend.

Refunded and disputed transactions retain their original receipt and amount.
Current payment status remains available in reports and Salesforce
reconciliation.

## Reports

**WFC Cart → Reports** accepts an inclusive date range of up to 366 days and
processes at most 5,000 transactions. It reports:

- transaction count;
- payment-state counts;
- generic CRM-state counts;
- original transaction totals by currency;
- receipt count; and
- batch count.

Totals are deliberately described as original transaction totals. Refund
amounts are not inferred when Stripe has not supplied an authoritative
refunded-amount record.

## CSV exports

**WFC Cart → Exports** downloads the same bounded date range. The fixed columns
are:

```text
transaction_key
created_at
payment_state
crm_mode
crm_state
salesforce_state
amount_minor
currency
package_id
frequency
receipt_number
receipt_delivery_state
batch_id
fund_code
external_reference
```

Donor names, donor email addresses, addresses, Stripe Customer IDs,
PaymentMethod IDs, and Salesforce record IDs are excluded. Text cells that
could be interpreted as spreadsheet formulas are prefixed safely.

## Operational metadata imports

**WFC Cart → Imports** accepts pasted CSV with this exact header:

```csv
transaction_key,fund_code,reference
```

The import is limited to 500 rows and 256 KB. It can attach a sanitised fund
code and opaque external operational reference to an existing transaction.
References accept machine identifiers such as `REF-100/ABC`; emails,
phone-like values, and free text are rejected.

It cannot:

- create a transaction;
- change an amount or currency;
- change a payment or Salesforce state;
- attach donor details; or
- select a Salesforce object or field.

Every transaction key is resolved before any row is updated. Duplicate keys,
unknown keys, additional financial columns, malformed rows, and oversized
documents fail the import.

## Batch management

**WFC Cart → Batch Management** creates a batch from up to 500 successful,
unbatched transactions in a maximum 366-day range.

Eligible payment states are:

```text
succeeded
partially_refunded
refunded
disputed
```

A stale-safe global lock prevents concurrent batch builders. A completed batch
is private and sealed, records its period, count, creator, timestamp, and
original totals by currency, and links each included transaction once.

## Server-side line-item adapters

WFC Cart itself creates a primary donation line item before Salesforce
delivery is queued. A server-side product, event, or shipping adapter may add
another item through:

```php
$line_item_id = wfcc_add_transaction_line_item(
	$transaction_id,
	array(
		'type'         => 'event',
		'source_key'   => 'booking_101',
		'source_ref'   => 'booking-101',
		'label'        => 'Community dinner',
		'quantity'     => 2,
		'unit_amount'  => 2500,
		'tax_amount'   => 500,
		'total_amount' => 5500,
		'currency'     => 'AUD',
		'fund_code'    => 'events-2026',
	)
);
```

`source_key` is required and provides per-transaction idempotency. Supported
types are `donation`, `product`, `event`, `shipping`, `fee`, and `adjustment`.
Only an adjustment may use negative amounts. WFC Cart does not require or load
an external commerce or event plugin to expose this server-side contract.

The fixed Salesforce request includes at most 100 line items under payload
schema version `1.1`.

## Operational checks

Before production use:

1. Confirm the scheduled-processing check is healthy.
2. Generate a receipt without automatic email.
3. Enable email in staging and verify the configured Gravity Forms field.
4. Export a test range and confirm no donor or reusable payment identifiers
   are present.
5. Test a metadata import containing an unknown transaction and confirm no
   rows change.
6. Create a batch and confirm the same transactions cannot enter another
   batch.
7. Add the same line-item source key twice and confirm only one item exists.
8. Verify Salesforce accepts payload schema `1.1` before production rollout.
