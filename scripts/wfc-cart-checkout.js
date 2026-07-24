(function () {
	'use strict';

	window.WFCC = window.WFCC || {};
	window.WFCC.instances = window.WFCC.instances || {};

	function message(key, fallback) {
		var config = window.WFCC_CONFIG || {};
		var strings = config.strings || {};
		return strings[key] || fallback;
	}

	function emit(name, detail) {
		window.dispatchEvent(new CustomEvent('wfcc:' + name, {
			detail: detail || {}
		}));
	}

	function requestKey() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID().replace(/-/g, '');
		}

		return (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2));
	}

	function Checkout(root) {
		this.root = root;
		this.formId = root.dataset.wfccFormId;
		this.packageId = root.dataset.wfccPackage;
		this.amountField = root.dataset.wfccAmountField;
		this.consentField = root.dataset.wfccConsentField;
		this.endpoint = root.dataset.wfccIntentUrl;
		this.form = root.closest('form');
		this.stripe = null;
		this.elements = null;
		this.paymentElement = null;
		this.intentType = 'payment';
		this.transactionKey = '';
		this.idempotencyKey = requestKey();
		this.confirmed = false;
		this.submitting = false;
		this.lastAmount = '';
		this.intentGeneration = 0;
		this.amountTimer = null;
	}

	Checkout.prototype.setSubmitDisabled = function (disabled) {
		var buttons = this.root.querySelectorAll('button[type="submit"], input[type="submit"]');
		Array.prototype.forEach.call(buttons, function (button) {
			button.disabled = disabled;
			button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
		});
	};

	Checkout.prototype.status = function (state, message) {
		var region = this.root.querySelector('[data-wfcc-status]');
		var error = this.root.querySelector('[data-wfcc-error]');

		this.root.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
		if (state === 'loading') {
			this.setSubmitDisabled(true);
		} else if (state === 'ready' || state === 'error') {
			this.setSubmitDisabled(false);
		}
		if (region) {
			region.textContent = state === 'error' ? '' : (message || '');
		}
		if (error) {
			error.hidden = state !== 'error';
			error.textContent = state === 'error' ? message : '';
			if (state === 'error') {
				error.setAttribute('tabindex', '-1');
				error.focus();
			}
		}
	};

	Checkout.prototype.amount = function () {
		var field;
		if (!this.amountField || this.amountField === '0') {
			return '';
		}
		field = document.getElementById('input_' + this.formId + '_' + this.amountField);
		return field ? field.value.trim() : '';
	};

	Checkout.prototype.consentGranted = function () {
		var fields;
		if (!this.consentField || this.consentField === '0') {
			return true;
		}
		fields = this.form.querySelectorAll(
			'[name="input_' + this.consentField + '"], [name^="input_' + this.consentField + '_"]'
		);
		if (!fields.length) {
			return false;
		}

		return Array.prototype.some.call(fields, function (field) {
			if (field.type === 'checkbox' || field.type === 'radio') {
				return field.checked;
			}
			return field.value.trim() !== '';
		});
	};

	Checkout.prototype.createIntent = async function () {
		var response;
		var body;
		var generation = ++this.intentGeneration;

		this.status('loading', message('preparing', 'Preparing secure payment fields…'));
		try {
			response = await window.fetch(this.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				referrerPolicy: 'same-origin',
				headers: {
					'Accept': 'application/json',
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					package: this.packageId,
					form_id: this.formId,
					amount: this.amount(),
					idempotency_key: this.idempotencyKey
				})
			});
		} catch (error) {
			if (generation !== this.intentGeneration) {
				return;
			}
			throw new Error(message('prepareFailed', 'Secure payment fields could not be prepared.'));
		}
		body = await response.json().catch(function () {
			return {};
		});
		if (generation !== this.intentGeneration) {
			return;
		}
		if (!response.ok) {
			throw new Error(body.message || message('prepareFailed', 'Secure payment fields could not be prepared.'));
		}

		this.intentType = body.intent_type;
		this.transactionKey = body.transaction_key;
		if (this.paymentElement) {
			this.paymentElement.destroy();
		}
		this.elements = this.stripe.elements({
			clientSecret: body.client_secret,
			appearance: {theme: 'stripe'}
		});
		this.paymentElement = this.elements.create('payment', {
			layout: 'tabs'
		});
		this.paymentElement.mount(this.root.querySelector('[data-wfcc-payment-element]'));
		this.lastAmount = this.amount();
		this.status('ready', message('ready', 'Secure payment fields are ready.'));
		emit('checkout_opened', {
			package: this.packageId
		});
	};

	Checkout.prototype.recreateForAmount = function () {
		var checkout = this;
		var requested = this.amount();
		window.clearTimeout(this.amountTimer);
		if (requested === this.lastAmount || this.submitting) {
			return;
		}
		this.status('loading', message('preparing', 'Preparing secure payment fields…'));
		this.amountTimer = window.setTimeout(function () {
			var current = checkout.amount();
			if (current === checkout.lastAmount || checkout.submitting) {
				if (!checkout.submitting) {
					checkout.status('ready', message('ready', 'Secure payment fields are ready.'));
				}
				return;
			}
			checkout.idempotencyKey = requestKey();
			checkout.createIntent().catch(checkout.fail.bind(checkout));
		}, 250);
	};

	Checkout.prototype.fail = function (error) {
		this.status('error', error && error.message ? error.message : message('paymentFailed', 'Payment could not be completed.'));
		this.submitting = false;
		emit('payment_failed', {
			package: this.packageId
		});
	};

	Checkout.prototype.handleSubmit = async function (event) {
		var result;
		var submitter = event.submitter || null;
		var intent;
		var keyInput;
		var intentInput;

		if (this.confirmed) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		if (this.submitting || !this.elements) {
			return;
		}

		this.submitting = true;
		this.status('loading', message('confirming', 'Confirming payment securely…'));
		emit('payment_started', {
			package: this.packageId
		});

		try {
			if (!this.consentGranted()) {
				throw new Error(message('consentRequired', 'Consent is required before saving a payment method for future payments.'));
			}
			result = await this.elements.submit();
			if (result.error) {
				throw result.error;
			}

			if (this.intentType === 'setup') {
				result = await this.stripe.confirmSetup({
					elements: this.elements,
					confirmParams: {return_url: window.location.href},
					redirect: 'if_required'
				});
				intent = result.setupIntent;
			} else {
				result = await this.stripe.confirmPayment({
					elements: this.elements,
					confirmParams: {return_url: window.location.href},
					redirect: 'if_required'
				});
				intent = result.paymentIntent;
			}
			if (result.error) {
				throw result.error;
			}
			if (!intent || intent.status !== 'succeeded') {
				throw new Error(message('notCompleted', 'Stripe has not completed the payment.'));
			}

			keyInput = this.root.querySelector('[name="wfcc_transaction_key"]');
			intentInput = this.root.querySelector('[name="wfcc_intent_id"]');
			keyInput.value = this.transactionKey;
			intentInput.value = intent.id;
			this.confirmed = true;
			this.status('success', message('confirmed', 'Payment confirmed. Completing your submission…'));
			emit('payment_succeeded', {
				package: this.packageId
			});
			this.setSubmitDisabled(false);
			if (typeof this.form.requestSubmit === 'function') {
				this.form.requestSubmit(submitter);
			} else {
				this.form.submit();
			}
		} catch (error) {
			this.fail(error);
		}
	};

	Checkout.prototype.init = function () {
		var amountInput;
		if (!this.form || !window.Stripe || !this.root.dataset.wfccPublishableKey) {
			this.fail(new Error(message('notConfigured', 'Stripe checkout is not configured.')));
			return;
		}

		this.stripe = window.Stripe(this.root.dataset.wfccPublishableKey);
		this.form.addEventListener('submit', this.handleSubmit.bind(this), true);
		if (this.amountField && this.amountField !== '0') {
			amountInput = document.getElementById('input_' + this.formId + '_' + this.amountField);
			if (amountInput) {
				amountInput.addEventListener('input', this.recreateForAmount.bind(this));
			}
		}
		this.createIntent().catch(this.fail.bind(this));
	};

	function initialise() {
		document.querySelectorAll('[data-wfcc-checkout]').forEach(function (root) {
			var key = root.dataset.wfccFormId;
			if (root.dataset.wfccInitialised === '1') {
				return;
			}
			root.dataset.wfccInitialised = '1';
			window.WFCC.instances[key] = new Checkout(root);
			window.WFCC.instances[key].init();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialise);
	} else {
		initialise();
	}
	document.addEventListener('gform/postRender', initialise);
}());
