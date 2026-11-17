<?php
// English source strings for the PrestaShop translator (Module::l()).
// Copy this file to <iso>.php and translate the values, e.g. ru.php.
global $_MODULE;
$_MODULE = array();
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_36871d01dc75393ec3a8b650572390f4'] = 'Payments are not available right now. Please try again later or choose another payment method.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_1910f6e4b75c292a0b4b1f390c18f8b2'] = 'We could not reach the payment service. Please try again in a moment.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_2baa81e5ca4befb752f98df7d4aad046'] = 'Payments are temporarily unavailable. Please try again later.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_ad2ac5ecaebe99ce74d9acda6fad19a5'] = 'The payment service is busy. Please wait a minute and try again.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_4a46533a933282c1d0ca92962de7955b'] = 'The payment service is temporarily unavailable. Please try again in a moment.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_947b9867fe00da2d7a05602a768ef0ac'] = 'The payment service returned an unexpected response. Please try again.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_ab23e38b18cc6e90961feeac5ce81c05'] = 'We could not create the payment for this order. Please return to your cart and try again.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_69d1c4b38c1e28dc6310d1d13fb78c7f'] = 'Too many payment attempts. Please wait a few minutes and try again.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_dba178d2cabf117f97540669eb17d237'] = 'Unable to create the order. Please try again.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_5c9fdcfc61d73994a2bf19a7175d7e58'] = 'Payment session expired';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_6b01733a39b8f9d35e4618b6d9b28009'] = 'This payment page is no longer valid. Please return to your cart and start the payment again — nothing has been charged.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_824a78908ffc3f48cefba98aaf88402f'] = 'The amount is an estimate: the rate is fixed when you start the payment, so it can change slightly until then.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_ef68e4e640e7e5c2f71365650ae6411e'] = 'Auto uses the live Frankfurter (ECB) rate and falls back to the store rate, then the manual rate.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_c46d36c0a4f775e0e533c7a54ef202c6'] = 'The store currency (%s) has no ECB reference rate, so the manual rate is used.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_1442793f42748de154500f45e223c964'] = 'The store currency (%s) has no ECB reference rate, so the store currency rate is used. Keep the store rates up to date.';

// Sync a payment (manual status pull)
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_046ab71aac1d7dca6b9c23c1a8dab5a9'] = 'Sync a payment';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_bdf7f31781e584520f87d976694eb804'] = 'AneePay payment ID';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_e900b0919eba4068fa4c576a937a31e5'] = 'Fetches the payment from AneePay right now and applies its status to the order. Use it when the buyer says they paid but the order is still pending.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_b781034b1393202bff8da13c4a2e0f81'] = 'Order ID (optional)';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_d8e87c0927539672f54462c837be0b7f'] = 'Sync';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_dfb86153432a640cd09a041f82a6ba7a'] = 'Enter the AneePay payment ID.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_ad3800ab89176e4bba707fe3266d4910'] = 'AneePay did not return a payment for %1$s (HTTP %2$d).';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_052e20e50347d3c397f3ee46b50b05d0'] = 'No AneePay order matches payment %s. Open the order and copy its payment ID, or pass the order ID as well.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_07d18ef3b8890e8550b37380653a1129'] = 'Order #%1$d synced: AneePay reports "%2$s".';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_1c6c1bbb95f5b26b88bc61c469ca4f56'] = 'Reconcile payments';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_f8106295a3fd115527f3d2ecfa3f805c'] = 'Days back';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_21f3dd67b3e0a9c8fa43d92d06789ceb'] = 'Compares this store with the payments AneePay recorded in the last N days and applies the status of every payment it can match to an order. A webhook is delivered once and never replayed, so this is how a missed delivery is found.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_fad857d5c329e6b67a007175c80bc7fe'] = 'Reconcile';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_ceea7f7c87cdafea3bdc33323f7264f0'] = 'AneePay did not return the payment list (HTTP %d).';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_82c28e5ee5352be2646077987c6cd455'] = 'Checked %1$d payments: %2$d matched an order, %3$d were updated, %4$d had no order.';
$_MODULE['<{ps_aneepay}prestashop>ps_aneepay_7b6501c829a16fbd3902900197e774b2'] = 'A payment for this order is already being created. Please wait a moment and refresh the page.';
