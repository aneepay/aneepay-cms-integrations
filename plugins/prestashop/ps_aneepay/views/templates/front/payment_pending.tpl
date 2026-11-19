{*
 * AneePay pending-payment screen: shows the token amount and the hosted-checkout
 * link, polls the status via check.php, reloads to success/fail on a terminal
 * status. Shown by controllers/front/payment.php.
 *}
{literal}<style>
.aneepay-pending{max-width:560px;margin:40px auto;text-align:center;}
.aneepay-pending-card{border:1px solid #e0e0e0;border-radius:8px;padding:32px 24px;background:#fff;}
.aneepay-pending-spinner{width:40px;height:40px;margin:0 auto 16px;border:4px solid #e6e6e6;border-top-color:#2f6fed;border-radius:50%;animation:aneepay-spin 1s linear infinite;}
@keyframes aneepay-spin{to{transform:rotate(360deg);}}
.aneepay-pending-amount{font-size:1.35em;margin:12px 0;}
.aneepay-pending-meta{color:#777;}
.aneepay-pending-info{color:#555;margin:12px 0 20px;}
.aneepay-pending-actions{margin:16px 0;}
.aneepay-pending-actions .btn{display:inline-block;margin:4px;}
.aneepay-pending-paused[hidden]{display:none;}
.aneepay-pending-paused p{color:#555;margin:12px 0 8px;}
.aneepay-pending-confirm{margin:12px auto;max-width:420px;text-align:left;font-size:13px;color:#555;}
.aneepay-pending-confirm label{display:flex;gap:8px;align-items:flex-start;cursor:pointer;}
.aneepay-pending-confirm input{margin-top:2px;flex:0 0 auto;}
.aneepay-pending-confirm.aneepay-confirm-error label span{color:#c0392b;}
.aneepay-pending-confirm.aneepay-confirm-error input{outline:2px solid #c0392b;outline-offset:2px;}
</style>{/literal}
<div class="aneepay-pending">
	<div class="aneepay-pending-card">
		<div class="aneepay-pending-spinner"></div>

		<h2>{l s='Waiting for the payment to be confirmed' mod='ps_aneepay'}</h2>
		<p>{l s='This page updates automatically. Once you have completed the payment, press the button below to refresh the status instantly.' mod='ps_aneepay'}</p>

		{if $pay_now}
			<p class="aneepay-pending-amount">{l s='Amount' mod='ps_aneepay'}: <strong>{$pay_now|escape:'html':'UTF-8'}</strong></p>
		{/if}

		{if $network}
			<p class="aneepay-pending-meta">{l s='Network' mod='ps_aneepay'}: {$network|escape:'html':'UTF-8'}{if $sandbox} &middot; {l s='testnet' mod='ps_aneepay'}{/if}</p>
		{/if}

		<p class="aneepay-pending-info">{l s='Payments are non-custodial: the funds go directly from your wallet to the merchant, minus a fixed 0.5% fee. No account or KYC is required.' mod='ps_aneepay'}</p>

		<div class="aneepay-pending-confirm" id="aneepay-confirm-wrap">
			<label for="aneepay-confirm">
				<input type="checkbox" id="aneepay-confirm" autocomplete="off">
				<span>{l s='I understand that I will be redirected to AneePay\'s secure payment page.' mod='ps_aneepay'}</span>
			</label>
		</div>

		<div class="aneepay-pending-actions">
			<a href="{$checkout_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="btn btn-primary btn-lg" id="aneepay-pay-now">{l s='Pay now' mod='ps_aneepay'}</a>
			<button type="button" class="btn btn-default" id="aneepay-i-paid">{l s='I already paid' mod='ps_aneepay'}</button>
		</div>

		<div class="aneepay-pending-paused" id="aneepay-poll-paused" hidden>
			<p>{l s='Automatic status checks are paused.' mod='ps_aneepay'}</p>
			<button type="button" class="btn btn-default" id="aneepay-check-again">{l s='Check again' mod='ps_aneepay'}</button>
		</div>

		<p><a href="{$shop_url|escape:'html':'UTF-8'}">{l s='Back to shop' mod='ps_aneepay'}</a></p>
	</div>
</div>

<script>
(function () {
	'use strict';
	var checkUrl = '{$check_url|escape:'javascript'}';
	var successUrl = '{$success_url|escape:'javascript'}';
	var failUrl = '{$fail_url|escape:'javascript'}';
	var orderId = {$order_id};
	var DELAYS = [5000, 15000, 30000];
	var BUDGET = 30 * 60 * 1000;
	var step = 0;
	var timer = null;
	var startedAt = 0;
	var stopped = false;
	var btn = document.getElementById('aneepay-i-paid');
	var payBtn = document.getElementById('aneepay-pay-now');
	var confirmBox = document.getElementById('aneepay-confirm');
	var confirmWrap = document.getElementById('aneepay-confirm-wrap');
	var pausedBox = document.getElementById('aneepay-poll-paused');
	var againBtn = document.getElementById('aneepay-check-again');

	function redirect(status) {
		if (status === 'success') {
			window.location.href = successUrl;
		} else if (status === 'failed' || status === 'cancelled') {
			window.location.href = failUrl;
		}
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
		var delay = DELAYS[Math.min(step, DELAYS.length - 1)];
		step += 1;
		if (Date.now() - startedAt + delay > BUDGET) {
			pause();
			return;
		}
		timer = setTimeout(poll, delay);
	}

	function poll() {
		var b = new URLSearchParams();
		b.append('id_order', orderId);
		fetch(checkUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: b.toString()
		}).then(function (r) { return r.json(); }).then(function (d) {
			if (d && (d.status === 'success' || d.status === 'failed' || d.status === 'cancelled')) {
				clearTimeout(timer);
				redirect(d.status);
			} else if (d && d.status === 'not_found') {
				clearTimeout(timer);
				stopped = true;
			} else {
				schedule();
			}
		}).catch(function () {
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

	if (btn) {
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			btn.textContent = '{l s='Checking…' mod='ps_aneepay'}';
			restart();
			poll();
		});
	}

	if (againBtn) {
		againBtn.addEventListener('click', function () {
			restart();
			poll();
		});
	}

	if (payBtn && confirmBox && confirmWrap) {
		payBtn.addEventListener('click', function (e) {
			if (!confirmBox.checked) {
				e.preventDefault();
				confirmWrap.classList.add('aneepay-confirm-error');
				confirmBox.focus();
			}
		});
		confirmBox.addEventListener('change', function () {
			confirmWrap.classList.remove('aneepay-confirm-error');
		});
	}

	startedAt = Date.now();
	poll();
})();
</script>