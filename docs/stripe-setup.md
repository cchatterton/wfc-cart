# Stripe setup

WFC Cart 0.4 uses Stripe-hosted Payment Element fields. Card numbers and CVCs
are sent from the donor's browser directly to Stripe and are never submitted
to WordPress or Gravity Forms.

## Credentials

Configure the following in **WFC Cart → Settings → Stripe**, or preferably as
`wp-config.php` constants/environment variables:

```php
define('WFCC_STRIPE_PUBLISHABLE_KEY', 'pk_test_...');
define('WFCC_STRIPE_SECRET_KEY', 'sk_test_...');
define('WFCC_STRIPE_WEBHOOK_SECRET', 'whsec_...');
```

Use matching test-mode keys while validating a site. Never place the secret key
or webhook signing secret in JavaScript, HTML, URLs, analytics, or logs.

## Webhook

Create a Stripe webhook endpoint with this URL:

```text
https://example.org/wp-json/wfc-cart/v1/stripe/webhook
```

Subscribe only to:

- `payment_intent.succeeded`
- `payment_intent.processing`
- `payment_intent.requires_action`
- `payment_intent.payment_failed`
- `payment_intent.canceled`
- `setup_intent.succeeded`
- `setup_intent.requires_action`
- `setup_intent.setup_failed`
- `setup_intent.canceled`
- `charge.refunded`
- `charge.dispute.created`

Copy that endpoint's signing secret into `WFCC_STRIPE_WEBHOOK_SECRET`. WFC Cart
uses the raw body, validates the timestamped HMAC, rejects unsigned events, and
deduplicates event IDs.

## Operational validation

Before live use, test a successful card, a decline, 3DS authentication,
duplicate submission, duplicate webhook delivery, refund, and dispute event.
Confirm the WFC transaction record reaches the expected state each time.

The implementation deliberately uses Stripe's HTTPS API through the WordPress
HTTP API, so it does not install a global PHP SDK or introduce Composer
dependency conflicts.
