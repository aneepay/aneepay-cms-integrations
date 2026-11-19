<?php
// Heading
$_['heading_title']     = 'Pay with Crypto (USDT/USDC/DAI)';

// Text
$_['text_instruction']  = 'Pay instantly with a stablecoin on the Polygon network.';
$_['text_pending']      = 'Awaiting crypto payment.';
$_['text_rate_note']    = 'The amount is an estimate: the rate is fixed when you start the payment, so it can change slightly until then.';
$_['text_you_pay']      = 'You pay';

// Button
$_['button_confirm']    = 'Confirm order';
$_['button_retry']      = 'Try again';
$_['button_shop']       = 'Back to shop';
$_['button_i_paid']     = 'I already paid';
$_['button_check_again'] = 'Check again';

// Result pages
$_['text_success']      = 'Payment successful';
$_['text_failed']       = 'Payment not completed';
$_['text_awaiting']     = 'Waiting for the transaction to be confirmed on-chain.';
$_['text_checking']     = 'Checking…';
$_['text_poll_paused']  = 'Automatic status checks are paused.';

// Errors shown to the shopper (technical details stay in the request log)
$_['error_config']      = 'Payments are not available right now. Please try again later or choose another payment method.';
$_['error_network']     = 'We could not reach the payment service. Please try again in a moment.';
$_['error_auth']        = 'Payments are temporarily unavailable. Please try again later.';
$_['error_rate_limit']  = 'The payment service is busy. Please wait a minute and try again.';
$_['error_server']      = 'The payment service is temporarily unavailable. Please try again in a moment.';
$_['error_invalid']     = 'The payment service returned an unexpected response. Please try again.';
$_['error_request']     = 'We could not create the payment for this order. Please return to your cart and try again.';
$_['text_mapping_failed'] = 'AneePay payment %s was created but could not be saved locally. The buyer can still pay it; check the AneePay account if the status stays pending.';
$_['error_payment_in_progress'] = 'A payment for this order is already being created. Please wait a moment and refresh the page.';
