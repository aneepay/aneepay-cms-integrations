<?php
// Heading
$_['heading_title']      = 'AneePay Crypto Gateway';

// Text
$_['text_extension']     = 'Extensions';
$_['text_payment']       = 'Payment';
$_['text_success']       = 'Success: You have modified AneePay!';
$_['text_edit']          = 'Edit AneePay Crypto Gateway';
$_['text_enabled']       = 'Enabled';
$_['text_disabled']      = 'Disabled';
$_['text_sandbox']       = 'SANDBOX';
$_['text_live']          = 'LIVE';
$_['text_general']       = 'General';
$_['text_connection']    = 'AneePay Connection';
$_['text_coin']          = 'Coin & Blockchain';
$_['text_statuses']      = 'Order Statuses';
$_['text_advanced']      = 'Advanced';
$_['text_always']        = 'Always';

// Entry
$_['entry_status']           = 'Status';
$_['entry_title']            = 'Title';
$_['entry_description']      = 'Description';
$_['entry_account_id']       = 'Account ID';
$_['entry_webhook_secret']   = 'Webhook Secret';
$_['entry_site_domain']      = 'Domain';
$_['entry_test_mode']        = 'Test / Sandbox Mode';
$_['entry_token']            = 'Cryptocurrency';
$_['entry_network']          = 'Blockchain';
$_['entry_rate_source']      = 'Exchange Rate Source';
$_['entry_exchange_rate']    = 'Manual Exchange Rate';
$_['entry_pending_status']   = 'Awaiting payment status';
$_['entry_paid_status']      = 'Payment confirmed status';
$_['entry_failed_status']    = 'Payment failed status';
$_['entry_cancelled_status'] = 'Cancelled status';
$_['entry_debug']            = 'Debug Log';
$_['help_debug']             = 'Log every AneePay API request (kept for 14 days). Errors are always logged, even when this is disabled.';
$_['entry_cron_interval']    = 'Status Sync Interval';
$_['entry_expiry_minutes']   = 'Abandoned Payment Expiry (minutes)';
$_['entry_fee_rule']         = 'Fee rule';
$_['entry_keep_data']        = 'On uninstall';

// Selects
$_['text_select']        = '— Select —';
$_['text_auto']          = 'Auto (Frankfurter/ECB) + manual fallback';
$_['text_manual']        = 'Manual rate only';
$_['text_fee_merchant']  = 'Merchant absorbs 0.5%';
$_['text_fee_customer']  = 'Add 0.5% to customer';
$_['text_keep_data']     = 'Keep data';
$_['text_delete_data']   = 'Delete data';

// Help / description
$_['help_account_id']       = 'Your AneePay account UUID. Sent in the X-Account-Id header for every API request.';
$_['help_webhook_secret']   = 'Used to verify the X-AneePay-Signature (HMAC-SHA256) on webhook calls. Required to confirm payments.';
$_['help_site_domain']      = 'Your verified merchant domain (Origin header). Leave empty to use your site host.';
$_['help_test_mode']        = 'When enabled, payments are created on the Amoy testnet with USDC and the is_safe check is bypassed.';
$_['help_exchange_rate']    = 'Used when the auto rate is unavailable. How many units of store currency make one token (≈1 USD).';
$_['help_cron_interval']    = 'How often to poll AneePay for pending orders. Set it up in your cron as shown below.';
$_['help_expiry_minutes']   = 'Pending orders older than this are cancelled by the cron job once the API confirms the payment did not go through. Set 0 to disable. Default: 120.';
$_['help_rate_fallback_store']  = 'The store currency (%s) has no ECB reference rate, so the auto rate is skipped and the store currency rate is used. Keep the store rates up to date.';
$_['help_rate_fallback_manual'] = 'The store currency (%s) has no ECB reference rate, so the auto rate is skipped and the manual rate is used.';
$_['help_keep_data']        = 'Keep AneePay orders and request logs in the database when the extension is uninstalled. Disable to delete them.';

// Errors
$_['error_permission']       = 'Warning: You do not have permission to modify AneePay!';
$_['error_account_id']       = 'AneePay Account ID is required (valid UUID).';
$_['error_webhook_secret']   = 'AneePay Webhook Secret is required to verify payment confirmations.';
$_['error_exchange_rate']    = 'Manual Exchange Rate must be a positive number.';
$_['error_site_domain']      = 'Origin domain must be a bare hostname, for example shop.example.com.';
$_['error_pending_status_id']   = 'Choose the order status for an awaiting payment.';
$_['error_paid_status_id']      = 'Choose the order status for a confirmed payment.';
$_['error_failed_status_id']    = 'Choose the order status for a failed payment.';
$_['error_cancelled_status_id'] = 'Choose the order status for a cancelled payment.';
$_['error_status_duplicate']    = 'Each payment state needs its own order status.';
$_['error_warning']          = 'Warning: Please check the form carefully for errors!';

// Buttons / misc
$_['button_test']        = 'Test connection';
$_['button_logs']        = 'Request log';
$_['button_sync']        = 'Sync';

// Sync one payment by ID
$_['text_sync_payment']       = 'Sync a Payment';
$_['entry_sync_payment_id']   = 'AneePay Payment ID';
$_['entry_sync_order_id']     = 'Order ID (optional)';
$_['text_syncing']            = 'Syncing…';
$_['text_sync_failed']        = 'Sync failed';
$_['text_sync_ok']            = 'Order #%1$d synced: AneePay reports "%2$s".';
$_['error_sync_payment_id']   = 'Enter the AneePay payment ID.';
$_['error_sync_not_found']    = 'AneePay did not return a payment for %1$s (HTTP %2$d).';
$_['error_sync_no_order']     = 'No AneePay order matches payment %s. Open the order and copy its payment ID, or pass the order ID as well.';
$_['help_sync_payment']       = 'Fetches the payment from AneePay right now and applies its status to the order. Use it when the buyer says they paid but the order is still pending.';

// Reconcile a period with the account payment list
$_['button_reconcile']         = 'Reconcile';
$_['text_reconcile']           = 'Reconcile Payments';
$_['text_reconciling']         = 'Reconciling…';
$_['text_reconcile_failed']    = 'Reconcile failed';
$_['text_reconcile_ok']        = 'Checked %1$d payments: %2$d matched an order, %3$d were updated, %4$d had no order.';
$_['entry_reconcile_days']     = 'Days back';
$_['error_reconcile_list']     = 'AneePay did not return the payment list (HTTP %d).';
$_['help_reconcile']           = 'Compares this store with the payments AneePay recorded in the last N days and applies the status of every payment it can match to an order. A webhook is delivered once and never replayed, so this is how a missed delivery is found.';

$_['button_clear']       = 'Clear';
$_['text_checking']      = 'Checking…';
$_['text_connected']     = 'Connection OK. Account exists and the domain matches.';
$_['text_failed']        = 'Connection failed';
$_['text_no_logs']       = 'No requests logged yet.';
$_['text_endpoint']      = 'Set these URLs in the AneePay account panel:';
$_['text_status_url']    = 'STATUS_URL (webhook)';
$_['text_success_url']   = 'SUCCESS_URL';
$_['text_fail_url']      = 'FAIL_URL';
$_['text_copy']          = 'Copy';
$_['text_copied']        = 'Copied';

// Quick setup / helpers
$_['text_quick_setup']    = 'Quick setup';
$_['text_quick_setup_1']  = 'Create or open your account in the AneePay dashboard.';
$_['text_quick_setup_2']  = 'Set your wallet address, domain and the three URLs below in the account panel.';
$_['text_quick_setup_3']  = 'Copy the Account ID and Webhook Secret into the fields, then save.';
$_['text_quick_setup_4']  = 'Press "Test connection" to verify.';
$_['text_cron_hint']      = 'Cron URL (schedule it on your server):';
$_['text_endpoints']      = 'Endpoints';
$_['text_example']        = 'Example conversion';
