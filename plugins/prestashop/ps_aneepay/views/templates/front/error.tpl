{*
 * AneePay error page (expired/invalid payment session).
 *}
<div class="aneepay-result">
	<div class="aneepay-result-icon err">&#10005;</div>
	<h2>{$error_title|escape:'html':'UTF-8'}</h2>
	<p>{$error_message|escape:'html':'UTF-8'}</p>

	<p>
		<a href="{$cart_url|escape:'html':'UTF-8'}" class="btn btn-primary">{l s='Back to cart' mod='ps_aneepay'}</a>
		<a href="{$shop_url|escape:'html':'UTF-8'}">{l s='Back to shop' mod='ps_aneepay'}</a>
	</p>
</div>
