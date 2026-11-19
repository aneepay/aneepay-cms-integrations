/**
 * AneePay Crypto Gateway - checkout status polling + "I already paid".
 *
 * The status is checked with a growing delay (5 s, 15 s, then every 30 s) for
 * at most 30 minutes, so a slow confirmation does not hammer the API while the
 * buyer keeps the tab open. When the budget runs out the polling stops and the
 * page offers an explicit "Check again" button instead of silently going idle.
 */
(function () {
	'use strict';

	var DELAYS_MS = [5000, 15000, 30000];
	var BUDGET_MS = 30 * 60 * 1000;

	var block = document.querySelector('.aneepay-payment[data-order-id]');

	if (!block) {
		return;
	}

	var orderId = block.getAttribute('data-order-id');
	var orderKey = block.getAttribute('data-order-key') || '';
	var pausedBox = block.querySelector('.aneepay-poll-paused');
	var againBtn = block.querySelector('.aneepay-check-again');
	var params = window.aneepayParams || {};
	var step = 0;
	var timer = null;
	var startedAt = 0;
	var stopped = false;

	function onTerminal(status) {
		if (status === 'success' || status === 'failed' || status === 'cancelled') {
			clearTimeout(timer);
			window.location.reload();
			return true;
		}
		return false;
	}

	function checkStatus() {
		var body = new URLSearchParams();
		body.append('action', 'aneepay_check_status');
		body.append('nonce', params.nonce);
		body.append('orderId', orderId);
		body.append('orderKey', orderKey);

		return fetch(params.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (data && data.success && data.data && data.data.status) {
					var status = data.data.status;
					if (onTerminal(status)) {
						return { terminal: true };
					}
					return { status: status };
				}
				return { status: '' };
			})
			.catch(function () {
				return { status: '' };
			});
	}

	function pause() {
		stopped = true;
		clearTimeout(timer);

		if (pausedBox) {
			pausedBox.hidden = false;
		}
	}

	function schedule() {
		if (stopped) {
			return;
		}

		var delay = DELAYS_MS[Math.min(step, DELAYS_MS.length - 1)];
		step += 1;

		if (Date.now() - startedAt + delay > BUDGET_MS) {
			pause();
			return;
		}

		timer = setTimeout(poll, delay);
	}

	function poll() {
		checkStatus().then(function (result) {
			if (result && result.terminal) {
				return;
			}
			schedule();
		});
	}

	function restart() {
		clearTimeout(timer);
		stopped = false;
		step = 0;
		startedAt = Date.now();

		if (pausedBox) {
			pausedBox.hidden = true;
		}
	}

	startedAt = Date.now();
	schedule();

	if (againBtn) {
		againBtn.addEventListener('click', function () {
			restart();
			poll();
		});
	}

	var paidBtn = block.querySelector('.aneepay-i-paid');

	if (paidBtn) {
		paidBtn.addEventListener('click', function () {
			paidBtn.disabled = true;
			var original = paidBtn.textContent;
			paidBtn.textContent = params.checkingLabel || 'Checking...';
			restart();

			checkStatus().then(function (result) {
				if (result && result.terminal) {
					return;
				}
				paidBtn.disabled = false;
				paidBtn.textContent = original;
				schedule();
			});
		});
	}
})();
