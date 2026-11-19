{*
 * AneePay result page (success / fail).
 *
 * The outcome is resolved on the server (ps_aneepay::resultData() asks AneePay
 * for the payment status), so the page is correct without JavaScript; the
 * script below only polls while the payment is still pending.
 *}
<div class="aneepay-result">
	{if $state == 'paid'}
		<div class="aneepay-result-icon ok">&#10003;</div>
		<h2>{l s='Payment confirmed' mod='ps_aneepay'}</h2>
		<p>{l s='We received your payment. Thank you for your order!' mod='ps_aneepay'}</p>
	{elseif $state == 'failed'}
		<div class="aneepay-result-icon err">&#10005;</div>
		<h2>{l s='Payment not completed' mod='ps_aneepay'}</h2>
	{else}
		<h2>{l s='Waiting for the payment to be confirmed' mod='ps_aneepay'}</h2>
		<p>{l s='Waiting for the transaction to be confirmed on-chain.' mod='ps_aneepay'}</p>
	{/if}

	{if $state != 'failed'}
		{if $order_id}
			<p>{l s='Order' mod='ps_aneepay'}: <strong>#{$order_id}</strong></p>
		{/if}

		{if $payment_id}
			<p>{l s='AneePay payment id' mod='ps_aneepay'}: <strong>{$payment_id|escape:'html':'UTF-8'}</strong></p>
		{/if}

		{if $token}
			<p>{l s='You pay' mod='ps_aneepay'}: <strong>{$pay_now|escape:'html':'UTF-8'}</strong></p>
		{/if}
	{/if}

	{if $state == 'failed'}
		{if $checkout_url}
			<p><a href="{$checkout_url|escape:'html':'UTF-8'}" class="btn btn-primary">{l s='Try again' mod='ps_aneepay'}</a></p>
		{/if}
	{elseif $state == 'pending' && $order_id}
		<a href="{$check_url|escape:'html':'UTF-8'}&amp;id_order={$order_id}" class="btn btn-primary" id="aneepay-i-paid">
			{l s='I already paid' mod='ps_aneepay'}
		</a>
		<div class="aneepay-poll-paused" id="aneepay-poll-paused" hidden>
			<p>{l s='Automatic status checks are paused.' mod='ps_aneepay'}</p>
			<button type="button" class="btn btn-default" id="aneepay-check-again">{l s='Check again' mod='ps_aneepay'}</button>
		</div>
	{/if}

	<p><a href="{$shop_url|escape:'html':'UTF-8'}">{l s='Back to shop' mod='ps_aneepay'}</a></p>
</div>

{literal}<style>.aneepay-poll-paused[hidden]{display:none;}</style>{/literal}
{if $state == 'pending' && $order_id}
<script>
(function () {
	var btn = document.getElementById('aneepay-i-paid');
	var pausedBox = document.getElementById('aneepay-poll-paused');
	var againBtn = document.getElementById('aneepay-check-again');
	var DELAYS = [5000, 15000, 30000];
	var BUDGET = 30 * 60 * 1000;
	var step = 0;
	var timer = null;
	var startedAt = Date.now();
	var stopped = false;

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
		b.append('id_order', {$order_id});
		fetch('{$check_url|escape:'javascript'}', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: b.toString()
		}).then(function (r) { return r.json(); }).then(function (d) {
			if (d && (d.status === 'success' || d.status === 'failed' || d.status === 'cancelled')) {
				clearTimeout(timer);
				window.location.reload();
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

	schedule();
})();
</script>
{/if}
