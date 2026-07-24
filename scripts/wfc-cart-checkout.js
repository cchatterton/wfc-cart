(function () {
	'use strict';

	window.WFCC = window.WFCC || {};

	window.WFCC.state = window.WFCC.state || {
		status: 'idle'
	};

	window.WFCC.setStatus = function (status, message) {
		var checkout = document.querySelector('.wfcc-checkout');
		var statusRegion;

		window.WFCC.state.status = status;

		if (!checkout) {
			return;
		}

		checkout.setAttribute('aria-busy', status === 'loading' ? 'true' : 'false');
		statusRegion = checkout.querySelector('[data-wfcc-status]');

		if (statusRegion) {
			statusRegion.textContent = message || '';
		}
	};
}());

